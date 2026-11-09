<?php
// Shared helpers: settings, sign-in session and the database.
if (!defined('VL_APP')) { http_response_code(403); exit; }

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    http_response_code(500);
    exit('Setup needed: in cPanel File Manager, copy config.example.php to config.local.php and set your passwords.');
}

const VL_COLLECTIONS = ['leads', 'posts', 'groups', 'contacts', 'projects', 'social', 'photos', 'nl_campaigns', 'manuals', 'shoot', 'licence', 'prefs', 'onboard'];

function vl_start_session() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('vivistays_leads');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function vl_user() {
    return $_SESSION['vl_user'] ?? null;
}

function vl_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . $dir . '/leads.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS docs (col TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, updated TEXT NOT NULL, PRIMARY KEY (col, id))');
    // Import data/seed.json if present: adds any records not already in the database
    // (existing records are never overwritten), then renames the file so it runs once.
    $seed = $dir . '/seed.json';
    if (is_file($seed)) {
        $all = json_decode(file_get_contents($seed), true) ?: [];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)');
        $pdo->beginTransaction();
        // "__update__" merges fields into records that already exist (used to correct details).
        $upd = $pdo->prepare('UPDATE docs SET data = ?, updated = ? WHERE col = ? AND id = ?');
        $get = $pdo->prepare('SELECT data FROM docs WHERE col = ? AND id = ?');
        foreach (($all['__update__'] ?? []) as $col => $docs) {
            if (!in_array($col, VL_COLLECTIONS, true)) continue;
            foreach ($docs as $id => $patch) {
                $get->execute([$col, $id]); $row = $get->fetchColumn();
                if ($row === false || !is_array($patch)) continue;
                $upd->execute([json_encode(array_merge(json_decode($row, true) ?: [], $patch), JSON_UNESCAPED_UNICODE), gmdate('c'), $col, $id]);
            }
        }
        unset($all['__update__']);
        foreach ($all as $col => $docs) {
            if (!in_array($col, VL_COLLECTIONS, true)) continue;
            foreach ($docs as $id => $data) $ins->execute([$col, $id, json_encode($data, JSON_UNESCAPED_UNICODE), gmdate('c')]);
        }
        $pdo->commit();
        @rename($seed, $dir . '/seed.imported-' . gmdate('Ymd-His') . '.json');
    }
    return $pdo;
}

// ---------- Slack (guest preferences go to the cleaners' channel) ----------
// The Incoming Webhook URL is a secret. It comes from $SLACK_WEBHOOK_URL in config.local.php
// if set there, otherwise from the app (Guest prefs > Slack settings), which keeps it in
// data/slack-webhook.txt (never in GitHub, never served: data/ is denied by .htaccess).
function vl_slack_file() { return __DIR__ . '/data/slack-webhook.txt'; }
function vl_slack_webhook() {
    global $SLACK_WEBHOOK_URL;
    if (!empty($SLACK_WEBHOOK_URL)) return trim($SLACK_WEBHOOK_URL);
    $f = vl_slack_file();
    return is_file($f) ? trim((string)@file_get_contents($f)) : '';
}
function vl_slack_source() {
    global $SLACK_WEBHOOK_URL;
    if (!empty($SLACK_WEBHOOK_URL)) return 'config';
    return vl_slack_webhook() !== '' ? 'app' : '';
}
function vl_slack_set($url) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    if ($url === '') { @unlink(vl_slack_file()); return; }
    file_put_contents(vl_slack_file(), $url, LOCK_EX);
    @chmod(vl_slack_file(), 0600);
}
// Slack treats & < > specially in message text.
function vl_slack_esc($s) { return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string)$s); }
// Per-property channels: each route is {match, url}. A property whose name contains
// `match` (case-insensitive, longest match wins) posts to that channel's webhook.
// Kept in data/slack-routes.json (secret, never served, never in GitHub).
function vl_slack_routes_file() { return __DIR__ . '/data/slack-routes.json'; }
function vl_slack_routes() {
    $f = vl_slack_routes_file();
    $r = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    return is_array($r) ? array_values(array_filter($r, fn($x) => !empty($x['match']) && (!empty($x['url']) || !empty($x['channel'])))) : [];
}
function vl_slack_routes_save($routes) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    usort($routes, fn($a, $b) => strnatcasecmp($a['match'], $b['match']));
    file_put_contents(vl_slack_routes_file(), json_encode(array_values($routes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(vl_slack_routes_file(), 0600);
}
function vl_slack_norm($s) { return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string)$s))); }
// The route that matches a property name, or null.
function vl_slack_route_for($property) {
    $p = ' ' . vl_slack_norm($property) . ' ';
    $best = null;
    foreach (vl_slack_routes() as $r) {
        $m = vl_slack_norm($r['match']);
        if ($m !== '' && strpos($p, " $m ") !== false && (!$best || strlen($m) > strlen(vl_slack_norm($best['match'])))) $best = $r;
    }
    return $best;
}
// No route set up for a property: with the bot token, find the Slack channel named after it
// (e.g. "1-baronet" for Baronet, "16-roxburgh-court" for 16 Roxburgh Court). Channel list cached for an hour.
function vl_slack_channels($fresh = false) {
    $f = __DIR__ . '/data/slack-channels.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!$fresh && is_array($c) && time() - (int)($c['at'] ?? 0) < 3600) return $c['list'] ?? [];
    $list = []; $cursor = '';
    for ($i = 0; $i < 10; $i++) {
        $j = vl_slack_api('conversations.list', ['types' => 'public_channel,private_channel', 'exclude_archived' => true, 'limit' => 1000] + ($cursor ? ['cursor' => $cursor] : []));
        if (empty($j['ok'])) { if (!$list && is_array($c)) return $c['list'] ?? []; break; }
        foreach ($j['channels'] ?? [] as $ch) $list[] = ['id' => $ch['id'], 'name' => $ch['name'], 'member' => !empty($ch['is_member']), 'private' => !empty($ch['is_private'])];
        $cursor = $j['response_metadata']['next_cursor'] ?? '';
        if ($cursor === '') break;
    }
    if ($list) @file_put_contents($f, json_encode(['at' => time(), 'list' => $list]));
    return $list;
}
function vl_slack_guess_channel($property) {
    if (vl_slack_bot_token() === '' || trim((string)$property) === '') return null;
    $abbr = ['rd' => 'road', 'st' => 'street', 'ave' => 'avenue', 'av' => 'avenue', 'cres' => 'crescent', 'pl' => 'place', 'sq' => 'square', 'gdns' => 'gardens', 'ct' => 'court', 'dr' => 'drive', 'ln' => 'lane', 'tce' => 'terrace'];
    $split = function ($s) use ($abbr) { $w = array_map(fn($x) => $abbr[$x] ?? $x, array_filter(explode(' ', vl_slack_norm(str_replace(['-', '_'], ' ', $s))), 'strlen')); $n = array_values(array_filter($w, 'ctype_digit')); return [array_values(array_diff($w, $n, ['the', 'flat', 'house', 'caravan', 'lodge', 'new'])), $n]; };
    [$pw, $pn] = $split($property);
    if (!$pw) return null;
    $best = null; $bestScore = 0; $tie = false;
    foreach (vl_slack_channels() as $ch) {
        [$cw, $cn] = $split($ch['name']);
        if (!$cw) continue;
        $shared = count(array_intersect($pw, $cw));
        // every word of the channel name must be in the property name, or the other way round
        if (!$shared || ($shared < count($cw) && $shared < count($pw))) continue;
        $nums = count(array_intersect($pn, $cn));
        if ($pn && $cn && !$nums && count($cn) && count($pw) <= 2) continue; // "16 Roxburgh" must not land in "7-roxburgh"
        $score = $shared * 3 + $nums * 2 - abs(count($cw) - count($pw));
        if ($score > $bestScore) { $bestScore = $score; $best = $ch; $tie = false; }
        elseif ($score === $bestScore) $tie = true; // two equally good channels: don't guess
    }
    return empty($tie) ? $best : null;
}
// One-time: add the bot to every private channel the person is in (private property channels can't be seen otherwise).
// Uses their User OAuth Token (xoxp-…) once; it is never saved. Returns ['added','already','failed','errors'].
function vl_slack_invite_bot_everywhere($userTok) {
    @set_time_limit(180);
    $me = vl_slack_api('auth.test', new stdClass());
    if (empty($me['ok']) || empty($me['user_id'])) return ['error' => 'bot_token'];
    $botId = $me['user_id'];
    $who = vl_slack_api('auth.test', new stdClass(), $userTok);
    if (empty($who['ok'])) return ['error' => 'user_token:' . ($who['error'] ?? 'unknown')];
    if (($who['team_id'] ?? '') !== ($me['team_id'] ?? '')) return ['error' => 'other_workspace'];
    $added = 0; $already = 0; $failed = 0; $errs = []; $cursor = '';
    for ($i = 0; $i < 15; $i++) {
        $j = vl_slack_api('users.conversations', ['types' => 'private_channel', 'exclude_archived' => true, 'limit' => 200] + ($cursor ? ['cursor' => $cursor] : []), $userTok);
        if (empty($j['ok'])) return ['error' => 'list:' . ($j['error'] ?? 'unknown'), 'needed' => $j['needed'] ?? '', 'provided' => $j['provided'] ?? ''];
        foreach ($j['channels'] ?? [] as $c) {
            $r = vl_slack_api('conversations.invite', ['channel' => $c['id'], 'users' => $botId], $userTok);
            if (!empty($r['ok'])) $added++;
            elseif (($r['error'] ?? '') === 'already_in_channel') $already++;
            else { $failed++; $errs[($r['error'] ?? 'unknown') . (!empty($r['needed']) ? ' (needs ' . $r['needed'] . ')' : '')] = true; }
        }
        $cursor = (string)($j['response_metadata']['next_cursor'] ?? '');
        if ($cursor === '') break;
    }
    return ['added' => $added, 'already' => $already, 'failed' => $failed, 'errors' => array_keys($errs)];
}
// Every property name we know: the guest manuals, plus any property a guest has sent preferences for.
function vl_property_names() {
    $names = [];
    foreach (vl_db()->query("SELECT col, data FROM docs WHERE col IN ('manuals','prefs')") as $row) {
        $d = json_decode($row['data'], true);
        $n = trim((string)($row['col'] === 'manuals' ? ($d['name'] ?? '') : ($d['property'] ?? '')));
        if ($n !== '' && mb_strlen($n) <= 80) $names[vl_slack_norm($n)] = $names[vl_slack_norm($n)] ?? $n;
    }
    $names = array_values($names);
    usort($names, 'strnatcasecmp');
    return $names;
}
// Match every property to its Slack channel by name. With $save, links the ones found that aren't linked yet.
// Returns ['rows' => [{property, linked, channel}], 'channels' => [...], 'saved' => n].
function vl_slack_automatch($save) {
    $chs = vl_slack_channels(true);
    $byId = []; foreach ($chs as $c) $byId[$c['id']] = $c;
    $routes = vl_slack_routes(); $rows = []; $saved = 0;
    foreach (vl_property_names() as $p) {
        $mine = null;
        foreach ($routes as $r) if (vl_slack_norm($r['match']) === vl_slack_norm($p)) $mine = $r;
        if ($mine && !empty($mine['channel'])) {
            $c = $byId[ltrim($mine['channel'], '#')] ?? null;
            if (!$c) foreach ($chs as $x) if ($x['name'] === ltrim($mine['channel'], '#')) $c = $x;
            $rows[] = ['property' => $p, 'linked' => true, 'channel' => $c ?: ['id' => '', 'name' => ltrim(vl_slack_label($mine), '#'), 'private' => false, 'member' => true]];
            continue;
        }
        $g = vl_slack_guess_channel($p);
        if ($g && $save) { $routes[] = ['match' => $p, 'channel' => $g['id'], 'name' => $g['name']]; $saved++; }
        $rows[] = ['property' => $p, 'linked' => (bool)($g && $save), 'channel' => $g];
    }
    if ($saved) vl_slack_routes_save($routes);
    usort($chs, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return ['rows' => $rows, 'channels' => $chs, 'saved' => $saved];
}
// Webhook for a property: its own channel, else the default channel, else ''.
function vl_slack_url_for($property) {
    $r = vl_slack_route_for($property);
    return $r ? vl_slack_target($r) : vl_slack_webhook();
}
// Where a route posts: a Slack channel (#name or ID, via the bot token) or a webhook URL.
function vl_slack_target($r) { return !empty($r['channel']) ? $r['channel'] : ($r['url'] ?? ''); }
// Short label for the app: "#baronet" or "webhook …abcd". Never the full URL.
function vl_slack_label($r) { return !empty($r['channel']) ? (!empty($r['name']) ? '#' . ltrim($r['name'], '#') : $r['channel']) : 'webhook …' . substr((string)($r['url'] ?? ''), -4); }
// Bot scopes the Slack app needs so it can post in every property channel:
//  chat:write (post), chat:write.public (post in public channels without being invited),
//  channels:read + groups:read (list public/private channels to link them), channels:join (join public ones).
const VL_SLACK_SCOPES = ['chat:write', 'chat:write.public', 'channels:read', 'groups:read', 'channels:join'];
function vl_slack_scopes_file() { return __DIR__ . '/data/slack-scopes.txt'; }
// Scopes the saved token has, as Slack reported on the last check ('' = never checked).
function vl_slack_scopes_known() { $f = vl_slack_scopes_file(); return is_file($f) ? trim((string)@file_get_contents($f)) : ''; }
// Ask Slack which scopes the token has; saves them and returns the missing ones (null if Slack didn't answer).
function vl_slack_check_scopes() {
    $j = vl_slack_api('auth.test', new stdClass());
    if (empty($j['ok'])) return null;
    $have = (string)($GLOBALS['vl_slack_last_scopes'] ?? '');
    @file_put_contents(vl_slack_scopes_file(), $have, LOCK_EX); @chmod(vl_slack_scopes_file(), 0600);
    return vl_slack_missing($have);
}
function vl_slack_missing($have) {
    if ($have === '') return [];
    $h = array_map('trim', explode(',', $have));
    return array_values(array_diff(VL_SLACK_SCOPES, $h));
}
// Bot token (xoxb-…) lets one Slack app post to any channel. Kept in data/slack-bot.txt.
function vl_slack_bot_file() { return __DIR__ . '/data/slack-bot.txt'; }
function vl_slack_bot_token() {
    global $SLACK_BOT_TOKEN;
    if (!empty($SLACK_BOT_TOKEN)) return trim($SLACK_BOT_TOKEN);
    $f = vl_slack_bot_file();
    return is_file($f) ? trim((string)@file_get_contents($f)) : '';
}
function vl_slack_bot_set($tok) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    if ($tok === '') { @unlink(vl_slack_bot_file()); return; }
    file_put_contents(vl_slack_bot_file(), $tok, LOCK_EX);
    @chmod(vl_slack_bot_file(), 0600);
}
// Calls the Slack Web API with the bot token. Returns the decoded reply (ok=false on any error).
function vl_slack_api($method, $payload, $tok = null) {
    if ($tok === null) $tok = vl_slack_bot_token();
    if ($tok === '') return ['ok' => false, 'error' => 'no_token'];
    $ch = curl_init('https://slack.com/api/' . $method);
    $GLOBALS['vl_slack_last_scopes'] = '';
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
        CURLOPT_HEADERFUNCTION => function ($c, $line) {
            if (stripos($line, 'x-oauth-scopes:') === 0) $GLOBALS['vl_slack_last_scopes'] = trim(substr($line, 15));
            return strlen($line);
        },
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Authorization: Bearer ' . $tok],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); curl_close($ch);
    $j = $res !== false ? json_decode($res, true) : null;
    return is_array($j) ? $j : ['ok' => false, 'error' => 'no_response'];
}
// Post a message. Returns 'sent', 'not_configured' or 'failed'. $url defaults to the default channel.
function vl_slack_post($text, $url = null) {
    if ($url === null) $url = vl_slack_webhook();
    if ($url !== '' && strpos($url, 'https://') !== 0) {
        // A channel name or ID: post with the bot token.
        $chn = ltrim($url, '#');
        $j = vl_slack_api('chat.postMessage', ['channel' => $chn, 'text' => $text]);
        if (empty($j['ok']) && ($j['error'] ?? '') === 'not_in_channel' && preg_match('/^C[A-Z0-9]+$/', $chn)) {
            // Public channel the bot isn't in yet (and no chat:write.public): join it, then try again.
            $jj = vl_slack_api('conversations.join', ['channel' => $chn]);
            if (!empty($jj['ok'])) $j = vl_slack_api('chat.postMessage', ['channel' => $chn, 'text' => $text]);
        }
        return !empty($j['ok']) ? 'sent' : ((($j['error'] ?? '') === 'no_token') ? 'not_configured' : 'failed');
    }
    if ($url === '') return 'not_configured';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($res !== false && $code >= 200 && $code < 300) ? 'sent' : 'failed';
}

// ---------- Onboarding (owner/property pipeline) ----------
// Secret key in the Jotform webhook URL, so only our form can add cards.
function vl_ob_key() {
    $f = __DIR__ . '/data/ob-secret.txt';
    if (!is_file($f)) @file_put_contents($f, bin2hex(random_bytes(12)));
    return trim((string)@file_get_contents($f));
}
