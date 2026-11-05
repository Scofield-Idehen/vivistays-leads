<?php
// Jotform webhook: every onboarding form submission becomes a card in the Onboarding tab
// (stage "New / form received"). The URL with its secret key is shown in the app.
define('VL_APP', true);
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
if (!hash_equals(vl_ob_key(), (string)($_GET['k'] ?? ''))) { http_response_code(403); exit('{"error":"forbidden"}'); }

// Jotform posts form fields with the answers as JSON in "rawRequest". A plain JSON body also works (for testing).
$raw = $_POST['rawRequest'] ?? null;
$req = is_string($raw) ? json_decode($raw, true) : json_decode(file_get_contents('php://input'), true);
if (!is_array($req)) { http_response_code(400); exit('{"error":"no answers"}'); }

$flat = function ($v) use (&$flat) {
    if (is_array($v)) return trim(implode(' ', array_filter(array_map($flat, $v), 'strlen')));
    return trim((string)$v);
};
$answers = []; $name = $email = $phone = $address = $property = '';
foreach ($req as $k => $v) {
    if (!preg_match('/^q\d+_(.+)$/', (string)$k, $m)) continue; // Jotform answer keys look like q3_ownerName
    $label = ucfirst(strtolower(trim(preg_replace('/(?<=[a-z])(?=[A-Z])|[_-]+/', ' ', $m[1]))));
    $val = mb_substr($flat($v), 0, 2000);
    if ($val === '') continue;
    $answers[] = ['q' => mb_substr($label, 0, 120), 'a' => $val];
    $l = strtolower($label);
    if (!$email && filter_var($val, FILTER_VALIDATE_EMAIL)) $email = $val;
    elseif (!$phone && preg_match('/phone|mobile|whats ?app|tel/', $l)) $phone = $val;
    elseif (preg_match('/address|postcode|property|unit|listing/', $l) && !preg_match('/type|bed|sleep|furnish|email/', $l)) {
        if (!$address && preg_match('/address|postcode/', $l)) $address = $val;
        if (!$property && preg_match('/property|unit|listing/', $l)) $property = $val;
    }
    elseif (!$name && preg_match('/name/', $l) && !preg_match('/property|company|bank|account/', $l)) $name = $val;
}
if (!$answers) { http_response_code(400); exit('{"error":"no answers"}'); }

$sub = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['submissionID'] ?? ($req['submissionID'] ?? '')));
$id = 'jf-' . ($sub ?: bin2hex(random_bytes(8)));
$now = gmdate('c');
$doc = ['property' => mb_substr($property ?: ($address ?: ($name ? $name . "'s property" : 'New property')), 0, 120),
    'address' => mb_substr($address, 0, 200), 'type' => '', 'stage' => 'new', 'source' => 'onboarding form', 'check' => false,
    'owner' => ['name' => mb_substr($name, 0, 80), 'email' => mb_substr($email, 0, 120), 'phone' => mb_substr($phone, 0, 40)],
    'assignee' => '', 'next' => ['what' => 'Call the owner', 'date' => gmdate('Y-m-d')], 'furnishing' => ['status' => '', 'quotes' => []],
    'form' => ['formID' => (string)($_POST['formID'] ?? ''), 'submissionID' => $sub, 'answers' => array_slice($answers, 0, 150)],
    'notes' => '', 'log' => [['at' => $now, 'by' => 'Jotform', 'what' => 'Onboarding form received']], 'created' => $now, 'by' => 'Jotform'];
$db = vl_db();
$db->prepare('INSERT OR IGNORE INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)')->execute(['onboard', $id, json_encode($doc, JSON_UNESCAPED_UNICODE), $now]);

// Let the team know (default Slack channel, if one is set up).
if (function_exists('vl_slack_post')) {
    try { @vl_slack_post('<!channel> New owner onboarding form: ' . $doc['property'] . ($name ? ' (' . $name . ')' : '') . '. It is in Leads → Onboarding.', null); } catch (Throwable $e) {}
}
echo json_encode(['ok' => true, 'id' => $id]);
