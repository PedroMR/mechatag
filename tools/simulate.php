#!/usr/bin/env php
<?php
/**
 * simulate.php — run headless MechaTag fights between two saved team profiles.
 *
 * Usage:
 *   php tools/simulate.php new <name>          Create a team profile (interactive wizard)
 *   php tools/simulate.php profiles            List saved profiles
 *   php tools/simulate.php show <name>         Print a profile's mechs
 *   php tools/simulate.php run <A> <B> [--fights=100 | -f 100] [--seed=N] [--log=K]
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
        $lines[] = sprintf("    %-6s %s%s", SLOT_LABELS[$slot], $name, $eq ? '  + ' . implode(', ', $eq) : '');
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
        echo basename($f, '.json'), "\n    mech 1: ", $mods[0] ?? '?', "\n    mech 2: ", $mods[1] ?? '?', "\n";
    }
}

function cmd_show(string $name): void {
    $p = load_profile($name);
    foreach ($p['mechs'] as $i => $m) echo "Mech ", $i + 1, "\n", describe_mech($m), "\n";
}

// ── Wizard ───────────────────────────────────────────────────────────────────

function ask(string $prompt): string {
    echo $prompt;
    $line = fgets(STDIN);
    if ($line === false) fail('input ended');
    return trim($line);
}

/** Numbered menu; returns the chosen key, or null for the blank/default answer. */
function menu(array $options, string $blank_label): ?string {
    $keys = array_keys($options);
    echo "      0) $blank_label\n";
    foreach ($keys as $i => $k) echo sprintf("     %2d) %s\n", $i + 1, $options[$k]);
    while (true) {
        $a = ask('    > ');
        if ($a === '' || $a === '0') return null;
        if (ctype_digit($a) && isset($keys[(int)$a - 1])) return $keys[(int)$a - 1];
        echo "    Enter a number from the list.\n";
    }
}

function card_label(array $c): string {
    $bits = ["T{$c['tier']}", $c['effects'] !== '' ? str_replace("\n", ' / ', $c['effects']) : '(no effect)'];
    if ($c['type'] === 'part')  $bits[] = ($c['hardpoints_offered'] ?? 0) . ' hardp';
    else                        $bits[] = 'needs ' . ($c['hardpoints_required'] ?? 0) . ' hardp';
    if (!empty($c['range']))      $bits[] = 'range ' . $c['range'];
    if (!empty($c['energy_max'])) $bits[] = "energy {$c['energy_min']}/{$c['energy_max']}";
    if (!empty($c['aoe']))        $bits[] = 'AoE ' . $c['aoe'];
    return str_pad($c['name'], 20) . ' ' . implode(' · ', $bits);
}

function cmd_new(string $name): void {
    $path = profile_path($name);
    if (file_exists($path) && strtolower(ask("Profile '$name' exists. Overwrite? [y/N] ")) !== 'y') return;

    $empty   = ['slots' => array_fill_keys(SLOTS, null), 'equipment' => array_fill_keys(SLOTS, [])];
    $profile = ['mechs' => [$empty, $empty]];

    foreach ([0, 1] as $mi) {
        if ($mi === 1 && strtolower(ask("\nMake mech 2 a copy of mech 1? [y/N] ")) === 'y') {
            $profile['mechs'][1] = $profile['mechs'][0];
            break;
        }
        echo "\n=== Mech ", $mi + 1, " ===\n";
        foreach (SLOTS as $slot) {
            $mech = &$profile['mechs'][$mi];

            // Part
            $parts = [];
            foreach (modules() as $id => $c) {
                if ($c['type'] === 'part' && slot_accepts($slot, $c)) $parts[$id] = card_label($c);
            }
            echo "\n  ", SLOT_LABELS[$slot], " part:\n";
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
                echo "\n  ", SLOT_LABELS[$slot], " equipment ($free hardp free",
                     $have ? '; has ' . implode(', ', $have) : '', "):\n";
                $pick = menu($eqs, 'done');
                if ($pick === null) break;
                $mech['equipment'][$slot][] = $pick;
            }
            unset($mech);
        }
    }

    if ($errors = validate($profile)) fail("loadout is not legal:\n  " . implode("\n  ", $errors));
    if (!is_dir(PROFILES_DIR)) mkdir(PROFILES_DIR, 0755, true);
    file_put_contents($path, json_encode($profile, JSON_PRETTY_PRINT) . "\n");
    echo "\nSaved $path\n";
    foreach ($profile['mechs'] as $i => $m) echo "Mech ", $i + 1, "\n", describe_mech($m), "\n";
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
            echo "── Fight $i log (seed ", $seed + $i, ") ──\n";
            foreach ($st['fight']['log'] as $line) echo "  $line\n";
            echo "\n";
        }
    }

    $pct  = fn($n) => sprintf('%5.1f%%', 100 * $n / $fights);
    $avg  = fn($n) => sprintf('%.1f', $n / $fights);
    $how  = ['eliminated' => 'eliminations', 'damage' => 'damage', 'progress' => 'territory'];

    echo "$fights fights · seed $seed (fight i uses seed+i; replay one with --seed=$seed --log=i)\n\n";
    echo "Results\n";
    foreach (['A', 'B'] as $t) {
        $parts = [];
        foreach ($how as $k => $l) if (!empty($by[$t][$k])) $parts[] = "{$by[$t][$k]} by $l";
        printf("  %-28s %5d wins  %s   %s\n", $label[$t], $wins[$t], $pct($wins[$t]), $parts ? '(' . implode(', ', $parts) . ')' : '');
    }
    printf("  %-28s %5d       %s\n\n", 'draws', $wins['draw'], $pct($wins['draw']));

    echo "Fight stats (averages per fight)\n";
    printf("  ended by wipe: %d · deck ran out: %d · cards flipped: %s\n", $ends['wipe'], $ends['deck'], $avg($sum['flips']));
    foreach (['A', 'B'] as $t) {
        printf("  %-28s HP left %5s · damage dealt %5s · mechs lost %s\n",
            $label[$t], $avg($sum[$t]['hp']), $avg($sum[$t]['dmg']), $avg($sum[$t]['lost']));
    }

    echo "\nModule usage (totals over all fights)\n";
    foreach (['A', 'B'] as $t) {
        echo "  {$label[$t]}\n";
        if (!$usage[$t]['weapons']) echo "    no attacks\n";
        ksort($usage[$t]['weapons']);
        foreach ($usage[$t]['weapons'] as $weapon => $u) {
            printf("    %-20s hits %5d · damage %5d · no target in range %4d · no energy %4d · no lock %4d\n",
                $weapon, $u['hits'] ?? 0, $u['damage'] ?? 0, $u['out_of_range'] ?? 0, $u['no_energy'] ?? 0, $u['no_lock'] ?? 0);
        }
        ksort($usage[$t]['shields']);
        foreach ($usage[$t]['shields'] as $shield => $n) printf("    %-20s blocked damage in %d hits\n", $shield, $n);
    }

    printf("\nTook %.1fs\n", microtime(true) - $t0);
}

// ── CLI ──────────────────────────────────────────────────────────────────────

$args = array_slice($argv, 1);
$opts = [];
$pos  = [];
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (preg_match('/^--(\w+)=(.*)$/', $arg, $m))  $opts[$m[1]] = $m[2];
    elseif (preg_match('/^-f=?(\d+)$/', $arg, $m)) $opts['fights'] = $m[1];   // -f N, -f=N, -fN
    elseif ($arg === '-f')                          $opts['fights'] = $args[++$i] ?? '';
    else $pos[] = $arg;
}

match ($pos[0] ?? '') {
    'new'      => isset($pos[1]) ? cmd_new($pos[1]) : fail('usage: simulate.php new <name>'),
    'profiles' => cmd_profiles(),
    'show'     => isset($pos[1]) ? cmd_show($pos[1]) : fail('usage: simulate.php show <name>'),
    'run'      => isset($pos[2]) ? cmd_run($pos[1], $pos[2], $opts) : fail('usage: simulate.php run <A> <B> [--fights=N | -f N] [--seed=N] [--log=K]'),
    default    => print(preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1])[0])),
};
