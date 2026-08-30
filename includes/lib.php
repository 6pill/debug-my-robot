<?php
require_once __DIR__ . '/config.php';

if (DEBUG_MODE) { ini_set('display_errors', 1); error_reporting(E_ALL); }
else { ini_set('display_errors', 0); }

date_default_timezone_set('UTC');

/* ---------- session ---------- */
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/* ---------- database ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit(DEBUG_MODE
                ? 'Database connection failed: ' . htmlspecialchars($e->getMessage())
                : 'The site is having trouble reaching its database.');
        }
    }
    return $pdo;
}

/* ---------- output safety ---------- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!$sent || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Security check failed. Go back, reload the page, and try again.');
    }
}

/* ---------- current user ---------- */
function current_user(): ?array {
    static $cached = false, $user = null;
    if ($cached) return $user;
    $cached = true;
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $user = $st->fetch() ?: null;
    if (!$user) unset($_SESSION['uid']);
    return $user;
}
function require_login(): array {
    $u = current_user();
    if (!$u) { redirect('auth.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')); }
    return $u;
}
function require_dev(): array {
    $u = require_login();
    if ($u['role'] !== 'dev') { http_response_code(403); exit('This page is limited to moderators.'); }
    return $u;
}
function is_banned(?array $u): bool {
    return $u && $u['suspended_until'] && strtotime($u['suspended_until']) > time();
}

/* ---------- navigation ---------- */
function redirect(string $to): void { header('Location: ' . $to); exit; }
function flash(string $msg): void { $_SESSION['flash'] = $msg; }
function take_flash(): ?string {
    if (empty($_SESSION['flash'])) return null;
    $m = $_SESSION['flash']; unset($_SESSION['flash']); return $m;
}

/* ---------- coins and ranks ---------- */
function coins_of(int $userId): int {
    $st = db()->prepare('SELECT COALESCE(SUM(delta),0) AS c FROM coin_ledger WHERE to_user_id = ?');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

function ranks(): array {
    return [
        [1,'First-Timer','#8A97A8'],       [5,'Bronze Helper','#B45309'],
        [15,'Silver Helper','#94A3B8'],    [50,'Gold Helper','#EAB308'],
        [100,'Bronze Professional','#92400E'], [150,'Diamond Helper','#06B6D4'],
        [200,'Silver Professional','#64748B'], [250,'Amethyst Helper','#A855F7'],
        [300,'Gold Professional','#CA8A04'],   [450,'Diamond Professional','#0891B2'],
        [500,'Amethyst Professional','#9333EA'],[750,'Professional Helper','#15803D'],
        [1000,'Experienced Professional','#4ADE80'],
    ];
}
function rank_for(int $coins): array {
    $r = [0,'Unranked','#4E5A6B'];
    foreach (ranks() as $k) if ($coins >= $k[0]) $r = $k;
    return $r;
}
function rank_badge(int $coins): string {
    [$at,$name,$col] = rank_for($coins);
    return '<span class="dot" style="background:' . e($col) . '"></span> <span class="meta">' . e($name) . '</span>';
}

/* ---------- rate limiting (server side) ---------- */
function rate_blocked(string $table, int $userId, int $limit): bool {
    $t = $table === 'posts' ? 'posts' : 'comments';   // whitelist
    $st = db()->prepare("SELECT COUNT(*) FROM {$t} WHERE author_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $st->execute([$userId]);
    return (int)$st->fetchColumn() >= $limit;
}

/* ---------- notifications ---------- */
function notify(int $userId, string $text, ?int $postId = null): void {
    $st = db()->prepare('INSERT INTO notifications (user_id, text, post_id) VALUES (?,?,?)');
    $st->execute([$userId, $text, $postId]);
}
function unread_count(int $userId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}
function open_reports_count(): int {
    return (int)db()->query("SELECT COUNT(*) FROM reports WHERE state = 'open'")->fetchColumn();
}

/* ---------- misc ---------- */
function ago($ts): string {
    $s = time() - strtotime($ts);
    if ($s < 90)    return 'just now';
    if ($s < 3600)  return floor($s/60) . 'm ago';
    if ($s < 86400) return floor($s/3600) . 'h ago';
    return floor($s/86400) . 'd ago';
}

function boards(): array {
    return ['Arduino','Raspberry Pi','ESP32','STM32','micro:bit','Other'];
}
function categories(): array {
    return ['Power','Wiring','Firmware / Code','Sensors','Motors & Actuators','Networking','Mechanical'];
}

/** Collapsible code block. */
function code_block(?string $text, string $label): string {
    if (!trim((string)$text)) return '';
    $lines = substr_count($text, "\n") + 1;
    return '<details class="codewrap"' . ($lines <= 8 ? ' open' : '') . '>'
         . '<summary>' . e($label) . ' <span>' . $lines . ' lines</span></summary>'
         . '<pre>' . e($text) . '</pre></details>';
}
