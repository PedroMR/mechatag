<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';

$player_id = $_COOKIE['player_id']   ?? null;
$name      = $_COOKIE['player_name'] ?? 'Anonymous';
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));
$channel   = $_POST['channel'] ?? 'global'; // 'global' or 'team'
$text      = trim($_POST['text'] ?? '');

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id)) {
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}
if ($text === '') {
    echo json_encode(['ok' => false, 'error' => 'Message cannot be empty']);
    exit;
}
if (strlen($text) > 500) {
    echo json_encode(['ok' => false, 'error' => 'Message too long (max 500 chars)']);
    exit;
}

$state = Room::get($room_id);
if (!$state || !isset($state['players'][$player_id])) {
    echo json_encode(['ok' => false, 'error' => 'Not in room']);
    exit;
}

if ($channel === 'team') {
    $team = $state['players'][$player_id]['team'];
    if (!$team) {
        echo json_encode(['ok' => false, 'error' => 'Join a team to use team chat']);
        exit;
    }
    $channel = 'team' . $team;
} elseif ($channel !== 'global') {
    echo json_encode(['ok' => false, 'error' => 'Invalid channel']);
    exit;
}

Room::sendChat($room_id, $channel, $player_id, $name, $text);
echo json_encode(['ok' => true]);
