<?php

require_once __DIR__ . '/FileStore.php';

define('DATA_DIR', __DIR__ . '/../data');
define('ONLINE_THRESHOLD', 30); // seconds without heartbeat before player shows offline

class Room {

    // -------------------------------------------------------------------------
    // Paths
    // -------------------------------------------------------------------------

    public static function dir(string $id): string {
        return DATA_DIR . '/rooms/' . preg_replace('/[^A-Z0-9]/', '', strtoupper($id));
    }

    public static function statePath(string $id): string {
        return self::dir($id) . '/state.json';
    }

    public static function chatPath(string $id, string $channel): string {
        // channel: 'global' | 'team1' | 'team2'
        return self::dir($id) . '/chat_' . preg_replace('/[^a-z0-9]/', '', $channel) . '.json';
    }

    public static function exists(string $id): bool {
        return is_dir(self::dir($id));
    }

    // -------------------------------------------------------------------------
    // Room lifecycle
    // -------------------------------------------------------------------------

    /**
     * Create a new room. Uses mkdir atomicity to avoid race conditions on ID generation.
     */
    public static function create(string $player_id, string $name): array {
        do {
            $id = strtoupper(bin2hex(random_bytes(3)));
            $created = @mkdir(self::dir($id), 0755, true);
        } while (!$created);

        $state = [
            'id'        => $id,
            'created'   => time(),
            'phase'     => 'lobby',
            'victories' => ['1' => 0, '2' => 0],
            'players'   => [
                $player_id => [
                    'name'      => $name,
                    'team'      => null,
                    'last_seen' => time(),
                ],
            ],
        ];

        FileStore::update(self::statePath($id), fn() => $state);
        return $state;
    }

    public static function get(string $id): ?array {
        return FileStore::read(self::statePath($id));
    }

    /**
     * Join or rejoin a room.
     * Returns ['state' => array, 'full' => false] on success,
     *         ['state' => array, 'full' => true] if room is full for a new player,
     *         null if the room doesn't exist.
     */
    public static function join(string $id, string $player_id, string $name): ?array {
        if (!self::exists($id)) return null;

        $full = false;
        $state = FileStore::update(self::statePath($id), function ($state) use ($player_id, $name, &$full) {
            $is_new = !isset($state['players'][$player_id]);
            if ($is_new && count($state['players']) >= 4) {
                $full = true;
                return $state; // no change
            }
            $existing_team = isset($state['players'][$player_id])
                ? $state['players'][$player_id]['team']
                : null;
            $state['players'][$player_id] = [
                'name'      => $name,
                'team'      => $existing_team !== null ? (int)$existing_team : null,
                'last_seen' => time(),
            ];
            return $state;
        });

        return ['state' => $state, 'full' => $full];
    }

    // -------------------------------------------------------------------------
    // Player presence
    // -------------------------------------------------------------------------

    public static function heartbeat(string $id, string $player_id): void {
        if (!self::exists($id)) return;
        FileStore::update(self::statePath($id), function ($state) use ($player_id) {
            if (isset($state['players'][$player_id])) {
                $state['players'][$player_id]['last_seen'] = time();
            }
            return $state;
        });
    }

    // -------------------------------------------------------------------------
    // Team management
    // -------------------------------------------------------------------------

    public static function setTeam(string $id, string $player_id, ?int $team): ?array {
        if (!self::exists($id)) return null;
        return FileStore::update(self::statePath($id), function ($state) use ($player_id, $team) {
            if (isset($state['players'][$player_id])) {
                $state['players'][$player_id]['team'] = $team;
            }
            return $state;
        });
    }

    public static function randomizeTeams(string $id): ?array {
        if (!self::exists($id)) return null;
        return FileStore::update(self::statePath($id), function ($state) {
            $ids = array_keys($state['players']);
            shuffle($ids);
            $half = (int)ceil(count($ids) / 2);
            foreach ($ids as $i => $pid) {
                $state['players'][$pid]['team'] = $i < $half ? 1 : 2;
            }
            return $state;
        });
    }

    // -------------------------------------------------------------------------
    // Chat
    // -------------------------------------------------------------------------

    public static function sendChat(string $id, string $channel, string $player_id, string $name, string $text): void {
        FileStore::update(self::chatPath($id, $channel), function ($messages) use ($player_id, $name, $text) {
            $messages[] = [
                'seq'  => count($messages) + 1,
                'pid'  => $player_id,
                'name' => $name,
                'text' => $text,
                'ts'   => time(),
            ];
            return $messages;
        }, []);
    }

    public static function getMessages(string $id, string $channel, int $since = 0): array {
        $all = FileStore::read(self::chatPath($id, $channel)) ?? [];
        return array_values(array_filter($all, fn($m) => $m['seq'] > $since));
    }

    // -------------------------------------------------------------------------
    // Output helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a sanitized room state suitable for sending to clients.
     */
    public static function publicState(array $state, string $current_player_id): array {
        $players = [];
        foreach ($state['players'] as $pid => $p) {
            $players[] = [
                'id'     => $pid,
                'name'   => $p['name'],
                'team'   => $p['team'] !== null ? (int)$p['team'] : null,
                'online' => (time() - $p['last_seen']) <= ONLINE_THRESHOLD,
                'is_me'  => $pid === $current_player_id,
                'pilot'  => $state['pilots'][$pid] ?? null,
            ];
        }
        return [
            'id'        => $state['id'],
            'phase'     => $state['phase'],
            'victories' => $state['victories'],
            'players'   => $players,
        ];
    }
}
