<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';

$player_id = $_COOKIE['player_id'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));
$team_raw  = $_POST['team'] ?? null;

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id)) {
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$team = ($team_raw === '' || $team_raw === null) ? null : (int)$team_raw;
if ($team !== null && !in_array($team, [1, 2], true)) {
    echo json_encode(['ok' => false, 'error' => 'Team must be 1 or 2']);
    exit;
}

// Verify caller is in the room
$state = Room::get($room_id);
if (!$state || !isset($state['players'][$player_id])) {
    echo json_encode(['ok' => false, 'error' => 'Not in room']);
    exit;
}

Room::setTeam($room_id, $player_id, $team);
echo json_encode(['ok' => true]);
