<?php
// Shared helpers: settings, sign-in session and the database.
if (!defined('VL_APP')) { http_response_code(403); exit; }

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    http_response_code(500);
    exit('Setup needed: in cPanel File Manager, copy config.example.php to config.local.php and set your passwords.');
}

const VL_COLLECTIONS = ['leads', 'posts', 'groups', 'contacts', 'projects', 'social', 'photos', 'nl_campaigns', 'manuals', 'shoot', 'licence', 'prefs'];

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
    return is_array($r) ? array_values(array_filter($r, fn($x) => !empty($x['match']) && !empty($x['url']))) : [];
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
// Webhook for a property: its own channel, else the default channel, else ''.
function vl_slack_url_for($property) {
    $r = vl_slack_route_for($property);
    return $r ? $r['url'] : vl_slack_webhook();
}
// Post a message. Returns 'sent', 'not_configured' or 'failed'. $url defaults to the default channel.
function vl_slack_post($text, $url = null) {
    if ($url === null) $url = vl_slack_webhook();
    if ($url === '') return 'not_configured';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($res !== false && $code >= 200 && $code < 300) ? 'sent' : 'failed';
}
