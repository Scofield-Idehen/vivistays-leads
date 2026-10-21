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
