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
                    'pos'        => self::START_POS[$tk],
                    'locks'      => [],   // pilot colors of enemy mechs this mech has a target lock on
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
        if (count($ids) > $pending['count'])                  { $error = 'Too many choices'; return $state; }
        if (array_diff($ids, $pending['options']))            { $error = 'Invalid choice';   return $state; }

        $modules_by_id = self::loadModules();

        if ($pending['kind'] === 'move') {
            unset($state['fight']['pending']);
            $delta = (int)($ids[0] ?? 0);   // skip = stay
            $mech  = $state['fight']['mechs'][$team][$pending['mech']];
            if ($delta === 0) {
                $state['fight']['log'][] = "{$mech['pilot_name']} stays put.";
            } else {
                [$state, $moved, $pushed] = self::moveMech($state, $team, $pending['mech'], $delta);
                $word = $delta > 0 ? 'forward' : 'back';
                $tile = $state['fight']['mechs'][$team][$pending['mech']]['pos'] + 1;
                $state['fight']['log'][] = $moved > 0
                    ? "{$mech['pilot_name']} moves $word $moved with {$pending['source']} (tile $tile)" . ($pushed ? ', pushing ' . implode(' & ', $pushed) : '') . '.'
                    : "{$mech['pilot_name']} tries to move $word but is blocked.";
            }
            return self::checkDeckEnd(self::runSteps($state));
        }

        if ($pending['kind'] === 'lock' || $pending['kind'] === 'spend_lock') {
            unset($state['fight']['pending']);
            $pilot_name = $state['fight']['mechs'][$team][$pending['mech']]['pilot_name'];
            if ($pending['kind'] === 'lock') {
                $state = $ids
                    ? self::addLock($state, $ids[0])
                    : self::logLine($state, "$pilot_name doesn't lock onto anyone.");
            } elseif ($ids) {
                array_unshift($state['fight']['turn']['steps'], $pending['attack'] + ['lock' => $ids[0]]);
            } else {
                $state['fight']['log'][] = "$pilot_name holds fire with {$pending['source']}; no lock spent.";
            }
            return self::checkDeckEnd(self::runSteps($state));
        }

        if ($pending['kind'] === 'defend') {
            // Pay each chosen shield's energy and pool its DEF with the free ones
            $def   = $pending['free_def'];
            $names = $pending['free_names'];
            $mech  = &$state['fight']['mechs'][$team][$pending['mech']];
            foreach ($ids as $id) {
                $mech['energy'][$id] -= $pending['details'][$id]['cost'];
                $def    += $pending['details'][$id]['def'];
                $names[] = $modules_by_id[self::baseId($id)]['name'] ?? $id;
            }
            unset($mech);
            unset($state['fight']['pending']);
            $state = self::applyHit($state, $pending['hit'], $def, $names);
            return self::checkDeckEnd(self::runSteps($state));
        }

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
        // Playing a new card forgoes the DEF of this pilot's previous card
        unset($state['fight']['mechs'][$team_key][$mech_idx]['action_def']);
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
                    $steps[] = [
                        'type' => 'module', 'id' => $id, 'slot' => $slot,
                        'is_part'   => $id === $mech['slots'][$slot],
                        'part_only' => empty($mech['equipment'][$slot]),   // nothing else to activate here
                    ];
                }
            } else {
                $steps[] = $a;
            }
        }

        $state['fight']['turn'] = [
            'card'    => $card['name'],
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
        while (true) {
            foreach (['1', '2'] as $tk) {
                if (self::teamWiped($state, $tk)) return self::endFight($state, 'wipe');
            }
            if (empty($state['fight']['turn']['steps']) || !empty($state['fight']['pending'])) break;
            $step  = array_shift($state['fight']['turn']['steps']);
            $state = self::runStep($state, $step, $modules_by_id);
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
                $delta = $a['dir'] === 'forward' ? $a['amount'] : -$a['amount'];
                [$state, $moved, $pushed] = self::moveMech($state, $team_key, $mech_idx, $delta);
                $word  = $a['dir'] === 'forward' ? 'forward' : 'back';
                $tile  = $state['fight']['mechs'][$team_key][$mech_idx]['pos'] + 1; // 1-based for display
                $state['fight']['log'][] = $moved > 0
                    ? "$pilot_name moves $word $moved (tile $tile)."
                    : "$pilot_name tries to move $word but is blocked.";
                break;

            case 'choose_move':
                // MOVE N: the player picks a direction and 0..N steps; preview each option
                $options = [];
                $labels  = [];
                for ($d = -$a['amount']; $d <= $a['amount']; $d++) {
                    [$sim, $moved, $pushed] = self::moveMech($state, $team_key, $mech_idx, $d);
                    $tile = $sim['fight']['mechs'][$team_key][$mech_idx]['pos'] + 1;
                    $what = $d === 0 ? 'Stay' : ($d > 0 ? "Forward $d" : 'Back ' . -$d);
                    $note = "tile $tile";
                    if ($d !== 0 && $moved < abs($d)) $note .= $moved ? ", only $moved" : ', blocked';
                    if ($pushed) $note .= ', pushes ' . implode(' & ', $pushed);
                    $options[] = (string)$d;
                    $labels[(string)$d] = "$what ($note)";
                }
                $state['fight']['pending'] = [
                    'kind' => 'move', 'team' => $team_key, 'mech' => $mech_idx,
                    'count' => 1, 'options' => $options, 'labels' => $labels, 'source' => $a['source'],
                ];
                $state['fight']['log'][] = "$pilot_name's {$a['source']}: choose where to move (up to {$a['amount']}).";
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

                if (preg_match('/\bLOCK\+(\d+)/i', $effect, $m)) {
                    // Sensor: acquire target lock(s), one enemy at a time
                    $gains = array_fill(0, (int)$m[1], ['type' => 'gain_lock', 'source' => $name]);
                    array_unshift($state['fight']['turn']['steps'], ...$gains);

                } elseif (preg_match('/\bMOVE\s+(\d+)/i', $effect, $m)) {
                    array_unshift($state['fight']['turn']['steps'], ['type' => 'choose_move', 'amount' => (int)$m[1], 'source' => $name]);

                } elseif (preg_match('/\+(\d+)\s*ENRG/i', $effect, $m)) {
                    // Generator: new cubes in any empty energy slots on this mech
                    $state = self::openCharge($state, (int)$m[1], $name);

                } elseif ($atk) {
                    $mech      = $state['fight']['mechs'][$team_key][$mech_idx];
                    $total_dmg = $atk['base_atk']
                        + ($bonuses[$atk['atk_type']] ?? 0)
                        + self::partBonus($mech, $a['slot'] ?? null, $atk['atk_type'], $modules_by_id);

                    $opp_team = $team_key === '1' ? '2' : '1';
                    $atk_pos  = $state['fight']['mechs'][$team_key][$mech_idx]['pos'];

                    if (preg_match('/\bLOCK-\d+\s*:/i', $effect)) {
                        $state = self::startLockedAttack($state, [
                            'type'     => 'locked_attack',
                            'id'       => $id,
                            'weapon'   => $name,
                            'dmg'      => $total_dmg,
                            'atk_type' => $atk['atk_type'],
                            'cost'     => (int)($module['energy_min'] ?? 0),
                            'range'    => (string)($module['range'] ?? ''),
                            'aoe'      => $module['aoe'] ?? null,
                        ]);
                        break;
                    }

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

                    // Each AoE target is a separate attack, defended individually: queue one
                    // hit per target ahead of the card's remaining steps
                    $hits = [];
                    foreach ($targets as $tgt_idx) {
                        $hits[] = [
                            'type'     => 'hit',
                            'team'     => $opp_team,
                            'idx'      => $tgt_idx,
                            'dmg'      => $total_dmg,
                            'atk_type' => $atk['atk_type'],
                            'attacker' => $pilot_name,
                            'weapon'   => $name,
                        ];
                    }
                    array_unshift($state['fight']['turn']['steps'], ...$hits);

                } elseif (self::parseModuleDef($effect)) {
                    $state['fight']['log'][] = "$pilot_name's $name only works against incoming attacks.";

                } elseif (!$a['is_part']) {
                    $state['fight']['log'][] = "$pilot_name uses $name.";
                } elseif (!empty($a['part_only'])) {
                    $slot_lbl = strtoupper(str_replace('_', ' ', $a['slot']));
                    $state['fight']['log'][] = "$pilot_name activates $slot_lbl (no equipment).";
                }
                break;

            case 'defense':
                // Action DEF: usable against attacks while it's this pilot's latest card
                $card_name = $state['fight']['turn']['card'] ?? 'action';
                $state['fight']['mechs'][$team_key][$mech_idx]['action_def'] = [
                    'amount' => $a['amount'],
                    'types'  => $a['types'],
                    'card'   => $card_name,
                ];
                $types_lbl = $a['types'] ? implode('/', $a['types']) : 'any';
                $state['fight']['log'][] = "$pilot_name readies $card_name: DEF {$a['amount']} ($types_lbl) until $pilot_name plays another card.";
                break;

            case 'hit':
                $state = self::resolveHit($state, $a, $modules_by_id);
                break;

            case 'gain_lock':
                $mech    = $state['fight']['mechs'][$team_key][$mech_idx];
                $opp     = $team_key === '1' ? '2' : '1';
                $options = [];
                foreach ($state['fight']['mechs'][$opp] as $em) {
                    if ($em['hp'] > 0 && !in_array($em['pilot'], $mech['locks'] ?? [], true)) $options[] = $em['pilot'];
                }
                if (!$options) {
                    $state['fight']['log'][] = "$pilot_name's {$a['source']}: already locked onto every enemy.";
                } elseif (count($options) === 1) {
                    $state = self::addLock($state, $options[0]);
                } else {
                    $state['fight']['pending'] = [
                        'kind' => 'lock', 'team' => $team_key, 'mech' => $mech_idx,
                        'count' => 1, 'options' => $options, 'source' => $a['source'],
                    ];
                    $state['fight']['log'][] = "$pilot_name's {$a['source']}: choose an enemy to lock onto.";
                }
                break;

            case 'locked_attack':
                // Spend the chosen lock (energy was checked before choosing) and attack that mech
                $mech_ref = &$state['fight']['mechs'][$team_key][$mech_idx];
                $mech_ref['energy'][$a['id']] = ($mech_ref['energy'][$a['id']] ?? 0) - $a['cost'];
                $mech_ref['locks'] = array_values(array_diff($mech_ref['locks'] ?? [], [$a['lock']]));
                unset($mech_ref);

                $opp = $team_key === '1' ? '2' : '1';
                [, $tgt_idx] = self::findMechByPilot($state, $a['lock']);
                $tgt_name = $state['fight']['mechs'][$opp][$tgt_idx]['pilot_name'];
                $state['fight']['log'][] = "$pilot_name spends the lock on $tgt_name to fire {$a['weapon']}.";

                $hits = [];
                foreach (self::aoeTargets($state, $opp, $tgt_idx, $a['aoe']) as $idx) {
                    $hits[] = [
                        'type' => 'hit', 'team' => $opp, 'idx' => $idx,
                        'dmg' => $a['dmg'], 'atk_type' => $a['atk_type'],
                        'attacker' => $pilot_name, 'weapon' => $a['weapon'],
                    ];
                }
                array_unshift($state['fight']['turn']['steps'], ...$hits);
                break;

            case 'core':
                $state = self::openCharge($state, self::CORE_CHARGES, 'CORE');
                break;
        }

        return $state;
    }

    /**
     * Move a mech $delta tiles (positive = forward, toward the enemy side), one step
     * at a time: a single enemy on the next tile is pushed ahead, two enemies or the
     * arena edge (for the mech or the pushed enemy) stop the rest of the move.
     * Teammates don't block. Returns [state, steps moved, names of pushed mechs].
     */
    private static function moveMech(array $state, string $team_key, int $mech_idx, int $delta): array {
        // Team 1 forward = +tile, Team 2 forward = -tile
        $dir    = ($team_key === '1' ? 1 : -1) * ($delta >= 0 ? 1 : -1);
        $opp    = $team_key === '1' ? '2' : '1';
        $moved  = 0;
        $pushed = [];
        for ($s = 0; $s < abs($delta); $s++) {
            $new_pos = $state['fight']['mechs'][$team_key][$mech_idx]['pos'] + $dir;
            if ($new_pos < 0 || $new_pos >= self::BOARD_TILES) break;
            $enemies_at = [];
            foreach ($state['fight']['mechs'][$opp] as $ei => $em) {
                if ($em['hp'] > 0 && $em['pos'] === $new_pos) $enemies_at[] = $ei;
            }
            if (count($enemies_at) >= 2) break; // two enemies block
            if (count($enemies_at) === 1) {
                $push = $new_pos + $dir;
                if ($push < 0 || $push >= self::BOARD_TILES) break; // wall behind the enemy
                $state['fight']['mechs'][$opp][$enemies_at[0]]['pos'] = $push;
                $pushed[$state['fight']['mechs'][$opp][$enemies_at[0]]['pilot_name']] = true;
            }
            $state['fight']['mechs'][$team_key][$mech_idx]['pos'] = $new_pos;
            $moved++;
        }
        return [$state, $moved, array_keys($pushed)];
    }

    private static function logLine(array $state, string $line): array {
        $state['fight']['log'][] = $line;
        return $state;
    }

    /** The active mech locks onto the enemy mech flown by $pilot. */
    private static function addLock(array $state, string $pilot): array {
        $team_key = $state['fight']['turn']['team'];
        $mech_idx = $state['fight']['turn']['mech'];
        $state['fight']['mechs'][$team_key][$mech_idx]['locks'][] = $pilot;
        [$tt, $ti] = self::findMechByPilot($state, $pilot);
        $state['fight']['log'][] = "{$state['fight']['mechs'][$team_key][$mech_idx]['pilot_name']} locks onto {$state['fight']['mechs'][$tt][$ti]['pilot_name']}.";
        return $state;
    }

    /**
     * A LOCK-1 weapon: needs a held lock on a live enemy within the weapon's range,
     * and its energy cost. If the cost can't be paid the attack is cancelled and no
     * lock is spent. With several usable locks the player picks one (or holds fire).
     */
    private static function startLockedAttack(array $state, array $attack): array {
        $team_key   = $state['fight']['turn']['team'];
        $mech_idx   = $state['fight']['turn']['mech'];
        $mech       = $state['fight']['mechs'][$team_key][$mech_idx];
        $pilot_name = $mech['pilot_name'];
        $weapon     = $attack['weapon'];

        $have = $mech['energy'][$attack['id']] ?? 0;
        if ($have < $attack['cost']) {
            $state['fight']['log'][] = "$pilot_name tries to fire $weapon, but it lacks energy ($have/{$attack['cost']}). No lock spent.";
            return $state;
        }

        $usable = [];
        foreach ($mech['locks'] ?? [] as $pilot) {
            [$tt, $ti] = self::findMechByPilot($state, $pilot);
            if ($tt === null) continue;
            $tgt = $state['fight']['mechs'][$tt][$ti];
            if ($tgt['hp'] > 0 && str_contains($attack['range'], (string)abs($tgt['pos'] - $mech['pos']))) $usable[] = $pilot;
        }

        if (!$usable) {
            $state['fight']['log'][] = empty($mech['locks'])
                ? "$pilot_name would fire $weapon, but has no target lock."
                : "$pilot_name would fire $weapon, but no locked target is in range.";
            return $state;
        }
        if (count($usable) === 1) {
            array_unshift($state['fight']['turn']['steps'], $attack + ['lock' => $usable[0]]);
            return $state;
        }
        $state['fight']['pending'] = [
            'kind' => 'spend_lock', 'team' => $team_key, 'mech' => $mech_idx,
            'count' => 1, 'options' => $usable, 'source' => $weapon, 'attack' => $attack,
        ];
        $state['fight']['log'][] = "$pilot_name readies $weapon: choose which lock to spend.";
        return $state;
    }

    /**
     * One attack against one mech. Free DEFs (the target pilot's current action
     * card, modules with no energy cost) apply automatically; if shields that
     * cost energy could still help, the defending team chooses which to use.
     */
    private static function resolveHit(array $state, array $hit, array $modules_by_id): array {
        $tgt = $state['fight']['mechs'][$hit['team']][$hit['idx']];
        if ($tgt['hp'] <= 0) return $state;

        $free_def   = 0;
        $free_names = [];
        $ad = $tgt['action_def'] ?? null;
        if ($ad && self::defCovers($ad['types'], $hit['atk_type'])) {
            $free_def    += $ad['amount'];
            $free_names[] = $ad['card'];
        }

        $options = [];
        $details = [];
        foreach (self::mechModuleIds($tgt) as $id) {
            $module = $modules_by_id[self::baseId($id)] ?? null;
            $def    = $module ? self::parseModuleDef($module['effects'] ?? '') : null;
            if (!$def || !self::defCovers($def['types'], $hit['atk_type'])) continue;
            $cost = (int)($module['energy_min'] ?? 0);
            if ($cost === 0) {
                $free_def    += $def['amount'];
                $free_names[] = $module['name'];
            } elseif (($tgt['energy'][$id] ?? 0) >= $cost) {
                $options[]    = $id;
                $details[$id] = ['def' => $def['amount'], 'cost' => $cost];
            }
        }

        if ($options && $hit['dmg'] > $free_def) {
            $state['fight']['pending'] = [
                'kind'       => 'defend',
                'team'       => $hit['team'],
                'mech'       => $hit['idx'],
                'count'      => count($options),
                'options'    => $options,
                'details'    => $details,
                'free_def'   => $free_def,
                'free_names' => $free_names,
                'hit'        => $hit,
            ];
            $state['fight']['log'][] = "{$hit['attacker']} attacks {$tgt['pilot_name']} with {$hit['weapon']} ({$hit['dmg']} {$hit['atk_type']}). {$tgt['pilot_name']} may use shields.";
            return $state;
        }

        return self::applyHit($state, $hit, $free_def, $free_names);
    }

    private static function applyHit(array $state, array $hit, int $def, array $def_names): array {
        $tgt = &$state['fight']['mechs'][$hit['team']][$hit['idx']];
        $blocked = min($def, $hit['dmg']);
        $actual  = $hit['dmg'] - $blocked;
        $tgt['hp'] = max(0, $tgt['hp'] - $actual);
        $punct = $actual < 3 ? '.' : '!';

        $line = "{$hit['attacker']} attacks {$tgt['pilot_name']} with {$hit['weapon']}, dealing $actual damage";
        if ($blocked > 0) $line .= " ($blocked blocked by " . implode(', ', $def_names) . ")";
        $state['fight']['log'][] = $line . $punct;

        if ($tgt['hp'] <= 0) {
            $state['fight']['log'][] = "⚡ {$tgt['pilot_name']}'s mech is destroyed!";
        }
        unset($tgt);
        return $state;
    }

    /** Ids of every module on a mech: parts, then equipment. */
    private static function mechModuleIds(array $mech): array {
        $ids = array_values(array_filter($mech['slots']));
        foreach ($mech['equipment'] as $eqs) foreach ($eqs as $id) $ids[] = $id;
        return $ids;
    }

    /** A module's DEF instruction, e.g. "DEF 2 PRJIMP" → ['amount' => 2, 'types' => ['PRJ','IMP']]. */
    private static function parseModuleDef(string $effect): ?array {
        if (!preg_match('/\bDEF\s+(\d+)(?:\s+([A-Za-z]+))?/', $effect, $m)) return null;
        return ['amount' => (int)$m[1], 'types' => self::parseDefTypes($m[2] ?? null)];
    }

    /** "PRJIMP" → ['PRJ','IMP']; missing or "any" → null (defends against every type). */
    private static function parseDefTypes(?string $types): ?array {
        if ($types === null || $types === '' || strtolower($types) === 'any') return null;
        return str_split(strtoupper($types), 3);
    }

    private static function defCovers(?array $types, string $atk_type): bool {
        return $types === null || in_array($atk_type, $types, true);
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
            } elseif (preg_match('/^DEF\s+(\d+)(?:\s+([A-Za-z]+))?$/', $token, $m)) {
                $actions[] = ['type' => 'defense', 'amount' => (int)$m[1], 'types' => self::parseDefTypes($m[2] ?? null)];
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
        return self::aoeTargets($state, $opp_team, $primary, $aoe);
    }

    /** The primary target plus, for AoE, live enemies on its tile ('tile') or also adjacent ones ('neighbor'). */
    private static function aoeTargets(array $state, string $opp_team, int $primary, ?string $aoe): array {
        if ($aoe !== 'tile' && $aoe !== 'neighbor') return [$primary];
        $mechs  = $state['fight']['mechs'][$opp_team];
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

    /**
     * The discard as one pile per pilot (team 1's pilots, then team 2's):
     * each pile's count and its top card, i.e. that pilot's last discarded card.
     */
    private static function discardPiles(array $state): array {
        $piles = [];
        foreach (['1', '2'] as $tk) {
            foreach ($state['pilots'][$tk] ?? [] as $pilot) {
                $piles[$pilot] = ['pilot' => $pilot, 'team' => $tk, 'count' => 0, 'top' => null];
            }
        }
        foreach ($state['fight']['discard'] as $card) {
            if (!isset($piles[$card['pilot']])) continue;
            $piles[$card['pilot']]['count']++;
            $piles[$card['pilot']]['top'] = $card;
        }
        return array_values($piles);
    }

    public static function publicState(array $state, string $player_id): ?array {
        if ($state['phase'] !== 'fight') return null;

        $state = self::ensureEnergy($state);

        return [
            'deck_count'    => count($state['fight']['deck']),
            'discard_count' => count($state['fight']['discard']),
            'discards'      => self::discardPiles($state),
            'current'       => $state['fight']['current'],
            'mechs'         => $state['fight']['mechs'],
            'pending'       => $state['fight']['pending'] ?? null,
            'result'        => $state['fight']['result'] ?? null,
            'log'           => array_slice($state['fight']['log'], -200),
        ];
    }
}
