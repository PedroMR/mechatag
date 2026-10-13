<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';

$player_id = $_COOKIE['player_id'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id)) {
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$state = Room::get($room_id);
if (!$state) {
    echo json_encode(['ok' => false, 'error' => 'Room not found']);
    exit;
}
if (!isset($state['players'][$player_id])) {
    echo json_encode(['ok' => false, 'error' => 'Not in room']);
    exit;
}

Room::randomizeTeams($room_id);
echo json_encode(['ok' => true]);
