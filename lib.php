<?php
// Shared helpers: settings, sign-in session and the database.
if (!defined('VL_APP')) { http_response_code(403); exit; }

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    http_response_code(500);
    exit('Setup needed: in cPanel File Manager, copy config.example.php to config.local.php and set your passwords.');
}

const VL_COLLECTIONS = ['leads', 'posts', 'groups', 'contacts', 'projects'];

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
