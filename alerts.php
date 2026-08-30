<?php
require_once __DIR__ . '/includes/lib.php';
$me = require_login();

$st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$st->execute([$me['id']]);
$rows = $st->fetchAll();

db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$me['id']]);

$pageTitle = 'Notifications — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>
<div style="margin:26px 0 18px"><h2>Notifications</h2></div>
<?php if (!$rows): ?>
  <div class="card empty"><h3>Nothing yet</h3><p>Replies, credits, and review outcomes land here.</p></div>
<?php else: foreach ($rows as $n): ?>
  <?php $open = $n['post_id'] ? 'post.php?id=' . (int)$n['post_id'] : null; ?>
  <div class="card" style="<?= $n['is_read'] ? '' : 'border-color:#4A3A22' ?>">
    <p style="color:var(--ink);margin:0">
      <?php if ($open): ?><a href="<?= e($open) ?>" style="color:var(--ink);text-decoration:none"><?= e($n['text']) ?></a>
      <?php else: ?><?= e($n['text']) ?><?php endif; ?>
    </p>
    <p class="meta" style="margin:6px 0 0"><?= e(date('j M Y, H:i', strtotime($n['created_at']))) ?></p>
  </div>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
