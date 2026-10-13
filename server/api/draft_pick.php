<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';
require_once __DIR__ . '/../lib/Draft.php';

$player_id = $_COOKIE['player_id'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));
$position  = isset($_POST['position']) ? (int)$_POST['position'] : -1;

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id) || $position < 0) {
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
if (($state['phase'] ?? '') !== 'draft') {
    echo json_encode(['ok' => false, 'error' => 'Not in draft phase']);
    exit;
}

FileStore::update(
    Room::statePath($room_id),
    fn($s) => Draft::togglePick($s, $player_id, $position)
);

echo json_encode(['ok' => true]);
