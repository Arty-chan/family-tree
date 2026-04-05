<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Handle signout from any page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'signout') {
    csrf_validate();
    session_destroy();
    redirect(url('login'));
}

// Already logged in — go straight through
if (is_admin_authed()) {
    redirect(url('home'));
}

$db    = get_db();
$error = null;
$next  = $_GET['next'] ?? $_POST['next'] ?? '';

// Find trees the user already has access to
$activeTreeNames = [];
if (!empty($_SESSION[SESSION_TREES]) && is_array($_SESSION[SESSION_TREES])) {
    $ids = array_map('intval', $_SESSION[SESSION_TREES]);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, title FROM trees WHERE id IN ($placeholders) ORDER BY title COLLATE NOCASE");
    $stmt->execute($ids);
    $activeTreeNames = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $password  = $_POST['password']  ?? '';
    $honeypot  = $_POST['website']   ?? '';   // honeypot field — must be empty

    // Check rate limit first
    $rlError = check_rate_limit('login');
    if ($rlError) {
        $error = $rlError;
    } elseif ($honeypot !== '') {
        // If the honeypot is checked/filled, silently show a generic error
        // (don't reveal that a trap was triggered)
        $error = t('login.error_incorrect');
    } else {
        $matched = false;

        // 1) Check admin password
        $adminHash = get_setting($db, 'admin_password_hash');
        if ($adminHash && password_verify($password, $adminHash)) {
            reset_rate_limit('login');
            session_regenerate_id(true);
            $_SESSION[SESSION_ADMIN] = true;
            redirect($next ? safe_next($next) : url('home'));
        }

        // 2) Check each tree's password — collect ALL matching tree IDs
        $trees = $db->query('SELECT id, password_hash FROM trees WHERE password_hash IS NOT NULL')->fetchAll();
        $matchedIds = [];
        foreach ($trees as $t_row) {
            if (password_verify($password, $t_row['password_hash'])) {
                $matchedIds[] = (int)$t_row['id'];
            }
        }
        if ($matchedIds) {
            reset_rate_limit('login');
            session_regenerate_id(true);
            foreach ($matchedIds as $id) {
                grant_tree_access($id);
            }
            // If ?next= targets a tree we just unlocked, go there
            if ($next) {
                $nextUrl = safe_next($next);
                if (preg_match('#/tree/(\d+)/#', $nextUrl, $m)
                    && in_array((int)$m[1], $matchedIds, true)) {
                    redirect($nextUrl);
                }
            }
            // Otherwise go to the first matched tree
            redirect(url('tree', ['id' => $matchedIds[0]]));
        }

        record_failed_attempt('login');
        $error = t('login.error_incorrect');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('login.title')) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="login-body">

<div class="card login-card">
    <div class="login-header">
        <h1><?= e(t('shared.site_title')) ?></h1>
        <form method="post" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_lang">
            <button type="submit" class="btn btn-primary"><?= get_lang() === 'en' ? '🇨🇳 中文' : '🇬🇧 English' ?></button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="alert-msg alert-msg--error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($activeTreeNames): ?>
        <div class="active-session-banner">
            <?= e(t('login.already_signed_in')) ?>
            <?php foreach ($activeTreeNames as $at): ?>
                <a href="<?= e(url('tree', ['id' => $at['id']])) ?>"><?= e($at['title']) ?> &rarr;</a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <div class="form-group">
            <label for="password"><?= e(t('login.password')) ?></label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" autofocus required>
        </div>

        <!-- ── Honeypot (invisible to real users) ── -->
        <div class="hp-field" aria-hidden="true">
            <label for="website">Check this box to continue</label>
            <input type="checkbox" id="website" name="website" value="1" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-full"><?= e(t('login.submit')) ?></button>
        </div>
    </form>
</div>

</body>
</html>
