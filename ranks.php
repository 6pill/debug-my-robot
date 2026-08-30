<?php
require_once __DIR__ . '/includes/lib.php';
$me = current_user();
$mine = $me ? coins_of((int)$me['id']) : 0;
$pageTitle = 'Rank ladder — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>
<div style="margin:26px 0 18px">
  <h2>Thirteen ranks, two tracks</h2>
  <p>Coins accumulate for life. A Helper line and a Professional line leapfrog each other before both cap out.</p>
</div>
<div class="card" style="padding:0;overflow:hidden"><table>
<?php foreach (ranks() as [$at,$name,$col]): ?>
  <tr class="<?= $mine >= $at ? 'hit' : '' ?>">
    <td class="n"><?= $at ?></td>
    <td><?= e($name) ?></td>
    <td style="width:34px;text-align:right"><span class="dot" style="background:<?= e($col) ?>"></span></td>
  </tr>
<?php endforeach; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
