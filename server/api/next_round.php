<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';
require_once __DIR__ . '/../lib/Draft.php';

$player_id = $_COOKIE['player_id'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id)) {
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$state = Room::get($room_id);
if (!$state)                               { echo json_encode(['ok' => false, 'error' => 'Room not found']); exit; }
if (!isset($state['players'][$player_id])) { echo json_encode(['ok' => false, 'error' => 'Not in room']);    exit; }
if ($state['phase'] !== 'fight' || empty($state['fight']['result'])) {
    echo json_encode(['ok' => false, 'error' => 'Fight is not over']);
    exit;
}
if (!empty($state['game_winner'])) { echo json_encode(['ok' => false, 'error' => 'Game is over']); exit; }

// Draft::nextRound re-checks under the lock, so a double click starts only one round
FileStore::update(Room::statePath($room_id), fn($s) => Draft::nextRound($s));

echo json_encode(['ok' => true]);
