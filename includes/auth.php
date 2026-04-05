<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('__Host-ft_sess');
    session_set_cookie_params([
        'lifetime'  => 0,
        'path'      => '/',
        'httponly'   => true,
        'samesite'  => 'Strict',
        'secure'    => $isSecure,
    ]);
    if (!$isSecure) {
        // On localhost (no HTTPS), __Host- prefix isn't valid — fall back
        session_name('ft_sess');
    }
    session_start();

    // Regenerate session ID periodically to limit session lifespan
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 1800) { // 30 minutes
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/lang.php';
handle_lang_toggle();

// ── Session keys ──────────────────────────────────────────────────────────────
const SESSION_ADMIN = 'ft_admin';   // bool – admin password was correct
const SESSION_TREES = 'ft_trees';   // int[] – tree IDs the user unlocked

// ── Auth checks ───────────────────────────────────────────────────────────────

/** True if the user has admin access OR at least one tree unlocked. */
function is_authenticated(): bool {
    return is_admin_authed() || !empty($_SESSION[SESSION_TREES]);
}

function is_admin_authed(): bool {
    return !empty($_SESSION[SESSION_ADMIN]);
}

/** Grant session access to a specific tree. */
function grant_tree_access(int $treeId): void {
    if (!isset($_SESSION[SESSION_TREES]) || !is_array($_SESSION[SESSION_TREES])) {
        $_SESSION[SESSION_TREES] = [];
    }
    if (!in_array($treeId, $_SESSION[SESSION_TREES], true)) {
        $_SESSION[SESSION_TREES][] = $treeId;
    }
}

/** Check whether the user can view/edit a specific tree. */
function can_access_tree(int $treeId): bool {
    if (is_admin_authed()) return true;
    return is_array($_SESSION[SESSION_TREES] ?? null)
        && in_array($treeId, $_SESSION[SESSION_TREES], true);
}

/**
 * Redirect to login if not authenticated at all.
 */
function require_auth(): void {
    if (!is_authenticated()) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect(url('login') . ($next ? '?next=' . $next : ''));
    }
}

/** Require access to a specific tree, or redirect to login. */
function require_tree_auth(int $treeId): void {
    if (!can_access_tree($treeId)) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect(url('login') . ($next ? '?next=' . $next : ''));
    }
}

function require_admin_auth(): void {
    if (!is_admin_authed()) {
        redirect(url('login'));
    }
}

// ── Settings helpers ──────────────────────────────────────────────────────────

function get_setting(PDO $db, string $key): ?string {
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['value'] : null;
}

function set_setting(PDO $db, string $key, string $value): void {
    $db->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    )->execute([$key, $value]);
}

// ── Sanitise a ?next= redirect target ────────────────────────────────────────
// Only allow relative paths within the app — no external redirects.
function safe_next(string $next): string {
    $decoded = urldecode($next);
    // Must start with / and contain only safe path characters
    if (preg_match('#^/(?!/)[a-zA-Z0-9/_\-\.\?=&]*$#', $decoded)) {
        return $decoded;
    }
    return url('home');
}

// ── CSRF protection ──────────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/** Output a hidden <input> with the CSRF token. */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Validate the CSRF token from a POST request. Aborts with 403 on failure. */
function csrf_validate(): void {
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Invalid or missing CSRF token.');
    }
}

// ── Rate limiting (session-based) ────────────────────────────────────────────

const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 300; // 5 minutes

function check_rate_limit(string $key = 'login'): ?string {
    $sKey = '_rl_' . $key;
    $attempts = $_SESSION[$sKey]['attempts'] ?? 0;
    $lockUntil = $_SESSION[$sKey]['lock_until'] ?? 0;

    if ($lockUntil > time()) {
        $remaining = $lockUntil - time();
        $minutes = (int)ceil($remaining / 60);
        return "Too many failed attempts. Please try again in {$minutes} minute(s). 嘗試次數過多，請在 {$minutes} 分鐘後重試。";
    }

    // Reset if lockout has expired
    if ($lockUntil > 0 && $lockUntil <= time()) {
        unset($_SESSION[$sKey]);
    }

    return null;
}

function record_failed_attempt(string $key = 'login'): void {
    $sKey = '_rl_' . $key;
    $_SESSION[$sKey]['attempts'] = ($_SESSION[$sKey]['attempts'] ?? 0) + 1;
    if ($_SESSION[$sKey]['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $_SESSION[$sKey]['lock_until'] = time() + LOGIN_LOCKOUT_SECONDS;
    }
}

function reset_rate_limit(string $key = 'login'): void {
    unset($_SESSION['_rl_' . $key]);
}
