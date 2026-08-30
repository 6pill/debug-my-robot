<?php
require_once __DIR__ . '/includes/lib.php';
$me = require_login();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (is_banned($me)) {
        $error = 'Your account is suspended, so you cannot post right now.';
    } elseif (rate_blocked('posts', (int)$me['id'], POST_LIMIT_PER_HOUR)) {
        $error = 'Post limit reached — ' . POST_LIMIT_PER_HOUR . ' per hour.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        $board = $_POST['board'] ?? '';
        $cat   = $_POST['category'] ?? '';
        $other = trim($_POST['other'] ?? '');

        if ($board === 'Other' && $other !== '') $board = mb_substr($other, 0, 60);

        if ($title === '' || $body === '')                     $error = 'A title and a description are required.';
        elseif (mb_strlen($title) > 200)                       $error = 'Keep the title under 200 characters.';
        elseif (!in_array($cat, categories(), true))           $error = 'Pick a category.';
        elseif ($board === '' || $board === 'Other')           $error = 'Name the board so helpers know what they are looking at.';
        else {
            // Keep only https image URLs, max 4.
            $images = array_slice(array_values(array_filter(
                array_map('trim', preg_split('/\r?\n/', $_POST['images'] ?? '')),
                fn($u) => (bool)filter_var($u, FILTER_VALIDATE_URL) && stripos($u, 'https://') === 0
            )), 0, 4);

            $st = db()->prepare(
              'INSERT INTO posts (author_id,board,category,title,body,code,log,images,multi)
               VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $me['id'], $board, $cat, $title, $body,
                $_POST['code'] ?: null, $_POST['log'] ?: null,
                $images ? json_encode($images) : null,
                ($_POST['multi'] ?? '0') === '1' ? 1 : 0,
            ]);
            flash('Published.');
            redirect('post.php?id=' . (int)db()->lastInsertId());
        }
    }
}

$pageTitle = 'Post a problem — ' . SITE_NAME;
require __DIR__ . '/includes/header.php';
$multi = ($_POST['multi'] ?? '0') === '1';
?>

<div style="margin:26px 0 20px">
  <h2>Post a problem</h2>
  <p>The more precise the symptom, the faster someone spots it.</p>
</div>

<?php if ($error): ?><div class="flash" style="border-color:#4A2A22;background:rgba(242,114,79,.1)"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="card">
  <?= csrf_field() ?>

  <label for="title" style="margin-top:0">Title</label>
  <input id="title" name="title" maxlength="200" required
         placeholder="ESP32 resets every time the servo moves" value="<?= e($_POST['title'] ?? '') ?>">

  <label for="board">Board / platform</label>
  <select id="board" name="board" onchange="document.getElementById('otherWrap').hidden = (this.value !== 'Other')">
    <?php foreach (boards() as $b): ?>
      <option <?= (($_POST['board'] ?? '')===$b)?'selected':'' ?>><?= e($b) ?></option>
    <?php endforeach; ?>
  </select>
  <div id="otherWrap" <?= (($_POST['board'] ?? '') === 'Other') ? '' : 'hidden' ?>>
    <label for="other">Board name</label>
    <input id="other" name="other" maxlength="60" value="<?= e($_POST['other'] ?? '') ?>">
  </div>

  <label for="category">Category</label>
  <select id="category" name="category">
    <?php foreach (categories() as $c): ?>
      <option <?= (($_POST['category'] ?? '')===$c)?'selected':'' ?>><?= e($c) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="body">What's wrong, and what have you tried?</label>
  <textarea id="body" name="body" rows="6" required
    placeholder="Symptom, wiring, power supply, and everything you've already ruled out…"><?= e($_POST['body'] ?? '') ?></textarea>

  <label for="code">Code snippet (optional)</label>
  <textarea id="code" name="code" class="code" rows="4"><?= e($_POST['code'] ?? '') ?></textarea>

  <label for="log">Error log / serial output (optional)</label>
  <textarea id="log" name="log" class="code" rows="4"><?= e($_POST['log'] ?? '') ?></textarea>

  <label for="images">Image URLs (optional, one per line, max 4)</label>
  <textarea id="images" name="images" class="code" rows="3" placeholder="https://…/wiring.jpg"><?= e($_POST['images'] ?? '') ?></textarea>

  <label>How many answers can be accepted?</label>
  <label class="choice <?= $multi?'':'on' ?>">
    <input type="radio" name="multi" value="0" <?= $multi?'':'checked' ?>
           onchange="document.querySelectorAll('.choice').forEach(c=>c.classList.toggle('on', c.contains(this)))">
    <span class="radio"></span>
    <span><b>One solution</b><em>A single comment gets the coin.</em></span>
  </label>
  <label class="choice <?= $multi?'on':'' ?>">
    <input type="radio" name="multi" value="1" <?= $multi?'checked':'' ?>
           onchange="document.querySelectorAll('.choice').forEach(c=>c.classList.toggle('on', c.contains(this)))">
    <span class="radio"></span>
    <span><b>Multiple solutions</b><em>Several comments can each earn a coin (e.g. one fixed the wiring, another the code).</em></span>
  </label>

  <div style="height:14px"></div>
  <button class="btn-a btn-full" type="submit" <?= is_banned($me)?'disabled':'' ?>>Publish problem</button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
