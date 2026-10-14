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
if (!$state) { echo json_encode(['ok' => false, 'error' => 'Room not found']); exit; }
if (!isset($state['players'][$player_id])) { echo json_encode(['ok' => false, 'error' => 'Not in room']); exit; }
if ($state['phase'] !== 'config') { echo json_encode(['ok' => false, 'error' => 'Not in config phase']); exit; }

$team = $state['players'][$player_id]['team'] ?? null;
if ($team === null) { echo json_encode(['ok' => false, 'error' => 'Not on a team']); exit; }

FileStore::update(Room::statePath($room_id), function ($s) use ($team) {
    $team_key = (string)$team;
    // Toggle this team's readiness
    $s['config']['ready'][$team_key] = !($s['config']['ready'][$team_key] ?? false);

    // If both teams are ready, start the fight
    if (($s['config']['ready']['1'] ?? false) && ($s['config']['ready']['2'] ?? false)) {
        $s = Fight::start($s);
    }

    return $s;
});

echo json_encode(['ok' => true]);
