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
if (!$state) {
    echo json_encode(['ok' => false, 'error' => 'Room not found']);
    exit;
}
if (!isset($state['players'][$player_id])) {
    echo json_encode(['ok' => false, 'error' => 'Not in room']);
    exit;
}
if ($state['phase'] !== 'lobby') {
    echo json_encode(['ok' => false, 'error' => 'Game already started']);
    exit;
}

// Validate: every player must be on a team, and there must be 2 teams
$teams_with_players = [];
foreach ($state['players'] as $pid => $p) {
    if ($p['team'] === null) {
        echo json_encode(['ok' => false, 'error' => 'All players must join a team before starting']);
        exit;
    }
    $teams_with_players[(int)$p['team']] = true;
}
if (count($teams_with_players) < 2) {
    echo json_encode(['ok' => false, 'error' => 'Need at least one player on each team']);
    exit;
}

FileStore::update(Room::statePath($room_id), function ($s) {
    $s['game_round'] = 1;
    return Draft::start($s);
});

echo json_encode(['ok' => true]);
