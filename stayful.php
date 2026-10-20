<?php
// Stayful sync: copies our Stayful landlord leads into the Leads list.
// Read-only on Stayful's side. Needs $STAYFUL_API_KEY in config.local.php
// (Stayful > Settings > API keys, scope "leads:read").
//
// Runs when someone opens the app (at most every 15 minutes), from the
// "Check Stayful now" button, or from a cPanel cron job:
//   php /home/USER/path/to/stayful.php
if (!defined('VL_APP') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }

const VL_STAYFUL_BASE = 'https://leads.stayful.co.uk/api/v1';
const VL_STAYFUL_EVERY = 15 * 60;

function vl_stayful_state_file() { return __DIR__ . '/data/stayful-sync.json'; }
function vl_stayful_state() {
    $f = vl_stayful_state_file();
    return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}

function vl_stayful_get($path, $key) {
    $ch = curl_init(VL_STAYFUL_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) throw new Exception('Could not reach Stayful');
    $j = json_decode($res, true);
    if ($code !== 200) {
        $c = $j['error']['code'] ?? '';
        $msg = [
            'invalid_api_key' => 'The Stayful API key is wrong. Make a new one in Stayful > Settings.',
            'revoked_api_key' => 'The Stayful API key was revoked. Make a new one in Stayful > Settings.',
            'expired_api_key' => 'The Stayful API key has expired. Make a new one in Stayful > Settings.',
            'insufficient_scope' => 'The Stayful API key needs the "leads:read" permission.',
            'rate_limited' => 'Stayful asked us to slow down. It will try again shortly.',
        ][$c] ?? ($j['error']['message'] ?? ('Stayful error ' . $code));
        throw new Exception($msg);
    }
    return is_array($j) ? $j : [];
}

// Pull the list in whichever wrapper Stayful uses.
function vl_stayful_rows($j) {
    foreach (['data', 'leads', 'items', 'results'] as $k) if (isset($j[$k]) && is_array($j[$k])) return $j[$k];
    return ($j && array_keys($j) === range(0, count($j) - 1)) ? $j : [];
}

function vl_stayful_first($name) {
    $name = trim((string)$name);
    if ($name === '') return 'there';
    $p = preg_split('/\s+/', $name);
    $f = $p[0];
    if (preg_match('/^(mr|mrs|ms|miss|dr)\.?$/i', $f) && isset($p[1])) return $name;
    return mb_convert_case($f, MB_CASE_TITLE);
}

function vl_stayful_town($lead, $withArea = true) {
    $addr = trim((string)($lead['address'] ?? ''));
        // Drop any postcodes (with or without the space) so the town is left at the end.
    $addr = trim(preg_replace('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', '', $addr), " ,");
    $addr = preg_replace('/\s+/', ' ', $addr);
    // Last word-ish part of the address that isn't a postcode is usually the town.
    $parts = array_values(array_filter(array_map('trim', preg_split('/,/', $addr))));
    $town = end($parts) ?: '';
    if ($town !== '' && count($parts) === 1) {
        $w = preg_split('/\s+/', $town);
        $town = end($w);
    }
    $area = $lead['postcode_area'] ?? '';
    return trim($town . ($withArea && $area && stripos($town, $area) === false ? ' (' . $area . ')' : ''));
}

function vl_stayful_message($lead) {
    $first = vl_stayful_first($lead['lead_name'] ?? '');
    $beds = trim((string)($lead['bedrooms'] ?? ''));
    $prop = ($beds !== '' && preg_match('/\d/', $beds)) ? $beds . '-bed property' : 'property';
    $where = vl_stayful_town($lead, false);
    $isGR = ($lead['lead_type'] ?? '') === 'guaranteed_rent';
    $offer = $isGR
        ? "You recently asked about guaranteed rent for your $prop" . ($where ? " in $where" : '') . "."
        : "You recently asked about having your $prop" . ($where ? " in $where" : '') . " managed as a short-term let.";
    return "Hi $first, it's [Your name] from Vivistays.\n\n$offer\n\n"
        . "We manage short lets end to end: listing, pricing, bookings, guest messages, cleaning and changeovers, and maintenance. "
        . "It's free to try: we only take a commission when we bring a booking.\n\n"
        . "Would a quick 10-minute call this week suit you? I can talk you through what your place could earn.\n\n"
        . "[Your name], Vivistays\nWhatsApp +44 7578 255663 · team@vivistays.com";
}

function vl_stayful_sync($force = false) {
    global $STAYFUL_API_KEY;
    $key = $STAYFUL_API_KEY ?? '';
    $state = vl_stayful_state();
    if (!$key) return ['configured' => false];
    if (!$force && !empty($state['last']) && time() - strtotime($state['last']) < VL_STAYFUL_EVERY) {
        return ['configured' => true, 'skipped' => true, 'last' => $state['last'], 'added' => 0, 'error' => $state['error'] ?? null];
    }
    $added = 0; $updated = 0; $seen = 0; $err = null;
    try {
        $db = vl_db();
        $get = $db->prepare('SELECT data FROM docs WHERE col = ? AND id = ?');
        $ins = $db->prepare('INSERT OR IGNORE INTO docs (col, id, data, updated) VALUES (?, ?, ?, ?)');
        $upd = $db->prepare('UPDATE docs SET data = ?, updated = ? WHERE col = ? AND id = ?');
        $cursor = null; $pages = 0;
        do {
            $q = ['limit' => 100];
            if ($cursor) $q['cursor'] = $cursor;
            $j = vl_stayful_get('/leads?' . http_build_query($q), $key);
            foreach (vl_stayful_rows($j) as $a) {
                if (!is_array($a) || empty($a['id'])) continue;
                $seen++;
                $lead = is_array($a['lead'] ?? null) ? $a['lead'] : $a;
                $id = 'stayful-' . preg_replace('/[^A-Za-z0-9_\-.~:@+]/', '', (string)$a['id']);
                $info = [
                    'assignmentId' => $a['id'], 'leadId' => $lead['id'] ?? null,
                    'type' => $lead['lead_type'] ?? null, 'status' => $a['status'] ?? null,
                    'stage' => $a['pipeline_stage'] ?? null, 'assignedAt' => $a['assigned_at'] ?? null,
                    'address' => $lead['address'] ?? null, 'postcode' => $lead['postcode'] ?? null,
                    'bedrooms' => $lead['bedrooms'] ?? null, 'profile' => $lead['lead_profile'] ?? null,
                    'income' => $lead['gross_annual_income'] ?? null, 'nightly' => $lead['avg_nightly_rate'] ?? null,
                    'occupancy' => $lead['occupancy_rate'] ?? null, 'desiredRent' => $lead['desired_rent'] ?? null,
                    'report' => !empty($lead['income_report_available']), 'pricePaid' => $a['price_paid'] ?? null,
                ];
                $now = gmdate('c');
                $get->execute(['leads', $id]);
                $row = $get->fetchColumn();
                if ($row === false) {
                    $beds = trim((string)($lead['bedrooms'] ?? ''));
                    $doc = [
                        'kind' => 'owner', 'source' => 'stayful', 'group' => 'Stayful',
                        'name' => trim((string)($lead['lead_name'] ?? '')),
                        'phone' => trim((string)($lead['phone'] ?? '')),
                        'email' => trim((string)($lead['email'] ?? '')),
                        'location' => vl_stayful_town($lead),
                        'properties' => trim(($beds !== '' ? $beds . ' bed' : '') . (($lead['lead_type'] ?? '') === 'guaranteed_rent' ? ' · wants guaranteed rent' : ''), ' ·'),
                        'need' => trim((string)($lead['lead_profile'] ?? '')) ?: (($lead['lead_type'] ?? '') === 'guaranteed_rent' ? 'Guaranteed rent enquiry via Stayful' : 'Asked Stayful about short-let management'),
                        'dmDraft' => vl_stayful_message($lead),
                        'score' => 'warm', 'status' => 'new', 'nextFollowUp' => gmdate('Y-m-d'),
                        'notes' => '', 'stayful' => $info,
                        'created' => $a['assigned_at'] ?? $now, 'updated' => $now, 'createdBy' => 'Stayful sync',
                    ];
                    $ins->execute(['leads', $id, json_encode($doc, JSON_UNESCAPED_UNICODE), $now]);
                    $added++;
                } else {
                    // Only refresh Stayful's own facts; never touch our status, notes or messages.
                    $doc = json_decode($row, true) ?: [];
                    if (($doc['stayful'] ?? null) != $info) {
                        $doc['stayful'] = $info;
                        foreach (['phone' => 'phone', 'email' => 'email'] as $k => $src)
                            if (empty($doc[$k]) && !empty($lead[$src])) $doc[$k] = trim((string)$lead[$src]);
                        $upd->execute([json_encode($doc, JSON_UNESCAPED_UNICODE), $now, 'leads', $id]);
                        $updated++;
                    }
                }
            }
            $cursor = $j['next_cursor'] ?? ($j['meta']['next_cursor'] ?? ($j['pagination']['next_cursor'] ?? null));
        } while ($cursor && ++$pages < 50);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    $state = ['last' => gmdate('c'), 'error' => $err, 'added' => $added, 'seen' => $seen,
              'lastAdded' => $added ? gmdate('c') : ($state['lastAdded'] ?? null)];
    @file_put_contents(vl_stayful_state_file(), json_encode($state));
    return ['configured' => true, 'last' => $state['last'], 'added' => $added, 'updated' => $updated, 'seen' => $seen, 'error' => $err];
}

// Command line (cPanel cron): php stayful.php
if (!defined('VL_APP')) {
    define('VL_APP', true);
    require __DIR__ . '/lib.php';
    $r = vl_stayful_sync(true);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(empty($r['error']) ? 0 : 1);
}
