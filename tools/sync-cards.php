#!/usr/bin/env php
<?php
/**
 * sync-cards.php — download the MechaTag card data from Google Sheets and
 * regenerate server/data/cards/modules.json and the action cards in
 * server/data/cards/pilots.json.
 *
 * Usage:  php tools/sync-cards.php
 */

define('MODULES_CSV_URL',
    'https://docs.google.com/spreadsheets/d/e/' .
    '2PACX-1vQYixM7NqCPCDOBE7TlpTizYIwZ87UJXZPGNn4wragvM1P6F6LyW2EzB6xu1d9wIbNfAJxKe7z4mSIM' .
    '/pub?gid=487914339&single=true&output=csv');

define('ACTIONS_CSV_URL',
    'https://docs.google.com/spreadsheets/d/e/' .
    '2PACX-1vQYixM7NqCPCDOBE7TlpTizYIwZ87UJXZPGNn4wragvM1P6F6LyW2EzB6xu1d9wIbNfAJxKe7z4mSIM' .
    '/pub?gid=1926521739&single=true&output=csv');

define('MODULES_OUT', __DIR__ . '/../server/data/cards/modules.json');
define('PILOTS_OUT',  __DIR__ . '/../server/data/cards/pilots.json');

// ── Helpers ──────────────────────────────────────────────────────────────────

function fetch_csv(string $url): string {
    $ctx = stream_context_create(['http' => [
        'method'          => 'GET',
        'follow_location' => 1,
        'max_redirects'   => 5,
        'timeout'         => 30,
        'header'          => 'User-Agent: mechatag-sync/1.0',
    ]]);
    $body = file_get_contents($url, false, $ctx);
    if ($body === false) die("Failed to fetch: $url\n");
    return $body;
}

function parse_csv(string $raw): array {
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $headers = fgetcsv($fh);
    $rows = [];
    while (($cols = fgetcsv($fh)) !== false) {
        if (count($cols) < 2) continue;
        // pad short rows so array_combine won't fail
        $cols = array_pad($cols, count($headers), '');
        $rows[] = array_combine($headers, $cols);
    }
    fclose($fh);
    return $rows;
}

function int_or_null(string $val): ?int {
    $val = trim($val);
    return $val !== '' ? (int)$val : null;
}

// ── Fetch & parse ─────────────────────────────────────────────────────────────

echo "Fetching Modules sheet…\n";
$csv  = fetch_csv(MODULES_CSV_URL);
$rows = parse_csv($csv);
echo "  " . count($rows) . " rows received.\n";

// ── Build modules array ───────────────────────────────────────────────────────

$modules = [];

foreach ($rows as $row) {
    $id = trim($row['id'] ?? '');
    if ($id === '') continue;                 // skip blank / header-repeat rows

    // Parts slot: "arm,torso,legs" → ['arm','torso','legs']
    $parts_str = trim($row['parts'] ?? '');
    $parts = $parts_str !== '' ? array_map('trim', explode(',', $parts_str)) : [];

    // AOE: 0 = entire tile, 1 = neighboring tiles, '' = none
    $aoe_raw = trim($row['aoe'] ?? '');
    $aoe = match($aoe_raw) {
        '0'     => 'tile',
        '1'     => 'neighbor',
        default => null,
    };

    $module = [
        'id'                  => $id,
        'name'                => trim($row['name']),
        'tier'                => (int)trim($row['tier']),
        'copies'              => (int)trim($row['Copies']),
        'type'                => trim($row['type']),
        'parts'               => $parts,
        'effects'             => trim($row['effects'] ?? ''),
        'hardpoints_offered'  => (int)trim($row['hardpoints offered'] ?? '0'),
        'hardpoints_required' => (int)trim($row['hardpoints'] ?? '0'),
    ];

    // Optional numeric fields — omit key entirely when blank
    $energy_min = int_or_null($row['energy cost'] ?? '');
    $energy_max = int_or_null($row['max energy']  ?? '');
    $range      = trim($row['range'] ?? '');

    if ($energy_min !== null) $module['energy_min'] = $energy_min;
    if ($energy_max !== null) $module['energy_max'] = $energy_max;
    if ($range      !== '')   $module['range']      = $range;
    if ($aoe        !== null) $module['aoe']        = $aoe;

    $modules[] = $module;
}

// ── Check for duplicate IDs ───────────────────────────────────────────────────

$seen_ids = [];
$has_dupes = false;
foreach ($modules as $m) {
    if (isset($seen_ids[$m['id']])) {
        echo "  WARNING: duplicate id '{$m['id']}' ('{$seen_ids[$m['id']]}' and '{$m['name']}')\n";
        $has_dupes = true;
    }
    $seen_ids[$m['id']] = $m['name'];
}
if ($has_dupes) {
    echo "  Fix duplicate IDs in the sheet before the data is usable.\n";
}

// ── Write JSON ────────────────────────────────────────────────────────────────

$json = json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents(MODULES_OUT, $json);

echo "  Written " . count($modules) . " modules → " . realpath(MODULES_OUT) . "\n";

// ── Pilot action cards ────────────────────────────────────────────────────────
// The sheet lays out one row per card per pilot for printing; every pilot gets
// the same deck, so each card id is taken once (warning if its rows disagree).
// The pilot list in pilots.json is kept as is.

echo "Fetching Pilot Actions sheet…\n";
$rows = parse_csv(fetch_csv(ACTIONS_CSV_URL));
echo "  " . count($rows) . " rows received.\n";

$actions = [];
foreach ($rows as $row) {
    $id = trim($row['id'] ?? '');
    if ($id === '') continue;
    $card = [
        'id'     => $id,
        'name'   => trim($row['name']),
        'copies' => (int)trim($row['copies']),
        // "IMP +1" → "IMP+1", the form the fight engine parses
        'effect' => preg_replace('/\b(IMP|PRJ|LSR|EXP)\s+\+(\d+)/', '$1+$2', trim($row['effect'])),
    ];
    if (isset($actions[$id]) && $actions[$id] !== $card) {
        echo "  WARNING: rows for action '$id' disagree; keeping the first: " . json_encode($actions[$id]) . "\n";
        continue;
    }
    $actions[$id] = $card;
}

$pilots = json_decode(file_get_contents(PILOTS_OUT), true);
$pilots['action_cards'] = array_values($actions);
file_put_contents(PILOTS_OUT, json_encode($pilots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

$deck_size = array_sum(array_column($actions, 'copies'));
echo "  Written " . count($actions) . " action cards ($deck_size per pilot) → " . realpath(PILOTS_OUT) . "\n";
echo "Done.\n";
