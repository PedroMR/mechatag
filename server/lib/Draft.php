<?php

require_once __DIR__ . '/FileStore.php';

class Draft {

    // -------------------------------------------------------------------------
    // Deck building
    // -------------------------------------------------------------------------

    public static function buildDeck(int $tier): array {
        $modules = json_decode(
            file_get_contents(__DIR__ . '/../data/cards/modules.json'), true
        );
        $deck = [];
        foreach ($modules as $m) {
            if ((int)$m['tier'] === $tier) {
                for ($i = 0; $i < (int)$m['copies']; $i++) {
                    $deck[] = $m['id'];
                }
            }
        }
        shuffle($deck);
        return $deck;
    }

    // -------------------------------------------------------------------------
    // Game start
    // -------------------------------------------------------------------------

    /**
     * Transition a room from lobby → draft.
     * Assigns pilots randomly, deals the first two hands.
     */
    public static function start(array $state): array {
        // Assign 4 pilot colors to the 4 mechs: 2 per team
        $pilots = ['blue', 'green', 'purple', 'black'];
        shuffle($pilots);
        $state['pilots'] = [
            '1' => [$pilots[0], $pilots[1]],
            '2' => [$pilots[2], $pilots[3]],
        ];

        $game_round = $state['game_round'] ?? 1;
        $tier       = $game_round;
        $deck = self::buildDeck($tier);
        $hand1 = array_splice($deck, 0, 6);
        $hand2 = array_splice($deck, 0, 6);

        $state['phase'] = 'draft';
        $state['draft']  = [
            'round'        => 1,
            'step'         => 'initial',
            'deck'         => $deck,
            'hands'        => ['1' => $hand1, '2' => $hand2],
            'player_picks' => [],   // player_id → [pos, ...]
            'collected'    => ['1' => [], '2' => []],
        ];

        return $state;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function getTeamId(array $state, string $player_id): ?int {
        $team = $state['players'][$player_id]['team'] ?? null;
        return $team !== null ? (int)$team : null;
    }

    public static function getTeamPlayers(array $state, int $team): array {
        $result = [];
        foreach ($state['players'] as $pid => $p) {
            if ((int)($p['team'] ?? -1) === $team) {
                $result[] = $pid;
            }
        }
        return $result;
    }

    /** Unique hand positions selected by all players on a team (as a set). */
    public static function getTeamUniquePicks(array $state, int $team): array {
        $team_players = self::getTeamPlayers($state, $team);
        $positions = [];
        foreach ($team_players as $pid) {
            foreach ($state['draft']['player_picks'][$pid] ?? [] as $pos) {
                $positions[(int)$pos] = true;
            }
        }
        return array_keys($positions);
    }

    public static function isTeamReady(array $state, int $team): bool {
        return count(self::getTeamUniquePicks($state, $team)) === 2;
    }

    // -------------------------------------------------------------------------
    // Pick toggling
    // -------------------------------------------------------------------------

    /**
     * Toggle a card pick for a player. Automatically advances the draft if
     * both teams become ready after this pick.
     */
    public static function togglePick(array $state, string $player_id, int $position): array {
        if (($state['phase'] ?? '') !== 'draft') return $state;

        $team = self::getTeamId($state, $player_id);
        if ($team === null) return $state;

        $hand = $state['draft']['hands'][(string)$team] ?? [];
        if ($position < 0 || $position >= count($hand)) return $state;

        // Don't allow changes once the team is already ready
        if (self::isTeamReady($state, $team)) return $state;

        $team_players = self::getTeamPlayers($state, $team);
        $is_solo      = count($team_players) === 1;
        $max_per_player = $is_solo ? 2 : 1;

        $my_picks = $state['draft']['player_picks'][$player_id] ?? [];

        if (in_array($position, $my_picks, true)) {
            // Deselect
            $my_picks = array_values(array_filter($my_picks, fn($p) => $p !== $position));
        } else {
            // Check the position is not already owned by a teammate
            foreach ($team_players as $pid) {
                if ($pid === $player_id) continue;
                if (in_array($position, $state['draft']['player_picks'][$pid] ?? [], true)) {
                    return $state; // position already taken by teammate
                }
            }

            if (count($my_picks) >= $max_per_player) {
                array_shift($my_picks); // drop oldest pick to make room
            }
            $my_picks[] = $position;
        }

        $state['draft']['player_picks'][$player_id] = $my_picks;

        // Advance if both teams are now ready
        if (self::isTeamReady($state, 1) && self::isTeamReady($state, 2)) {
            $state = self::advance($state);
        }

        return $state;
    }

    // -------------------------------------------------------------------------
    // Phase advancement
    // -------------------------------------------------------------------------

    public static function advance(array $state): array {
        $picks1 = self::getTeamUniquePicks($state, 1);
        $picks2 = self::getTeamUniquePicks($state, 2);
        $hand1  = $state['draft']['hands']['1'] ?? [];
        $hand2  = $state['draft']['hands']['2'] ?? [];

        // Collect picked cards
        foreach ($picks1 as $pos) {
            if (isset($hand1[$pos])) {
                $state['draft']['collected']['1'][] = $hand1[$pos];
            }
        }
        foreach ($picks2 as $pos) {
            if (isset($hand2[$pos])) {
                $state['draft']['collected']['2'][] = $hand2[$pos];
            }
        }

        if ($state['draft']['step'] === 'initial') {
            // Remaining cards pass to the opposing team
            $remaining1 = self::remainingCards($hand1, $picks1);
            $remaining2 = self::remainingCards($hand2, $picks2);

            $state['draft']['hands']['1']   = array_values($remaining2); // team 1 receives team 2's leftovers
            $state['draft']['hands']['2']   = array_values($remaining1);
            $state['draft']['player_picks'] = [];
            $state['draft']['step']         = 'passed';

        } else { // 'passed'
            // Remaining cards are discarded (no swap)
            $state['draft']['player_picks'] = [];

            if ($state['draft']['round'] < 2) {
                // Start round 2: deal fresh hands from the deck
                $state['draft']['round']++;
                $state['draft']['step'] = 'initial';

                $deck = $state['draft']['deck'];
                $state['draft']['hands']['1'] = array_splice($deck, 0, 6);
                $state['draft']['hands']['2'] = array_splice($deck, 0, 6);
                $state['draft']['deck']       = $deck;
            } else {
                // Draft complete → mech configuration
                $empty_equip = ['head'=>[],'torso'=>[],'r_arm'=>[],'l_arm'=>[],'legs'=>[]];
                $empty_mech  = ['slots' => ['head'=>null,'torso'=>null,'r_arm'=>null,'l_arm'=>null,'legs'=>null], 'equipment' => $empty_equip];
                $state['phase'] = 'config';
                $state['config'] = [
                    'modules' => $state['draft']['collected'],
                    'mechs'   => [
                        '1' => [$empty_mech, $empty_mech],
                        '2' => [$empty_mech, $empty_mech],
                    ],
                ];
                unset($state['draft']);
            }
        }

        return $state;
    }

    private static function remainingCards(array $hand, array $picked_positions): array {
        $remaining = [];
        for ($i = 0; $i < count($hand); $i++) {
            if (!in_array($i, $picked_positions, true)) {
                $remaining[] = $hand[$i];
            }
        }
        return $remaining;
    }

    // -------------------------------------------------------------------------
    // Client-facing state
    // -------------------------------------------------------------------------

    /**
     * Returns draft state visible to a given player (hides opponent's hand).
     * Returns null when the room is not in draft phase.
     */
    public static function publicState(array $state, string $player_id): ?array {
        if (($state['phase'] ?? '') !== 'draft') return null;

        $team     = self::getTeamId($state, $player_id);
        if ($team === null) return null;
        $opp_team = $team === 1 ? 2 : 1;

        return [
            'round'          => $state['draft']['round'],
            'step'           => $state['draft']['step'],
            'hand'           => $state['draft']['hands'][(string)$team] ?? [],
            'player_picks'   => $state['draft']['player_picks'],
            'my_team_ready'  => self::isTeamReady($state, $team),
            'opp_team_ready' => self::isTeamReady($state, $opp_team),
            'collected'      => [
                'mine' => $state['draft']['collected'][(string)$team]     ?? [],
                'opp'  => $state['draft']['collected'][(string)$opp_team] ?? [],
            ],
        ];
    }
}
