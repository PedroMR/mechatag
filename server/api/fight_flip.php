<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';
require_once __DIR__ . '/../lib/Fight.php';

$player_id = $_COOKIE['player_id'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id)) {
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$state = Room::get($room_id);
if (!$state)                               { echo json_encode(['ok' => false, 'error' => 'Room not found']); exit; }
if (!isset($state['players'][$player_id])) { echo json_encode(['ok' => false, 'error' => 'Not in room']);    exit; }
if ($state['phase'] !== 'fight')           { echo json_encode(['ok' => false, 'error' => 'Not in fight']);   exit; }

// Autoplay sends the flip count it saw, so two clients autoplaying at once
// can't both flip the same card: a stale request is ignored.
$expect = isset($_POST['expect_flips']) ? (int)$_POST['expect_flips'] : null;

FileStore::update(Room::statePath($room_id), function ($s) use ($expect) {
    if ($expect !== null && $expect !== ($s['fight']['flips'] ?? 0)) return $s;
    return Fight::flip($s);
});

echo json_encode(['ok' => true]);
