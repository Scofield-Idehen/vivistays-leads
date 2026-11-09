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

        case 'onboard_meta':
            // The Jotform webhook URL (with its secret key) for the Onboarding tab.
            $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
            out(['hook' => $base . '/onboard-hook.php?k=' . vl_ob_key()]);

        case 'licence_meta':
            require_once __DIR__ . '/licence.php';
            $items = LIC_ITEMS;
            foreach ($items as $k => $it) if (count($it['councils']) === 3) $items[$k]['councils'][] = 'other';
            out(['items' => $items, 'councils' => LIC_COUNCILS, 'questions' => LIC_QUESTIONS]);

        case 'licence_new':
            require_once __DIR__ . '/licence.php';
            $council = (string)($in['council'] ?? '');
            if (!isset(LIC_COUNCILS[$council])) fail('Pick the council');
            $cname = trim(mb_substr((string)($in['councilName'] ?? ''), 0, 80));
            if ($council === 'other' && $cname === '') fail('Type the council name (e.g. Argyll and Bute Council)');
            $prop = trim(mb_substr((string)($in['property'] ?? ''), 0, 120));
            if ($prop === '') fail('Enter the property');
            $o = $in['owner'] ?? [];
            $id = bin2hex(random_bytes(10));
            $doc = ['property' => $prop, 'address' => trim(mb_substr((string)($in['address'] ?? ''), 0, 200)), 'council' => $council, 'councilName' => $council === 'other' ? $cname : '',
                'owner' => ['name' => trim(mb_substr((string)($o['name'] ?? ''), 0, 80)), 'email' => trim(mb_substr((string)($o['email'] ?? ''), 0, 120)), 'phone' => trim(mb_substr((string)($o['phone'] ?? ''), 0, 40))],
                'stage' => 'collecting', 'items' => new stdClass(), 'info' => new stdClass(),
                'chase' => ['auto' => true, 'every' => max(1, min(14, (int)($in['every'] ?? 3))), 'started' => gmdate('c'), 'count' => 0],
                'log' => [['at' => gmdate('c'), 'by' => $user, 'what' => 'Application started']], 'created' => gmdate('c'), 'by' => $user];
            lic_put($id, json_decode(json_encode($doc), true));
            out(['id' => $id, 'link' => lic_link($id)]);

        case 'licence_link':
            require_once __DIR__ . '/licence.php';
            $id = id_ok($in['id'] ?? '');
            out(['link' => lic_link($id)]);

        case 'licence_chase':
            require_once __DIR__ . '/licence.php';
            $id = id_ok($in['id'] ?? '');
            $doc = lic_get($id); if (!$doc) fail('Not found', 404);
            if (($in['channel'] ?? '') === 'whatsapp') {
                $doc['chase']['lastWhatsApp'] = gmdate('c');
                $doc['log'][] = ['at' => gmdate('c'), 'by' => $user, 'what' => 'WhatsApp reminder sent'];
                lic_put($id, $doc);
                out(['ok' => true]);
            }
            $r = lic_chase_email($id, $doc);
            if (!empty($r['skipped'])) fail('Not sent: ' . $r['skipped']);
            out(['ok' => true]);

        case 'licence_upload':
            require_once __DIR__ . '/licence.php';
            $id = id_ok($in['id'] ?? ''); $item = (string)($in['item'] ?? '');
            $raw = base64_decode(preg_replace('/^data:[^;]+;base64,/', '', (string)($in['data'] ?? '')), true);
            if ($raw === false) fail('Bad file');
            $tmp = tempnam(sys_get_temp_dir(), 'lic'); file_put_contents($tmp, $raw);
            try { lic_store_file($id, $item, $tmp, (string)($in['name'] ?? ''), $user); } catch (Throwable $e) { @unlink($tmp); fail($e->getMessage()); }
            out(['ok' => true]);

        case 'prefs_meta':
            // Field labels, the Guesty link template and whether Slack is set up (never the URL itself).
            require_once __DIR__ . '/prefs.php';
            $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
            $fields = [];
            foreach (PREF_FIELDS as $k => $f) $fields[] = ['k' => $k, 'label' => $f['label'], 'short' => $f['short'] ?? $f['label'], 'default' => $f['default'] ?? ''];
            out(['fields' => $fields, 'template' => pref_template($base), 'slack' => vl_slack_source(),
                 'routes' => array_map(fn($r) => ['match' => $r['match'], 'tail' => vl_slack_label($r)], vl_slack_routes()), 'bot' => vl_slack_bot_token() !== '',
                 'scopes' => VL_SLACK_SCOPES, 'missing' => vl_slack_bot_token() !== '' ? vl_slack_missing(vl_slack_scopes_known()) : []]);

        case 'prefs_invite_all':
            // One-time: with the person's User OAuth Token, add the bot to every channel they're in, then link properties.
            require_once __DIR__ . '/prefs.php';
            if (vl_slack_bot_token() === '') fail('Save the Slack bot token first');
            $ut = trim((string)($in['user_token'] ?? ''));
            if (!preg_match('/^xoxp-[A-Za-z0-9-]{20,250}$/', $ut)) fail('That is not a User OAuth Token (it starts xoxp-)');
            $r = vl_slack_invite_bot_everywhere($ut);
            if (isset($r['error'])) {
                $e = $r['error'];
                if ($e === 'other_workspace') fail('That user token is for a different Slack workspace.');
                if (strpos($e, 'missing_scope') !== false) fail('That user token is missing a permission. Slack says it needs: ' . ($r['needed'] ?: 'groups:read') . '. The token you pasted only has: ' . ($r['provided'] ?: 'nothing') . '. Add the missing one under User Token Scopes, click Reinstall to Workspace, then copy the User OAuth Token again (it changes after reinstalling).');
                fail('Slack said: ' . $e);
            }
            $m = vl_slack_automatch(true);
            out($r + ['linked' => $m['saved'], 'rows' => $m['rows'], 'channels' => $m['channels'],
                 'routes' => array_map(fn($x) => ['match' => $x['match'], 'tail' => vl_slack_label($x)], vl_slack_routes())]);

        case 'prefs_broadcast':
            // Post one message to every linked property channel (once per channel). Returns counts and failures.
            require_once __DIR__ . '/prefs.php';
            $text = trim((string)($in['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 3000) fail('Write the message first (up to 3000 characters)');
            if (vl_slack_bot_token() === '') fail('Save the Slack bot token first');
            @set_time_limit(300);
            // Sent in batches (from, count) so one request stays well under the server's time limit.
            $from = max(0, (int)($in['from'] ?? 0)); $count = min(25, max(1, (int)($in['count'] ?? 20)));
            $targets = []; $names = [];
            foreach (vl_slack_routes() as $r) { $t = vl_slack_target($r); if ($t !== '' && !isset($targets[$t])) { $targets[$t] = $r; } }
            // Or a hand-picked list of channel IDs (only = ['C…', …]).
            if (!empty($in['only']) && is_array($in['only'])) {
                $targets = [];
                foreach (array_slice($in['only'], 0, 200) as $cid) if (is_string($cid) && preg_match('/^[CG][A-Z0-9]{6,20}$/', $cid)) $targets[$cid] = ['match' => $cid, 'channel' => $cid];
            }
            $all = array_values($targets); $total = count($all);
            $sent = 0; $failed = [];
            foreach (array_slice($all, $from, $count) as $r) {
                $t = vl_slack_target($r);
                if (vl_slack_post(vl_slack_esc($text), $t) === 'sent') $sent++; else $failed[] = $r['match'];
                usleep(300000); // stay gentle with Slack's rate limits
            }
            out(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'total' => $total, 'next' => $from + $count < $total ? $from + $count : null]);

        case 'prefs_automatch':
            // Match every property (guest manuals) to its Slack channel by name; save:true links the ones found.
            require_once __DIR__ . '/prefs.php';
            if (vl_slack_bot_token() === '') fail('Save the Slack bot token first');
            $missing = vl_slack_check_scopes();
            if ($missing === null) fail('Slack did not answer. Check the bot token.');
            $m = vl_slack_automatch(!empty($in['save']));
            if (!$m['channels']) fail(array_intersect(['channels:read', 'groups:read'], $missing) ? 'The Slack app needs the channels:read and groups:read scopes. Add them, reinstall the app, then paste the new token.' : 'Slack returned no channels. Check the app is installed in the workspace.');
            out($m + ['missing' => $missing, 'routes' => array_map(fn($r) => ['match' => $r['match'], 'tail' => vl_slack_label($r)], vl_slack_routes())]);

        case 'prefs_bot':
            // Save / clear / check the Slack bot token. Never returns the token.
            require_once __DIR__ . '/prefs.php';
            if (array_key_exists('token', $in)) {
                $tok = trim((string)$in['token']);
                if ($tok !== '' && !preg_match('/^xoxb-[A-Za-z0-9-]{20,250}$/', $tok)) fail('That is not a Slack bot token (it starts xoxb-)');
                $old = vl_slack_bot_token();
                vl_slack_bot_set($tok);
                if ($tok !== '') {
                    $j = vl_slack_api('auth.test', new stdClass());
                    if (empty($j['ok'])) { vl_slack_bot_set($old); fail('Slack rejected the token (' . ($j['error'] ?? 'unknown') . '). Check you copied the Bot User OAuth Token.'); }
                    $have = (string)($GLOBALS['vl_slack_last_scopes'] ?? '');
                    @file_put_contents(vl_slack_scopes_file(), $have, LOCK_EX); @chmod(vl_slack_scopes_file(), 0600);
                    // Straight away, link every property whose channel is named after it.
                    $auto = 0; try { $auto = vl_slack_automatch(true)['saved']; } catch (Throwable $e) {}
                    out(['ok' => true, 'bot' => true, 'team' => $j['team'] ?? '', 'missing' => vl_slack_missing($have), 'autoLinked' => $auto,
                         'routes' => array_map(fn($r) => ['match' => $r['match'], 'tail' => vl_slack_label($r)], vl_slack_routes())]);
                }
                @unlink(vl_slack_scopes_file());
            }
            if (!empty($in['check'])) {
                $m = vl_slack_check_scopes();
                if ($m === null) fail('Slack did not accept the token. Paste it again.');
                out(['ok' => true, 'bot' => true, 'missing' => $m]);
            }
            out(['ok' => true, 'bot' => vl_slack_bot_token() !== '', 'missing' => []]);

        case 'prefs_routes':
            // Per-property Slack channels. add {match,url} / remove {match} / test {match}. Never returns URLs.
            require_once __DIR__ . '/prefs.php';
            $match = trim(preg_replace('/\s+/', ' ', (string)($in['match'] ?? '')));
            if (($in['op'] ?? '') === 'link_many') {
                // Link many channels at once: links = [{match, channel (ID), name}]. Replaces any route with the same property.
                if (vl_slack_bot_token() === '') fail('Save the Slack bot token first');
                $links = is_array($in['links'] ?? null) ? array_slice($in['links'], 0, 300) : [];
                $routes = vl_slack_routes(); $n = 0;
                foreach ($links as $l) {
                    $m = trim(preg_replace('/\s+/', ' ', (string)($l['match'] ?? '')));
                    $id = (string)($l['channel'] ?? ''); $nm = strtolower(trim((string)($l['name'] ?? ''), '# '));
                    if ($m === '' || mb_strlen($m) > 80 || vl_slack_norm($m) === '' || !preg_match('/^[CG][A-Z0-9]{6,20}$/', $id) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $nm)) continue;
                    $k = vl_slack_norm($m);
                    $routes = array_values(array_filter($routes, fn($r) => vl_slack_norm($r['match']) !== $k));
                    $routes[] = ['match' => $m, 'channel' => $id, 'name' => $nm]; $n++;
                }
                if (!$n) fail('Nothing to link. Give each ticked channel a property name.');
                vl_slack_routes_save($routes);
                out(['ok' => true, 'linked' => $n, 'routes' => array_map(fn($r) => ['match' => $r['match'], 'tail' => vl_slack_label($r)], vl_slack_routes())]);
            }
            if ($match === '' || mb_strlen($match) > 80) fail('Enter the property name (or a word from it), e.g. Baronet');
            $routes = vl_slack_routes();
            $key = vl_slack_norm($match);
            $others = array_values(array_filter($routes, fn($r) => vl_slack_norm($r['match']) !== $key));
            $mine = array_values(array_filter($routes, fn($r) => vl_slack_norm($r['match']) === $key));
            $op = (string)($in['op'] ?? '');
            if ($op === 'add' && trim((string)($in['channel'] ?? '')) !== '') {
                $chn = trim((string)$in['channel']);
                if (!preg_match('/^#?[a-z0-9][a-z0-9._-]{0,79}$/i', $chn)) fail('Enter the Slack channel name, e.g. #baronet');
                if (vl_slack_bot_token() === '') fail('Save the Slack bot token first');
                if (strlen(ltrim($chn, '#')) < 9 || !preg_match('/^[CG][A-Z0-9]+$/', ltrim($chn, '#'))) $chn = '#' . strtolower(ltrim($chn, '#'));
                $others[] = ['match' => $match, 'channel' => $chn];
                vl_slack_routes_save($others);
            } elseif ($op === 'add') {
                $url = trim((string)($in['url'] ?? ''));
                if (!preg_match('#^https://hooks\.slack\.com/(services|workflows|triggers)/[A-Za-z0-9/_\-]{10,200}$#', $url)) fail('That is not a Slack incoming webhook URL (it starts https://hooks.slack.com/services/…)');
                $others[] = ['match' => $match, 'url' => $url];
                vl_slack_routes_save($others);
            } elseif ($op === 'remove') {
                vl_slack_routes_save($others);
            } elseif ($op === 'test') {
                if (!$mine) fail('No channel saved for that property');
                $r = vl_slack_post('Test from ViVi Stays Leads: guest preferences for *' . vl_slack_esc($mine[0]['match']) . '* will be posted in this channel.', vl_slack_target($mine[0]));
                if ($r !== 'sent') fail(!empty($mine[0]['channel']) ? 'Slack did not accept it. Check the channel name, and for a private channel invite the app (/invite @ViVi Guest Prefs).' : 'Slack did not accept the message. Check the webhook URL.');
            } else fail('Unknown request');
            out(['ok' => true, 'routes' => array_map(fn($r) => ['match' => $r['match'], 'tail' => vl_slack_label($r)], vl_slack_routes())]);

        case 'prefs_settings':
            // Save, clear or test the Slack webhook. Replies only with whether it is set.
            require_once __DIR__ . '/prefs.php';
            if (!empty($in['test'])) {
                $r = vl_slack_post('Test from ViVi Stays Leads: guest preferences will be posted in this channel. (' . vl_slack_esc($user) . ')');
                if ($r !== 'sent') fail($r === 'not_configured' ? 'Slack is not set up yet' : 'Slack did not accept the message. Check the webhook URL.');
                out(['ok' => true, 'slack' => vl_slack_source()]);
            }
            if (vl_slack_source() === 'config') fail('The webhook is set in config.local.php on the server. Change it there.');
            $url = trim((string)($in['slack_webhook'] ?? ''));
            if ($url !== '' && !preg_match('#^https://hooks\.slack\.com/(services|workflows|triggers)/[A-Za-z0-9/_\-]{10,200}$#', $url)) fail('That is not a Slack incoming webhook URL (it starts https://hooks.slack.com/services/…)');
            vl_slack_set($url);
            out(['ok' => true, 'slack' => vl_slack_source()]);

        case 'prefs_resend':
            require_once __DIR__ . '/prefs.php';
            $id = id_ok($in['id'] ?? '');
            $doc = pref_get($id); if (!$doc) fail('Not found', 404);
            if (isset($in['channel'])) { $doc['channelOverride'] = trim((string)$in['channel']); if (!empty($in['property']) && empty($doc['property'])) $doc['property'] = mb_substr(trim((string)$in['property']), 0, 120); }
            $r = pref_send($id, $doc, !empty($in['updated']) || (int)($doc['submissions'] ?? 1) > 1);
            if ($r === 'not_configured') fail('No Slack channel for this property. Add one under Slack channels, or set a default channel.');
            if ($r === 'failed') fail('Slack did not accept the message. Check the webhook URL and try again.');
            out(['ok' => true]);

        case 'shoot_link':
            require_once __DIR__ . '/newsletter.php';
            $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
            out(['url' => $base . '/shoot.php?k=' . substr(hash_hmac('sha256', 'shoot-days-link', nl_secret()), 0, 20), 'sync' => $base . '/shoot.php?s=' . substr(hash_hmac('sha256', 'shoot-sync', nl_secret()), 0, 24)]);

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
