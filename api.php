<?php
// Data and AI endpoint for the Vivistays Leads page. Only signed-in users can use it.
define('VL_APP', true);
require __DIR__ . '/lib.php';
vl_start_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code = 400, $errCode = 'error') { out(['error' => $msg, 'code' => $errCode], $code); }

$user = vl_user();
if (!$user) fail('Not signed in', 401, 'not_signed_in');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') fail('Bad request', 400);
session_write_close(); // don't block other requests while this one runs

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

function col_ok($c) { if (!in_array($c, VL_COLLECTIONS, true)) fail('Unknown list'); return $c; }
function id_ok($id) { if (!is_string($id) || !preg_match('/^[A-Za-z0-9_\-.~:@+]{1,200}$/', $id)) fail('Bad id'); return $id; }
function data_ok($d) { if (!is_array($d)) fail('Bad data'); $j = json_encode($d, JSON_UNESCAPED_UNICODE); if (strlen($j) > 200000) fail('Too large'); return $d; }

try {
    $db = vl_db();
    switch ($action) {
        case 'list':
            $col = col_ok($in['collection'] ?? '');
            $st = $db->prepare('SELECT id, data FROM docs WHERE col = ?');
            $st->execute([$col]);
            $docs = [];
            foreach ($st as $r) $docs[] = ['id' => $r['id'], 'data' => json_decode($r['data'], true)];
            out(['docs' => $docs]);

        case 'add':
            $col = col_ok($in['collection'] ?? '');
            $data = data_ok($in['data'] ?? null);
            $id = bin2hex(random_bytes(10));
            $db->prepare('INSERT INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)')->execute([$col, $id, json_encode($data, JSON_UNESCAPED_UNICODE), gmdate('c')]);
            out(['id' => $id]);

        case 'set':
            $col = col_ok($in['collection'] ?? ''); $id = id_ok($in['id'] ?? '');
            $data = data_ok($in['data'] ?? null);
            $db->prepare('INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)')->execute([$col, $id, json_encode($data, JSON_UNESCAPED_UNICODE), gmdate('c')]);
            out(['ok' => true]);

        case 'update':
            $col = col_ok($in['collection'] ?? ''); $id = id_ok($in['id'] ?? '');
            $patch = data_ok($in['data'] ?? null);
            $db->beginTransaction();
            $st = $db->prepare('SELECT data FROM docs WHERE col = ? AND id = ?');
            $st->execute([$col, $id]);
            $row = $st->fetchColumn();
            if ($row === false) { $db->rollBack(); fail('Not found', 404, 'not_found'); }
            $doc = array_merge(json_decode($row, true) ?: [], $patch);
            $db->prepare('UPDATE docs SET data = ?, updated = ? WHERE col = ? AND id = ?')->execute([json_encode(data_ok($doc), JSON_UNESCAPED_UNICODE), gmdate('c'), $col, $id]);
            $db->commit();
            out(['ok' => true]);

        case 'nl_stats': require_once __DIR__ . '/newsletter.php'; out(nl_stats());
        case 'nl_import':
            require_once __DIR__ . '/newsletter.php';
            $em = $in['emails'] ?? null; if (!is_array($em) || count($em) > 5000) fail('Send up to 5,000 emails at a time');
            out(nl_import($em));
        case 'nl_list':
            require_once __DIR__ . '/newsletter.php';
            out(nl_list(trim((string)($in['q'] ?? '')), (string)($in['status'] ?? ''), (string)($in['segment'] ?? ''), (string)($in['campaign'] ?? ''), (int)($in['offset'] ?? 0)));
        case 'nl_set':
            require_once __DIR__ . '/newsletter.php';
            out(['ok' => nl_set((string)($in['email'] ?? ''), (string)($in['status'] ?? ''), isset($in['note']) ? mb_substr((string)$in['note'], 0, 300) : null)]);
        case 'nl_preview':
            require_once __DIR__ . '/newsletter.php';
            out(['html' => nl_html((string)($in['subject'] ?? ''), (string)($in['body'] ?? ''), 'jane.smith@example.com')]);
        case 'nl_test':
            require_once __DIR__ . '/newsletter.php';
            try { nl_test((string)($in['campaign'] ?? ''), (int)($in['step'] ?? 0), (string)($in['to'] ?? '')); out(['ok' => true]); }
            catch (Throwable $e) { fail($e->getMessage()); }
        case 'nl_run':
            require_once __DIR__ . '/newsletter.php';
            out(nl_run(min(100, max(1, (int)($in['max'] ?? 50))), ($in['campaign'] ?? '') ?: null));

        case 'photo_upload':
            // A photo for the Posts flyers. The browser shrinks it first; we re-encode it
            // as a fresh JPEG (drops anything hidden in the file) and keep it in img/u/.
            $area = $in['area'] ?? '';
            if (!in_array($area, ['seton', 'thurston', 'borders', 'edinburgh'], true)) fail('Pick which place the photo is from');
            $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 80);
            $raw = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', (string)($in['data'] ?? '')), true);
            if ($raw === false || strlen($raw) < 1000) fail('That file is not a photo');
            if (strlen($raw) > 8 * 1024 * 1024) fail('Photo is too large (8 MB max)');
            if (!function_exists('imagecreatefromstring')) fail('Photo uploads need the PHP GD extension on the server', 500);
            $im = @imagecreatefromstring($raw);
            if (!$im) fail('That file is not a photo');
            $w = imagesx($im); $h = imagesy($im);
            if ($w < 600 || $h < 400) { imagedestroy($im); fail('Photo is too small. Use one at least 600 px wide.'); }
            $max = 2400;
            if ($w > $max || $h > $max) {
                $s = $max / max($w, $h); $nw = (int)round($w * $s); $nh = (int)round($h * $s);
                $res = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($res, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($im); $im = $res; $w = $nw; $h = $nh;
            }
            $dir = __DIR__ . '/img/u';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $id = bin2hex(random_bytes(10));
            if (!imagejpeg($im, "$dir/$id.jpg", 86)) { imagedestroy($im); fail('Could not save the photo', 500); }
            imagedestroy($im);
            $doc = ['area' => $area, 'label' => $label, 'src' => "img/u/$id.jpg", 'w' => $w, 'h' => $h, 'by' => $user, 'created' => gmdate('c')];
            $db->prepare('INSERT INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)')->execute(['photos', $id, json_encode($doc, JSON_UNESCAPED_UNICODE), gmdate('c')]);
            out(['id' => $id, 'src' => $doc['src']]);

        case 'photo_delete':
            $id = id_ok($in['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{20}$/', $id)) fail('Bad id');
            $db->prepare('DELETE FROM docs WHERE col = ? AND id = ?')->execute(['photos', $id]);
            @unlink(__DIR__ . "/img/u/$id.jpg");
            out(['ok' => true]);

        case 'manual_pdf_chunk':
            // A guest-manual PDF, sent in pieces (each well under the server's upload limit).
            // Stored in data/manuals/ which the web server never serves directly.
            $id = (string)($in['id'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $id)) fail('Bad manual id');
            $idx = (int)($in['idx'] ?? -1); $total = (int)($in['total'] ?? 0);
            if ($idx < 0 || $total < 1 || $total > 400 || $idx >= $total) fail('Bad chunk');
            $raw = base64_decode((string)($in['data'] ?? ''), true);
            if ($raw === false) fail('Bad chunk data');
            $dir = __DIR__ . '/data/manuals';
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $tmp = "$dir/$id.part";
            if ($idx === 0) @unlink($tmp);
            elseif (!is_file($tmp) || (int)($in['offset'] ?? -1) !== filesize($tmp)) fail('Upload out of order, please retry');
            file_put_contents($tmp, $raw, FILE_APPEND | LOCK_EX);
            if ($idx < $total - 1) out(['ok' => true]);
            if (filesize($tmp) > 60 * 1024 * 1024) { @unlink($tmp); fail('PDF is too large (60 MB max)'); }
            $fh = fopen($tmp, 'rb'); $head = fread($fh, 5); fclose($fh);
            if ($head !== '%PDF-') { @unlink($tmp); fail('That file is not a PDF'); }
            rename($tmp, "$dir/$id.pdf");
            $file = mb_substr(preg_replace('/[\\\\\/:*?"<>|\r\n]/', '', (string)($in['file'] ?? '')), 0, 150);
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
            $st = $db->prepare('SELECT data FROM docs WHERE col = ? AND id = ?'); $st->execute(['manuals', $id]);
            $row = $st->fetchColumn();
            $doc = $row !== false ? (json_decode($row, true) ?: []) : ['name' => $name ?: $id, 'page' => 3, 'sections' => []];
            if ($file) $doc['file'] = $file;
            $doc['pdfAt'] = gmdate('c'); $doc['pdfSize'] = filesize("$dir/$id.pdf"); $doc['pdfBy'] = $user;
            $db->prepare('INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)')->execute(['manuals', $id, json_encode($doc, JSON_UNESCAPED_UNICODE), gmdate('c')]);
            out(['ok' => true, 'size' => $doc['pdfSize']]);

        case 'manual_pull':
            // Fetch an encrypted manual PDF from this project's GitHub release, decrypt it with the
            // key sent by the signed-in user (AES-256-GCM: 12-byte nonce + ciphertext + 16-byte tag), store it.
            $id = (string)($in['id'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $id)) fail('Bad manual id');
            $url = (string)($in['url'] ?? '');
            if (!preg_match('#^https://raw\.githubusercontent\.com/Scofield-Idehen/vivistays-leads/manuals-transfer/enc/[a-z0-9-]+\.bin$#', $url)) fail('Bad source');
            $key = hex2bin((string)($in['key'] ?? ''));
            if ($key === false || strlen($key) !== 32) fail('Bad key');
            if (!function_exists('openssl_decrypt')) fail('Server needs the PHP openssl extension', 500);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'vivistays-leads']);
            $blob = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($blob === false || $code !== 200 || strlen($blob) < 40) fail('Could not download (' . $code . ')');
            $pdf = openssl_decrypt(substr($blob, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, -16));
            if ($pdf === false || substr($pdf, 0, 5) !== '%PDF-') fail('Could not decrypt');
            $dir = __DIR__ . '/data/manuals';
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            file_put_contents("$dir/$id.pdf.tmp", $pdf); rename("$dir/$id.pdf.tmp", "$dir/$id.pdf");
            $file = mb_substr(preg_replace('/[\\\\\/:*?"<>|\r\n]/', '', (string)($in['file'] ?? '')), 0, 150);
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
            $st = $db->prepare('SELECT data FROM docs WHERE col = ? AND id = ?'); $st->execute(['manuals', $id]);
            $row = $st->fetchColumn();
            $doc = $row !== false ? (json_decode($row, true) ?: []) : ['name' => $name ?: $id, 'page' => 3, 'sections' => []];
            if ($file) $doc['file'] = $file;
            $doc['pdfAt'] = gmdate('c'); $doc['pdfSize'] = strlen($pdf); $doc['pdfBy'] = $user;
            $db->prepare('INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)')->execute(['manuals', $id, json_encode($doc, JSON_UNESCAPED_UNICODE), gmdate('c')]);
            out(['ok' => true, 'size' => strlen($pdf)]);

        case 'delete':
            $col = col_ok($in['collection'] ?? ''); $id = id_ok($in['id'] ?? '');
            $db->prepare('DELETE FROM docs WHERE col = ? AND id = ?')->execute([$col, $id]);
            out(['ok' => true]);

        case 'ai':
            global $ANTHROPIC_API_KEY, $MODEL_QUICK, $MODEL_DEFAULT;
            if (!$ANTHROPIC_API_KEY) fail('AI is not set up', 403, 'not_granted');
            $prompt = $in['prompt'] ?? '';
            if (!is_string($prompt) || $prompt === '') fail('Empty prompt');
            $prompt = mb_substr($prompt, 0, 20000);
            $model = ($in['tier'] ?? '') === 'quick' ? $MODEL_QUICK : $MODEL_DEFAULT;
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => [
                    'content-type: application/json',
                    'x-api-key: ' . $ANTHROPIC_API_KEY,
                    'anthropic-version: 2023-06-01',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $model,
                    'max_tokens' => 8000,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]),
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($res === false) fail('Could not reach the AI service', 502, 'network');
            $j = json_decode($res, true);
            if ($code === 429) fail('Too many requests', 429, 'rate_limited');
            if ($code !== 200) fail($j['error']['message'] ?? 'AI error', 502, 'ai_error');
            $text = '';
            foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
            out(['text' => $text, 'stop' => $j['stop_reason'] ?? '']);

        case 'stayful':
            require_once __DIR__ . '/stayful.php';
            out(vl_stayful_sync(!empty($in['force'])));

        default:
            fail('Unknown action');
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fail('Server error', 500, 'server');
}
