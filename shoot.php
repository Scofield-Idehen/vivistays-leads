<?php
// Shoot days: a no-login page for the photographer (link has a secret key).
// Shows, from the latest Guesty check, which caravans are empty on each day and at what times,
// and lets him tick off each caravan when it is done. Data lives in docs col 'shoot':
//   id 'calendar' = {syncedAt, start:'YYYY-MM-DD', listings:[{id,name,area,n:'1BX..'}]}  (n = one char per night from start: 1 free, B guest, X blocked)
//   id 'progress' = {<listingId>: {done:true, at, by}}
define('VL_APP', true);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/newsletter.php';
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');

function shoot_key() { return substr(hash_hmac('sha256', 'shoot-days-link', nl_secret()), 0, 20); }

$k = (string)($_GET['k'] ?? '');
$ok = $k !== '' && hash_equals(shoot_key(), $k);
$db = vl_db();
$get = function ($id) use ($db) {
    $st = $db->prepare("SELECT data FROM docs WHERE col = 'shoot' AND id = ?");
    $st->execute([$id]);
    $r = $st->fetchColumn();
    return $r === false ? null : json_decode($r, true);
};

// Calendar sync from Guesty (sent by the team's browser while it has Guesty open). Separate key, never shown to the photographer.
$s = (string)($_GET['s'] ?? '');
if ($s !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: https://app.guesty.com');
    if (!hash_equals(substr(hash_hmac('sha256', 'shoot-sync', nl_secret()), 0, 24), $s)) { http_response_code(403); echo '{"error":"Bad key"}'; exit; }
    $c = json_decode(file_get_contents('php://input'), true);
    if (!is_array($c) || !isset($c['listings']) || !is_array($c['listings']) || count($c['listings']) > 300) { http_response_code(400); echo '{"error":"Bad data"}'; exit; }
    $clean = ['syncedAt' => gmdate('c'), 'start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['start'] ?? '') ? $c['start'] : gmdate('Y-m-d'), 'listings' => []];
    foreach ($c['listings'] as $l) {
        if (!preg_match('/^[A-Za-z0-9]{6,40}$/', (string)($l['id'] ?? ''))) continue;
        $clean['listings'][] = ['id' => $l['id'], 'name' => substr((string)($l['name'] ?? ''), 0, 80), 'area' => substr((string)($l['area'] ?? ''), 0, 40), 'n' => preg_replace('/[^1BX]/', '', substr((string)($l['n'] ?? ''), 0, 400))];
    }
    $db->prepare("INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES ('shoot', 'calendar', ?, ?)")->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), gmdate('c')]);
    echo json_encode(['ok' => true, 'count' => count($clean['listings'])]);
    exit;
}

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $lid = (string)($in['listing'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{6,40}$/', $lid)) { http_response_code(400); echo '{"error":"Bad caravan"}'; exit; }
    if (array_key_exists('clean', $in)) {
        // Cleaning status for a caravan on a given day: 'clean', 'not' or '' (clear). Set by the team.
        $st = (string)$in['clean']; $day = (string)($in['date'] ?? '');
        if (!in_array($st, ['clean', 'not', ''], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { http_response_code(400); echo '{"error":"Bad status"}'; exit; }
        $db->beginTransaction();
        $c = $get('clean') ?: [];
        if ($st === '') unset($c[$lid][$day]); else $c[$lid][$day] = ['s' => $st, 'at' => gmdate('c'), 'by' => substr(preg_replace('/[^\w .\-]/u', '', (string)($in['by'] ?? 'Team')), 0, 30)];
        if (isset($c[$lid]) && !$c[$lid]) unset($c[$lid]);
        // keep only the last 30 days
        foreach ($c as $k2 => $days) foreach ($days as $d2 => $_) if ($d2 < gmdate('Y-m-d', time() - 30 * 86400)) unset($c[$k2][$d2]);
        $db->prepare("INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES ('shoot', 'clean', ?, ?)")->execute([json_encode($c ?: new stdClass(), JSON_UNESCAPED_UNICODE), gmdate('c')]);
        $db->commit();
        echo json_encode(['ok' => true, 'clean' => $c ?: new stdClass()]);
        exit;
    }
    $db->beginTransaction();
    $p = $get('progress') ?: [];
    if (!empty($in['done'])) $p[$lid] = ['done' => true, 'at' => gmdate('c'), 'by' => substr(preg_replace('/[^\w .\-]/u', '', (string)($in['by'] ?? 'Yasin')), 0, 30)];
    else unset($p[$lid]);
    $db->prepare("INSERT OR REPLACE INTO docs (col, id, data, updated) VALUES ('shoot', 'progress', ?, ?)")->execute([json_encode($p ?: new stdClass(), JSON_UNESCAPED_UNICODE), gmdate('c')]);
    $db->commit();
    echo json_encode(['ok' => true, 'progress' => $p ?: new stdClass()]);
    exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!$ok) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Shoot days</title><body style="font:17px/1.5 Arial,sans-serif;padding:32px;max-width:480px;margin:auto"><h1 style="font:700 24px Georgia,serif">Link not recognised</h1><p>Please ask the ViVi Stays team for the latest shoot link.</p></body>';
    exit;
}
$cal = $get('calendar') ?: ['listings' => []];
$prog = $get('progress') ?: new stdClass();
$clean = $get('clean') ?: new stdClass();
$embed = !empty($_GET['embed']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Yasin's shoot days – ViVi Stays</title>
<style>
:root{--bg:#f5f3ee;--card:#fff;--ink:#1c1b19;--muted:#6b665d;--line:#e4dfd5;--gold:#b08a2e;--goldbg:#f7eed8;
--all:#1f7a4d;--allbg:#dcf1e5;--pm:#2c5f9e;--pmbg:#dde9f7;--am:#9a5b12;--ambg:#f8e7cf;--done:#8a857b;--warn:#a33a2c;--warnbg:#f8e1dd}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
padding:<?= $embed ? '0' : '20px' ?> max(16px,env(safe-area-inset-right)) 40px max(16px,env(safe-area-inset-left))}
.wrap{max-width:720px;margin:0 auto;display:grid;gap:18px;grid-template-columns:minmax(0,1fr)}
.wrap>*{min-width:0}
h1{font:700 28px/1.15 Georgia,"Times New Roman",serif;margin:0}
h2{font:700 19px/1.2 Georgia,serif;margin:0}
.sub{color:var(--muted);margin:4px 0 0}
.sync{font-size:14px;color:var(--muted)}
.sync.old{color:var(--warn);background:var(--warnbg);padding:8px 12px;border-radius:8px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
.prog{display:grid;gap:8px}
.bar{height:10px;background:var(--line);border-radius:99px;overflow:hidden}
.bar i{display:block;height:100%;background:var(--all);border-radius:99px}
.best{display:grid;gap:10px}
.bestday{display:flex;justify-content:space-between;align-items:center;gap:12px;background:var(--goldbg);border:0;border-radius:12px;padding:14px 16px;font:inherit;color:inherit;text-align:left;cursor:pointer;width:100%}
.bestday b{font:700 18px Georgia,serif;display:block}
.bestday span{color:var(--muted);font-size:14px}
.bestday em{font-style:normal;font-weight:700;color:var(--gold);white-space:nowrap}
.days{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;scrollbar-width:thin}
.days button{flex:0 0 auto;border:1px solid var(--line);background:var(--card);border-radius:12px;padding:8px 10px;font:inherit;color:inherit;cursor:pointer;text-align:center;min-width:62px}
.days button small{display:block;font-size:12px;color:var(--muted)}
.days button b{display:block;font-size:18px}
.days button i{display:block;font-style:normal;font-size:12px;color:var(--all);font-weight:600}
.days button[aria-pressed="true"]{background:var(--ink);color:#fff;border-color:var(--ink)}
.days button[aria-pressed="true"] small,.days button[aria-pressed="true"] i{color:#e9dfc6}
.days button:focus-visible,.bestday:focus-visible,.tick:focus-visible{outline:3px solid var(--gold);outline-offset:2px}
.park{display:grid;gap:8px}
.park h3{margin:6px 0 0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--gold)}
.row{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px}
.row .nm{flex:1;min-width:0;font-weight:600}
.row .nm small{display:block;font-weight:400;color:var(--muted);font-size:13px}
.chip{font-size:13px;font-weight:700;padding:5px 10px;border-radius:99px;white-space:nowrap}
.c-all{background:var(--allbg);color:var(--all)}.c-pm{background:var(--pmbg);color:var(--pm)}.c-am{background:var(--ambg);color:var(--am)}
.tick{border:2px solid var(--line);background:var(--card);border-radius:10px;width:44px;height:44px;font-size:20px;cursor:pointer;color:var(--done);flex:0 0 auto}
.row.done{opacity:.6}.row.done .tick{background:var(--all);border-color:var(--all);color:#fff}
.key{display:grid;gap:6px;font-size:14px;color:var(--muted)}
.key div{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.cl{font-size:12px;font-weight:700;padding:3px 8px;border-radius:99px;display:inline-block;margin-top:4px}
.cl-clean{background:var(--allbg);color:var(--all)}.cl-not{background:var(--warnbg);color:var(--warn)}.cl-unk{background:var(--line);color:var(--muted)}
.clbtns{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}.clbtns button{font:inherit;font-size:13px;font-weight:600;border:1px solid var(--line);background:var(--card);border-radius:8px;padding:5px 10px;cursor:pointer;min-height:32px}
.clbtns button[aria-pressed="true"].c1{background:var(--all);border-color:var(--all);color:#fff}.clbtns button[aria-pressed="true"].c0{background:var(--warn);border-color:var(--warn);color:#fff}
.empty{color:var(--muted);text-align:center;padding:20px}
.foot{font-size:13px;color:var(--muted)}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style></head><body><div class="wrap">
<?php if (!$embed): ?>
<header><h1>Yasin's shoot days</h1><p class="sub">ViVi Stays caravans: which ones are empty, and when you can go in.</p></header>
<?php endif; ?>
<div id="sync" class="sync"></div>
<section class="card prog" aria-label="Progress"><div id="progtxt"></div><div class="bar"><i id="progbar" style="width:0"></i></div></section>
<section class="best" aria-labelledby="bh"><h2 id="bh">Best days to go</h2><div id="best"></div></section>
<section aria-labelledby="ph"><h2 id="ph" style="margin-bottom:10px">Pick a day</h2><div class="days" id="days" role="group" aria-label="Days"></div></section>
<section id="list" aria-live="polite"></section>
<section class="card key"><b style="color:var(--ink)">What the times mean</b>
<div><span class="chip c-all">All day</span>No guests that day.</div>
<div><span class="chip c-pm">After 12 noon</span>A guest leaves that morning. Wait for the cleaners to finish.</div>
<div><span class="chip c-am">Before 2pm</span>A new guest arrives at 3pm. Be out by 2pm.</div>
<div><span class="cl cl-clean">Cleaned</span><span class="cl cl-not">Not cleaned yet</span>The team updates this on the day. Only go into caravans marked Cleaned.</div>
<div>Tap the box next to a caravan when it's done. The team will see it straight away.</div>
<div>Ask the team for door or key codes before you go.</div></section>
<p class="foot">Times come from Guesty and are checked several times a day. If a day says "checked a while ago", message the team before you set off.</p>
</div>
<script>
const CAL=<?= json_encode($cal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
let PROG=<?= json_encode($prog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
let CLEAN=<?= json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const BY=new URLSearchParams(location.search).get("by")||"Yasin";
const TEAM=BY.toLowerCase()!=="yasin";
const KEY=<?= json_encode($k) ?>;
const DOW=["Sun","Mon","Tue","Wed","Thu","Fri","Sat"],MON=["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
const start=new Date((CAL.start||"2026-10-31")+"T12:00:00");
const L=(CAL.listings||[]);
const today=new Date();today.setHours(12,0,0,0);
function idx(d){return Math.round((d-start)/864e5)}
function dateAt(i){const d=new Date(start);d.setDate(d.getDate()+i);return d}
function fmt(d,long){return (long?["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"][d.getDay()]:DOW[d.getDay()])+" "+d.getDate()+" "+MON[d.getMonth()]}
// window for a caravan on day i: night before = n[i-1], night of = n[i]
function win(l,i){const n=l.n||"";const prev=i>0?n[i-1]:"1",cur=n[i];if(cur===undefined)return null;
  if(prev==="X"&&cur==="X")return null;
  if(cur==="1"&&prev==="1")return "all"; if(cur==="1")return "pm"; if(cur!=="1"&&prev==="1"&&cur==="B")return "am"; return null}
const LAB={all:"All day",pm:"After 12 noon",am:"Before 2pm"};
const done=id=>!!(PROG&&PROG[id]&&PROG[id].done);
const first=Math.max(0,idx(today)),last=Math.min(first+27,Math.max(0,...L.map(l=>(l.n||"").length-1)));
let sel=null;
function esc(s){return String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]))}
function render(){
  const todo=L.filter(l=>!done(l.id));
  const nd=L.length-todo.length;
  document.getElementById("progtxt").innerHTML=`<b>${nd} of ${L.length} caravans done</b>`+(todo.length?` · ${todo.length} still to shoot`:` · all done`);
  document.getElementById("progbar").style.width=(L.length?nd/L.length*100:0)+"%";
  // sync note
  const s=document.getElementById("sync");
  if(CAL.syncedAt){const mins=Math.round((Date.now()-new Date(CAL.syncedAt))/6e4);const t=mins<60?mins+" min ago":mins<1440?Math.round(mins/60)+" hours ago":Math.round(mins/1440)+" days ago";
    s.textContent="Checked with Guesty "+t+".";s.className="sync"+(mins>360?" old":"");if(mins>360)s.textContent+=" Message the team to confirm before you go."}
  else{s.textContent="Not checked with Guesty yet.";s.className="sync old"}
  // day scores
  const score=[];for(let i=first;i<=last;i++){const all=todo.filter(l=>win(l,i)==="all").length,any=todo.filter(l=>win(l,i)).length;score.push({i,all,any})}
  const best=score.filter(x=>x.all>0).sort((a,b)=>b.all-a.all||a.i-b.i).slice(0,3).sort((a,b)=>a.i-b.i);
  document.getElementById("best").innerHTML=best.length?best.map(b=>`<button class="bestday" data-i="${b.i}"><div><b>${fmt(dateAt(b.i),true)}</b><span>${b.all} caravans empty all day${b.any>b.all?`, ${b.any-b.all} more for part of the day`:""}</span></div><em>See list ›</em></button>`).join(""):'<p class="empty">No full free days left in the next 4 weeks.</p>';
  if(sel===null)sel=best.length?best[0].i:first;
  document.getElementById("days").innerHTML=score.map(x=>`<button aria-pressed="${x.i===sel}" data-i="${x.i}"><small>${DOW[dateAt(x.i).getDay()]}</small><b>${dateAt(x.i).getDate()}</b><i>${x.any} free</i></button>`).join("");
  // list for selected day
  const rows=L.map(l=>({l,w:win(l,sel)})).filter(r=>r.w||done(r.l.id));
  const parks=[...new Set(L.map(l=>l.area))];
  let h=`<h2 style="margin:4px 0 10px">${fmt(dateAt(sel),true)}</h2>`;
  const avail=rows.filter(r=>r.w&&!done(r.l.id));
  if(!avail.length)h+='<p class="empty">No caravans free to shoot on this day.</p>';
  for(const p of parks){const pr=avail.filter(r=>r.l.area===p).sort((a,b)=>"all pm am".indexOf(a.w)-"all pm am".indexOf(b.w));if(!pr.length)continue;
    h+=`<div class="park"><h3>${esc(p)}</h3>`+pr.map(r=>row(r.l,r.w)).join("")+`</div>`}
  const dn=L.filter(l=>done(l.id));
  if(dn.length)h+=`<div class="park"><h3>Already done</h3>`+dn.map(l=>row(l,null)).join("")+`</div>`;
  document.getElementById("list").innerHTML=h;
}
function iso(d){return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0")}
function cleanOf(id){const c=CLEAN&&CLEAN[id]&&CLEAN[id][iso(dateAt(sel))];return c||null}
function row(l,w){const d=done(l.id);const by=d&&PROG[l.id].at?` <small>Done ${fmt(new Date(PROG[l.id].at))}</small>`:"";
  let cl="";if(w&&!d){const c=cleanOf(l.id);
    cl=c?`<span class="cl cl-${c.s}">${c.s==="clean"?"Cleaned":"Not cleaned yet"}</span>`:`<span class="cl cl-unk">Clean not confirmed</span>`;
    if(TEAM)cl+=`<div class="clbtns"><button class="c1" data-cl="clean" data-id="${esc(l.id)}" aria-pressed="${!!(c&&c.s==="clean")}">Cleaned</button><button class="c0" data-cl="not" data-id="${esc(l.id)}" aria-pressed="${!!(c&&c.s==="not")}">Not cleaned</button></div>`}
  return `<div class="row${d?" done":""}"><div class="nm">${esc(l.name)}${by}${cl?"<div>"+cl+"</div>":""}</div>${w&&!d?`<span class="chip c-${w}">${LAB[w]}</span>`:""}<button class="tick" data-id="${esc(l.id)}" aria-pressed="${d}" aria-label="${d?"Mark "+esc(l.name)+" not done":"Mark "+esc(l.name)+" done"}">${d?"✓":""}</button></div>`}
document.addEventListener("click",async e=>{
  const b=e.target.closest("[data-i]");if(b){sel=+b.dataset.i;render();if(b.classList.contains("bestday"))document.getElementById("list").scrollIntoView({behavior:"smooth"});return}
  const cb=e.target.closest("[data-cl]");if(cb){const id=cb.dataset.id,c=cleanOf(id),v=(c&&c.s===cb.dataset.cl)?"":cb.dataset.cl;cb.disabled=true;
    try{const r=await fetch(location.pathname+"?k="+encodeURIComponent(KEY),{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({listing:id,clean:v,date:iso(dateAt(sel)),by:BY})});const j=await r.json();if(j.ok){CLEAN=j.clean;render()}else throw 0}
    catch(_){cb.disabled=false;alert("Couldn't save. Check your signal and try again.")}return}
  const t=e.target.closest(".tick");if(t){const id=t.dataset.id,nd=!done(id);t.disabled=true;
    try{const r=await fetch(location.pathname+"?k="+encodeURIComponent(KEY),{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({listing:id,done:nd,by:new URLSearchParams(location.search).get("by")||"Yasin"})});const j=await r.json();if(j.ok){PROG=j.progress;render()}else throw 0}
    catch(_){t.disabled=false;alert("Couldn't save. Check your signal and try again.")}}
});
render();
</script></body></html>
