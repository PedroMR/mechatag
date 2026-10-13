<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Room.php';
require_once __DIR__ . '/../lib/Config.php';

$player_id = $_COOKIE['player_id'] ?? null;
$room_id   = strtoupper(trim($_POST['room_id'] ?? ''));
$mech_idx  = (int)($_POST['mech_idx'] ?? -1);
$slot      = trim($_POST['slot'] ?? '');
$card_id   = trim($_POST['card_id'] ?? '');

if (!$player_id || !preg_match('/^[A-F0-9]{6}$/', $room_id) || $mech_idx < 0 || $mech_idx > 1 || !$slot || !$card_id) {
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}

$state = Room::get($room_id);
if (!$state) { echo json_encode(['ok' => false, 'error' => 'Room not found']); exit; }
if (!isset($state['players'][$player_id])) { echo json_encode(['ok' => false, 'error' => 'Not in room']); exit; }
if ($state['phase'] !== 'config') { echo json_encode(['ok' => false, 'error' => 'Not in config phase']); exit; }

FileStore::update(Room::statePath($room_id), function ($s) use ($player_id, $mech_idx, $slot, $card_id) {
    return Config::assignCard($s, $player_id, $mech_idx, $slot, $card_id);
});

echo json_encode(['ok' => true]);
