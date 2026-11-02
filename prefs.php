<?php
// Guest preferences -> Slack for the cleaners.
//  - Guests open s?c=<confirmation code>&p=<listing nickname>&d=<check-in date> (short alias of prefs.php; old in=/g= params still work)
//    from a Guesty pre-arrival message (no login, no key: Guesty can only fill in its own variables).
//  - Their answers are saved (docs col 'prefs', id = confirmation code) and posted to the cleaners'
//    Slack channel. Sending the form again for the same code replaces the answers and posts "Updated".
//  - cron.php posts a morning digest of tomorrow's arrivals and retries failed Slack posts.
//  - The team sees everything in the app (Guest prefs).
// Never put lock or door codes in anything here: this page and the Slack channel are not the place.
if (!defined('VL_APP')) {
    define('VL_APP', true);
    require __DIR__ . '/lib.php';
    $PREF_PAGE = true;
}

// ---------- the questions (edit here) ----------
// type: select | number | yesno | textarea.  default: the "nothing special" answer; answers equal to it
// are left out of the Slack message.  always: always shown in Slack when answered.  short: Slack label.
const PREF_FIELDS = [
    'arrival'  => ['label' => 'Expected arrival time', 'short' => 'Arrival', 'type' => 'select', 'always' => true,
                   'options' => ['15:00–16:00', '16:00–18:00', '18:00–20:00', 'After 20:00', 'Not sure yet']],
    'guests'   => ['label' => 'Number of guests', 'short' => 'Guests', 'type' => 'number', 'always' => true, 'min' => 1, 'max' => 20],
    'bed'      => ['label' => 'Bed setup', 'short' => 'Beds', 'type' => 'select', 'default' => 'As listed',
                   'options' => ['As listed', 'Twin beds instead of a double, if possible', 'Sofa bed made up'],
                   'note' => 'Only where the property has these options.'],
    'cot'      => ['label' => 'Cot needed', 'short' => 'Cot', 'type' => 'yesno', 'default' => 'No'],
    'highchair'=> ['label' => 'Highchair needed', 'short' => 'Highchair', 'type' => 'yesno', 'default' => 'No'],
    'linen'    => ['label' => 'Extra towels / linen', 'short' => 'Extra towels/linen', 'type' => 'yesno', 'default' => 'No'],
    'dog'      => ['label' => 'Travelling with a dog', 'short' => 'Dog', 'type' => 'yesno', 'default' => 'No',
                   'note' => 'Only where the property is pet-friendly.'],
    'earlylate'=> ['label' => 'Early check-in or late check-out', 'short' => 'Early/late', 'type' => 'select', 'default' => 'No',
                   'options' => ['No', 'Early check-in', 'Late check-out', 'Both'],
                   'note' => "Subject to availability, we'll confirm."],
    'notes'    => ['label' => 'Anything else the team should know?', 'short' => 'Note', 'type' => 'textarea', 'max' => 500,
                   'placeholder' => 'e.g. celebrating a birthday, arriving by train, allergies'],
];
// Per-property changes, keyed by the Guesty listing nickname (case doesn't matter). Empty by default.
// Example:  '22 Aberlady' => ['dog' => ['hide' => true], 'bed' => ['options' => ['As listed', 'Sofa bed made up']]],
const PREF_PROPERTY_OVERRIDES = [];
const PREF_RATE_PER_HOUR = 10;     // form submissions per visitor (IP) per hour
const PREF_DIGEST_FROM = 8;        // morning digest: first UK hour it may go out ...
const PREF_DIGEST_UNTIL = 11;      // ... and the hour it stops trying for the day
const PREF_RETRY_MAX = 12;         // give up re-sending a failed Slack post after this many tries

function pref_fields($property) {
    $f = PREF_FIELDS;
    foreach (PREF_PROPERTY_OVERRIDES as $name => $ov) {
        if (strcasecmp(trim($name), trim((string)$property)) !== 0) continue;
        foreach ($ov as $k => $o) {
            if (!isset($f[$k])) continue;
            if (!empty($o['hide'])) unset($f[$k]); else $f[$k] = array_merge($f[$k], $o);
        }
    }
    return $f;
}
// A Guesty variable that wasn't filled in arrives as "{{...}}": treat it as missing.
function pref_param($v, $max) {
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$v));
    if ($v === '' || strpos($v, '{') !== false || strpos($v, '}') !== false) return '';
    return mb_substr($v, 0, $max);
}
function pref_code_ok($c) { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,39}$/', $c); }
// Check-in date to YYYY-MM-DD. Accepts 2026-11-05, 05/11/2026 (UK order), "Nov 5, 2026", "5 November 2026".
function pref_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/', $raw, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('/[A-Za-z]/', $raw)) { $t = strtotime($raw); if ($t) return date('Y-m-d', $t); }
    return '';
}
function pref_nice_date($iso, $raw = '') {
    if (!$iso) return $raw;
    $d = DateTime::createFromFormat('!Y-m-d', $iso);
    return $d ? $d->format('D j M Y') : $iso;
}
function pref_get($id) {
    $st = vl_db()->prepare("SELECT data FROM docs WHERE col = 'prefs' AND id = ?");
    $st->execute([$id]);
    $r = $st->fetchColumn();
    return $r === false ? null : (json_decode($r, true) ?: []);
}
function pref_put($id, $doc) {
    vl_db()->prepare("INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES ('prefs', ?, ?, ?)")->execute([$id, json_encode($doc, JSON_UNESCAPED_UNICODE), gmdate('c')]);
}
// Clean the posted answers against the field list.
function pref_answers($src, $fields) {
    $a = [];
    foreach ($fields as $k => $f) {
        $v = trim((string)($src[$k] ?? ''));
        if ($f['type'] === 'select') $v = in_array($v, $f['options'], true) ? $v : '';
        elseif ($f['type'] === 'yesno') $v = in_array($v, ['Yes', 'No'], true) ? $v : '';
        elseif ($f['type'] === 'number') $v = ctype_digit($v) && (int)$v >= ($f['min'] ?? 0) && (int)$v <= ($f['max'] ?? 99) ? (string)(int)$v : '';
        else $v = mb_substr(trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', str_replace("\r", '', $v))), 0, $f['max'] ?? 500);
        if ($v !== '') $a[$k] = $v;
    }
    return $a;
}
// The answers worth telling the cleaners: arrival and guest count, plus anything not the default.
function pref_lines($doc) {
    $out = [];
    foreach (pref_fields($doc['property'] ?? '') as $k => $f) {
        $v = $doc['answers'][$k] ?? '';
        if ($v === '' || (!empty($f['default']) && $v === $f['default'])) continue;
        $out[] = [$f['short'] ?? $f['label'], $v];
    }
    return $out;
}
function pref_slack_block($doc) {
    $t = 'Check-in: ' . vl_slack_esc(pref_nice_date($doc['checkin'] ?? '', $doc['checkinRaw'] ?? '') ?: 'not given')
       . ' · Guest: ' . vl_slack_esc(($doc['guest'] ?? '') ?: 'not given') . ' · Code: ' . vl_slack_esc($doc['code'] ?? '');
    $lines = pref_lines($doc);
    foreach ($lines as [$l, $v]) $t .= "\n• " . vl_slack_esc($l) . ': ' . vl_slack_esc(str_replace("\n", ' / ', $v));
    if (!$lines) $t .= "\n• No special requests";
    return $t;
}
function pref_slack_text($doc, $updated) {
    return '*' . ($updated ? 'Updated' : 'New') . ' guest preferences* — ' . vl_slack_esc(($doc['property'] ?? '') ?: 'Property not given') . "\n" . pref_slack_block($doc);
}
// Post one record to Slack and save the outcome on it.
function pref_send($id, $doc, $updated) {
    $route = vl_slack_route_for($doc['property'] ?? '');
    $doc['slackChannel'] = $route ? $route['match'] : (vl_slack_webhook() !== '' ? 'Default' : '');
    $status = vl_slack_post(pref_slack_text($doc, $updated), vl_slack_url_for($doc['property'] ?? ''));
    $doc['slackStatus'] = $status;
    $doc['slackUpdated'] = (bool)$updated;
    if ($status === 'sent') { $doc['slackAt'] = gmdate('c'); $doc['slackTries'] = 0; }
    elseif ($status === 'failed') { $doc['slackTries'] = (int)($doc['slackTries'] ?? 0) + 1; $doc['slackFailedAt'] = gmdate('c'); }
    pref_put($id, $doc);
    return $status;
}
function pref_all() {
    $out = [];
    foreach (vl_db()->query("SELECT id, data FROM docs WHERE col = 'prefs'") as $r) $out[$r['id']] = json_decode($r['data'], true) ?: [];
    return $out;
}
// cron: re-send records whose Slack post failed.
function pref_retry_failed($max = 5) {
    $n = 0;
    foreach (pref_all() as $id => $doc) {
        if ($n >= $max) break;
        if (($doc['slackStatus'] ?? '') !== 'failed' || (int)($doc['slackTries'] ?? 0) >= PREF_RETRY_MAX) continue;
        if (pref_send($id, $doc, !empty($doc['slackUpdated'])) === 'sent') $n++;
    }
    return $n;
}
// cron: once each morning (UK), tomorrow's arrivals that sent preferences.
function pref_digest($force = false) {
    $uk = new DateTime('now', new DateTimeZone('Europe/London'));
    $today = $uk->format('Y-m-d');
    $f = __DIR__ . '/data/prefs-digest.txt';
    if (!$force) {
        $hr = (int)$uk->format('G');
        if ($hr < PREF_DIGEST_FROM || $hr >= PREF_DIGEST_UNTIL) return 'not_time';
        if (trim((string)@file_get_contents($f)) === $today) return 'done_today';
    }
    if (vl_slack_webhook() === '' && !vl_slack_routes()) return 'not_configured';
    $tomorrow = (clone $uk)->modify('+1 day')->format('Y-m-d');
    $list = array_filter(pref_all(), fn($d) => ($d['checkin'] ?? '') === $tomorrow);
    if (!$list) { @file_put_contents($f, $today, LOCK_EX); return 'none'; }
    uasort($list, fn($a, $b) => strnatcasecmp($a['property'] ?? '', $b['property'] ?? ''));
    // One digest per channel, each listing only that channel's properties.
    $groups = [];
    foreach ($list as $d) { $u = vl_slack_url_for($d['property'] ?? ''); if ($u !== '') $groups[$u][] = $d; }
    if (!$groups) { @file_put_contents($f, $today, LOCK_EX); return 'none'; }
    $r = 'sent';
    foreach ($groups as $u => $ds) {
        $t = '*Arrivals tomorrow with requests* (' . pref_nice_date($tomorrow) . ')';
        foreach ($ds as $d) $t .= "\n\n*" . vl_slack_esc(($d['property'] ?? '') ?: 'Property not given') . "*\n" . pref_slack_block($d);
        if (vl_slack_post($t, $u) !== 'sent') $r = 'failed';
    }
    if ($r === 'sent') @file_put_contents($f, $today, LOCK_EX);
    return $r;
}
// The link to paste into Guesty. Placeholders are examples: use Guesty's own variable names.
function pref_template($base) {
    return rtrim($base, '/') . '/s?c={{confirmation_code}}&p={{listing_nickname}}&d={{check_in_date}}';
}
// Simple per-IP limit, kept in files under data/prefs-rate/ (IP is hashed, files older than a day removed).
function pref_rate_ok() {
    $dir = __DIR__ . '/data/prefs-rate';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $file = $dir . '/' . substr(hash('sha256', 'prefs-rate|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 32) . '.json';
    $fh = fopen($file, 'c+');
    if (!$fh) return true;
    flock($fh, LOCK_EX);
    $hits = array_values(array_filter(json_decode(stream_get_contents($fh), true) ?: [], fn($t) => is_int($t) && $t > time() - 3600));
    $ok = count($hits) < PREF_RATE_PER_HOUR;
    if ($ok) $hits[] = time();
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN); fclose($fh);
    if (mt_rand(1, 50) === 1) foreach (glob($dir . '/*.json') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
    return $ok;
}

// ================= guest page =================
if (empty($PREF_PAGE)) return;

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Values from the Guesty link (read-only on the page when given), or typed in by the guest.
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$fromLink = [
    'c'  => pref_param($_GET['c'] ?? '', 40),
    'p'  => pref_param($_GET['p'] ?? '', 120),
    'in' => pref_param(($_GET['d'] ?? '') !== '' ? $_GET['d'] : ($_GET['in'] ?? ''), 40),
    'g'  => pref_param($_GET['g'] ?? '', 60),
];
if (!pref_code_ok($fromLink['c'])) $fromLink['c'] = '';
$val = [];
foreach ($fromLink as $k => $v) $val[$k] = $v !== '' ? $v : pref_param($src[$k] ?? '', $k === 'p' ? 120 : ($k === 'g' ? 60 : 40));
$errors = []; $done = false; $answers = [];
$fields = pref_fields($val['p']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answers = pref_answers($_POST, $fields);
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $done = true; // honeypot filled: a bot. Look normal, save nothing.
    } elseif (!pref_rate_ok()) {
        http_response_code(429);
        $errors[] = 'Too many submissions from this connection. Please try again in an hour, or WhatsApp us on 07578 255663.';
    } else {
        // Guests are already booked: we never ask them for booking details. If the link came without a code, keep the answers under a generated id.
        if (!pref_code_ok($val['c'])) $val['c'] = 'NOCODE-' . strtoupper(bin2hex(random_bytes(3)));
        if (mb_strlen((string)($_POST['notes'] ?? '')) > 500) $errors[] = 'Please keep the note to 500 characters.';
        if (!$errors) {
            $id = strtoupper($val['c']);
            $old = pref_get($id);
            $now = gmdate('c');
            $doc = [
                'code' => $id, 'property' => $val['p'], 'checkin' => pref_date($val['in']), 'checkinRaw' => $val['in'],
                'guest' => $val['g'], 'answers' => $answers, 'submittedAt' => $now,
                'firstSubmittedAt' => $old['firstSubmittedAt'] ?? $now, 'submissions' => (int)($old['submissions'] ?? 0) + 1,
                'slackStatus' => 'pending',
            ];
            pref_put($id, $doc);
            try { pref_send($id, $doc, (bool)$old); } catch (Throwable $e) { $doc['slackStatus'] = 'failed'; pref_put($id, $doc); }
            $done = true;
        }
    }
}
$first = $val['g'] !== '' ? $val['g'] : '';
$showDate = pref_nice_date(pref_date($val['in']), $val['in']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Help us get your stay ready – ViVi Stays</title>
<style>
:root{--bg:#f5f3ee;--card:#fff;--ink:#1c1b19;--muted:#6b665d;--line:#e4dfd5;--gold:#b08a2e;--goldbg:#f7eed8;--ok:#1f7a4d;--okbg:#dcf1e5;--warn:#a33a2c}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;padding:20px max(16px,env(safe-area-inset-right)) 48px max(16px,env(safe-area-inset-left))}
.wrap{max-width:600px;margin:0 auto;display:grid;gap:16px;grid-template-columns:minmax(0,1fr)}
.wrap>*{min-width:0}
.logo{display:block;width:180px;max-width:60%;height:auto;margin:0 auto 4px;mix-blend-mode:multiply}
h1{font:700 26px/1.2 Georgia,"Times New Roman",serif;margin:0}
h2{font:700 19px/1.25 Georgia,serif;margin:0 0 10px}
.sub{color:var(--muted);margin:6px 0 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
.facts{display:grid;grid-template-columns:auto minmax(0,1fr);gap:4px 14px;margin:0}
.facts dt{color:var(--muted)}.facts dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.q{display:grid;gap:6px;margin-bottom:18px}.q:last-child{margin-bottom:0}
.q>span,.q legend{font-weight:600;padding:0}
.q small{color:var(--muted);font-size:14px}
fieldset.q{border:0;margin:0 0 18px;padding:0;min-width:0}
textarea,input[type=text],input[type=number],input[type=date],select{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);min-height:44px}
textarea{min-height:100px;resize:vertical}
.yn{display:flex;gap:8px}
.yn label{flex:1;position:relative}
.yn input{position:absolute;opacity:0;inset:0;width:100%;height:100%;margin:0;cursor:pointer}
.yn span{display:flex;align-items:center;justify-content:center;min-height:44px;border:1px solid var(--line);border-radius:10px;font-weight:600;background:#fff}
.yn input:checked+span{background:var(--ink);color:#fff;border-color:var(--ink)}
.yn input:focus-visible+span{outline:3px solid var(--gold);outline-offset:2px}
button{font:inherit;font-weight:600;border-radius:10px;padding:12px 16px;border:1px solid var(--ink);background:var(--ink);color:#fff;cursor:pointer;min-height:48px;width:100%}
.note{background:var(--goldbg);border-radius:10px;padding:10px 12px;font-size:14px;color:#5e4a17}
.err{background:#fbe9e6;color:var(--warn);border-radius:10px;padding:10px 12px;font-weight:600}
.err p{margin:0}.err p+p{margin-top:6px}
.okbox{background:var(--okbg);color:var(--ok);border-radius:10px;padding:12px 14px;font-weight:600}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.count{font-size:13px;color:var(--muted);text-align:right}
:focus-visible{outline:3px solid var(--gold);outline-offset:2px}
.foot{font-size:14px;color:var(--muted);text-align:center}
.foot a{color:inherit}
ul.sum{margin:8px 0 0;padding-left:20px}
</style></head><body><div class="wrap">
<header style="text-align:center"><img class="logo" src="img/logo.png" alt="ViVi Stays" width="180" height="47">
<?php if ($done): ?>
<h1>Thank you<?= $first !== '' ? ', ' . $h($first) : '' ?>!</h1>
<p class="sub">We've passed your answers to the team getting <?= $h($val['p'] ?: 'your stay') ?> ready.</p>
<?php else: ?>
<h1>Help us get your stay ready</h1>
<p class="sub"><?= $first !== '' ? 'Hi ' . $h($first) . ', a' : 'A' ?> few quick questions so <?= $val['p'] !== '' ? $h($val['p']) : 'the place' ?> is just right when you arrive<?= $showDate !== '' ? ' on ' . $h($showDate) : '' ?>. It takes about a minute.</p>
<?php endif; ?>
</header>

<?php if ($done): ?>
<section class="card">
<div class="okbox" role="status">Your preferences are saved.</div>
<?php $lines = pref_lines(['property' => $val['p'], 'answers' => $answers]); if ($lines): ?>
<ul class="sum"><?php foreach ($lines as [$l, $v]): ?><li><?= $h($l) ?>: <b><?= $h($v) ?></b></li><?php endforeach; ?></ul>
<?php endif; ?>
<p class="sub">Need to change something? <a href="<?= $h('s?' . http_build_query(array_filter($val, fn($v) => $v !== ''))) ?>">Send the form again</a> and we'll use your latest answers. Early check-in and late check-out are subject to availability; we'll confirm with you.</p>
</section>
<?php else: ?>
<form method="post" class="wrap" style="padding:0" novalidate>
<?php if ($errors): ?><div class="err" role="alert"><?php foreach ($errors as $e): ?><p><?= $h($e) ?></p><?php endforeach; ?></div><?php endif; ?>

<?php foreach (['c', 'p', 'in', 'g'] as $k): if ($val[$k] !== ''): ?><input type="hidden" name="<?= $h($k) ?>" value="<?= $h($val[$k]) ?>"><?php endif; endforeach; ?>

<section class="card" aria-labelledby="ph"><h2 id="ph">Your preferences</h2>
<?php foreach ($fields as $k => $f): $v = $answers[$k] ?? ($f['default'] ?? ''); $id = 'f-' . $k; ?>
<?php if ($f['type'] === 'yesno'): ?>
<fieldset class="q"><legend><?= $h($f['label']) ?></legend>
<div class="yn"><?php foreach (['No', 'Yes'] as $o): ?><label><input type="radio" name="<?= $h($k) ?>" value="<?= $o ?>"<?= $v === $o ? ' checked' : '' ?>><span><?= $o ?></span></label><?php endforeach; ?></div>
<?php if (!empty($f['note'])): ?><small><?= $h($f['note']) ?></small><?php endif; ?></fieldset>
<?php else: ?>
<label class="q" for="<?= $id ?>"><span><?= $h($f['label']) ?></span>
<?php if ($f['type'] === 'select'): ?>
<select id="<?= $id ?>" name="<?= $h($k) ?>"><?php if (empty($f['default'])): ?><option value="">Choose…</option><?php endif; ?><?php foreach ($f['options'] as $o): ?><option<?= $v === $o ? ' selected' : '' ?>><?= $h($o) ?></option><?php endforeach; ?></select>
<?php elseif ($f['type'] === 'number'): ?>
<input id="<?= $id ?>" type="number" name="<?= $h($k) ?>" value="<?= $h($v) ?>" min="<?= (int)($f['min'] ?? 0) ?>" max="<?= (int)($f['max'] ?? 99) ?>" inputmode="numeric">
<?php else: ?>
<textarea id="<?= $id ?>" name="<?= $h($k) ?>" maxlength="<?= (int)($f['max'] ?? 500) ?>" placeholder="<?= $h($f['placeholder'] ?? '') ?>"><?= $h($v) ?></textarea>
<div class="count" aria-live="polite"><span data-count="<?= $id ?>"><?= mb_strlen($v) ?></span> / <?= (int)($f['max'] ?? 500) ?></div>
<?php endif; ?>
<?php if (!empty($f['note'])): ?><small><?= $h($f['note']) ?></small><?php endif; ?></label>
<?php endif; ?>
<?php endforeach; ?>
<div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
<p class="note">Paid extras are arranged separately, so there's nothing to pay or book here.</p>
<button type="submit">Send to the team</button>
</section>
</form>
<?php endif; ?>
<p class="foot">Questions? WhatsApp us on <a href="https://wa.me/447578255663">07578 255663</a>.<br>ViVi Stays</p>
</div>
<script>
document.querySelectorAll("[data-count]").forEach(c=>{const t=document.getElementById(c.dataset.count);t&&t.addEventListener("input",()=>c.textContent=t.value.length)});
</script>
</body></html>
