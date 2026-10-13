#!/usr/bin/env php
<?php
/**
 * sync-cards.php — download the MechaTag card data from Google Sheets and
 * regenerate server/data/cards/modules.json.
 *
 * Usage:  php tools/sync-cards.php
 */

define('MODULES_CSV_URL',
    'https://docs.google.com/spreadsheets/d/e/' .
    '2PACX-1vQYixM7NqCPCDOBE7TlpTizYIwZ87UJXZPGNn4wragvM1P6F6LyW2EzB6xu1d9wIbNfAJxKe7z4mSIM' .
    '/pub?output=csv');

define('MODULES_OUT', __DIR__ . '/../server/data/cards/modules.json');

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

// ── Write JSON ────────────────────────────────────────────────────────────────

$json = json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents(MODULES_OUT, $json);

echo "  Written " . count($modules) . " modules → " . realpath(MODULES_OUT) . "\n";
echo "Done.\n";
