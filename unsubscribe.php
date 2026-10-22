<?php
// One-click unsubscribe for newsletter emails. GET shows a button (link scanners only GET),
// POST unsubscribes (also used by mail apps' built-in "Unsubscribe" via List-Unsubscribe-Post).
define('VL_APP', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/newsletter.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$e = strtolower(trim((string)($_GET['e'] ?? $_POST['e'] ?? '')));
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$ok = $e && hash_equals(nl_token($e), $t);
$done = false;
if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    nl_db()->prepare("UPDATE nl_contacts SET status = 'unsubscribed', note = 'Unsubscribed by link', updated = ? WHERE email = ?")->execute([gmdate('c'), $e]);
    $done = true;
}
$h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe – ViVi Stays</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f1ec;font:17px/1.5 Arial,Helvetica,sans-serif;color:#1d1b18;padding:16px}main{background:#fff;border-radius:10px;padding:32px;max-width:420px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.06)}h1{margin:0 0 10px;font:700 24px Georgia,serif}button{border:0;background:#121214;color:#e4c987;border-radius:8px;padding:12px 18px;font:600 16px Arial,sans-serif;cursor:pointer}</style></head><body><main>
<?php if (!$ok): ?><h1>Link not recognised</h1><p>Please reply to our email with "unsubscribe" and we'll remove you straight away.</p>
<?php elseif ($done): ?><h1>You're unsubscribed</h1><p><?= $h($e) ?> won't get any more emails from us. Sorry to see you go.</p>
<?php else: ?><h1>Unsubscribe?</h1><p>Stop ViVi Stays emails to <strong><?= $h($e) ?></strong>.</p>
<form method="post"><input type="hidden" name="e" value="<?= $h($e) ?>"><input type="hidden" name="t" value="<?= $h($t) ?>"><button type="submit">Unsubscribe</button></form>
<?php endif; ?></main></body></html>
