<?php

require_once __DIR__ . '/FileStore.php';

class Fight {

    const PILOT_NAMES = ['blue' => 'Lapis', 'green' => 'Damien', 'purple' => 'Kaori', 'black' => 'Sakharov'];
    const MECH_BASE_HP  = 6;
    const BOARD_TILES   = 6;
    // Starting tile index (0-based): team 1 at tile 1, team 2 at tile 4
    const START_POS = ['1' => 1, '2' => 4];
    // CORE stat of the Ulysses chassis: modules charged per CORE instruction
    const CORE_CHARGES  = 2;

    // Effect string token → slot key
    const SLOT_MAP = [
        'R ARM' => 'r_arm',
        'L ARM' => 'l_arm',
        'TORSO' => 'torso',
        'LEGS'  => 'legs',
        'HEAD'  => 'head',
    ];

    // ── Deck building ──────────────────────────────────────────────────────

    public static function buildDeck(): array {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../data/cards/pilots.json'), true
        );
        $pilots = ['blue', 'green', 'purple', 'black'];
        $deck = [];
        foreach ($pilots as $pilot) {
            foreach ($data['action_cards'] as $card) {
                for ($i = 0; $i < (int)$card['copies']; $i++) {
                    $deck[] = [
                        'action' => $card['id'],
                        'pilot'  => $pilot,
                        'name'   => $card['name'],
                        'effect' => $card['effect'],
                    ];
                }
            }
        }
        shuffle($deck);
        return $deck;
    }

    // ── Phase transition ──────────────────────────────────────────────────

    public static function start(array $state): array {
        // Build per-mech fight state from config
        $modules_by_id = self::loadModules();
        $fight_mechs = [];
        foreach (['1', '2'] as $tk) {
            foreach ($state['config']['mechs'][$tk] as $idx => $mech) {
                $pilot  = $state['pilots'][$tk][$idx];
                $max_hp = self::mechMaxHp($mech, $modules_by_id);
                $fight_mechs[$tk][$idx] = [
                    'pilot'      => $pilot,
                    'pilot_name' => self::PILOT_NAMES[$pilot] ?? $pilot,
                    'hp'         => $max_hp,
                    'max_hp'     => $max_hp,
                    'defense'    => 0,
                    'pos'        => self::START_POS[$tk],
                    'slots'      => $mech['slots'],
                    'equipment'  => $mech['equipment'],
                ];
                $fight_mechs[$tk][$idx]['energy'] = self::initialEnergy($fight_mechs[$tk][$idx], $modules_by_id);
            }
        }

        $state['phase'] = 'fight';
        $state['fight'] = [
            'deck'    => self::buildDeck(),
            'discard' => [],
            'current' => null,
            'mechs'   => $fight_mechs,
            'log'     => [],
        ];
        // Keep the team's modules and mech layouts for the next round
        $state['owned']      = $state['config']['modules'];
        $state['last_mechs'] = $state['config']['mechs'];
        unset($state['config']);
        return $state;
    }

    // ── Flip ──────────────────────────────────────────────────────────────

    public static function flip(array $state): array {
        if ($state['phase'] !== 'fight') return $state;
        if (empty($state['fight']['deck'])) return $state;
        if (!empty($state['fight']['pending'])) return $state; // waiting on a player choice
        if (!empty($state['fight']['result'])) return $state;  // fight is over

        // Move previous current to discard
        if ($state['fight']['current'] !== null) {
            $state['fight']['discard'][] = $state['fight']['current'];
        }

        // Draw top card; cards of destroyed mechs are ignored (discarded, draw again)
        $card = array_shift($state['fight']['deck']);
        while ($card !== null && self::isDestroyed($state, $card['pilot'])) {
            $pilot_name = self::PILOT_NAMES[$card['pilot']] ?? $card['pilot'];
            $state['fight']['log'][] = "{$card['name']} ($pilot_name) is ignored: mech destroyed.";
            $state['fight']['discard'][] = $card;
            $card = array_shift($state['fight']['deck']);
        }
        $state['fight']['current'] = $card;
        if ($card === null) return self::endFight($state, 'deck');

        // Resolve
        $state = self::ensureEnergy($state);
        $state = self::resolveCard($state, $card);

        return self::checkDeckEnd($state);
    }

    // ── Fight end ─────────────────────────────────────────────────────────

    /** End the fight once the last card has fully resolved. */
    private static function checkDeckEnd(array $state): array {
        if (empty($state['fight']['result']) && empty($state['fight']['pending'])
            && empty($state['fight']['deck'])) {
            return self::endFight($state, 'deck');
        }
        return $state;
    }

    private static function teamWiped(array $state, string $team): bool {
        foreach ($state['fight']['mechs'][$team] as $mech) {
            if ($mech['hp'] > 0) return false;
        }
        return true;
    }

    /**
     * Decide the fight, award the point and record the result.
     * Victory order: more enemy mechs eliminated, then more damage dealt,
     * then furthest into enemy territory; otherwise a draw.
     */
    private static function endFight(array $state, string $reason): array {
        $stats = [];
        foreach (['1', '2'] as $tk) {
            $opp = $tk === '1' ? '2' : '1';
            $eliminated = 0;
            $damage     = 0;
            foreach ($state['fight']['mechs'][$opp] as $m) {
                if ($m['hp'] <= 0) $eliminated++;
                $damage += $m['max_hp'] - $m['hp']; // no healing, so HP lost = damage dealt
            }
            // Tiles advanced by the team's most forward live mech (team 1 advances upward)
            $progress = null;
            foreach ($state['fight']['mechs'][$tk] as $m) {
                if ($m['hp'] <= 0) continue;
                $adv = $tk === '1' ? $m['pos'] : self::BOARD_TILES - 1 - $m['pos'];
                $progress = max($progress ?? $adv, $adv);
            }
            $stats[$tk] = ['eliminated' => $eliminated, 'damage' => $damage, 'progress' => $progress ?? -1];
        }

        $winner = null;
        $decided_by = 'draw';
        foreach (['eliminated', 'damage', 'progress'] as $key) {
            if ($stats['1'][$key] !== $stats['2'][$key]) {
                $winner     = $stats['1'][$key] > $stats['2'][$key] ? '1' : '2';
                $decided_by = $key;
                break;
            }
        }

        $state['fight']['result'] = [
            'reason'     => $reason,       // 'wipe' | 'deck'
            'winner'     => $winner,       // '1' | '2' | null (draw)
            'decided_by' => $decided_by,   // 'eliminated' | 'damage' | 'progress' | 'draw'
            'stats'      => $stats,
        ];
        unset($state['fight']['pending'], $state['fight']['turn']);

        $state['fight']['log'][] = $reason === 'wipe'
            ? '🏁 A team has been wiped out. The fight is over!'
            : '🏁 The Fight Deck has run out. The fight is over!';

        if ($winner === null) {
            $state['fight']['log'][] = 'The fight is a draw: no point awarded.';
            return $state;
        }

        $state['victories'][$winner] = ($state['victories'][$winner] ?? 0) + 1;
        $state['fight']['log'][] = "Team $winner wins the fight! Score: "
            . $state['victories']['1'] . ' – ' . $state['victories']['2'] . '.';
        if ($state['victories'][$winner] >= 2) {
            $state['game_winner'] = $winner;
            $state['fight']['log'][] = "🏆 Team $winner wins the game!";
        }
        return $state;
    }

    // ── Choices ───────────────────────────────────────────────────────────

    /**
     * Resolve the pending charge choice for $team with the chosen module ids
     * (empty = skip). Sets $error and leaves state unchanged if invalid.
     */
    public static function choose(array $state, string $team, array $ids, ?string &$error = null): array {
        $pending = $state['fight']['pending'] ?? null;
        if (!$pending)                    { $error = 'Nothing to choose'; return $state; }
        if ($pending['team'] !== $team)   { $error = 'Not your choice';   return $state; }

        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (count($ids) > $pending['count'])                  { $error = 'Too many modules'; return $state; }
        if (array_diff($ids, $pending['options']))            { $error = 'Invalid module';   return $state; }

        $modules_by_id = self::loadModules();
        $mech  = &$state['fight']['mechs'][$team][$pending['mech']];
        $names = [];
        foreach ($ids as $id) {
            $max = (int)($modules_by_id[self::baseId($id)]['energy_max'] ?? 0);
            $mech['energy'][$id] = min($max, ($mech['energy'][$id] ?? 0) + 1);
            $names[] = $modules_by_id[self::baseId($id)]['name'] ?? $id;
        }
        $pilot_name = $mech['pilot_name'];
        unset($mech);

        $state['fight']['log'][] = $names
            ? "$pilot_name charges " . implode(', ', $names) . (count($names) > 1 ? " (+1 energy each)." : " (+1 energy).")
            : "$pilot_name skips charging.";
        unset($state['fight']['pending']);

        return self::checkDeckEnd(self::runSteps($state));
    }

    // ── Resolution ────────────────────────────────────────────────────────

    /**
     * Expand the card into steps (each activated slot becomes one step per
     * module: part first, then its equipment) and run them. Steps live in
     * $state['fight']['turn'] so resolution can pause for a player choice.
     */
    private static function resolveCard(array $state, array $card): array {
        [$team_key, $mech_idx] = self::findMechByPilot($state, $card['pilot']);
        if ($team_key === null) return $state;

        $actions = self::parseEffect($card['effect']);
        $mech    = $state['fight']['mechs'][$team_key][$mech_idx];

        // Collect attack-type bonuses declared on this card
        $bonuses = [];
        $steps   = [];
        foreach ($actions as $a) {
            if ($a['type'] === 'bonus') {
                $bonuses[$a['atk_type']] = ($bonuses[$a['atk_type']] ?? 0) + $a['amount'];
            } elseif ($a['type'] === 'activate') {
                $slot   = $a['slot'];
                $ids    = array_merge(
                    $mech['slots'][$slot] ? [$mech['slots'][$slot]] : [],
                    $mech['equipment'][$slot] ?? []
                );
                if (!$ids) $steps[] = ['type' => 'activate_empty', 'slot' => $slot];
                foreach ($ids as $id) {
                    $steps[] = ['type' => 'module', 'id' => $id, 'slot' => $slot, 'is_part' => $id === $mech['slots'][$slot]];
                }
            } else {
                $steps[] = $a;
            }
        }

        $state['fight']['turn'] = [
            'team'    => $team_key,
            'mech'    => $mech_idx,
            'bonuses' => $bonuses,
            'steps'   => $steps,
        ];
        return self::runSteps($state);
    }

    /** Run queued steps until done or a step opens a player choice. */
    private static function runSteps(array $state): array {
        $modules_by_id = self::loadModules();
        while (!empty($state['fight']['turn']['steps']) && empty($state['fight']['pending'])) {
            $step  = array_shift($state['fight']['turn']['steps']);
            $state = self::runStep($state, $step, $modules_by_id);
            foreach (['1', '2'] as $tk) {
                if (self::teamWiped($state, $tk)) return self::endFight($state, 'wipe');
            }
        }
        if (empty($state['fight']['pending'])) unset($state['fight']['turn']);
        return $state;
    }

    private static function runStep(array $state, array $a, array $modules_by_id): array {
        $team_key   = $state['fight']['turn']['team'];
        $mech_idx   = $state['fight']['turn']['mech'];
        $bonuses    = $state['fight']['turn']['bonuses'];
        $pilot_name = $state['fight']['mechs'][$team_key][$mech_idx]['pilot_name'];

        switch ($a['type']) {

            case 'move':
                // Team 1 FORW = +tile, Team 2 FORW = -tile; BACK is the reverse.
                $dir  = (($team_key === '1') === ($a['dir'] === 'forward')) ? 1 : -1;
                $opp  = $team_key === '1' ? '2' : '1';
                $moved = 0;
                for ($s = 0; $s < $a['amount']; $s++) {
                    $cur     = $state['fight']['mechs'][$team_key][$mech_idx]['pos'];
                    $new_pos = $cur + $dir;
                    if ($new_pos < 0 || $new_pos >= self::BOARD_TILES) break;
                    // Count live enemy mechs on the target tile
                    $enemies_at = [];
                    foreach ($state['fight']['mechs'][$opp] as $ei => $em) {
                        if ($em['hp'] > 0 && $em['pos'] === $new_pos) $enemies_at[] = $ei;
                    }
                    if (count($enemies_at) >= 2) break; // two enemies block advance
                    if (count($enemies_at) === 1) {
                        $push = $new_pos + $dir;
                        if ($push < 0 || $push >= self::BOARD_TILES) break; // wall behind enemy
                        $state['fight']['mechs'][$opp][$enemies_at[0]]['pos'] = $push;
                    }
                    $state['fight']['mechs'][$team_key][$mech_idx]['pos'] = $new_pos;
                    $moved++;
                }
                $word  = $a['dir'] === 'forward' ? 'forward' : 'back';
                $tile  = $state['fight']['mechs'][$team_key][$mech_idx]['pos'] + 1; // 1-based for display
                $state['fight']['log'][] = $moved > 0
                    ? "$pilot_name moves $word $moved (tile $tile)."
                    : "$pilot_name tries to move $word but is blocked.";
                break;

            case 'activate_empty':
                $slot_lbl = strtoupper(str_replace('_', ' ', $a['slot']));
                $state['fight']['log'][] = "$pilot_name activates $slot_lbl (no equipment).";
                break;

            case 'module':
                $id     = $a['id'];
                $module = $modules_by_id[self::baseId($id)] ?? null;
                if (!$module) break;
                $name   = $module['name'];
                $effect = $module['effects'] ?? '';
                $atk    = self::parseEquipEffect($effect);

                if (preg_match('/\+(\d+)\s*ENRG/i', $effect, $m)) {
                    // Generator: new cubes in any empty energy slots on this mech
                    $state = self::openCharge($state, (int)$m[1], $name);

                } elseif ($atk) {
                    $mech      = $state['fight']['mechs'][$team_key][$mech_idx];
                    $total_dmg = $atk['base_atk']
                        + ($bonuses[$atk['atk_type']] ?? 0)
                        + self::partBonus($mech, $a['slot'] ?? null, $atk['atk_type'], $modules_by_id);

                    $opp_team = $team_key === '1' ? '2' : '1';
                    $atk_pos  = $state['fight']['mechs'][$team_key][$mech_idx]['pos'];
                    $targets  = self::findTargets(
                        $state, $opp_team, $atk_pos,
                        (string)($module['range'] ?? ''), $module['aoe'] ?? null
                    );

                    if (empty($targets)) {
                        $state['fight']['log'][] = "$pilot_name would fire $name, but no target is in range.";
                        break;
                    }

                    // Pay the energy cost (filled slots); cancel the attack if we can't
                    $cost = (int)($module['energy_min'] ?? 0);
                    if ($cost > 0) {
                        $have = $state['fight']['mechs'][$team_key][$mech_idx]['energy'][$id] ?? 0;
                        if ($have < $cost) {
                            $state['fight']['log'][] = "$pilot_name tries to fire $name, but it lacks energy ($have/$cost).";
                            break;
                        }
                        $state['fight']['mechs'][$team_key][$mech_idx]['energy'][$id] = $have - $cost;
                    }

                    // Each AoE target is a separate attack, defended individually
                    foreach ($targets as $tgt_idx) {
                        $tgt       = &$state['fight']['mechs'][$opp_team][$tgt_idx];
                        $tgt_name  = $tgt['pilot_name'];

                        // Defense absorbs damage, then resets
                        $absorbed  = min($tgt['defense'], $total_dmg);
                        $actual    = $total_dmg - $absorbed;
                        $tgt['defense'] = max(0, $tgt['defense'] - $absorbed);
                        $tgt['hp']      = max(0, $tgt['hp'] - $actual);

                        if ($absorbed > 0) {
                            $state['fight']['log'][] = "$pilot_name attacks $tgt_name with $name, dealing $actual damage! ($absorbed blocked by defense)";
                        } else {
                            $state['fight']['log'][] = "$pilot_name attacks $tgt_name with $name, dealing $actual damage!";
                        }

                        if ($tgt['hp'] <= 0) {
                            $state['fight']['log'][] = "⚡ $tgt_name's mech is destroyed!";
                        }
                        unset($tgt);
                    }

                } elseif (!$a['is_part']) {
                    $state['fight']['log'][] = "$pilot_name uses $name.";
                }
                break;

            case 'defense':
                $state['fight']['mechs'][$team_key][$mech_idx]['defense'] += $a['amount'];
                $state['fight']['log'][] = "$pilot_name raises defenses (+{$a['amount']} DEF).";
                break;

            case 'core':
                $state = self::openCharge($state, self::CORE_CHARGES, 'CORE');
                break;
        }

        return $state;
    }

    /**
     * Ask the active team to pick up to $count different modules on the
     * active mech to receive 1 energy cube each. Full modules can't be picked;
     * if none can take a cube, the charge fizzles without asking.
     */
    private static function openCharge(array $state, int $count, string $source): array {
        $team_key = $state['fight']['turn']['team'];
        $mech_idx = $state['fight']['turn']['mech'];
        $mech     = $state['fight']['mechs'][$team_key][$mech_idx];

        $modules_by_id = self::loadModules();
        $options = [];
        foreach ($mech['energy'] as $id => $cubes) {
            $max = (int)($modules_by_id[self::baseId($id)]['energy_max'] ?? 0);
            if ($cubes < $max) $options[] = (string)$id;
        }

        if (!$options) {
            $state['fight']['log'][] = "{$mech['pilot_name']}'s $source: every module is fully charged.";
            return $state;
        }

        $count = min($count, count($options));
        $state['fight']['pending'] = [
            'kind'    => 'charge',
            'team'    => $team_key,
            'mech'    => $mech_idx,
            'count'   => $count,
            'source'  => $source,
            'options' => $options,
        ];
        $state['fight']['log'][] = "{$mech['pilot_name']}'s $source: choose up to $count module" . ($count > 1 ? 's' : '') . " to charge.";
        return $state;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function parseEffect(string $effect): array {
        $actions = [];
        foreach (array_map('trim', explode(',', $effect)) as $token) {
            if (preg_match('/^FORW\s+(\d+)$/', $token, $m)) {
                $actions[] = ['type' => 'move', 'dir' => 'forward', 'amount' => (int)$m[1]];
            } elseif (preg_match('/^BACK\s+(\d+)$/', $token, $m)) {
                $actions[] = ['type' => 'move', 'dir' => 'back',    'amount' => (int)$m[1]];
            } elseif (isset(self::SLOT_MAP[$token])) {
                $actions[] = ['type' => 'activate', 'slot' => self::SLOT_MAP[$token]];
            } elseif (preg_match('/^(IMP|PRJ|LSR|EXP)\+(\d+)$/', $token, $m)) {
                $actions[] = ['type' => 'bonus', 'atk_type' => $m[1], 'amount' => (int)$m[2]];
            } elseif (preg_match('/^DEF\s+(\d+)$/', $token, $m)) {
                $actions[] = ['type' => 'defense', 'amount' => (int)$m[1]];
            } elseif ($token === 'CORE') {
                $actions[] = ['type' => 'core'];
            }
        }
        return $actions;
    }

    /** Base HP plus every "HP+N" on the mech's parts (e.g. Heavy Legs, Tough Torso). */
    private static function mechMaxHp(array $mech, array $modules_by_id): int {
        $hp = self::MECH_BASE_HP;
        foreach ($mech['slots'] as $part_id) {
            if (!$part_id) continue;
            $effect = $modules_by_id[self::baseId($part_id)]['effects'] ?? '';
            if (preg_match_all('/\bHP\+(\d+)/i', $effect, $m)) $hp += array_sum(array_map('intval', $m[1]));
        }
        return $hp;
    }

    /**
     * Attack bonus from the mech's parts for an attack of $atk_type made with
     * equipment on $slot: a plain bonus ("IMP+1") only helps equipment on that
     * part; an "All" bonus ("All PRJ+1") helps every attack of that type.
     */
    private static function partBonus(array $mech, ?string $slot, string $atk_type, array $modules_by_id): int {
        $bonus = 0;
        foreach ($mech['slots'] as $part_slot => $part_id) {
            if (!$part_id) continue;
            $effect = $modules_by_id[self::baseId($part_id)]['effects'] ?? '';
            foreach (preg_split('/[,\n]/', $effect) as $token) {
                if (!preg_match('/^(All\s+)?(IMP|PRJ|LSR|EXP)\+(\d+)$/i', trim($token), $m)) continue;
                if (strtoupper($m[2]) !== $atk_type) continue;
                if ($m[1] !== '' || $part_slot === $slot) $bonus += (int)$m[3];
            }
        }
        return $bonus;
    }

    /** Returns ['base_atk' => N, 'atk_type' => TYPE] or null. */
    private static function parseEquipEffect(string $effect): ?array {
        if (preg_match('/\bATK\s+(\d+)\s+(PRJ|IMP|LSR|EXP)\b/i', $effect, $m)) {
            return ['base_atk' => (int)$m[1], 'atk_type' => strtoupper($m[2])];
        }
        return null;
    }

    private static function findMechByPilot(array $state, string $pilot): array {
        foreach ($state['fight']['mechs'] as $tk => $mechs) {
            foreach ($mechs as $idx => $mech) {
                if ($mech['pilot'] === $pilot) return [(string)$tk, $idx]; // PHP casts numeric keys to int
            }
        }
        return [null, null];
    }

    /**
     * Indices of the live $opp_team mechs hit by an attack from $atk_pos.
     * $range lists the allowed tile distances as digits (e.g. "234").
     * Primary target: closest in range, then most HP. A remaining tie should
     * be the attacker's choice; for now the first such mech is taken.
     * AoE 'tile' adds every enemy on the primary's tile; 'neighbor' also
     * adds enemies on the adjacent tiles.
     */
    private static function findTargets(array $state, string $opp_team, int $atk_pos, string $range, ?string $aoe): array {
        $mechs = $state['fight']['mechs'][$opp_team] ?? [];
        $primary = null;
        foreach ($mechs as $idx => $mech) {
            if ($mech['hp'] <= 0) continue;
            $dist = abs($mech['pos'] - $atk_pos);
            if (!str_contains($range, (string)$dist)) continue;
            if ($primary === null) { $primary = $idx; continue; }
            $best = $mechs[$primary];
            $best_dist = abs($best['pos'] - $atk_pos);
            if ($dist < $best_dist || ($dist === $best_dist && $mech['hp'] > $best['hp'])) {
                $primary = $idx;
            }
        }
        if ($primary === null) return [];
        if ($aoe !== 'tile' && $aoe !== 'neighbor') return [$primary];

        $center = $mechs[$primary]['pos'];
        $reach  = $aoe === 'neighbor' ? 1 : 0;
        $hit = [];
        foreach ($mechs as $idx => $mech) {
            if ($mech['hp'] > 0 && abs($mech['pos'] - $center) <= $reach) $hit[] = $idx;
        }
        return $hit;
    }

    private static function loadModules(): array {
        return array_column(
            json_decode(file_get_contents(__DIR__ . '/../data/cards/modules.json'), true),
            null, 'id'
        );
    }

    /**
     * Energy cubes per module instance at fight start: each module begins
     * with enough cubes for one activation (its filled energy slots).
     */
    private static function initialEnergy(array $mech, array $modules_by_id): array {
        $ids = array_filter(array_values($mech['slots']));
        foreach ($mech['equipment'] as $slot_equip) {
            foreach ($slot_equip as $eq) $ids[] = $eq;
        }
        $energy = [];
        foreach ($ids as $id) {
            $m = $modules_by_id[self::baseId($id)] ?? null;
            if ($m && !empty($m['energy_max'])) $energy[$id] = (int)($m['energy_min'] ?? 0);
        }
        return $energy;
    }

    private static function isDestroyed(array $state, string $pilot): bool {
        [$tk, $idx] = self::findMechByPilot($state, $pilot);
        return $tk !== null && $state['fight']['mechs'][$tk][$idx]['hp'] <= 0;
    }

    /** Fights started before energy tracking: fill in starting cubes. */
    private static function ensureEnergy(array $state): array {
        $modules_by_id = null;
        foreach ($state['fight']['mechs'] as $tk => $team_mechs) {
            foreach ($team_mechs as $idx => $mech) {
                if (isset($mech['energy'])) continue;
                $modules_by_id ??= self::loadModules();
                $state['fight']['mechs'][$tk][$idx]['energy'] = self::initialEnergy($mech, $modules_by_id);
            }
        }
        return $state;
    }

    /** Strip the instance suffix from an instance key, e.g. "small_laser:1" → "small_laser". */
    private static function baseId(string $id): string {
        return explode(':', $id)[0];
    }

    // ── Client state ──────────────────────────────────────────────────────

    public static function publicState(array $state, string $player_id): ?array {
        if ($state['phase'] !== 'fight') return null;

        $state = self::ensureEnergy($state);

        return [
            'deck_count'    => count($state['fight']['deck']),
            'discard_count' => count($state['fight']['discard']),
            'current'       => $state['fight']['current'],
            'mechs'         => $state['fight']['mechs'],
            'pending'       => $state['fight']['pending'] ?? null,
            'result'        => $state['fight']['result'] ?? null,
            'log'           => array_slice($state['fight']['log'], -200),
        ];
    }
}
