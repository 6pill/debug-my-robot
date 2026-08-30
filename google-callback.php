<?php
/* ============================================================
   Google Sign-In callback.

   The browser posts Google's ID token here. We verify it with
   Google's own tokeninfo endpoint before trusting anything —
   that check happens on the server, so a forged token gets you
   nowhere. This is the part a static HTML file cannot do.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';

if (!GOOGLE_CLIENT_ID) { flash('Google sign-in is not configured.'); redirect('auth.php'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('auth.php');

// Google posts g_csrf_token both as a cookie and a form field; they must match.
$cookieTok = $_COOKIE['g_csrf_token'] ?? '';
$postTok   = $_POST['g_csrf_token'] ?? '';
if (!$cookieTok || !$postTok || !hash_equals($cookieTok, $postTok)) {
    flash('Google sign-in failed a security check. Try again.');
    redirect('auth.php');
}

$credential = $_POST['credential'] ?? '';
if (!$credential) { flash('No credential returned by Google.'); redirect('auth.php'); }

/* --- verify the token with Google --- */
$url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
$raw = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $raw = curl_exec($ch);
    curl_close($ch);
} else {
    $raw = @file_get_contents($url);
}
if ($raw === false) { flash("Couldn't reach Google to verify the sign-in."); redirect('auth.php'); }

$claims = json_decode($raw, true);
if (!is_array($claims) || empty($claims['email'])) { flash('Google rejected that sign-in.'); redirect('auth.php'); }

// The token must be issued for OUR app, by Google, and not expired.
$audOk = ($claims['aud'] ?? '') === GOOGLE_CLIENT_ID;
$issOk = in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
$expOk = isset($claims['exp']) && (int)$claims['exp'] > time();
$verOk = ($claims['email_verified'] ?? 'false') === 'true' || ($claims['email_verified'] ?? false) === true;

if (!$audOk || !$issOk || !$expOk || !$verOk) {
    flash('That Google token could not be verified.');
    redirect('auth.php');
}

$email = strtolower($claims['email']);
$sub   = $claims['sub'] ?? null;

$st = db()->prepare('SELECT * FROM users WHERE email = ?');
$st->execute([$email]);
$u = $st->fetch();

if (!$u) {
    // Build a unique display name.
    $base = trim($claims['name'] ?? strstr($email, '@', true));
    $base = mb_substr($base !== '' ? $base : 'member', 0, 32);
    $name = $base;
    for ($i = 0; $i < 30; $i++) {
        $c = db()->prepare('SELECT 1 FROM users WHERE LOWER(name) = ?');
        $c->execute([mb_strtolower($name)]);
        if (!$c->fetch()) break;
        $name = $base . random_int(10, 99);
    }
    $role = ($email === strtolower(DEV_EMAIL)) ? 'dev' : 'member';
    $ins = db()->prepare('INSERT INTO users (email,name,provider,google_sub,role) VALUES (?,?,?,?,?)');
    $ins->execute([$email, $name, 'google', $sub, $role]);
    $uid = (int)db()->lastInsertId();
    flash($role === 'dev' ? "Signed in as {$name} — you have moderator access." : "Welcome, {$name}.");
} else {
    $uid = (int)$u['id'];
    if (!$u['google_sub'] && $sub) {
        $up = db()->prepare('UPDATE users SET google_sub = ? WHERE id = ?');
        $up->execute([$sub, $uid]);
    }
}

session_regenerate_id(true);
$_SESSION['uid'] = $uid;
redirect('index.php');
