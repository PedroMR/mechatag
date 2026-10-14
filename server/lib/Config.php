<?php

require_once __DIR__ . '/FileStore.php';

class Config {

    // Default hardpoints offered by each slot when no part card is assigned
    const DEFAULT_SLOT_HP = [
        'head'  => 0,
        'torso' => 1,
        'r_arm' => 1,
        'l_arm' => 1,
        'legs'  => 1,
    ];

    private static function loadModules(): array {
        $raw = json_decode(file_get_contents(__DIR__ . '/../data/cards/modules.json'), true);
        return array_column($raw, null, 'id');
    }

    /** Strip the instance suffix from an instance key, e.g. "small_laser:1" → "small_laser". */
    private static function baseId(string $id): string {
        return explode(':', $id)[0];
    }

    private static function slotHardpointsAvailable(string $slot, array $mech, array $modules_by_id): int {
        $card_id = $mech['slots'][$slot] ?? null;
        if ($card_id !== null) {
            return (int)($modules_by_id[self::baseId($card_id)]['hardpoints_offered'] ?? 0);
        }
        return self::DEFAULT_SLOT_HP[$slot] ?? 0;
    }

    private static function slotHardpointsUsed(string $slot, array $mech, array $modules_by_id): int {
        $used = 0;
        foreach ($mech['equipment'][$slot] ?? [] as $card_id) {
            $used += (int)($modules_by_id[self::baseId($card_id)]['hardpoints_required'] ?? 0);
        }
        return $used;
    }

    public static function mechHardpointsTotal(array $mech, array $modules_by_id): int {
        $total = 0;
        foreach (array_keys(self::DEFAULT_SLOT_HP) as $slot) {
            $total += self::slotHardpointsAvailable($slot, $mech, $modules_by_id);
        }
        return $total;
    }

    public static function mechHardpointsUsed(array $mech, array $modules_by_id): int {
        $used = 0;
        foreach (array_keys(self::DEFAULT_SLOT_HP) as $slot) {
            $used += self::slotHardpointsUsed($slot, $mech, $modules_by_id);
        }
        return $used;
    }

    /**
     * Remove a card from every slot/equipment on a team's mechs.
     */
    private static function removeCardFromTeam(array $state, string $team_key, string $card_id): array {
        foreach ($state['config']['mechs'][$team_key] as $idx => $_) {
            foreach (array_keys(self::DEFAULT_SLOT_HP) as $slot) {
                if (($state['config']['mechs'][$team_key][$idx]['slots'][$slot] ?? null) === $card_id) {
                    $state['config']['mechs'][$team_key][$idx]['slots'][$slot] = null;
                }
                $state['config']['mechs'][$team_key][$idx]['equipment'][$slot] = array_values(
                    array_filter($state['config']['mechs'][$team_key][$idx]['equipment'][$slot] ?? [], fn($c) => $c !== $card_id)
                );
            }
        }
        return $state;
    }

    /**
     * Assign or unassign a card to/from a mech.
     * $slot is always a body-part slot name ('head','torso','r_arm','l_arm','legs').
     * Parts go into the slot; equipment attaches to the slot and draws from its hardpoints.
     */
    public static function assignCard(array $state, string $player_id, int $mech_idx, string $slot, string $card_id): array {
        if ($state['phase'] !== 'config') return $state;

        $modules_by_id = self::loadModules();

        $team = $state['players'][$player_id]['team'] ?? null;
        if ($team === null) return $state;
        $team_key = (string)$team;

        if (!isset($state['config']['mechs'][$team_key][$mech_idx])) return $state;
        $card = $modules_by_id[self::baseId($card_id)] ?? null;
        if (!$card) return $state;

        if (!array_key_exists($slot, self::DEFAULT_SLOT_HP)) return $state;

        // Card must belong to this team's pool
        if (!in_array($card_id, $state['config']['modules'][$team_key] ?? [], true)) return $state;

        if ($card['type'] === 'part') {
            $current = $state['config']['mechs'][$team_key][$mech_idx]['slots'][$slot] ?? null;
            if ($current === $card_id) {
                $state['config']['mechs'][$team_key][$mech_idx]['slots'][$slot] = null;
            } else {
                $state = self::removeCardFromTeam($state, $team_key, $card_id);
                $state['config']['mechs'][$team_key][$mech_idx]['slots'][$slot] = $card_id;
            }

        } else if ($card['type'] === 'equipment') {
            // Validate equipment can attach to this slot
            $card_parts = $card['parts'] ?? [];
            if (!empty($card_parts) && !in_array('any', $card_parts, true)) {
                $allowed = ($slot === 'r_arm' || $slot === 'l_arm')
                    ? in_array('arm', $card_parts, true)
                    : in_array($slot, $card_parts, true);
                if (!$allowed) return $state;
            }

            $eq = $state['config']['mechs'][$team_key][$mech_idx]['equipment'][$slot] ?? [];
            if (in_array($card_id, $eq, true)) {
                // Unassign
                $state['config']['mechs'][$team_key][$mech_idx]['equipment'][$slot] = array_values(
                    array_filter($eq, fn($c) => $c !== $card_id)
                );
            } else {
                // Check per-slot hardpoints
                $mech     = $state['config']['mechs'][$team_key][$mech_idx];
                $hp_avail = self::slotHardpointsAvailable($slot, $mech, $modules_by_id);
                $hp_used  = self::slotHardpointsUsed($slot, $mech, $modules_by_id);
                $hp_cost  = (int)($card['hardpoints_required'] ?? 0);
                if ($hp_used + $hp_cost > $hp_avail) return $state;
                $state = self::removeCardFromTeam($state, $team_key, $card_id);
                $state['config']['mechs'][$team_key][$mech_idx]['equipment'][$slot][] = $card_id;
            }
        }

        return $state;
    }

    /**
     * Returns config state visible to a player. Returns null when not in config phase.
     */
    public static function publicState(array $state, string $player_id): ?array {
        if ($state['phase'] !== 'config') return null;

        $modules_by_id = self::loadModules();
        $team = $state['players'][$player_id]['team'] ?? null;
        if ($team === null) return null;
        $team_key = (string)$team;

        $mechs_out = [];
        foreach ($state['config']['mechs'][$team_key] as $mech) {
            $slot_hp = [];
            foreach (array_keys(self::DEFAULT_SLOT_HP) as $slot) {
                $slot_hp[$slot] = [
                    'available' => self::slotHardpointsAvailable($slot, $mech, $modules_by_id),
                    'used'      => self::slotHardpointsUsed($slot, $mech, $modules_by_id),
                ];
            }
            $mechs_out[] = [
                'slots'     => $mech['slots'],
                'equipment' => $mech['equipment'],
                'hp_total'  => self::mechHardpointsTotal($mech, $modules_by_id),
                'hp_used'   => self::mechHardpointsUsed($mech, $modules_by_id),
                'slot_hp'   => $slot_hp,
            ];
        }

        // Compute available (unassigned) cards server-side
        $assigned = [];
        foreach ($state['config']['mechs'][$team_key] as $mech) {
            foreach ($mech['slots'] as $v) {
                if ($v !== null) $assigned[] = $v;
            }
            foreach ($mech['equipment'] as $slot_equip) {
                foreach ($slot_equip as $eq) $assigned[] = $eq;
            }
        }
        $available = array_values(array_filter(
            $state['config']['modules'][$team_key] ?? [],
            fn($id) => !in_array($id, $assigned, true)
        ));

        $opp_key = $team_key === '1' ? '2' : '1';
        return [
            'modules'        => $state['config']['modules'][$team_key] ?? [],
            'available'      => $available,
            'mechs'          => $mechs_out,
            'my_team_ready'  => (bool)($state['config']['ready'][$team_key] ?? false),
            'opp_team_ready' => (bool)($state['config']['ready'][$opp_key]  ?? false),
        ];
    }
}
