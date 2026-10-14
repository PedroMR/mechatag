<?php
ini_set('display_errors', '0');
ob_start();
require_once __DIR__ . '/../lib/Room.php';
require_once __DIR__ . '/../lib/Draft.php';
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Fight.php';
ob_end_clean();  // discard any warnings printed during require
header('Content-Type: application/json');

$player_id    = $_COOKIE['player_id'] ?? null;
$room_id      = strtoupper(trim($_GET['room_id'] ?? ''));
$since_global = max(0, (int)($_GET['since_global'] ?? 0));
$since_team   = max(0, (int)($_GET['since_team']   ?? 0));

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

// Update last_seen
Room::heartbeat($room_id, $player_id);
// Re-read so the heartbeat is reflected in the response
$state = Room::get($room_id);

$team         = $state['players'][$player_id]['team'] ?? null;
$team_channel = $team ? 'team' . $team : null;

echo json_encode([
    'ok'   => true,
    'room' => Room::publicState($state, $player_id),
    'chat' => [
        'global' => Room::getMessages($room_id, 'global', $since_global),
        'team'   => $team_channel ? Room::getMessages($room_id, $team_channel, $since_team) : [],
    ],
    'draft'       => Draft::publicState($state, $player_id),
    'config'      => Config::publicState($state, $player_id),
    'config_ready'=> ($state['phase'] === 'config') ? ($state['config']['ready'] ?? ['1'=>false,'2'=>false]) : null,
    'fight'       => Fight::publicState($state, $player_id),
]);
