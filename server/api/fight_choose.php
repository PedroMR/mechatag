<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';
require_once __DIR__ . '/../lib/Fight.php';

$player_id = $_COOKIE['player_id'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));
$ids       = $_POST['ids'] ?? [];

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id) || !is_array($ids)) {
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$state = Room::get($room_id);
if (!$state)                               { echo json_encode(['ok' => false, 'error' => 'Room not found']); exit; }
if (!isset($state['players'][$player_id])) { echo json_encode(['ok' => false, 'error' => 'Not in room']);    exit; }
if ($state['phase'] !== 'fight')           { echo json_encode(['ok' => false, 'error' => 'Not in fight']);   exit; }

$team = $state['players'][$player_id]['team'] ?? null;
if ($team === null) { echo json_encode(['ok' => false, 'error' => 'Not on a team']); exit; }

$error = null;
FileStore::update(Room::statePath($room_id), function ($s) use ($team, $ids, &$error) {
    if ($s['phase'] !== 'fight') { $error = 'Not in fight'; return $s; }
    return Fight::choose($s, (string)$team, $ids, $error);
});

echo json_encode($error ? ['ok' => false, 'error' => $error] : ['ok' => true]);
