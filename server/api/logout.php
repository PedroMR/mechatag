<?php
// Clear identity cookies
setcookie('player_id',   '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
setcookie('player_name', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);

header('Location: /');
