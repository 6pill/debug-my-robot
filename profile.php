<?php
require_once __DIR__ . '/includes/lib.php';

$id = (int)($_GET['id'] ?? 0);

// No id given? Show the signed-in user's own profile.
if (!$id && current_user()) $id = (int)current_user()['id'];

$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$id]);
$u = $st->fetch();

if (!$u) {
    $pageTitle = 'Not found — ' . SITE_NAME;
    require __DIR__ . '/includes/header.php';
    echo '<div class="card empty"><h3>No such member</h3>
          <p>That profile does not exist.</p><div style="height:12px"></div>
          <a class="btn-a" href="index.php">Back to the feed</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$c    = coins_of($id);
$rk   = rank_for($c);
$me   = current_user();
$self = $me && (int)$me['id'] === $id;

$q = db()->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?');
$q->execute([$id]);
$nPosts = (int)$q->fetchColumn();

$q = db()->prepare('SELECT COUNT(*) FROM comments WHERE author_id = ? AND hidden = 0');
$q->execute([$id]);
$nComments = (int)$q->fetchColumn();

$led = db()->prepare('SELECT * FROM coin_ledger WHERE to_user_id = ? ORDER BY created_at DESC LIMIT 100');
$led->execute([$id]);
$entries = $led->fetchAll();

$earned = array_filter(ranks(), function ($k) use ($c) { return $c >= $k[0]; });

$pageTitle = $u['name'] . ' — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>

<p style="margin:20px 0"><a href="index.php">&larr; All problems</a></p>

<div class="card">
  <h2><?= e($u['name']) ?></h2>
  <p class="meta">
    <span class="dot" style="background:<?= e($rk[2]) ?>"></span> <?= e($rk[1]) ?>
    &middot; <?= $c ?> coins
    &middot; <?= $nPosts ?> posts
    &middot; <?= $nComments ?> replies
    &middot; joined <?= e(date('j M Y', strtotime($u['created_at']))) ?>
    <?= $u['role'] === 'dev' ? ' &middot; dev' : '' ?>
  </p>

  <?php if (is_banned($u)): ?>
    <p class="meta" style="color:var(--red)">
      Suspended until <?= e(date('j M Y, H:i', strtotime($u['suspended_until']))) ?> UTC
    </p>
  <?php endif; ?>

  <div class="row" style="margin-top:12px">
    <?php if ($earned): ?>
      <?php foreach ($earned as $k): ?>
        <span class="tag"><?= e($k[1]) ?></span>
      <?php endforeach; ?>
    <?php else: ?>
      <span class="meta">No ranks earned yet</span>
    <?php endif; ?>
  </div>

  <?php if ($self): ?>
    <div style="height:16px"></div>
    <div class="row">
      <a class="btn-sm" style="border:1px solid var(--line);padding:9px 14px;border-radius:9px;text-decoration:none;color:var(--ink)" href="settings.php">Settings</a>
      <a class="btn-sm" style="border:1px solid var(--line);padding:9px 14px;border-radius:9px;text-decoration:none;color:var(--ink)" href="logout.php">Sign out</a>
    </div>
  <?php endif; ?>
</div>

<h3 style="margin:26px 0 10px">Coin ledger</h3>
<p style="margin-bottom:12px">Append-only. Credit that moves is written as a reversal, never erased.</p>

<div class="ledger">
  <?php if (!$entries): ?>
    <span class="meta">no entries</span>
  <?php else: ?>
    <?php foreach ($entries as $x): ?>
      <div>
        <span class="<?= $x['delta'] > 0 ? 'plus' : 'minus' ?>"><?= $x['delta'] > 0 ? '+1' : '&minus;1' ?></span>
        <span style="flex:1;color:var(--ink);opacity:.8"><?= e($x['reason']) ?></span>
        <span class="meta"><?= e(date('j M y', strtotime($x['created_at']))) ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
