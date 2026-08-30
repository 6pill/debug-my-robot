<?php
require_once __DIR__ . '/includes/lib.php';
$me = require_login();

$error = null;
$ok    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    /* ---------------- change display name ---------------- */
    if ($do === 'name') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            $error = 'Pick a display name of 40 characters or fewer.';
        } else {
            $c = db()->prepare('SELECT 1 FROM users WHERE LOWER(name) = ? AND id <> ?');
            $c->execute([mb_strtolower($name), $me['id']]);
            if ($c->fetch()) {
                $error = 'That name is taken.';
            } else {
                db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $me['id']]);
                flash('Display name updated.');
                redirect('settings.php');
            }
        }
    }

    /* ---------------- change password ---------------- */
    if ($do === 'password') {
        if ($me['provider'] === 'google') {
            $error = 'This account signs in with Google, so it has no password to change.';
        } else {
            $cur = $_POST['current'] ?? '';
            $new = $_POST['new'] ?? '';
            $rpt = $_POST['repeat'] ?? '';

            if (!password_verify($cur, $me['password_hash'])) {
                $error = 'Your current password is wrong.';
            } elseif (strlen($new) < 8) {
                $error = 'The new password needs at least 8 characters.';
            } elseif ($new !== $rpt) {
                $error = "The two new passwords don't match.";
            } elseif ($new === $cur) {
                $error = 'That is the same as your current password.';
            } else {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                // Signing everyone else out of this account after a password change.
                session_regenerate_id(true);
                flash('Password changed.');
                redirect('settings.php');
            }
        }
    }

    /* ---------------- delete account ---------------- */
    if ($do === 'delete') {
        $confirm = trim($_POST['confirm'] ?? '');

        // Password accounts must prove it. Google accounts type their name.
        $proved = $me['provider'] === 'password'
            ? password_verify($_POST['current'] ?? '', $me['password_hash'])
            : ($confirm === $me['name']);

        if (!$proved) {
            $error = $me['provider'] === 'password'
                ? 'Password incorrect — account not deleted.'
                : 'That did not match your display name — account not deleted.';
        } elseif ($me['role'] === 'dev') {
            // Guard rail: don't let the only moderator delete themselves.
            $n = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'dev'")->fetchColumn();
            if ($n <= 1) {
                $error = 'You are the only moderator. Promote someone else before deleting this account, '
                       . 'or the review queue will have nobody to run it.';
            }
        }

        if (!$error) {
            // Foreign keys cascade: posts, comments, ledger rows, reports and
            // notifications belonging to this account go with it.
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$me['id']]);
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            session_start();
            flash('Your account and everything on it has been deleted.');
            redirect('index.php');
        }
    }
}

/* counts, so the delete warning is concrete rather than vague */
$np = db()->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?');    $np->execute([$me['id']]);
$nPosts = (int)$np->fetchColumn();
$nc = db()->prepare('SELECT COUNT(*) FROM comments WHERE author_id = ?'); $nc->execute([$me['id']]);
$nComments = (int)$nc->fetchColumn();
$myCoins = coins_of((int)$me['id']);

$pageTitle = 'Account settings — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>

<div style="margin:26px 0 18px">
  <h2>Account settings</h2>
  <p>Signed in as <?= e($me['email']) ?><?= $me['provider'] === 'google' ? ' (Google)' : '' ?>.</p>
</div>

<?php if ($error): ?>
  <div class="flash" style="border-color:#4A2A22;background:rgba(242,114,79,.1)"><?= e($error) ?></div>
<?php endif; ?>

<!-- ---------------- display name ---------------- -->
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="name">
  <h3 style="margin-bottom:6px">Display name</h3>
  <p style="margin-top:0">This is what appears on your posts and on the leaderboard.</p>
  <label for="name" class="sr">Display name</label>
  <input id="name" name="name" maxlength="40" value="<?= e($me['name']) ?>" required>
  <div style="height:14px"></div>
  <button class="btn-a" type="submit">Save name</button>
</form>

<!-- ---------------- password ---------------- -->
<div class="card">
  <h3 style="margin-bottom:6px">Password</h3>
  <?php if ($me['provider'] === 'google'): ?>
    <p style="margin-top:0">This account signs in with Google, so there's no password here to change. Manage it in your Google account instead.</p>
  <?php else: ?>
    <p style="margin-top:0">Changing this signs out any other device using the old password.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <label for="current">Current password</label>
      <input id="current" name="current" type="password" autocomplete="current-password" required>
      <label for="new">New password — 8 characters or more</label>
      <input id="new" name="new" type="password" autocomplete="new-password" required>
      <label for="repeat">New password again</label>
      <input id="repeat" name="repeat" type="password" autocomplete="new-password" required>
      <div style="height:14px"></div>
      <button class="btn-a" type="submit">Change password</button>
    </form>
  <?php endif; ?>
</div>

<!-- ---------------- sign out ---------------- -->
<div class="card">
  <h3 style="margin-bottom:6px">Sign out</h3>
  <p style="margin-top:0">Ends this session on this device. Your account stays exactly as it is.</p>
  <a class="btn-a" style="background:transparent;color:var(--ink);border-color:var(--line)" href="logout.php">Sign out</a>
</div>

<!-- ---------------- delete ---------------- -->
<div class="card banner">
  <h3 style="margin-bottom:6px;color:var(--ink)">Delete account</h3>
  <p style="margin-top:0">
    This removes your account and everything attached to it:
    <b style="color:var(--ink)"><?= $nPosts ?></b> <?= $nPosts === 1 ? 'post' : 'posts' ?>,
    <b style="color:var(--ink)"><?= $nComments ?></b> <?= $nComments === 1 ? 'reply' : 'replies' ?>,
    and <b style="color:var(--ink)"><?= $myCoins ?></b> <?= $myCoins === 1 ? 'coin' : 'coins' ?>.
    Other people's posts that your replies solved will go back to Open.
    There is no undo and no way to get any of it back.
  </p>

  <details>
    <summary class="btn-sm btn-red" style="list-style:none;cursor:pointer;display:inline-block;padding:9px 14px;border:1px solid #4A2A22;border-radius:9px">
      I want to delete my account
    </summary>
    <form method="post" style="margin-top:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="delete">
      <?php if ($me['provider'] === 'password'): ?>
        <label for="del_pw">Type your password to confirm</label>
        <input id="del_pw" name="current" type="password" autocomplete="current-password" required>
      <?php else: ?>
        <label for="del_name">Type your display name <b style="color:var(--ink)"><?= e($me['name']) ?></b> to confirm</label>
        <input id="del_name" name="confirm" autocomplete="off" required>
      <?php endif; ?>
      <div style="height:14px"></div>
      <button class="btn-red btn-full" type="submit">Delete my account permanently</button>
    </form>
  </details>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
