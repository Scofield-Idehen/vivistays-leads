<?php
// Newsletter: email campaigns to contractor / insurance lists, sent through Brevo,
// with a weekly follow-up sequence and open / click / reply tracking.
// Needs $BREVO_API_KEY in config.local.php (Brevo > SMTP & API > API keys).
// Optional: $NL_FROM_EMAIL, $NL_FROM_NAME, $NL_REPLY_TO, $NL_DAILY_LIMIT, $NL_ADDRESS, $NL_PUBLIC_URL.
if (!defined('VL_APP')) { http_response_code(403); exit; }

const NL_SEGMENTS = [
    'insurance' => 'Insurance & claims',
    'construction' => 'Construction & trades',
    'business' => 'Other businesses',
    'public' => 'Councils, NHS & universities',
    'personal' => 'Personal addresses (not emailed)',
    'overseas' => 'Overseas (not emailed)',
];
const NL_EXCLUDED = ['personal', 'overseas'];
const NL_WEBMAIL = ['gmail.com','googlemail.com','hotmail.com','hotmail.co.uk','outlook.com','outlook.co.uk','live.com','live.co.uk','msn.com','yahoo.com','yahoo.co.uk','ymail.com','icloud.com','me.com','mac.com','aol.com','aol.co.uk','btinternet.com','btopenworld.com','sky.com','talktalk.net','virginmedia.com','blueyonder.co.uk','ntlworld.com','tiscali.co.uk','mail.com','gmx.com','gmx.co.uk','protonmail.com','proton.me','zoho.com'];

function nl_db() {
    static $done = false;
    $db = vl_db();
    if (!$done) {
        $db->exec('CREATE TABLE IF NOT EXISTS nl_contacts (email TEXT PRIMARY KEY, segment TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", campaign TEXT NOT NULL DEFAULT "", step INTEGER NOT NULL DEFAULT 0, last_sent TEXT, opens INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0, last_event TEXT, note TEXT, created TEXT NOT NULL, updated TEXT NOT NULL)');
        $db->exec('CREATE INDEX IF NOT EXISTS nl_c_seg ON nl_contacts (segment, status)');
        $db->exec('CREATE INDEX IF NOT EXISTS nl_c_camp ON nl_contacts (campaign, step, last_sent)');
        $db->exec('CREATE TABLE IF NOT EXISTS nl_log (day TEXT PRIMARY KEY, sent INTEGER NOT NULL)');
        $done = true;
    }
    return $db;
}

function nl_secret() {
    $f = __DIR__ . '/data/nl-secret.txt';
    if (!is_file($f)) @file_put_contents($f, bin2hex(random_bytes(24)));
    return trim((string)@file_get_contents($f));
}
function nl_token($email) { return substr(hash_hmac('sha256', strtolower($email), nl_secret()), 0, 20); }
function nl_hook_key() { return substr(hash_hmac('sha256', 'brevo-webhook', nl_secret()), 0, 24); }
function nl_base_url() {
    global $NL_PUBLIC_URL;
    if (!empty($NL_PUBLIC_URL)) return rtrim($NL_PUBLIC_URL, '/');
    $host = $_SERVER['HTTP_HOST'] ?? 'leads.mandexoil.xyz';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return 'https://' . $host . $dir;
}
function nl_cfg() {
    global $BREVO_API_KEY, $NL_FROM_EMAIL, $NL_FROM_NAME, $NL_REPLY_TO, $NL_DAILY_LIMIT, $NL_ADDRESS;
    return [
        'key' => $BREVO_API_KEY ?? '',
        'from' => $NL_FROM_EMAIL ?? 'team@vivistays.com',
        'name' => $NL_FROM_NAME ?? 'ViVi Stays',
        'reply' => $NL_REPLY_TO ?? 'team@vivistays.com',
        'limit' => max(1, (int)($NL_DAILY_LIMIT ?? 150)),
        'address' => $NL_ADDRESS ?? '',
    ];
}

function nl_segment($email) {
    $d = substr(strrchr($email, '@'), 1);
    if (in_array($d, NL_WEBMAIL, true)) return 'personal';
    if (preg_match('/\.(nz|au|ca|za|in|us|sg|ae|de|fr|es|it|nl|ie)$/', $d) || in_array($d, ['raywhite.com'], true)) return 'overseas';
    if (preg_match('/\.(ac|gov|nhs|sch|police|mod)\.uk$/', $d)) return 'public';
    if (preg_match('/insur|allianz|aviva|axa|zurich|travelers|archinsurance|chubb|hiscox|rsagroup|ageas|claims|loss|crawford|sedgwick|cunningham|davies|qbe|markel|ecclesiastical|nfumutual|broker|adjust|direct-?line|admiral|lv\.com|churchill|esure|hastings/', $d)) return 'insurance';
    if (preg_match('/construct|build|civil|contract|engineer|electric|plumb|rail|scaff|groundwork|roof|mech|steel|demolit|energy|power|infra|joiner|joinery|plant|drain|utilit|surfac|paving|fencing/', $d)) return 'construction';
    return 'business';
}
function nl_clean($e) {
    $e = strtolower(trim(str_replace(["\xc2\xa0", "\t", ' ', '<', '>', '"', "'"], '', (string)$e)));
    $e = preg_replace('/^mailto:/', '', $e);
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}
function nl_first_name($email) {
    $local = explode('@', $email)[0];
    if (preg_match('/^([a-z]{2,15})[._-][a-z]{2,}/', $local, $m) && !preg_match('/^(info|admin|office|sales|enquir|hello|contact|team|accounts|mail|support|claims|service|reception|bookings|jobs|hr)/', $m[1])) return ucfirst($m[1]);
    return 'there';
}

function nl_import($emails) {
    $db = nl_db(); $now = gmdate('c');
    $ins = $db->prepare('INSERT OR IGNORE INTO nl_contacts (email, segment, status, created, updated) VALUES (?, ?, ?, ?, ?)');
    $added = 0; $dup = 0; $bad = 0; $seg = [];
    $db->beginTransaction();
    foreach ((array)$emails as $raw) {
        $e = nl_clean($raw);
        if (!$e) { $bad++; continue; }
        $s = nl_segment($e);
        $ins->execute([$e, $s, in_array($s, NL_EXCLUDED, true) ? 'excluded' : 'active', $now, $now]);
        if ($ins->rowCount()) { $added++; $seg[$s] = ($seg[$s] ?? 0) + 1; } else $dup++;
    }
    $db->commit();
    return ['added' => $added, 'duplicates' => $dup, 'invalid' => $bad, 'bySegment' => $seg];
}

function nl_campaigns($onlyActive = false) {
    $out = [];
    foreach (vl_db()->query("SELECT id, data FROM docs WHERE col = 'nl_campaigns'") as $r) {
        $c = json_decode($r['data'], true) ?: [];
        $c['id'] = $r['id'];
        if (!$onlyActive || ($c['status'] ?? '') === 'active') $out[$c['id']] = $c;
    }
    return $out;
}

function nl_stats() {
    $db = nl_db();
    $seg = [];
    foreach ($db->query('SELECT segment, status, COUNT(*) n FROM nl_contacts GROUP BY segment, status') as $r) $seg[$r['segment']][$r['status']] = (int)$r['n'];
    $camp = [];
    foreach ($db->query('SELECT campaign, step, status, COUNT(*) n, SUM(opens > 0) o, SUM(clicks > 0) c FROM nl_contacts WHERE campaign <> "" GROUP BY campaign, step, status') as $r) {
        $k = $r['campaign'];
        $camp[$k] = $camp[$k] ?? ['contacted' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'archived' => 0, 'steps' => []];
        $camp[$k]['contacted'] += $r['n'];
        $camp[$k]['opened'] += $r['o'];
        $camp[$k]['clicked'] += $r['c'];
        if (isset($camp[$k][$r['status']])) $camp[$k][$r['status']] += $r['n'];
        $camp[$k]['steps'][$r['step']] = ($camp[$k]['steps'][$r['step']] ?? 0) + $r['n'];
    }
    $today = $db->query("SELECT sent FROM nl_log WHERE day = '" . gmdate('Y-m-d') . "'")->fetchColumn();
    $cfg = nl_cfg();
    return ['segments' => $seg, 'campaigns' => $camp, 'sentToday' => (int)$today, 'dailyLimit' => $cfg['limit'],
            'configured' => (bool)$cfg['key'], 'from' => $cfg['from'], 'fromName' => $cfg['name'], 'replyTo' => $cfg['reply'],
            'hasAddress' => (bool)$cfg['address'],
            'webhookUrl' => nl_base_url() . '/nl-hook.php?k=' . nl_hook_key(), 'labels' => NL_SEGMENTS];
}

function nl_list($q, $status, $segment, $campaign, $offset) {
    $db = nl_db(); $w = []; $a = [];
    if ($q !== '') { $w[] = 'email LIKE ?'; $a[] = '%' . $q . '%'; }
    if ($status === 'engaged') $w[] = "(clicks > 0 OR status = 'replied')";
    elseif ($status !== '') { $w[] = 'status = ?'; $a[] = $status; }
    if ($segment !== '') { $w[] = 'segment = ?'; $a[] = $segment; }
    if ($campaign !== '') { $w[] = 'campaign = ?'; $a[] = $campaign; }
    $sql = 'FROM nl_contacts' . ($w ? ' WHERE ' . implode(' AND ', $w) : '');
    $n = $db->prepare("SELECT COUNT(*) $sql"); $n->execute($a);
    $st = $db->prepare("SELECT email, segment, status, campaign, step, last_sent, opens, clicks, last_event, note $sql ORDER BY (clicks > 0) DESC, updated DESC LIMIT 100 OFFSET " . max(0, (int)$offset));
    $st->execute($a);
    return ['total' => (int)$n->fetchColumn(), 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

function nl_set($email, $status, $note) {
    $ok = ['active', 'replied', 'unsubscribed', 'bounced', 'archived', 'excluded'];
    if (!in_array($status, $ok, true)) return false;
    $st = nl_db()->prepare('UPDATE nl_contacts SET status = ?, note = COALESCE(?, note), updated = ? WHERE email = ?');
    $st->execute([$status, $note, gmdate('c'), strtolower($email)]);
    return $st->rowCount() > 0;
}

// Plain text with blank-line paragraphs, "• " bullets, **bold** and bare links -> simple, safe HTML.
function nl_html($subject, $body, $email) {
    $cfg = nl_cfg();
    $esc = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $body = str_replace('{{first_name}}', nl_first_name($email), $body);
    $blocks = preg_split("/\n\s*\n/", trim($body));
    $html = '';
    foreach ($blocks as $b) {
        $lines = explode("\n", trim($b));
        $isList = count(array_filter($lines, fn($l) => preg_match('/^\s*[•\-\*]\s+/', $l))) === count($lines);
        $fmt = function ($t) use ($esc) {
            $t = $esc($t);
            $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
            return preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#8a6420">$1</a>', $t);
        };
        if ($isList) {
            $html .= '<ul style="margin:0 0 16px;padding-left:20px">';
            foreach ($lines as $l) $html .= '<li style="margin:0 0 6px">' . $fmt(preg_replace('/^\s*[•\-\*]\s+/', '', $l)) . '</li>';
            $html .= '</ul>';
        } else {
            $html .= '<p style="margin:0 0 16px">' . implode('<br>', array_map($fmt, $lines)) . '</p>';
        }
    }
    $unsub = nl_base_url() . '/unsubscribe.php?e=' . rawurlencode($email) . '&t=' . nl_token($email);
    $addr = $cfg['address'] ? $esc($cfg['address']) . '<br>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $esc($subject) . '</title></head>'
        . '<body style="margin:0;background:#f4f1ec;font-family:Arial,Helvetica,sans-serif;color:#1d1b18">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ec"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden">'
        . '<tr><td style="background:#121214;padding:18px 28px;font-family:Georgia,serif;font-size:22px;letter-spacing:3px;color:#d9b66f">VIVI STAYS</td></tr>'
        . '<tr><td style="padding:28px;font-size:16px;line-height:1.55">' . $html . '</td></tr>'
        . '<tr><td style="padding:16px 28px 26px;border-top:1px solid #eee;font-size:12px;line-height:1.5;color:#6b665e">'
        . 'ViVi Stays · vivistays.com · WhatsApp +44 7578 255663<br>' . $addr
        . 'You received this because your business email is listed publicly for accommodation and contractor enquiries. '
        . '<a href="' . $esc($unsub) . '" style="color:#6b665e">Unsubscribe</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}
function nl_text($body, $email) {
    $unsub = nl_base_url() . '/unsubscribe.php?e=' . rawurlencode($email) . '&t=' . nl_token($email);
    return str_replace(['{{first_name}}', '**'], [nl_first_name($email), ''], $body) . "\n\n--\nViVi Stays · vivistays.com · WhatsApp +44 7578 255663\nUnsubscribe: " . $unsub;
}

function nl_brevo($payload) {
    $cfg = nl_cfg();
    global $NL_BREVO_URL; // only for testing
    $ch = curl_init($NL_BREVO_URL ?? 'https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $cfg['key']],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false) throw new Exception('Could not reach Brevo');
    if ($code >= 300) { $j = json_decode($res, true); throw new Exception('Brevo: ' . ($j['message'] ?? ('error ' . $code))); }
    return json_decode($res, true);
}
function nl_one($c, $step, $to) {
    $cfg = nl_cfg(); $s = $c['steps'][$step] ?? null;
    if (!$s) throw new Exception('That email step does not exist');
    $unsub = nl_base_url() . '/unsubscribe.php?e=' . rawurlencode($to) . '&t=' . nl_token($to);
    return ['sender' => ['name' => $cfg['name'], 'email' => $cfg['from']], 'replyTo' => ['email' => $cfg['reply']],
        'to' => [['email' => $to]], 'subject' => $s['subject'], 'htmlContent' => nl_html($s['subject'], $s['body'], $to),
        'textContent' => nl_text($s['body'], $to), 'tags' => ['nl-' . $c['id'], 'step-' . ($step + 1)],
        'headers' => ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']];
}

function nl_test($campaignId, $step, $to) {
    $cfg = nl_cfg();
    if (!$cfg['key']) throw new Exception('Brevo is not connected yet');
    $c = nl_campaigns()[$campaignId] ?? null;
    if (!$c) throw new Exception('Campaign not found');
    $to = nl_clean($to); if (!$to) throw new Exception('Enter a valid email for the test');
    $p = nl_one($c, (int)$step, $to); $p['subject'] = '[TEST] ' . $p['subject'];
    nl_brevo($p);
    return true;
}

// Send what is due: first emails to new contacts in the campaign's groups, and the next
// email to anyone whose last one was at least gapDays ago with no reply. After the last
// email plus the gap with no click or reply, the contact is archived.
function nl_run($max = 50, $only = null) {
    $cfg = nl_cfg(); $db = nl_db();
    if (!$cfg['key']) return ['configured' => false];
    $day = gmdate('Y-m-d');
    $sentToday = (int)$db->query("SELECT sent FROM nl_log WHERE day = '$day'")->fetchColumn();
    $room = min($max, $cfg['limit'] - $sentToday);
    $sent = 0; $archived = 0; $errors = [];
    foreach (nl_campaigns(true) as $c) {
        if ($only && $c['id'] !== $only) continue;
        $n = count($c['steps'] ?? []); $gap = max(1, (int)($c['gapDays'] ?? 7));
        $cut = gmdate('c', time() - $gap * 86400);
        $a = $db->prepare("UPDATE nl_contacts SET status = 'archived', note = ?, updated = ? WHERE campaign = ? AND status = 'active' AND step >= ? AND last_sent <= ? AND clicks = 0");
        $a->execute(['No response after ' . $n . ' emails', gmdate('c'), $c['id'], $n, $cut]); $archived += $a->rowCount();
        if ($room - $sent <= 0) continue;
        $segs = array_values(array_diff((array)($c['segments'] ?? []), NL_EXCLUDED));
        if (!$segs) continue;
        $ph = implode(',', array_fill(0, count($segs), '?'));
        $q = $db->prepare("SELECT email, step FROM nl_contacts WHERE status = 'active' AND ((campaign = ? AND step > 0 AND step < ? AND last_sent <= ?) OR (campaign = '' AND step = 0 AND segment IN ($ph))) ORDER BY step DESC, RANDOM() LIMIT " . (int)($room - $sent));
        $q->execute(array_merge([$c['id'], $n, $cut], $segs));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            try { nl_brevo(nl_one($c, (int)$r['step'], $r['email'])); }
            catch (Throwable $e) { $errors[] = $e->getMessage(); if (count($errors) >= 3) break; continue; }
            $db->prepare('UPDATE nl_contacts SET campaign = ?, step = step + 1, last_sent = ?, updated = ? WHERE email = ?')->execute([$c['id'], gmdate('c'), gmdate('c'), $r['email']]);
            $sent++;
            $db->prepare('INSERT OR IGNORE INTO nl_log (day, sent) VALUES (?, 0)')->execute([$day]);
            $db->prepare('UPDATE nl_log SET sent = sent + 1 WHERE day = ?')->execute([$day]);
        }
    }
    return ['configured' => true, 'sent' => $sent, 'archived' => $archived, 'sentToday' => $sentToday + $sent, 'dailyLimit' => $cfg['limit'], 'errors' => array_values(array_unique($errors))];
}

// Brevo webhook events -> contact tracking.
function nl_event($ev) {
    $email = strtolower(trim($ev['email'] ?? '')); $type = $ev['event'] ?? '';
    if (!$email || !$type) return;
    $db = nl_db(); $now = gmdate('c');
    $map = ['opened' => 'opens = opens + 1', 'unique_opened' => 'opens = opens + 1', 'proxy_open' => 'opens = opens + 1', 'click' => 'clicks = clicks + 1'];
    if (isset($map[$type])) { $db->prepare("UPDATE nl_contacts SET {$map[$type]}, last_event = ?, updated = ? WHERE email = ?")->execute([$type, $now, $email]); return; }
    $status = ['hard_bounce' => 'bounced', 'invalid_email' => 'bounced', 'blocked' => 'bounced', 'spam' => 'unsubscribed', 'unsubscribed' => 'unsubscribed'][$type] ?? null;
    if ($status) $db->prepare("UPDATE nl_contacts SET status = ?, last_event = ?, note = ?, updated = ? WHERE email = ? AND status <> 'replied'")->execute([$status, $type, 'Brevo: ' . $type, $now, $email]);
    else $db->prepare('UPDATE nl_contacts SET last_event = ?, updated = ? WHERE email = ?')->execute([$type, $now, $email]);
}
