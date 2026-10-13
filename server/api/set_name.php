<?php
header('Content-Type: application/json');

$name = trim($_POST['name'] ?? '');
if ($name === '' || strlen($name) > 32) {
    echo json_encode(['ok' => false, 'error' => 'Name must be 1–32 characters']);
    exit;
}

$player_id = $_COOKIE['player_id'] ?? bin2hex(random_bytes(16));

// Non-httponly so PHP can also read them back; SameSite=Lax for basic CSRF protection
setcookie('player_id',   $player_id, ['expires' => time() + 7 * 86400, 'path' => '/', 'samesite' => 'Lax']);
setcookie('player_name', $name,      ['expires' => time() + 7 * 86400, 'path' => '/', 'samesite' => 'Lax']);

echo json_encode(['ok' => true, 'player_id' => $player_id, 'name' => $name]);
