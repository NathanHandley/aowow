<?php
// EQWOW: aowow caches DBC files into dbc_* tables and only reloads a table when it is
// missing/empty (CLISetup::loadDBC). After overlaying freshly generated DBCs, these caches
// must be dropped or --sql/--build silently regenerate from the OLD client data.
// Called by eqwow-viewer-update.ps1 / eqwow-viewer-update.sh before `php aowow --sql`.

$AoWoWconf = [];
require __DIR__ . '/config/config.php';

$c = $AoWoWconf['aowow'];
$m = new mysqli($c['host'], $c['user'], $c['pass'], $c['db']);
if ($m->connect_error)
    die('eqwow-drop-dbc-cache: cannot connect to aowow db: ' . $m->connect_error . "\n");

$res     = $m->query("SHOW TABLES LIKE 'dbc\\_%'");
$dropped = 0;
while ($row = $res->fetch_row())
    if ($m->query('DROP TABLE `' . $row[0] . '`'))
        $dropped++;

echo 'eqwow-drop-dbc-cache: dropped ' . $dropped . " cached dbc_* tables (will reload from setup/mpqdata)\n";
