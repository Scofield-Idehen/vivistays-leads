<?php
// Brevo transactional webhook: opens, clicks, bounces, unsubscribes and spam reports.
// The URL (with its secret key) is shown in the app's Newsletter tab.
define('VL_APP', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/newsletter.php';
header('Content-Type: application/json');
if (!hash_equals(nl_hook_key(), (string)($_GET['k'] ?? ''))) { http_response_code(403); exit('{"error":"forbidden"}'); }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); exit('{"error":"bad body"}'); }
$events = isset($in['event']) ? [$in] : $in;
foreach (array_slice($events, 0, 1000) as $ev) if (is_array($ev)) nl_event($ev);
echo '{"ok":true}';
