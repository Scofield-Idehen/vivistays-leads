<?php
// Short-term let licence applications.
//  - The team tracks each property's application in the app (Licences).
//  - Each application has a no-login owner link: the owner sees what is still needed,
//    uploads certificates and answers the property questions used for the FRA, LRA and escape plan.
//  - Owners with missing items are chased by email automatically (cron) and the app shows
//    who is due a WhatsApp nudge.
// Data: docs col 'licence', one doc per application. Files: data/licence/<appId>/ (never public).
if (!defined('VL_APP')) {
    define('VL_APP', true);
    require __DIR__ . '/lib.php';
    $LIC_PAGE = true;
}
require_once __DIR__ . '/newsletter.php';

// ---------- what each council needs ----------
// who: owner = the owner sends it; us = ViVi Stays does it; gen = ViVi Stays drafts it in the app.
// renew = months a certificate stays valid (used for expiry warnings).
const LIC_ITEMS = [
    'owner_details' => ['label' => 'Owner details (full names of all owners, home address, date of birth)', 'who' => 'owner', 'help' => 'Needed on the application form for every owner of the property.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'convictions'   => ['label' => 'Declaration of any unspent convictions', 'who' => 'owner', 'help' => 'The council checks with Police Scotland that everyone named is "fit and proper". A short written statement is enough (say "none" if none).', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'consent'       => ['label' => "Owner's written consent for ViVi Stays to apply and run the let", 'who' => 'owner', 'help' => 'A signed letter or email from every owner. We can send you a template.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'gas'           => ['label' => 'Gas safety certificate (CP12)', 'who' => 'owner', 'renew' => 12, 'na' => 'No gas or LPG at the property', 'help' => 'From a Gas Safe registered engineer. Renewed every year. Includes LPG in caravans.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'eicr'          => ['label' => 'Electrical Installation Condition Report (EICR)', 'who' => 'owner', 'renew' => 60, 'help' => 'From a qualified electrician. Valid for up to 5 years.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'pat'           => ['label' => 'Portable Appliance Testing (PAT) report', 'who' => 'owner', 'renew' => 12, 'help' => 'Every plug-in appliance supplied to guests, tested by a qualified person. Renewed every year.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'buildings_ins' => ['label' => 'Buildings insurance certificate', 'who' => 'owner', 'renew' => 12, 'help' => 'Must cover short-term letting. For caravans, the caravan insurance schedule.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'liability_ins' => ['label' => 'Public liability insurance certificate', 'who' => 'owner', 'renew' => 12, 'help' => 'Scottish Borders asks for at least £5 million cover.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'epc'           => ['label' => 'Energy Performance Certificate (EPC)', 'who' => 'owner', 'renew' => 120, 'na' => 'Caravan or lodge (no EPC needed)', 'help' => 'Free to download from the Scottish EPC register if one exists. The rating must also show on listings.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'measurements'  => ['label' => 'Room sizes and photos of each room (for the floor plan)', 'who' => 'owner', 'help' => 'Length and width of every room, or an existing floor plan. We draw the licence floor plan from this.', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'planning'      => ['label' => 'Planning permission or certificate of lawfulness for short-term letting', 'who' => 'owner', 'na' => 'Not needed for this property', 'help' => 'Edinburgh is a short-term let control area: whole-property lets need planning permission. Borders: only where planning applies.', 'councils' => ['edinburgh', 'borders']],
    'water'         => ['label' => 'Private water supply test results', 'who' => 'owner', 'na' => 'Mains water', 'help' => 'Only if the property is not on mains water.', 'councils' => ['borders']],
    // Drafted by ViVi Stays in the app (from the owner's answers), then checked before submitting
    'fra'           => ['label' => 'Fire safety risk assessment + SFRS fire safety checklist', 'who' => 'gen', 'doc' => 'fra', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'lra'           => ['label' => 'Legionella (water safety) risk assessment', 'who' => 'gen', 'doc' => 'lra', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'escape'        => ['label' => 'Fire escape plan and guest fire notice', 'who' => 'gen', 'doc' => 'escape', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'floorplan'     => ['label' => 'Floor plan (A4, to scale 1:50 or 1:100, room sizes, escape routes, sockets/lights in East Lothian)', 'who' => 'us', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'siteplan'      => ['label' => 'Site plan (location of the property)', 'who' => 'us', 'councils' => ['borders']],
    'self_decs'     => ['label' => 'Self-declarations (insurance held, legionella assessment done)', 'who' => 'us', 'councils' => ['edinburgh']],
    'site_notice'   => ['label' => 'Site notice displayed for 21 days, then compliance certificate returned', 'who' => 'us', 'councils' => ['east-lothian']],
    'form'          => ['label' => 'Application form completed', 'who' => 'us', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
    'fee'           => ['label' => 'Application fee paid', 'who' => 'us', 'councils' => ['east-lothian', 'edinburgh', 'borders']],
];
const LIC_COUNCILS = ['east-lothian' => 'East Lothian Council', 'edinburgh' => 'City of Edinburgh Council', 'borders' => 'Scottish Borders Council', 'other' => 'Another council (standard checklist)'];

// Property questions the owner answers (feed the FRA, LRA and escape plan drafts)
const LIC_QUESTIONS = [
    ['k' => 'ptype', 'q' => 'What type of property is it?', 'o' => ['House', 'Flat', 'Static caravan', 'Lodge', 'Other']],
    ['k' => 'floors', 'q' => 'How many floors do guests use?', 'o' => ['1', '2', '3', '4+']],
    ['k' => 'bedrooms', 'q' => 'How many bedrooms?', 't' => 'number'],
    ['k' => 'sleeps', 'q' => 'Maximum number of guests?', 't' => 'number'],
    ['k' => 'heating', 'q' => 'How is the property heated?', 'o' => ['Mains gas', 'LPG (bottled gas)', 'Electric', 'Oil', 'Heat pump', 'Other']],
    ['k' => 'smoke', 'q' => 'Are there smoke alarms in the living room and every hall/landing, linked together?', 'o' => ['Yes, linked', 'Yes, not linked', 'Some rooms only', 'No / not sure']],
    ['k' => 'heat', 'q' => 'Is there a heat alarm in the kitchen?', 'o' => ['Yes', 'No / not sure']],
    ['k' => 'co', 'q' => 'Is there a carbon monoxide alarm next to every gas/LPG/oil appliance and flue?', 'o' => ['Yes', 'No', 'No such appliances']],
    ['k' => 'blanket', 'q' => 'Is there a fire blanket in the kitchen?', 'o' => ['Yes', 'No']],
    ['k' => 'extinguisher', 'q' => 'Is there a fire extinguisher?', 'o' => ['Yes', 'No']],
    ['k' => 'exits', 'q' => 'Which doors can guests leave by in a fire?', 't' => 'text', 'p' => 'e.g. front door to driveway, back door to garden'],
    ['k' => 'escape_windows', 'q' => 'Do upstairs bedrooms have windows that open wide enough to escape from?', 'o' => ['Yes', 'No', 'No upstairs bedrooms']],
    ['k' => 'keys', 'q' => 'Can the exit doors be opened from inside without a key?', 'o' => ['Yes (thumb-turn/latch)', 'No, key needed']],
    ['k' => 'assembly', 'q' => 'Where should guests gather outside after leaving?', 't' => 'text', 'p' => 'e.g. pavement opposite the front door'],
    ['k' => 'furniture', 'q' => 'Do sofas, chairs and mattresses have fire-safety labels?', 'o' => ['Yes, all', 'Some', 'Not sure']],
    ['k' => 'hotwater', 'q' => 'How is hot water made?', 'o' => ['Combi boiler (no tank)', 'Hot water cylinder/tank', 'Electric water heater', 'Not sure']],
    ['k' => 'coldtank', 'q' => 'Is there a cold water storage tank (e.g. in the loft)?', 'o' => ['No', 'Yes', 'Not sure']],
    ['k' => 'showers', 'q' => 'How many showers?', 't' => 'number'],
    ['k' => 'lowuse', 'q' => 'Any taps or showers that are hardly ever used?', 'o' => ['No', 'Yes']],
    ['k' => 'water_supply', 'q' => 'Water supply', 'o' => ['Mains', 'Private supply (well, borehole, spring)']],
    ['k' => 'notes', 'q' => 'Anything else we should know?', 't' => 'text', 'p' => 'Optional'],
];

function lic_items_for($council) {
    $out = [];
    // 'other': any council we haven't mapped yet gets the items every council asks for.
    foreach (LIC_ITEMS as $k => $it) if (in_array($council, $it['councils'], true) || ($council === 'other' && count($it['councils']) === 3)) $out[$k] = $it;
    return $out;
}
function lic_key($id) { return substr(hash_hmac('sha256', 'licence:' . $id, nl_secret()), 0, 18); }
function lic_base() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $host ? (($https ? 'https://' : 'http://') . $host) : nl_base_url();
}
function lic_link($id) { return lic_base() . '/licence.php?a=' . rawurlencode($id) . '&k=' . lic_key($id); }
function lic_get($id) {
    $st = vl_db()->prepare("SELECT data FROM docs WHERE col = 'licence' AND id = ?");
    $st->execute([$id]);
    $r = $st->fetchColumn();
    return $r === false ? null : (json_decode($r, true) ?: []);
}
function lic_put($id, $doc) {
    $doc['updated'] = gmdate('c');
    vl_db()->prepare("INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES ('licence', ?, ?, ?)")->execute([$id, json_encode($doc, JSON_UNESCAPED_UNICODE), gmdate('c')]);
}
function lic_status($doc, $k) { return $doc['items'][$k]['status'] ?? 'missing'; }
// Items still waiting on the owner
function lic_owner_missing($doc) {
    $miss = [];
    foreach (lic_items_for($doc['council'] ?? '') as $k => $it) if ($it['who'] === 'owner' && lic_status($doc, $k) === 'missing') $miss[$k] = $it;
    if (empty($doc['info']['answered'])) $miss['_questions'] = ['label' => 'Answer the property safety questions (about 3 minutes)', 'who' => 'owner'];
    return $miss;
}
function lic_dir($id) {
    $d = __DIR__ . '/data/licence/' . preg_replace('/[^a-f0-9]/', '', $id);
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

// Store an uploaded file against an item. $by = 'owner' or a team name.
function lic_store_file($id, $item, $tmp, $origName, $by) {
    if (!isset(LIC_ITEMS[$item])) throw new Exception('Unknown item');
    $size = filesize($tmp);
    if ($size < 200) throw new Exception('That file looks empty');
    if ($size > 15 * 1024 * 1024) throw new Exception('File is too large (15 MB max)');
    $head = file_get_contents($tmp, false, null, 0, 12);
    $ext = substr($head, 0, 4) === '%PDF' ? 'pdf' : (substr($head, 0, 3) === "\xFF\xD8\xFF" ? 'jpg' : (substr($head, 0, 4) === "\x89PNG" ? 'png' : (substr($head, 4, 4) === 'ftyp' ? 'heic' : '')));
    if (!$ext) throw new Exception('Please upload a PDF or a photo (JPG, PNG)');
    $name = $item . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = lic_dir($id) . '/' . $name;
    $okMove = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : rename($tmp, $dest);
    if (!$okMove) throw new Exception('Could not save the file');
    $doc = lic_get($id);
    if (!$doc) throw new Exception('Application not found');
    $cur = $doc['items'][$item] ?? [];
    $files = $cur['files'] ?? [];
    $files[] = ['f' => $name, 'orig' => mb_substr(preg_replace('/[^\w .()\-]/u', '', (string)$origName), 0, 80), 'at' => gmdate('c'), 'by' => $by];
    $doc['items'][$item] = array_merge($cur, ['status' => 'received', 'files' => $files, 'at' => gmdate('c'), 'by' => $by]);
    $doc['log'][] = ['at' => gmdate('c'), 'by' => $by, 'what' => 'Uploaded: ' . LIC_ITEMS[$item]['label']];
    $doc['log'] = array_slice($doc['log'], -60);
    lic_put($id, $doc);
    return $doc;
}

// ---------- chasing ----------
function lic_chase_email($id, $doc) {
    $miss = lic_owner_missing($doc);
    if (!$miss) return ['skipped' => 'nothing missing'];
    $to = trim($doc['owner']['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['skipped' => 'no owner email'];
    $cfg = nl_cfg();
    if (!$cfg['key']) return ['skipped' => 'email not set up'];
    $first = trim(explode(' ', trim($doc['owner']['name'] ?? ''))[0]) ?: 'there';
    $prop = $doc['property'] ?? 'your property';
    $n = (int)($doc['chase']['count'] ?? 0);
    $subject = $n === 0 ? "Licence application for $prop: what we need from you" : "Reminder: licence application for $prop";
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $li = ''; $tx = '';
    foreach ($miss as $it) { $li .= '<li style="margin:4px 0">' . $esc($it['label']) . '</li>'; $tx .= "- {$it['label']}\n"; }
    $link = lic_link($id);
    $intro = $n === 0
        ? "We're preparing the short-term let licence application for $prop. To send it to the council we still need the items below."
        : "A quick reminder: we can't send the licence application for $prop to the council until we have the items below.";
    $html = '<div style="font:15px/1.6 Arial,sans-serif;color:#1c1b19;max-width:560px">'
        . '<p>Hi ' . $esc($first) . ',</p><p>' . $esc($intro) . '</p><ul style="padding-left:20px">' . $li . '</ul>'
        . '<p>You can upload everything and answer the questions on one page, from your phone or computer:</p>'
        . '<p><a href="' . $esc($link) . '" style="display:inline-block;background:#1c1b19;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold">Open your checklist</a></p>'
        . '<p>Or just reply to this email with the documents attached, or WhatsApp us on 07578 255663.</p>'
        . '<p>Thank you,<br>ViVi Stays</p></div>';
    $text = "Hi $first,\n\n$intro\n\n$tx\nUpload everything here: $link\n\nOr reply to this email, or WhatsApp us on 07578 255663.\n\nThank you,\nViVi Stays";
    nl_brevo(['sender' => ['email' => $cfg['from'], 'name' => $cfg['name']], 'replyTo' => ['email' => $cfg['reply']],
        'to' => [['email' => $to, 'name' => $doc['owner']['name'] ?? '']], 'subject' => $subject, 'htmlContent' => $html, 'textContent' => $text]);
    $doc['chase']['count'] = $n + 1;
    $doc['chase']['lastEmail'] = gmdate('c');
    $doc['log'][] = ['at' => gmdate('c'), 'by' => 'Auto', 'what' => 'Chase email sent (' . count($miss) . ' items missing)'];
    $doc['log'] = array_slice($doc['log'], -60);
    lic_put($id, $doc);
    return ['sent' => true];
}
// Called from cron: emails owners whose chase is due (UK weekdays 9am-5pm only).
function lic_chase_due($max = 10) {
    $uk = new DateTime('now', new DateTimeZone('Europe/London'));
    if ((int)$uk->format('N') > 5 || (int)$uk->format('G') < 9 || (int)$uk->format('G') >= 17) return 0;
    $sent = 0;
    foreach (vl_db()->query("SELECT id, data FROM docs WHERE col = 'licence'") as $r) {
        if ($sent >= $max) break;
        $doc = json_decode($r['data'], true) ?: [];
        $c = $doc['chase'] ?? [];
        if (empty($c['auto']) || ($doc['stage'] ?? '') === 'submitted' || ($doc['stage'] ?? '') === 'granted') continue;
        $every = max(1, (int)($c['every'] ?? 3));
        $last = max(strtotime($c['lastEmail'] ?? '2000-01-01'), strtotime($c['started'] ?? '2000-01-01'));
        if (time() - $last < $every * 86400 - 3600) continue;
        try { $res = lic_chase_email($r['id'], $doc); if (!empty($res['sent'])) $sent++; } catch (Throwable $e) {}
    }
    return $sent;
}

// ================= owner page (licence.php?a=..&k=..) and team file downloads =================
if (empty($LIC_PAGE)) return;

header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
$id = (string)($_GET['a'] ?? '');
$k = (string)($_GET['k'] ?? '');

// Team download of a stored file (needs the app sign-in)
if (isset($_GET['dl'])) {
    vl_start_session();
    if (!vl_user()) { http_response_code(403); exit('Please sign in to the Leads app first.'); }
    $f = basename((string)$_GET['dl']);
    $path = lic_dir($id) . '/' . $f;
    if (!preg_match('/^[a-z_]+-\d{8}-\d{6}-[a-f0-9]{6}\.(pdf|jpg|png|heic)$/', $f) || !is_file($path)) { http_response_code(404); exit('Not found'); }
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'heic' => 'image/heic'];
    header('Content-Type: ' . $types[pathinfo($f, PATHINFO_EXTENSION)]);
    header('Content-Disposition: inline; filename="' . $f . '"');
    readfile($path);
    exit;
}

$doc = preg_match('/^[a-f0-9]{20}$/', $id) ? lic_get($id) : null;
if (!$doc || !hash_equals(lic_key($id), $k)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Licence checklist</title><body style="font:17px/1.5 Arial,sans-serif;padding:32px;max-width:480px;margin:auto"><h1 style="font:700 24px Georgia,serif">Link not recognised</h1><p>Please ask ViVi Stays for your latest checklist link, or WhatsApp us on 07578 255663.</p></body>';
    exit;
}

// Owner actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['act'] ?? '';
        if ($act === 'upload') {
            $item = (string)($_POST['item'] ?? '');
            $its = lic_items_for($doc['council'] ?? '');
            if (!isset($its[$item]) || $its[$item]['who'] !== 'owner') throw new Exception('Unknown item');
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload failed, please try again');
            lic_store_file($id, $item, $_FILES['file']['tmp_name'], $_FILES['file']['name'], 'Owner');
        } elseif ($act === 'na') {
            $item = (string)($_POST['item'] ?? '');
            $its = lic_items_for($doc['council'] ?? '');
            if (!isset($its[$item]['na'])) throw new Exception('Unknown item');
            $doc['items'][$item] = ['status' => 'na', 'at' => gmdate('c'), 'by' => 'Owner', 'note' => $its[$item]['na']];
            $doc['log'][] = ['at' => gmdate('c'), 'by' => 'Owner', 'what' => 'Marked not needed: ' . $its[$item]['label']];
            lic_put($id, $doc);
        } elseif ($act === 'text') {
            $item = (string)($_POST['item'] ?? '');
            $its = lic_items_for($doc['council'] ?? '');
            $txt = trim(mb_substr((string)($_POST['text'] ?? ''), 0, 3000));
            if (!isset($its[$item]) || $its[$item]['who'] !== 'owner' || $txt === '') throw new Exception('Please type something first');
            $doc['items'][$item] = array_merge($doc['items'][$item] ?? [], ['status' => 'received', 'text' => $txt, 'at' => gmdate('c'), 'by' => 'Owner']);
            $doc['log'][] = ['at' => gmdate('c'), 'by' => 'Owner', 'what' => 'Answered: ' . $its[$item]['label']];
            lic_put($id, $doc);
        } elseif ($act === 'info') {
            $ans = [];
            foreach (LIC_QUESTIONS as $q) { $v = trim(mb_substr((string)($_POST[$q['k']] ?? ''), 0, 300)); if ($v !== '') $ans[$q['k']] = $v; }
            $doc['info'] = array_merge($doc['info'] ?? [], $ans, ['answered' => gmdate('c')]);
            $doc['log'][] = ['at' => gmdate('c'), 'by' => 'Owner', 'what' => 'Answered the property safety questions'];
            lic_put($id, $doc);
        } else throw new Exception('Bad request');
        $doc = lic_get($id);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$items = lic_items_for($doc['council'] ?? '');
$ownerItems = array_filter($items, fn($it) => $it['who'] === 'owner');
$done = count(array_filter(array_keys($ownerItems), fn($key) => lic_status($doc, $key) !== 'missing')) + (empty($doc['info']['answered']) ? 0 : 1);
$total = count($ownerItems) + 1;
$textItems = ['owner_details', 'convictions', 'measurements'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Licence checklist – <?= $h($doc['property'] ?? '') ?></title>
<style>
:root{--bg:#f5f3ee;--card:#fff;--ink:#1c1b19;--muted:#6b665d;--line:#e4dfd5;--gold:#b08a2e;--goldbg:#f7eed8;--ok:#1f7a4d;--okbg:#dcf1e5;--warn:#a33a2c}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;padding:20px max(16px,env(safe-area-inset-right)) 48px max(16px,env(safe-area-inset-left))}
.wrap{max-width:680px;margin:0 auto;display:grid;gap:16px;grid-template-columns:minmax(0,1fr)}
.wrap>*{min-width:0}
h1{font:700 26px/1.2 Georgia,"Times New Roman",serif;margin:0}
h2{font:700 19px/1.25 Georgia,serif;margin:0 0 4px}
.sub{color:var(--muted);margin:6px 0 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
.bar{height:10px;background:var(--line);border-radius:99px;overflow:hidden;margin-top:8px}.bar i{display:block;height:100%;background:var(--ok)}
.item{display:grid;gap:8px;border-top:1px solid var(--line);padding:14px 0}.item:first-of-type{border-top:0;padding-top:4px}
.item .t{font-weight:600}.item .hp{color:var(--muted);font-size:14px}
.state{font-size:13px;font-weight:700;padding:3px 9px;border-radius:99px;justify-self:start}
.s-missing{background:var(--goldbg);color:#8a6a1c}.s-ok{background:var(--okbg);color:var(--ok)}
.btns{display:flex;flex-wrap:wrap;gap:8px}
button,.btn{font:inherit;font-weight:600;border-radius:10px;padding:10px 14px;border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer;min-height:44px}
.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
label.up{display:inline-flex;align-items:center}
input[type=file]{position:absolute;width:1px;height:1px;opacity:0}
textarea,input[type=text],input[type=number],select{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink)}
textarea{min-height:90px}
.q{display:grid;gap:6px;margin-bottom:14px}.q span{font-weight:600}
.msg{font-size:14px;color:var(--ok)}.err{color:var(--warn)}
:focus-visible{outline:3px solid var(--gold);outline-offset:2px}
.foot{font-size:14px;color:var(--muted)}
</style></head><body><div class="wrap">
<header><h1>Licence checklist</h1><p class="sub"><?= $h($doc['property'] ?? '') ?> · <?= $h(($doc['council'] ?? '') === 'other' ? ($doc['councilName'] ?? '') : (LIC_COUNCILS[$doc['council']] ?? '')) ?></p></header>
<section class="card"><b><?= $done ?> of <?= $total ?> done</b><div class="bar"><i style="width:<?= round($done / max(1, $total) * 100) ?>%"></i></div>
<p class="sub">Hi <?= $h(trim(explode(' ', trim($doc['owner']['name'] ?? ''))[0]) ?: 'there') ?>, these are the things we need from you to apply for the short-term let licence. Upload a PDF or a clear photo of each one. You can come back to this page any time.</p></section>

<section class="card" aria-labelledby="dh"><h2 id="dh">Documents</h2>
<?php foreach ($ownerItems as $key => $it): $st = lic_status($doc, $key); $isOk = $st !== 'missing'; ?>
<div class="item" id="i-<?= $h($key) ?>">
  <div class="t"><?= $h($it['label']) ?></div>
  <?php if (!empty($it['help'])): ?><div class="hp"><?= $h($it['help']) ?></div><?php endif; ?>
  <span class="state <?= $isOk ? 's-ok' : 's-missing' ?>"><?= $st === 'na' ? 'Not needed' : ($isOk ? 'Received, thank you' : 'Still needed') ?></span>
  <?php if (in_array($key, $textItems, true)): ?>
    <textarea aria-label="<?= $h($it['label']) ?>" data-text="<?= $h($key) ?>" placeholder="Type here"><?= $h($doc['items'][$key]['text'] ?? '') ?></textarea>
    <div class="btns"><button class="primary" type="button" data-save="<?= $h($key) ?>">Save</button>
    <label class="btn up">Or upload a file<input type="file" accept=".pdf,image/*" data-up="<?= $h($key) ?>"></label></div>
  <?php else: ?>
    <div class="btns"><label class="btn up primary"><?= $isOk ? 'Upload another' : 'Upload' ?><input type="file" accept=".pdf,image/*" data-up="<?= $h($key) ?>"></label>
    <?php if (!empty($it['na']) && $st === 'missing'): ?><button type="button" data-na="<?= $h($key) ?>"><?= $h($it['na']) ?></button><?php endif; ?></div>
  <?php endif; ?>
  <div class="msg" role="status"></div>
</div>
<?php endforeach; ?>
</section>

<section class="card" aria-labelledby="qh"><h2 id="qh">Property safety questions</h2>
<p class="sub" style="margin-bottom:14px">We use your answers to prepare the fire safety and water safety assessments the council asks for. <?= empty($doc['info']['answered']) ? '' : '<b style="color:var(--ok)">Thank you, we have your answers. You can change them below.</b>' ?></p>
<form id="qf">
<?php foreach (LIC_QUESTIONS as $q): $v = $doc['info'][$q['k']] ?? ''; ?>
<label class="q"><span><?= $h($q['q']) ?></span>
<?php if (!empty($q['o'])): ?><select name="<?= $h($q['k']) ?>"><option value="">Choose…</option><?php foreach ($q['o'] as $o): ?><option<?= $v === $o ? ' selected' : '' ?>><?= $h($o) ?></option><?php endforeach; ?></select>
<?php else: ?><input type="<?= $q['t'] === 'number' ? 'number' : 'text' ?>" name="<?= $h($q['k']) ?>" value="<?= $h($v) ?>" placeholder="<?= $h($q['p'] ?? '') ?>" <?= $q['t'] === 'number' ? 'min="0" inputmode="numeric"' : '' ?>><?php endif; ?>
</label>
<?php endforeach; ?>
<button class="primary" type="submit">Save answers</button> <span class="msg" id="qmsg" role="status"></span>
</form></section>
<p class="foot">Questions? WhatsApp ViVi Stays on 07578 255663 or reply to our email. Your documents are only seen by the ViVi Stays team and the council.</p>
</div>
<script>
const post=async fd=>{const r=await fetch(location.href,{method:"POST",body:fd});const j=await r.json().catch(()=>({}));if(!r.ok||j.error)throw new Error(j.error||"Something went wrong, please try again");return j};
const say=(el,t,bad)=>{el.textContent=t;el.classList.toggle("err",!!bad)};
document.querySelectorAll("[data-up]").forEach(inp=>inp.addEventListener("change",async()=>{
  const it=inp.closest(".item"),m=it.querySelector(".msg");const f=inp.files[0];if(!f)return;
  if(f.size>15*1024*1024){say(m,"That file is over 15 MB. Please send a smaller photo or PDF.",true);return}
  say(m,"Uploading…");const fd=new FormData();fd.append("act","upload");fd.append("item",inp.dataset.up);fd.append("file",f);
  try{await post(fd);say(m,"Uploaded. Thank you!");setTimeout(()=>location.reload(),900)}catch(e){say(m,e.message,true)}}));
document.querySelectorAll("[data-na]").forEach(b=>b.addEventListener("click",async()=>{
  const m=b.closest(".item").querySelector(".msg");const fd=new FormData();fd.append("act","na");fd.append("item",b.dataset.na);
  try{await post(fd);say(m,"Noted.");setTimeout(()=>location.reload(),700)}catch(e){say(m,e.message,true)}}));
document.querySelectorAll("[data-save]").forEach(b=>b.addEventListener("click",async()=>{
  const it=b.closest(".item"),m=it.querySelector(".msg");const fd=new FormData();fd.append("act","text");fd.append("item",b.dataset.save);fd.append("text",it.querySelector("textarea").value);
  try{await post(fd);say(m,"Saved. Thank you!");setTimeout(()=>location.reload(),900)}catch(e){say(m,e.message,true)}}));
document.getElementById("qf").addEventListener("submit",async e=>{e.preventDefault();const m=document.getElementById("qmsg");const fd=new FormData(e.target);fd.append("act","info");
  try{await post(fd);say(m,"Saved. Thank you!");setTimeout(()=>location.reload(),900)}catch(er){say(m,er.message,true)}});
</script>
</body></html>
