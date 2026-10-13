<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';

$player_id = $_COOKIE['player_id']   ?? null;
$name      = $_COOKIE['player_name'] ?? null;

if (!$player_id || !$name) {
    echo json_encode(['ok' => false, 'error' => 'Set a name first']);
    exit;
}

$state = Room::create($player_id, $name);
echo json_encode(['ok' => true, 'room_id' => $state['id']]);
