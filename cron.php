<?php
// Pinged every 15 minutes by GitHub (.github/workflows/stayful-cron.yml) to pull new
// Stayful leads even when nobody has the app open. Safe to leave public: it only
// reads from Stayful, runs at most once every 10 minutes, and reveals nothing.
define('VL_APP', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/stayful.php';
require __DIR__ . '/newsletter.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$r = vl_stayful_sync(false);
// Newsletter: send a slice of what's due, on UK weekdays 8am-6pm only, within the daily limit.
$uk = new DateTime('now', new DateTimeZone('Europe/London'));
$nl = null;
if ((int)$uk->format('N') <= 5 && (int)$uk->format('G') >= 8 && (int)$uk->format('G') < 18) {
    try { $nl = nl_run(40); } catch (Throwable $e) { $nl = ['error' => 'newsletter']; }
}
echo json_encode(['ok' => empty($r['error']), 'added' => (int)($r['added'] ?? 0), 'newsletterSent' => (int)($nl['sent'] ?? 0)]);
