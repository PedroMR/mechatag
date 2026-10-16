#!/usr/bin/env php
<?php
/**
 * simulate.php — run headless MechaTag fights between two saved team profiles.
 *
 * Usage:
 *   php tools/simulate.php new <name>          Create a team profile (interactive wizard)
 *   php tools/simulate.php profiles            List saved profiles
 *   php tools/simulate.php show <name>         Print a profile's mechs
 *   php tools/simulate.php run <A> <B> [--fights=100 | -f 100] [--seed=N] [--log=K] [--no-color]
 *                                              Fight profile A (team 1) against B (team 2)
 *
 * Profiles are JSON files in tools/profiles/. Loadouts are checked against the
 * config rules: parts and equipment in slots their card allows, and equipment
 * within each slot's hardpoints. The number of copies of a card is not limited.
 *
 * Every choice in a fight is made by a fixed rule:
 *   charge (CORE / generator)  the first modules offered, up to the allowed count
 *   MOVE                       forward 1
 *   shields                    in the order offered, until the damage is fully blocked
 *   lock target / lock to use  the first one offered
 *
 * Output is colored when stdout is a terminal (disable with --no-color or NO_COLOR).
 *
 * Fight i uses seed (base seed + i), so --seed=S --log=K replays fight K exactly.
 */

require_once __DIR__ . '/../server/lib/Fight.php';
require_once __DIR__ . '/../server/lib/Config.php';

const PROFILES_DIR = __DIR__ . '/profiles';
const SLOTS        = ['head', 'torso', 'r_arm', 'l_arm', 'legs'];
const SLOT_LABELS  = ['head' => 'Head', 'torso' => 'Torso', 'r_arm' => 'R Arm', 'l_arm' => 'L Arm', 'legs' => 'Legs'];
const TEAM_PILOTS  = ['1' => ['blue', 'green'], '2' => ['purple', 'black']];

function modules(): array {
    static $m = null;
    return $m ??= array_column(
        json_decode(file_get_contents(__DIR__ . '/../server/data/cards/modules.json'), true), null, 'id'
    );
}

// ── ANSI colors (off when stdout isn't a terminal, NO_COLOR is set, or --no-color) ──

const C_NUM   = '38;5;75';     // numbers: light blue
const C_HEAD  = '1';           // section headings and team labels: bold
const C_DIM   = '2';           // side notes
const C_LEAD  = '1;38;5;84';   // the better win percentage: bold green
const C_TRAIL = '1;38;5;203';  // the worse win percentage: bold red
const C_EVEN  = '1;38;5;221';  // draws, and win percentages when tied: bold yellow
const C_TITLE = '1;38;5;213';  // wizard: mech headings
const C_NAME  = '1';           // wizard: card names
const C_PROMPT= '38;5;117';    // wizard: input prompt
const C_WARN  = '38;5;214';    // wizard: invalid input
const C_OK    = '1;38;5;84';   // wizard: saved
const C_T1    = '38;5;103';    // tier tags, as in the game UI
const C_T2    = '1;38;5;43';
const C_T3    = '1;38;5;205';

function color(string $code, string $s): string {
    return USE_COLOR ? "\e[{$code}m$s\e[0m" : $s;
}

// Echoes a line with every number not already inside a colored span painted C_NUM.
function out(string $s): void {
    if (USE_COLOR) {
        $inside = false;
        $s = preg_replace_callback('/\e\[([\d;]*)m|\d+(?:\.\d+)?%?/', function ($m) use (&$inside) {
            if (isset($m[1])) { $inside = $m[1] !== '0'; return $m[0]; }
            return $inside ? $m[0] : color(C_NUM, $m[0]);
        }, $s);
    }
    echo $s;
}

function fail(string $msg): never {
    fwrite(STDERR, "Error: $msg\n");
    exit(1);
}

// ── Loadout rules ────────────────────────────────────────────────────────────

function slot_accepts(string $slot, array $card): bool {
    $parts = $card['parts'] ?? [];
    if (!$parts || in_array('any', $parts, true)) return true;
    return in_array(in_array($slot, ['r_arm', 'l_arm'], true) ? 'arm' : $slot, $parts, true);
}

function slot_hardpoints(array $mech, string $slot): int {
    $part = $mech['slots'][$slot] ?? null;
    return $part !== null
        ? (int)(modules()[$part]['hardpoints_offered'] ?? 0)
        : Config::DEFAULT_SLOT_HP[$slot];
}

function slot_hardpoints_used(array $mech, string $slot): int {
    $used = 0;
    foreach ($mech['equipment'][$slot] ?? [] as $id) $used += (int)(modules()[$id]['hardpoints_required'] ?? 0);
    return $used;
}

/** Problems with a profile's loadout; empty if it's legal. */
function validate(array $profile): array {
    $errors = [];
    if (count($profile['mechs'] ?? []) !== 2) return ['a profile needs exactly 2 mechs'];
    foreach ($profile['mechs'] as $i => $mech) {
        $n = $i + 1;
        foreach (SLOTS as $slot) {
            $part = $mech['slots'][$slot] ?? null;
            if ($part !== null) {
                $card = modules()[$part] ?? null;
                if (!$card)                        $errors[] = "mech $n $slot: unknown card '$part'";
                elseif ($card['type'] !== 'part')  $errors[] = "mech $n $slot: '$part' is not a part";
                elseif (!slot_accepts($slot, $card)) $errors[] = "mech $n $slot: {$card['name']} can't go in this slot";
            }
            foreach ($mech['equipment'][$slot] ?? [] as $id) {
                $card = modules()[$id] ?? null;
                if (!$card)                             $errors[] = "mech $n $slot: unknown card '$id'";
                elseif ($card['type'] !== 'equipment')  $errors[] = "mech $n $slot: '$id' is not equipment";
                elseif (!slot_accepts($slot, $card))    $errors[] = "mech $n $slot: {$card['name']} can't attach here";
            }
            if (slot_hardpoints_used($mech, $slot) > slot_hardpoints($mech, $slot)) {
                $errors[] = "mech $n $slot: equipment needs " . slot_hardpoints_used($mech, $slot)
                    . " hardpoints, slot has " . slot_hardpoints($mech, $slot);
            }
        }
    }
    return $errors;
}

// ── Profiles ─────────────────────────────────────────────────────────────────

function profile_path(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) fail("profile names may only use letters, digits, - and _");
    return PROFILES_DIR . "/$name.json";
}

function load_profile(string $name): array {
    $path = profile_path($name);
    if (!file_exists($path)) fail("no profile '$name' (see: php tools/simulate.php profiles)");
    $profile = json_decode(file_get_contents($path), true);
    if (!$profile) fail("$path is not valid JSON");
    if ($errors = validate($profile)) fail("profile '$name' is not a legal loadout:\n  " . implode("\n  ", $errors));
    return $profile;
}

function describe_mech(array $mech): string {
    $lines = [];
    foreach (SLOTS as $slot) {
        $part = $mech['slots'][$slot] ?? null;
        $name = $part ? modules()[$part]['name'] : ($slot === 'head' ? '—' : 'Default');
        $eq   = array_map(fn($id) => modules()[$id]['name'], $mech['equipment'][$slot] ?? []);
        $lines[] = '    ' . color(C_DIM, str_pad(SLOT_LABELS[$slot], 6)) . ' ' . color(C_NAME, $name)
            . ($eq ? '  + ' . implode(', ', $eq) : '');
    }
    return implode("\n", $lines);
}

function cmd_profiles(): void {
    $files = glob(PROFILES_DIR . '/*.json') ?: [];
    if (!$files) { echo "No profiles yet. Create one with: php tools/simulate.php new <name>\n"; return; }
    foreach ($files as $f) {
        $p = json_decode(file_get_contents($f), true);
        $mods = [];
        foreach ($p['mechs'] ?? [] as $m) {
            $ids = array_merge(array_filter(array_values($m['slots'])), ...array_values($m['equipment']));
            $mods[] = implode(', ', array_map(fn($id) => modules()[$id]['name'] ?? $id, $ids)) ?: '(all default)';
        }
        out(color(C_HEAD, basename($f, '.json')) . "\n"
            . color(C_DIM, '    mech 1: ') . ($mods[0] ?? '?') . "\n"
            . color(C_DIM, '    mech 2: ') . ($mods[1] ?? '?') . "\n");
    }
}

function cmd_show(string $name): void {
    $p = load_profile($name);
    foreach ($p['mechs'] as $i => $m) out(color(C_TITLE, 'Mech ' . ($i + 1)) . "\n" . describe_mech($m) . "\n");
}

// ── Wizard ───────────────────────────────────────────────────────────────────
//
// Every answer is recorded. Typing b drops the last answer and rebuilds the
// profile by silently replaying the remaining ones, then asks that question again.

final class GoBack extends Exception {}

final class Wiz {
    public static array $answers = [];
    public static int   $pos     = 0;
    public static function replaying(): bool { return self::$pos < count(self::$answers); }
}

/** Prints only when the wizard is asking live (not replaying recorded answers). */
function say(string $s): void {
    if (!Wiz::replaying()) out($s);
}

function read_line(string $prompt): string {
    echo $prompt;
    $line = fgets(STDIN);
    if ($line === false) fail('input ended');
    return trim($line);
}

/**
 * Asks one recorded question. $parse turns the typed text into an answer, or
 * returns false to ask again. Typing b goes back to the previous question.
 */
function ask(string $prompt, callable $parse, string $retry): mixed {
    if (Wiz::replaying()) return Wiz::$answers[Wiz::$pos++];
    while (true) {
        $a = read_line($prompt);
        if (strtolower($a) === 'b') {
            if (!Wiz::$answers) { out(color(C_WARN, '    Nothing to go back to.') . "\n"); continue; }
            array_pop(Wiz::$answers);
            out(color(C_DIM, '    ← back') . "\n");
            throw new GoBack();
        }
        $v = $parse($a);
        if ($v !== false) {
            Wiz::$answers[] = $v;
            Wiz::$pos++;
            return $v;
        }
        out(color(C_WARN, "    $retry") . "\n");
    }
}

function ask_yes_no(string $question): bool {
    say("\n" . color(C_HEAD, $question) . ' ' . color(C_DIM, '[y/N, b = back]') . "\n");
    return ask(color(C_PROMPT, '    > '), fn($a) => match (strtolower($a)) {
        'y', 'yes'     => 'y',
        '', 'n', 'no'  => 'n',
        default        => false,
    }, 'Answer y or n, or b to go back.') === 'y';
}

/** Numbered menu; returns the chosen key, or null for the blank/default answer. */
function menu(array $options, string $blank_label): ?string {
    $keys = array_keys($options);
    say(color(C_DIM, "      0) $blank_label") . "\n");
    foreach ($keys as $i => $k) say(sprintf("     %2d) %s\n", $i + 1, $options[$k]));
    return ask(color(C_PROMPT, '    > '), function ($a) use ($keys) {
        if ($a === '' || $a === '0') return null;
        if (ctype_digit($a) && isset($keys[(int)$a - 1])) return $keys[(int)$a - 1];
        return false;
    }, 'Enter a number from the list, or b to go back.');
}

function card_label(array $c): string {
    $tier = color([1 => C_T1, 2 => C_T2, 3 => C_T3][$c['tier']] ?? C_HEAD, "T{$c['tier']}");
    $bits = [$tier, $c['effects'] !== '' ? str_replace("\n", ' / ', $c['effects']) : '(no effect)'];
    if ($c['type'] === 'part')  $bits[] = ($c['hardpoints_offered'] ?? 0) . ' hardp';
    else                        $bits[] = 'needs ' . ($c['hardpoints_required'] ?? 0) . ' hardp';
    if (!empty($c['range']))      $bits[] = 'range ' . $c['range'];
    if (!empty($c['energy_max'])) $bits[] = "energy {$c['energy_min']}/{$c['energy_max']}";
    if (!empty($c['aoe']))        $bits[] = 'AoE ' . $c['aoe'];
    return color(C_NAME, str_pad($c['name'], 20)) . ' ' . implode(color(C_DIM, ' · '), $bits);
}

function cmd_new(string $name): void {
    $path = profile_path($name);
    if (file_exists($path) && strtolower(read_line("Profile '$name' exists. Overwrite? [y/N] ")) !== 'y') return;

    out(color(C_DIM, 'Pick by number; Enter takes option 0; b goes back one answer.') . "\n");
    while (true) {
        Wiz::$pos = 0;
        try { $profile = build_profile(); break; }
        catch (GoBack) {}
    }
    if ($errors = validate($profile)) fail("loadout is not legal:\n  " . implode("\n  ", $errors));
    if (!is_dir(PROFILES_DIR)) mkdir(PROFILES_DIR, 0755, true);
    file_put_contents($path, json_encode($profile, JSON_PRETTY_PRINT) . "\n");
    out("\n" . color(C_OK, "Saved $path") . "\n");
    foreach ($profile['mechs'] as $i => $m) out(color(C_TITLE, 'Mech ' . ($i + 1)) . "\n" . describe_mech($m) . "\n");
}

function build_profile(): array {
    $empty   = ['slots' => array_fill_keys(SLOTS, null), 'equipment' => array_fill_keys(SLOTS, [])];
    $profile = ['mechs' => [$empty, $empty]];

    foreach ([0, 1] as $mi) {
        if ($mi === 1 && ask_yes_no('Make mech 2 a copy of mech 1?')) {
            $profile['mechs'][1] = $profile['mechs'][0];
            break;
        }
        say("\n" . color(C_TITLE, '=== Mech ' . ($mi + 1) . ' ===') . "\n");
        foreach (SLOTS as $slot) {
            $mech = &$profile['mechs'][$mi];

            // Part
            $parts = [];
            foreach (modules() as $id => $c) {
                if ($c['type'] === 'part' && slot_accepts($slot, $c)) $parts[$id] = card_label($c);
            }
            say("\n  " . color(C_HEAD, SLOT_LABELS[$slot] . ' part:') . "\n");
            $default = $slot === 'head' ? 'none' : 'default (1 hardp)';
            $mech['slots'][$slot] = $parts ? menu($parts, $default) : null;

            // Equipment, while hardpoints remain
            while (true) {
                $free = slot_hardpoints($mech, $slot) - slot_hardpoints_used($mech, $slot);
                $eqs  = [];
                foreach (modules() as $id => $c) {
                    if ($c['type'] === 'equipment' && slot_accepts($slot, $c)
                        && (int)($c['hardpoints_required'] ?? 0) <= $free) $eqs[$id] = card_label($c);
                }
                if (!$eqs) break;
                $have = array_map(fn($id) => modules()[$id]['name'], $mech['equipment'][$slot]);
                say("\n  " . color(C_HEAD, SLOT_LABELS[$slot] . ' equipment')
                    . " ($free hardp free" . ($have ? '; has ' . implode(', ', $have) : '') . "):\n");
                $pick = menu($eqs, 'done');
                if ($pick === null) break;
                $mech['equipment'][$slot][] = $pick;
            }
            unset($mech);
        }
    }
    return $profile;
}

// ── Fighting ─────────────────────────────────────────────────────────────────

/** Fight-start state for two profiles: A is team 1, B is team 2. */
function fight_state(array $a, array $b): array {
    $state = ['phase' => 'config', 'victories' => ['1' => 0, '2' => 0], 'pilots' => TEAM_PILOTS];
    foreach (['1' => $a, '2' => $b] as $tk => $profile) {
        $seen = [];
        $key  = function (string $id) use (&$seen) { $n = $seen[$id] ?? 0; $seen[$id] = $n + 1; return "$id:$n"; };
        foreach ($profile['mechs'] as $mi => $m) {
            $mech = ['slots' => [], 'equipment' => []];
            foreach (SLOTS as $slot) {
                $mech['slots'][$slot]     = $m['slots'][$slot] !== null ? $key($m['slots'][$slot]) : null;
                $mech['equipment'][$slot] = array_map($key, $m['equipment'][$slot] ?? []);
            }
            $state['config']['mechs'][$tk][$mi] = $mech;
        }
        $state['config']['modules'][$tk] = [];
    }
    return Fight::start($state);
}

/** The fixed decision for a pending choice. */
function decide(array $p): array {
    switch ($p['kind']) {
        case 'charge':
            return array_slice($p['options'], 0, $p['count']);
        case 'move':
            return in_array('1', $p['options'], true) ? ['1'] : ['0'];
        case 'defend':
            $ids = [];
            $def = $p['free_def'];
            foreach ($p['options'] as $id) {
                if ($def >= $p['hit']['dmg']) break;
                $ids[] = $id;
                $def  += $p['details'][$id]['def'];
            }
            return $ids;
        default: // lock, spend_lock
            return [$p['options'][0]];
    }
}

function run_fight(array $a, array $b, int $seed): array {
    mt_srand($seed);
    $state = fight_state($a, $b);
    $flips = 0;
    for ($guard = 0; $guard < 10000 && empty($state['fight']['result']); $guard++) {
        if (!empty($state['fight']['pending'])) {
            $p     = $state['fight']['pending'];
            $error = null;
            $state = Fight::choose($state, $p['team'], decide($p), $error);
            if ($error) fail("choice rejected ({$p['kind']}): $error");
        } else {
            $before = count($state['fight']['deck']);
            $state  = Fight::flip($state);
            $flips += $before - count($state['fight']['deck']);
        }
    }
    if (empty($state['fight']['result'])) fail("fight with seed $seed did not finish");
    return ['state' => $state, 'flips' => $flips];
}

/** Tally weapon and shield use from the fight log. */
function tally_log(array $log, array $team_of, array &$usage): void {
    foreach ($log as $line) {
        if (preg_match('/^(\w+) attacks \w+ with (.+?), dealing (\d+) damage(?: \((\d+) blocked by (.+)\))?[.!]$/u', $line, $m)) {
            $u = &$usage[$team_of[$m[1]]]['weapons'][$m[2]];
            $u['hits']   = ($u['hits'] ?? 0) + 1;
            $u['damage'] = ($u['damage'] ?? 0) + (int)$m[3];
            unset($u);
            if (!empty($m[4])) {
                $defender = $team_of[$m[1]] === 'A' ? 'B' : 'A';
                foreach (explode(', ', $m[5]) as $shield) {
                    $usage[$defender]['shields'][$shield] = ($usage[$defender]['shields'][$shield] ?? 0) + 1;
                }
            }
        } elseif (preg_match('/^(\w+) would fire (.+), but no target is in range\.$/u', $line, $m)) {
            $usage[$team_of[$m[1]]]['weapons'][$m[2]]['out_of_range'] = ($usage[$team_of[$m[1]]]['weapons'][$m[2]]['out_of_range'] ?? 0) + 1;
        } elseif (preg_match('/^(\w+) tries to fire (.+), but it lacks energy/u', $line, $m)) {
            $usage[$team_of[$m[1]]]['weapons'][$m[2]]['no_energy'] = ($usage[$team_of[$m[1]]]['weapons'][$m[2]]['no_energy'] ?? 0) + 1;
        } elseif (preg_match('/^(\w+) would fire (.+), but (?:has no target lock|no locked target is in range)\.$/u', $line, $m)) {
            $usage[$team_of[$m[1]]]['weapons'][$m[2]]['no_lock'] = ($usage[$team_of[$m[1]]]['weapons'][$m[2]]['no_lock'] ?? 0) + 1;
        }
    }
}

function cmd_run(string $name_a, string $name_b, array $opts): void {
    $t0     = microtime(true);
    $a      = load_profile($name_a);
    $b      = load_profile($name_b);
    $fights = max(1, (int)($opts['fights'] ?? 100));
    $seed   = isset($opts['seed']) ? (int)$opts['seed'] : random_int(1, 1_000_000_000);
    $log_k  = isset($opts['log']) ? (int)$opts['log'] : null;

    $team_of = ['Lapis' => 'A', 'Damien' => 'A', 'Kaori' => 'B', 'Sakharov' => 'B'];
    $label   = ['A' => "$name_a (team 1)", 'B' => "$name_b (team 2)"];
    $plain   = $label;   // uncolored, for padding
    $wins    = ['A' => 0, 'B' => 0, 'draw' => 0];
    $by      = ['A' => [], 'B' => []];
    $ends    = ['wipe' => 0, 'deck' => 0];
    $sum     = ['flips' => 0, 'A' => ['hp' => 0, 'dmg' => 0, 'lost' => 0], 'B' => ['hp' => 0, 'dmg' => 0, 'lost' => 0]];
    $usage   = ['A' => ['weapons' => [], 'shields' => []], 'B' => ['weapons' => [], 'shields' => []]];

    for ($i = 1; $i <= $fights; $i++) {
        ['state' => $st, 'flips' => $flips] = run_fight($a, $b, $seed + $i);
        $r = $st['fight']['result'];
        $w = $r['winner'] === null ? 'draw' : ($r['winner'] === '1' ? 'A' : 'B');
        $wins[$w]++;
        if ($w !== 'draw') $by[$w][$r['decided_by']] = ($by[$w][$r['decided_by']] ?? 0) + 1;
        $ends[$r['reason']]++;
        $sum['flips'] += $flips;
        foreach (['A' => '1', 'B' => '2'] as $t => $tk) {
            foreach ($st['fight']['mechs'][$tk] as $m) {
                $sum[$t]['hp'] += $m['hp'];
                if ($m['hp'] <= 0) $sum[$t]['lost']++;
            }
            $sum[$t]['dmg'] += $r['stats'][$tk]['damage'];
        }
        tally_log($st['fight']['log'], $team_of, $usage);

        if ($log_k === $i) {
            out(color(C_HEAD, '── Fight ' . $i . ' log (seed ' . ($seed + $i) . ') ──') . "\n");
            foreach ($st['fight']['log'] as $line) out("  $line\n");
            echo "\n";
        }
    }

    $pct  = fn($n) => sprintf('%5.1f%%', 100 * $n / $fights);
    $avg  = fn($n) => sprintf('%.1f', $n / $fights);
    $how  = ['eliminated' => 'eliminations', 'damage' => 'damage', 'progress' => 'territory'];
    $pad  = fn($t, $w) => color(C_HEAD, $label[$t]) . str_repeat(' ', max(0, $w - strlen($plain[$t])));
    $win_color = [
        'A' => $wins['A'] === $wins['B'] ? C_EVEN : ($wins['A'] > $wins['B'] ? C_LEAD : C_TRAIL),
        'B' => $wins['A'] === $wins['B'] ? C_EVEN : ($wins['B'] > $wins['A'] ? C_LEAD : C_TRAIL),
    ];

    out("$fights fights · seed $seed " . color(C_DIM, "(fight i uses seed+i; replay one with --seed=$seed --log=i)") . "\n\n");
    out(color(C_HEAD, 'Results') . "\n");
    foreach (['A', 'B'] as $t) {
        $parts = [];
        foreach ($how as $k => $l) if (!empty($by[$t][$k])) $parts[] = "{$by[$t][$k]} by $l";
        out(sprintf("  %s %5d wins  %s   %s\n", $pad($t, 28), $wins[$t], color($win_color[$t], $pct($wins[$t])),
            $parts ? '(' . implode(', ', $parts) . ')' : ''));
    }
    out(sprintf("  %-28s %5d       %s\n\n", 'draws', $wins['draw'], color(C_EVEN, $pct($wins['draw']))));

    out(color(C_HEAD, 'Fight stats') . color(C_DIM, ' (averages per fight)') . "\n");
    out(sprintf("  ended by wipe: %d · deck ran out: %d · cards flipped: %s\n", $ends['wipe'], $ends['deck'], $avg($sum['flips'])));
    foreach (['A', 'B'] as $t) {
        out(sprintf("  %s HP left %5s · damage dealt %5s · mechs lost %s\n",
            $pad($t, 28), $avg($sum[$t]['hp']), $avg($sum[$t]['dmg']), $avg($sum[$t]['lost'])));
    }

    out("\n" . color(C_HEAD, 'Module usage') . color(C_DIM, ' (totals over all fights)') . "\n");
    foreach (['A', 'B'] as $t) {
        out('  ' . color(C_HEAD, $label[$t]) . "\n");
        if (!$usage[$t]['weapons']) out(color(C_DIM, '    no attacks') . "\n");
        ksort($usage[$t]['weapons']);
        foreach ($usage[$t]['weapons'] as $weapon => $u) {
            out(sprintf("    %-20s hits %5d · damage %5d · no target in range %4d · no energy %4d · no lock %4d\n",
                $weapon, $u['hits'] ?? 0, $u['damage'] ?? 0, $u['out_of_range'] ?? 0, $u['no_energy'] ?? 0, $u['no_lock'] ?? 0));
        }
        ksort($usage[$t]['shields']);
        foreach ($usage[$t]['shields'] as $shield => $n) out(sprintf("    %-20s blocked damage in %d hits\n", $shield, $n));
    }

    out(sprintf("\nTook %.1fs\n", microtime(true) - $t0));
}

// ── CLI ──────────────────────────────────────────────────────────────────────

$args = array_slice($argv, 1);
$opts = [];
$pos  = [];
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (preg_match('/^--(\w+)=(.*)$/', $arg, $m))  $opts[$m[1]] = $m[2];
    elseif (preg_match('/^-f=?(\d+)$/', $arg, $m)) $opts['fights'] = $m[1];   // -f N, -f=N, -fN
    elseif ($arg === '--no-color')                  $opts['no-color'] = true;
    elseif ($arg === '-f')                          $opts['fights'] = $args[++$i] ?? '';
    else $pos[] = $arg;
}

define('USE_COLOR', empty($opts['no-color']) && getenv('NO_COLOR') === false && stream_isatty(STDOUT));

match ($pos[0] ?? '') {
    'new'      => isset($pos[1]) ? cmd_new($pos[1]) : fail('usage: simulate.php new <name>'),
    'profiles' => cmd_profiles(),
    'show'     => isset($pos[1]) ? cmd_show($pos[1]) : fail('usage: simulate.php show <name>'),
    'run'      => isset($pos[2]) ? cmd_run($pos[1], $pos[2], $opts) : fail('usage: simulate.php run <A> <B> [--fights=N | -f N] [--seed=N] [--log=K]'),
    default    => print(preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1])[0])),
};
