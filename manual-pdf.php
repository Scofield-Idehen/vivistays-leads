<?php
// Download one guest-manual PDF. Signed-in users only.
define('VL_APP', true);
require __DIR__ . '/lib.php';
vl_start_session();
if (!vl_user()) { http_response_code(401); exit('Please sign in first.'); }
session_write_close();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $id)) { http_response_code(400); exit('Bad id'); }
$path = __DIR__ . "/data/manuals/$id.pdf";
if (!is_file($path)) { http_response_code(404); exit('No PDF uploaded for this property yet.'); }
$name = $id . '.pdf';
$st = vl_db()->prepare('SELECT data FROM docs WHERE col = ? AND id = ?'); $st->execute(['manuals', $id]);
if (($row = $st->fetchColumn()) !== false) { $d = json_decode($row, true) ?: []; if (!empty($d['file'])) $name = $d['file']; }
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$inline = isset($_GET['view']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
readfile($path);
