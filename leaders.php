<?php
require_once __DIR__ . '/includes/lib.php';

$span = ($_GET['span'] ?? 'all') === 'week' ? 'week' : 'all';
$sql = "SELECT u.id, u.name, SUM(l.delta) AS c
        FROM coin_ledger l JOIN users u ON u.id = l.to_user_id "
     . ($span === 'week' ? "WHERE l.created_at > (NOW() - INTERVAL 7 DAY) " : "")
     . "GROUP BY u.id, u.name HAVING c > 0 ORDER BY c DESC LIMIT 50";
$rows = db()->query($sql)->fetchAll();

$pageTitle = 'Top helpers — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>
<div style="margin:26px 0 18px">
  <h2>&#127942; Top helpers</h2>
  <p>One Knowledge Coin per answer marked "It worked!".</p>
</div>

<div class="tabs" style="max-width:340px">
  <a class="<?= $span==='week'?'on':'' ?>" href="?span=week">This week</a>
  <a class="<?= $span==='all'?'on':'' ?>"  href="?span=all">All time</a>
</div>

<?php if (!$rows): ?>
  <div class="card empty"><h3>No coins earned yet</h3><p>Be the first to solve something.</p></div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden"><table>
  <?php foreach ($rows as $i => $r): $rk = rank_for(coins_of((int)$r['id'])); ?>
    <tr>
      <td class="n"><?= $i+1 ?></td>
      <td>
        <a href="profile.php?id=<?= (int)$r['id'] ?>" style="color:var(--ink);text-decoration:none;font-size:14.5px"><?= e($r['name']) ?></a>
        <div class="meta"><span class="dot" style="background:<?= e($rk[2]) ?>"></span> <?= e($rk[1]) ?></div>
      </td>
      <td class="n" style="text-align:right"><?= (int)$r['c'] ?></td>
    </tr>
  <?php endforeach; ?>
  </table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
