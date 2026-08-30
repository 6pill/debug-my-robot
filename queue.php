<?php
require_once __DIR__ . '/includes/lib.php';
$me = require_dev();   // server-side role check — not a hidden button

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    /* --- decide a report --- */
    if ($do === 'decide') {
        $rid    = (int)($_POST['report_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $days   = max(0, min(3650, (int)($_POST['days'] ?? 0)));
        if ($action === 'suspend' && $days < 1) { flash('Enter a number of days.'); redirect('queue.php'); }
        if (!in_array($action, ['dismiss','warn','suspend'], true)) { flash('Unknown action.'); redirect('queue.php'); }

        $r = db()->prepare("SELECT r.*, c.author_id AS comment_author, c.id AS cid, p.title
                            FROM reports r JOIN comments c ON c.id = r.comment_id
                            JOIN posts p ON p.id = r.post_id
                            WHERE r.id = ? AND r.state = 'open'");
        $r->execute([$rid]);
        $rep = $r->fetch();
        if (!$rep) { flash('That report has already been handled.'); redirect('queue.php'); }

        db()->beginTransaction();
        try {
            if ($action === 'dismiss') {
                $text = 'A report on your comment was reviewed and dismissed. No action taken.';
            } elseif ($action === 'warn') {
                db()->prepare('UPDATE comments SET hidden = 1 WHERE id = ?')->execute([$rep['cid']]);
                $text = 'Your comment was removed (' . $rep['reason'] . '). This is a warning — no suspension.';
            } else {
                db()->prepare('UPDATE comments SET hidden = 1 WHERE id = ?')->execute([$rep['cid']]);
                db()->prepare('UPDATE users SET suspended_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?')
                    ->execute([$days, $rep['comment_author']]);
                $text = 'Your comment was removed (' . $rep['reason'] . ') and your account is suspended for '
                      . $days . ' ' . ($days === 1 ? 'day' : 'days') . '.';
            }
            db()->prepare('UPDATE reports SET state = ?, days = ?, reviewed_by = ? WHERE id = ?')
                ->execute([$action, $action === 'suspend' ? $days : 0, $me['id'], $rid]);

            notify((int)$rep['comment_author'], $text, (int)$rep['post_id']);
            if ((int)$rep['reporter_id'] !== (int)$rep['comment_author']) {
                notify((int)$rep['reporter_id'], 'Your report was reviewed — outcome: ' . $action . '.', (int)$rep['post_id']);
            }
            db()->commit();
            flash('Decision recorded: ' . $action . '.');
        } catch (Throwable $ex) { db()->rollBack(); flash('That did not go through.'); }
        redirect('queue.php');
    }

    /* --- lift a suspension early --- */
    if ($do === 'unban') {
        $uid = (int)($_POST['user_id'] ?? 0);
        db()->prepare('UPDATE users SET suspended_until = NULL WHERE id = ?')->execute([$uid]);
        notify($uid, 'Your suspension was lifted early. Welcome back.');
        flash('Suspension lifted.');
        redirect('queue.php?tab=bans');
    }
}

$tab = $_GET['tab'] ?? 'open';

$open = db()->query(
  "SELECT r.*, c.body AS comment_body, c.author_id AS comment_author,
          u.name AS comment_author_name, rp.name AS reporter_name, p.title AS post_title,
          (SELECT COUNT(*) FROM reports r2 JOIN comments c2 ON c2.id = r2.comment_id
            WHERE c2.author_id = c.author_id AND r2.state = 'suspend') AS prior_bans
   FROM reports r
   JOIN comments c ON c.id = r.comment_id
   JOIN users u    ON u.id = c.author_id
   JOIN users rp   ON rp.id = r.reporter_id
   JOIN posts p    ON p.id = r.post_id
   WHERE r.state = 'open' ORDER BY r.created_at ASC")->fetchAll();

$bans = db()->query(
  "SELECT id, name, suspended_until FROM users
   WHERE suspended_until IS NOT NULL AND suspended_until > NOW()
   ORDER BY suspended_until ASC")->fetchAll();

$history = db()->query(
  "SELECT r.*, u.name AS comment_author_name, d.name AS reviewer
   FROM reports r
   JOIN comments c ON c.id = r.comment_id
   JOIN users u    ON u.id = c.author_id
   LEFT JOIN users d ON d.id = r.reviewed_by
   WHERE r.state <> 'open' ORDER BY r.created_at DESC LIMIT 60")->fetchAll();

$pageTitle = 'Review queue — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>

<div style="margin:26px 0 18px">
  <h2>&#9878; Review queue</h2>
  <p>Coins are never at stake in a report — only the comment and the commenter's standing.</p>
</div>

<div class="tabs">
  <a class="<?= $tab==='open'?'on':'' ?>"    href="?tab=open">Open<?= $open ? ' ('.count($open).')' : '' ?></a>
  <a class="<?= $tab==='bans'?'on':'' ?>"    href="?tab=bans">Active bans<?= $bans ? ' ('.count($bans).')' : '' ?></a>
  <a class="<?= $tab==='history'?'on':'' ?>" href="?tab=history">History</a>
</div>

<?php if ($tab === 'open'): ?>
  <?php if (!$open): ?>
    <div class="card empty"><h3>Queue is clear</h3><p>No reports waiting.</p></div>
  <?php else: foreach ($open as $r): ?>
    <div class="card">
      <div class="row" style="margin-bottom:8px">
        <span class="tag" style="border-color:#4A2A22;color:var(--red)"><?= e($r['reason']) ?></span>
        <span class="meta"><?= e(ago($r['created_at'])) ?></span>
        <?php if ($r['prior_bans']): ?>
          <span class="meta" style="color:var(--red)"><?= (int)$r['prior_bans'] ?> prior suspension<?= $r['prior_bans']>1?'s':'' ?></span>
        <?php endif; ?>
      </div>
      <p class="meta" style="margin:0 0 10px">
        on "<a href="post.php?id=<?= (int)$r['post_id'] ?>"><?= e(mb_strimwidth($r['post_title'],0,60,'…')) ?></a>"
        · by <a href="profile.php?id=<?= (int)$r['comment_author'] ?>"><?= e($r['comment_author_name']) ?></a>
        · reported by <?= e($r['reporter_name']) ?>
      </p>
      <p style="background:var(--panel2);border:1px solid var(--line);border-radius:11px;padding:14px;color:var(--ink);opacity:.9;white-space:pre-wrap"><?= e($r['comment_body']) ?></p>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="decide">
        <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
        <div class="row" style="margin-top:14px">
          <button class="btn-sm"          name="action" value="dismiss" type="submit">Dismiss</button>
          <button class="btn-sm btn-red"  name="action" value="warn"    type="submit">Warn + remove</button>
        </div>
        <label>Suspend — length is your call</label>
        <div class="row">
          <?php foreach ([1,3,7,30] as $d): ?>
            <button class="btn-sm" type="submit" name="action" value="suspend"
                    onclick="this.form.days.value=<?= $d ?>"><?= $d ?> <?= $d===1?'day':'days' ?></button>
          <?php endforeach; ?>
          <input type="number" name="days" min="1" max="3650" placeholder="N"
                 style="width:88px;padding:8px 10px;font-family:var(--mono)">
          <button class="btn-sm btn-red" type="submit" name="action" value="suspend">Custom</button>
        </div>
      </form>
    </div>
  <?php endforeach; endif; ?>

<?php elseif ($tab === 'bans'): ?>
  <?php if (!$bans): ?>
    <div class="card empty"><h3>Nobody is suspended</h3></div>
  <?php else: foreach ($bans as $b): ?>
    <div class="card">
      <div class="row">
        <b style="font-size:15px"><?= e($b['name']) ?></b>
        <span class="meta">until <?= e(date('j M Y, H:i', strtotime($b['suspended_until']))) ?> UTC</span>
      </div>
      <div class="row" style="margin-top:12px">
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="unban">
          <input type="hidden" name="user_id" value="<?= (int)$b['id'] ?>">
          <button class="btn-sm" type="submit">Lift suspension</button>
        </form>
        <a class="btn-sm" style="border:1px solid var(--line);padding:8px 13px;border-radius:9px;text-decoration:none;color:var(--ink)"
           href="profile.php?id=<?= (int)$b['id'] ?>">View profile</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

<?php else: ?>
  <?php if (!$history): ?>
    <div class="card empty"><h3>No decisions yet</h3></div>
  <?php else: foreach ($history as $h): ?>
    <div class="card"><div class="row">
      <span class="tag"><?= e($h['state']) ?><?= $h['days'] ? ' · '.(int)$h['days'].'d' : '' ?></span>
      <span class="meta"><?= e($h['reason']) ?> · <?= e($h['comment_author_name']) ?> · <?= e(ago($h['created_at'])) ?><?= $h['reviewer'] ? ' · by '.e($h['reviewer']) : '' ?></span>
    </div></div>
  <?php endforeach; endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
