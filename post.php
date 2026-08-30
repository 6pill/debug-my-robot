<?php
require_once __DIR__ . '/includes/lib.php';

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT p.*, u.name AS author_name FROM posts p JOIN users u ON u.id = p.author_id WHERE p.id = ?');
$st->execute([$id]);
$post = $st->fetch();

if (!$post) {
    $pageTitle = 'Not found — ' . SITE_NAME;
    require __DIR__ . '/includes/header.php';
    echo '<div class="card empty"><h3>Post not found</h3><p>It may have been removed.</p>
          <div style="height:12px"></div><a class="btn-a" href="index.php">Back to the feed</a></div>';
    require __DIR__ . '/includes/footer.php'; exit;
}

$me   = current_user();
$isOP = $me && (int)$me['id'] === (int)$post['author_id'];

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $me = require_login();
    $do = $_POST['do'] ?? '';

    if (is_banned($me)) { flash('Your account is suspended.'); redirect("post.php?id=$id"); }

    /* --- add a reply --- */
    if ($do === 'reply') {
        $body = trim($_POST['body'] ?? '');
        if ($body === '') { flash('Write something first.'); redirect("post.php?id=$id"); }
        if (rate_blocked('comments', (int)$me['id'], COMMENT_LIMIT_PER_HOUR)) {
            flash('Comment limit reached — ' . COMMENT_LIMIT_PER_HOUR . ' per hour.');
            redirect("post.php?id=$id");
        }
        $ins = db()->prepare('INSERT INTO comments (post_id,author_id,body,code) VALUES (?,?,?,?)');
        $ins->execute([$id, $me['id'], $body, $_POST['code'] ?: null]);
        if ((int)$post['author_id'] !== (int)$me['id']) {
            notify((int)$post['author_id'], $me['name'] . ' replied to "' . mb_strimwidth($post['title'],0,60,'…') . '"', $id);
        }
        redirect("post.php?id=$id#c" . (int)db()->lastInsertId());
    }

    /* --- award or withdraw "It worked!" --- */
    if ($do === 'award') {
        // Server checks: only the post author, never their own comment.
        if (!$isOP) { http_response_code(403); exit('Only the post author can award credit.'); }
        $cid = (int)($_POST['comment_id'] ?? 0);
        $c = db()->prepare('SELECT * FROM comments WHERE id = ? AND post_id = ?');
        $c->execute([$cid, $id]);
        $comment = $c->fetch();
        if (!$comment) { flash('That comment is gone.'); redirect("post.php?id=$id"); }
        if ((int)$comment['author_id'] === (int)$me['id']) { http_response_code(403); exit('You cannot credit your own comment.'); }

        $helper = db()->prepare('SELECT * FROM users WHERE id = ?');
        $helper->execute([$comment['author_id']]);
        $helper = $helper->fetch();

        db()->beginTransaction();
        try {
            if ($comment['accepted']) {
                db()->prepare('UPDATE comments SET accepted = 0 WHERE id = ?')->execute([$cid]);
                db()->prepare('INSERT INTO coin_ledger (to_user_id,delta,reason,post_id) VALUES (?,?,?,?)')
                    ->execute([$comment['author_id'], -1, 'credit removed on "' . mb_strimwidth($post['title'],0,80,'…') . '"', $id]);
                flash('Credit removed. The ledger keeps the record.');
            } else {
                $tooNew = (time() - strtotime($helper['created_at'])) < MIN_ACCOUNT_AGE_DAYS * 86400;

                // Single-solution posts: credit moves off the previous answer.
                if (!$post['multi']) {
                    $prev = db()->prepare('SELECT * FROM comments WHERE post_id = ? AND accepted = 1');
                    $prev->execute([$id]);
                    foreach ($prev->fetchAll() as $old) {
                        db()->prepare('UPDATE comments SET accepted = 0 WHERE id = ?')->execute([$old['id']]);
                        db()->prepare('INSERT INTO coin_ledger (to_user_id,delta,reason,post_id) VALUES (?,?,?,?)')
                            ->execute([$old['author_id'], -1, 'credit moved off an earlier comment on "' . mb_strimwidth($post['title'],0,60,'…') . '"', $id]);
                    }
                }

                db()->prepare('UPDATE comments SET accepted = 1 WHERE id = ?')->execute([$cid]);

                if ($tooNew) {
                    flash('Marked as the fix, but no coin — accounts pay out after ' . MIN_ACCOUNT_AGE_DAYS . ' days.');
                } else {
                    db()->prepare('INSERT INTO coin_ledger (to_user_id,delta,reason,post_id) VALUES (?,?,?,?)')
                        ->execute([$comment['author_id'], 1, 'accepted solution on "' . mb_strimwidth($post['title'],0,80,'…') . '"', $id]);
                    notify((int)$comment['author_id'],
                           'Your comment on "' . mb_strimwidth($post['title'],0,50,'…') . '" was marked It worked! (+1 coin)', $id);
                    flash('Marked as the fix. +1 Knowledge Coin.');
                }
            }
            // Status follows whether any accepted answer remains.
            $n = db()->prepare('SELECT COUNT(*) FROM comments WHERE post_id = ? AND accepted = 1');
            $n->execute([$id]);
            db()->prepare('UPDATE posts SET status = ? WHERE id = ?')
                ->execute([$n->fetchColumn() ? 'solved' : 'open', $id]);
            db()->commit();
        } catch (Throwable $ex) { db()->rollBack(); flash('That did not go through. Try again.'); }
        redirect("post.php?id=$id");
    }

    /* --- report a comment --- */
    if ($do === 'report') {
        if (!$isOP) { http_response_code(403); exit('Only the post author can report comments on their post.'); }
        $cid    = (int)($_POST['comment_id'] ?? 0);
        $reason = $_POST['reason'] ?? '';
        if (!in_array($reason, ['spam','unhelpful','abusive','off-topic'], true)) { flash('Pick a reason.'); redirect("post.php?id=$id"); }
        $dupe = db()->prepare("SELECT 1 FROM reports WHERE comment_id = ? AND state = 'open'");
        $dupe->execute([$cid]);
        if ($dupe->fetch()) { flash('That comment is already in the review queue.'); redirect("post.php?id=$id"); }
        db()->prepare('INSERT INTO reports (comment_id,post_id,reporter_id,reason) VALUES (?,?,?,?)')
            ->execute([$cid, $id, $me['id'], $reason]);
        flash('Report sent to the review queue.');
        redirect("post.php?id=$id");
    }
}

/* ---------------- view ---------------- */
$cs = db()->prepare(
  'SELECT c.*, u.name AS author_name, u.id AS uid
   FROM comments c JOIN users u ON u.id = c.author_id
   WHERE c.post_id = ? AND c.hidden = 0 ORDER BY c.accepted DESC, c.created_at ASC');
$cs->execute([$id]);
$comments = $cs->fetchAll();

$images = $post['images'] ? (json_decode($post['images'], true) ?: []) : [];
$pageTitle = $post['title'] . ' — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
?>

<p style="margin:20px 0"><a href="index.php">&larr; All problems</a></p>

<div class="card">
  <div class="row" style="margin-bottom:12px">
    <span class="tag"><?= e($post['board']) ?></span>
    <span class="tag"><?= e($post['category']) ?></span>
    <span class="meta st-<?= e($post['status']) ?>">&#9679; <?= e($post['status']) ?></span>
    <?php if ($post['multi']): ?><span class="meta">multi-solution</span><?php endif; ?>
  </div>
  <h2><?= e($post['title']) ?></h2>
  <p class="meta">by <a href="profile.php?id=<?= (int)$post['author_id'] ?>"><?= e($post['author_name']) ?></a> · <?= e(ago($post['created_at'])) ?></p>
  <p style="color:var(--ink);opacity:.9;white-space:pre-wrap"><?= e($post['body']) ?></p>
  <?= code_block($post['code'], 'code') ?>
  <?= code_block($post['log'], 'log output') ?>
  <?php foreach ($images as $src): ?>
    <img class="shot" src="<?= e($src) ?>" alt="" loading="lazy">
  <?php endforeach; ?>
</div>

<h3 style="margin:26px 0 14px"><?= count($comments) ?> <?= count($comments)===1?'reply':'replies' ?></h3>

<?php if (!$comments): ?>
  <div class="card empty"><h3>No replies yet</h3><p>Be the one who knows why.</p></div>
<?php else: foreach ($comments as $c):
  $cc = coins_of((int)$c['uid']); ?>
  <div class="card <?= $c['accepted'] ? 'accepted' : '' ?>" id="c<?= (int)$c['id'] ?>">
    <div class="row" style="margin-bottom:8px">
      <a class="meta" href="profile.php?id=<?= (int)$c['uid'] ?>"><?= e($c['author_name']) ?></a>
      <?= rank_badge($cc) ?>
      <span class="meta"><?= e(ago($c['created_at'])) ?></span>
    </div>
    <p style="color:var(--ink);opacity:.9;white-space:pre-wrap"><?= e($c['body']) ?></p>
    <?= code_block($c['code'], 'code') ?>

    <div class="row" style="margin-top:14px">
      <?php if ($c['accepted']): ?>
        <span class="btn-sm" style="background:rgba(74,222,128,.12);border:1px solid #2C5C3E;color:var(--green);border-radius:9px;padding:8px 13px">&#10003; accepted solution</span>
      <?php endif; ?>

      <?php if ($isOP && (int)$c['uid'] !== (int)$me['id'] && !is_banned($me)): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="award">
          <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
          <button class="btn-sm <?= $c['accepted']?'':'btn-a' ?>" type="submit">
            <?= $c['accepted'] ? 'Remove credit' : '&#10003; It worked!' ?>
          </button>
        </form>
        <details style="display:inline-block">
          <summary class="btn-sm btn-red" style="list-style:none;cursor:pointer;display:inline-block">Report</summary>
          <form method="post" style="margin-top:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="report">
            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
            <div class="row">
              <?php foreach (['spam','unhelpful','abusive','off-topic'] as $r): ?>
                <button class="btn-sm" name="reason" value="<?= $r ?>" type="submit"><?= $r ?></button>
              <?php endforeach; ?>
            </div>
          </form>
        </details>
      <?php elseif ($isOP && (int)$c['uid'] === (int)$me['id']): ?>
        <span class="meta">You can't credit your own comment</span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php if ($me && !is_banned($me)): ?>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reply">
    <label for="body" style="margin-top:0">Your reply</label>
    <textarea id="body" name="body" rows="4" required placeholder="What you think is going wrong, and how to confirm it…"></textarea>
    <label for="code">Code (optional)</label>
    <textarea id="code" name="code" class="code" rows="3"></textarea>
    <div style="height:14px"></div>
    <button class="btn-a btn-full" type="submit">Send reply</button>
  </form>
<?php elseif (!$me): ?>
  <div class="card empty">
    <h3>Sign in to reply</h3><p>Only signed-in members can answer and earn coins.</p>
    <div style="height:12px"></div>
    <a class="btn-a" href="auth.php?next=<?= urlencode('post.php?id='.$id) ?>">Sign in</a>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
