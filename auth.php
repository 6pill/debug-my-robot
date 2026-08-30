<?php
require_once __DIR__ . '/includes/lib.php';
if (current_user()) redirect('index.php');

$next = $_GET['next'] ?? 'index.php';
$mode = ($_GET['mode'] ?? '') === 'up' ? 'up' : 'in';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw    = $_POST['password'] ?? '';
    $act   = $_POST['action'] ?? '';

    if ($act === 'signup') {
        $mode = 'up';
        $name = trim($_POST['name'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))      $error = "That doesn't look like an email address.";
        elseif ($name === '' || mb_strlen($name) > 40)       $error = 'Pick a display name of 40 characters or fewer.';
        elseif (strlen($pw) < 8)                             $error = 'Password needs at least 8 characters.';
        else {
            $st = db()->prepare('SELECT 1 FROM users WHERE email = ? OR LOWER(name) = ?');
            $st->execute([$email, mb_strtolower($name)]);
            if ($st->fetch()) $error = 'That email or display name is already taken.';
            else {
                // The dev role is decided here, on the server, from config.
                $role = ($email === strtolower(DEV_EMAIL)) ? 'dev' : 'member';
                $ins = db()->prepare(
                  'INSERT INTO users (email,name,password_hash,provider,role) VALUES (?,?,?,?,?)');
                $ins->execute([$email, $name, password_hash($pw, PASSWORD_DEFAULT), 'password', $role]);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)db()->lastInsertId();
                flash($role === 'dev' ? 'Account created — you have moderator access.' : 'Account created.');
                redirect($next);
            }
        }
    }

    if ($act === 'signin') {
        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u)                          $error = 'No account with that email.';
        elseif ($u['provider'] === 'google') $error = 'That account uses Google — use the Google button below.';
        elseif (!password_verify($pw, $u['password_hash'])) $error = 'Wrong password.';
        else {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            redirect($next);
        }
    }
}

$pageTitle = ($mode === 'up' ? 'Create account' : 'Sign in') . ' — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>

<div style="text-align:center;margin:34px 0 22px">
  <h2 style="font-size:30px"><?= e(SITE_NAME) ?></h2>
  <p>Post the problem. Get the fix. Pay the helper a coin.</p>
</div>

<div class="card">
  <div class="tabs">
    <a class="<?= $mode==='in'?'on':'' ?>" href="?mode=in&amp;next=<?= urlencode($next) ?>">Sign in</a>
    <a class="<?= $mode==='up'?'on':'' ?>" href="?mode=up&amp;next=<?= urlencode($next) ?>">Create account</a>
  </div>

  <?php if ($error): ?><div class="flash" style="border-color:#4A2A22;background:rgba(242,114,79,.1)"><?= e($error) ?></div><?php endif; ?>

  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $mode==='up'?'signup':'signin' ?>">

    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com"
           value="<?= e($_POST['email'] ?? '') ?>" required>

    <?php if ($mode === 'up'): ?>
      <label for="name">Display name</label>
      <input id="name" name="name" autocomplete="nickname" placeholder="how you'll appear on the bench"
             value="<?= e($_POST['name'] ?? '') ?>" required>
    <?php endif; ?>

    <label for="password">Password<?= $mode==='up' ? ' — 8 characters or more' : '' ?></label>
    <input id="password" name="password" type="password" required
           autocomplete="<?= $mode==='up'?'new-password':'current-password' ?>">

    <div style="height:20px"></div>
    <button class="btn-a btn-full" type="submit"><?= $mode==='up' ? 'Create account' : 'Sign in' ?></button>
  </form>

  <div class="sep"><i></i><span>or</span><i></i></div>

  <?php if (GOOGLE_CLIENT_ID): ?>
    <div id="g_id_onload"
         data-client_id="<?= e(GOOGLE_CLIENT_ID) ?>"
         data-login_uri="<?= e(SITE_URL) ?>/google-callback.php"
         data-ux_mode="redirect"
         data-auto_prompt="false"></div>
    <div style="display:flex;justify-content:center">
      <div class="g_id_signin" data-type="standard" data-theme="filled_black"
           data-shape="pill" data-text="continue_with" data-size="large"></div>
    </div>
  <?php else: ?>
    <p class="meta" style="text-align:center">Google sign-in is off — no client ID in config.php.</p>
  <?php endif; ?>
</div>

<p style="text-align:center"><a href="index.php">Browse the feed without an account</a></p>

<?php require __DIR__ . '/includes/footer.php'; ?>
