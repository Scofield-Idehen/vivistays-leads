<?php
// Vivistays Leads – sign-in screen and the app itself.
define('VL_APP', true);
require __DIR__ . '/lib.php';
vl_start_session();
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['password'])) {
    $name = trim((string)$_POST['name']);
    $pass = (string)$_POST['password'];
    $ok = false;
    foreach ($USERS as $u => $p) {
        if (strcasecmp($u, $name) === 0 && hash_equals((string)$p, $pass) && strpos($p, 'CHANGE-THIS') !== 0) { $ok = $u; break; }
    }
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['vl_user'] = $ok;
        header('Location: ./');
        exit;
    }
    sleep(1); // slow down password guessing
    $error = 'That name and password don\'t match. Check config.php if you haven\'t set them yet.';
}

$user = vl_user();
if (!$user) { ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vivistays Leads – Sign in</title>
<style>
:root{--bg:#F5F5F7;--card:#FFFFFF;--ink:#1D1D1F;--ink2:#6E6E73;--field:rgba(118,118,128,.12);--accent:#0071E3;--red:#FF3B30}
@media (prefers-color-scheme:dark){:root{--bg:#000;--card:#1C1C1E;--ink:#F5F5F7;--ink2:#98989D;--field:rgba(118,118,128,.24);--accent:#2997FF;--red:#FF453A;color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:17px/1.47 -apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",Arial,sans-serif;letter-spacing:-.022em;padding:16px}
form{background:var(--card);border-radius:18px;padding:32px;width:100%;max-width:380px;display:flex;flex-direction:column;gap:14px;box-shadow:0 4px 24px rgba(0,0,0,.06)}
h1{margin:0;font-size:28px;letter-spacing:-.03em}
p{margin:0;color:var(--ink2);font-size:15px}
label{font-size:13px;font-weight:500;color:var(--ink2);display:flex;flex-direction:column;gap:6px}
input{border:0;background:var(--field);border-radius:10px;padding:11px 12px;font:inherit;color:inherit;outline:none}
input:focus{box-shadow:0 0 0 3px rgba(0,113,227,.25)}
button{border:0;background:var(--accent);color:#fff;border-radius:99px;padding:11px;font:inherit;font-weight:500;cursor:pointer;margin-top:4px}
.err{color:var(--red);font-size:14px}
</style></head><body>
<form method="post">
  <h1>Vivistays Leads</h1>
  <p>Sign in to see leads, projects and outreach.</p>
  <?php if ($error) echo '<p class="err">' . htmlspecialchars($error) . '</p>'; ?>
  <label>Name<input name="name" id="name" autocomplete="username" required autofocus></label>
  <label>Password<input name="password" id="password" type="password" autocomplete="current-password" required></label>
  <button type="submit">Sign in</button>
</form>
</body></html>
<?php exit; }

// Signed in: serve the app with a small bridge that replaces claude.ai's storage and AI with this server.
global $ANTHROPIC_API_KEY;
$app = file_get_contents(__DIR__ . '/app.html');
$bridge = '<script>window.VL_USER=' . json_encode($user) . ';window.VL_AI=' . ($ANTHROPIC_API_KEY ? 'true' : 'false') . ';</script>'
        . '<script src="bridge.js?v=1"></script>';
$app = preg_replace('/<script>/', $bridge . "\n<script>", $app, 1);
$app = str_replace('<button class="addbtn" id="addtop">', '<a class="signout" href="?logout=1">Sign out ' . htmlspecialchars($user) . '</a><button class="addbtn" id="addtop">', $app);
header('Cache-Control: no-store');
echo $app;
