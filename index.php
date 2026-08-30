<?php
require_once __DIR__ . '/includes/lib.php';

$q      = trim($_GET['q'] ?? '');
$board  = $_GET['board']  ?? 'all';
$cat    = $_GET['cat']    ?? 'all';
$status = $_GET['status'] ?? 'all';
$sort   = $_GET['sort']   ?? 'new';

$where = []; $args = [];
if ($q !== '')                              { $where[] = '(p.title LIKE ? OR p.body LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
if ($board !== 'all')                       { $where[] = 'p.board = ?';    $args[] = $board; }
if ($cat !== 'all')                         { $where[] = 'p.category = ?'; $args[] = $cat; }
if (in_array($status, ['open','solved'], true)) { $where[] = 'p.status = ?'; $args[] = $status; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$order = $sort === 'old'  ? 'p.created_at ASC'
       : ($sort === 'busy' ? 'reply_count DESC, p.created_at DESC'
       : 'p.created_at DESC');

$sql = "SELECT p.*, u.name AS author_name,
        (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.hidden = 0) AS reply_count
        FROM posts p JOIN users u ON u.id = p.author_id
        $whereSql ORDER BY $order LIMIT 100";
$st = db()->prepare($sql);
$st->execute($args);
$posts = $st->fetchAll();

$totalPosts = (int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn();

$pageTitle = SITE_NAME . ' — community hardware support';
require __DIR__ . '/includes/header.php';

$keep = fn($k, $v) => e('?' . http_build_query(array_merge(
    ['q'=>$q,'board'=>$board,'cat'=>$cat,'status'=>$status,'sort'=>$sort], [$k=>$v])));
?>

<div class="hero">
  <h1>Something on your board <em>isn't doing what it should.</em></h1>
  <p class="lead">Describe the board, the symptom and what you've already tried. When a comment actually fixes it, hit <b style="color:var(--ink)">It worked!</b> and pay that person a Knowledge Coin.</p>
  <div class="row" style="margin-top:18px">
    <a class="btn-a" href="<?= $me ? 'new.php' : 'auth.php' ?>">Post a problem</a>
    <a class="btn-a" style="background:transparent;color:var(--ink);border-color:var(--line)" href="leaders.php">&#9677; Top helpers</a>
    <a class="btn-a" style="background:transparent;color:var(--ink);border-color:var(--line)" href="ranks.php">Rank ladder</a>
  </div>
</div>

<form method="get" class="filters">
  <input class="full" name="q" placeholder="Search problems&hellip;" value="<?= e($q) ?>">
  <select name="board" onchange="this.form.submit()">
    <option value="all">All boards</option>
    <?php foreach (boards() as $b): ?>
      <option <?= $board===$b?'selected':'' ?>><?= e($b) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="cat" onchange="this.form.submit()">
    <option value="all">All categories</option>
    <?php foreach (categories() as $c): ?>
      <option <?= $cat===$c?'selected':'' ?>><?= e($c) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" onchange="this.form.submit()">
    <option value="all">Any status</option>
    <option value="open"   <?= $status==='open'?'selected':'' ?>>Open</option>
    <option value="solved" <?= $status==='solved'?'selected':'' ?>>Solved</option>
  </select>
  <select name="sort" onchange="this.form.submit()">
    <option value="new"  <?= $sort==='new'?'selected':'' ?>>Newest</option>
    <option value="old"  <?= $sort==='old'?'selected':'' ?>>Oldest</option>
    <option value="busy" <?= $sort==='busy'?'selected':'' ?>>Most replies</option>
  </select>
  <noscript><button class="full btn-a" type="submit">Apply filters</button></noscript>
</form>

<?php if (!$posts): ?>
  <div class="card empty">
    <h3>Nothing here yet</h3>
    <p><?= $totalPosts === 0
        ? 'No posts at all. Be the first to ask for help.'
        : 'No posts match this filter.' ?></p>
    <div style="height:14px"></div>
    <a class="btn-a" href="<?= $me ? 'new.php' : 'auth.php' ?>">Post a problem</a>
  </div>
<?php else: foreach ($posts as $p): ?>
  <a class="post" href="post.php?id=<?= (int)$p['id'] ?>">
    <div class="row" style="margin-bottom:10px">
      <span class="tag"><?= e($p['board']) ?></span>
      <span class="tag"><?= e($p['category']) ?></span>
      <span class="meta st-<?= e($p['status']) ?>">&#9679; <?= e($p['status']) ?></span>
      <?php if ($p['multi']): ?><span class="meta">multi-solution</span><?php endif; ?>
    </div>
    <h3><?= e($p['title']) ?></h3>
    <p style="margin:8px 0 10px"><?= e(mb_strimwidth($p['body'], 0, 150, '…')) ?></p>
    <p class="meta" style="margin:0"><?= e($p['author_name']) ?> · <?= (int)$p['reply_count'] ?> <?= $p['reply_count']==1?'reply':'replies' ?> · <?= e(ago($p['created_at'])) ?></p>
  </a>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
