<?php
// Pinged every 15 minutes by GitHub (.github/workflows/stayful-cron.yml) to pull new
// Stayful leads even when nobody has the app open. Safe to leave public: it only
// reads from Stayful, runs at most once every 10 minutes, and reveals nothing.
define('VL_APP', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/stayful.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$r = vl_stayful_sync(false);
echo json_encode(['ok' => empty($r['error']), 'added' => (int)($r['added'] ?? 0)]);
