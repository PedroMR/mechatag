<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';

$player_id = $_COOKIE['player_id']   ?? null;
$name      = $_COOKIE['player_name'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? $_GET['room_id'] ?? ''));

if (!$player_id || !$name) {
    echo json_encode(['ok' => false, 'error' => 'Set a name first']);
    exit;
}
if (!preg_match('/^[A-F0-9]{6}$/', $room_id)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid room code']);
    exit;
}

$result = Room::join($room_id, $player_id, $name);
if ($result === null) {
    echo json_encode(['ok' => false, 'error' => 'Room not found']);
    exit;
}
if ($result['full']) {
    echo json_encode(['ok' => false, 'error' => 'Room is full (max 4 players)']);
    exit;
}

echo json_encode(['ok' => true, 'room_id' => $room_id]);
