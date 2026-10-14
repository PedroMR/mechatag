<?php

/**
 * Version of what the browser loaded with the room page: the page itself (with its
 * inline JS), the stylesheet and the card data embedded in it. Polls compare it to
 * the version the page was served with, so open tabs learn they're out of date.
 */
function client_version(): string {
    $files = [
        __DIR__ . '/../room.php',
        __DIR__ . '/../css/style.css',
        __DIR__ . '/../data/cards/modules.json',
    ];
    clearstatcache();
    return (string)max(array_map('filemtime', $files));
}
