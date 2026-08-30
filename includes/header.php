<?php
require_once __DIR__ . '/lib.php';
$me = current_user();
$flash = take_flash();
$pageTitle = $pageTitle ?? SITE_NAME;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="Ask. Share. Solve. Build. Post the problem, get the fix, pay the helper a coin.">
<link rel="icon" type="image/png" sizes="any" href="/assets/logo.png?v=2">
<link rel="shortcut icon" href="/assets/logo.png?v=2">
<link rel="apple-touch-icon" href="/assets/logo.png?v=2">
<link rel="stylesheet" href="assets/style.css">
<style>
  /* Logo chip — white pad so the light logo reads on the dark header.
     If you swap in a transparent PNG, delete the background + padding lines. */
  .logo img{
    height:34px; width:auto; display:block;
    background:#fff;                 /* delete for transparent logo */
    padding:3px;                     /* delete for transparent logo */
    border-radius:9px;
  }
  .logo{gap:10px}
  .logo .wordmark{display:flex;flex-direction:column;line-height:1}
  .logo .wordmark b{font-family:var(--mono);font-size:15px;letter-spacing:-.3px}
  .logo .wordmark span{font-family:var(--mono);font-size:8.5px;letter-spacing:.18em;color:var(--dim);margin-top:3px}
  @media(max-width:430px){
    .logo .wordmark{display:none}   /* on narrow phones the mark alone */
    .logo img{height:32px}
  }
</style>
<?php if (GOOGLE_CLIENT_ID): ?><script src="https://accounts.google.com/gsi/client" async defer></script><?php endif; ?>
</head>
<body>

<header>
  <div class="wrap hrow">
    <a class="logo" href="index.php" aria-label="<?= e(SITE_NAME) ?> home">
      <img src="assets/logo.png" alt="">
      <span class="wordmark">
        <b>debug<span style="color:var(--amber)">.</span>my<span style="color:var(--amber)">.</span>robot</b>
        <span>ASK. SHARE. SOLVE. BUILD.</span>
      </span>
    </a>

    <?php if ($me): $unread = unread_count($me['id']); ?>
      <a class="ic" href="alerts.php" title="Notifications">&#128276;<?php if ($unread): ?><span class="nib"><?= $unread ?></span><?php endif; ?></a>
      <?php if ($me['role'] === 'dev'): $qn = open_reports_count(); ?>
        <a class="ic" href="queue.php" title="Review queue">&#9878;<?php if ($qn): ?><span class="nib"><?= $qn ?></span><?php endif; ?></a>
      <?php endif; ?>
      <a class="ic amber" href="new.php" title="Post a problem">+</a>
      <a class="coinpill" href="profile.php?id=<?= (int)$me['id'] ?>">&#9677; <?= coins_of($me['id']) ?></a>
      <a class="ic" href="logout.php" title="Sign out">&#8677;</a>
    <?php else: ?>
      <a class="ic" href="leaders.php" title="Top helpers">&#127942;</a>
      <a class="btn-a btn-sm" href="auth.php">Sign in</a>
    <?php endif; ?>
  </div>
</header>

<main class="wrap">
<?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
<?php if (is_banned($me)): ?>
  <div class="card banner">
    <p class="eyebrow" style="color:var(--red)">Account suspended</p>
    <p style="color:var(--ink)">Read-only until <?= e(date('j M Y, H:i', strtotime($me['suspended_until']))) ?> UTC. You can't post, reply, or report.</p>
  </div>
<?php endif; ?>
