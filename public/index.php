<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin_auth();

$db      = get_db();
$error   = null;
$success = null;

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = $_POST['action'] ?? '';

    // ── Set / change a tree's password ────────────────────────────────────────
    if ($action === 'set_tree_password') {
        $treeId = (int)($_POST['tree_id'] ?? 0);
        $newPw  = $_POST['tree_password'] ?? '';
        if ($treeId > 0 && $newPw !== '') {
            if (mb_strlen($newPw) < 8) {
                $error = t('admin.err_tree_pw_short');
            } else {
                $db->prepare('UPDATE trees SET password_hash = ? WHERE id = ?')
                   ->execute([password_hash($newPw, PASSWORD_BCRYPT), $treeId]);
                $success = t('admin.success_pw');
            }
        }
    }

    // ── Change admin password ─────────────────────────────────────────────────
    if ($action === 'change_admin_password') {
        $newAdmin     = $_POST['new_admin_password']     ?? '';
        $confirmAdmin = $_POST['confirm_admin_password'] ?? '';

        if ($newAdmin === '') {
            $error = t('admin.err_empty');
        } elseif (mb_strlen($newAdmin) < 8) {
            $error = t('admin.err_admin_short');
        } elseif ($newAdmin !== $confirmAdmin) {
            $error = t('admin.err_mismatch');
        } else {
            set_setting($db, 'admin_password_hash', password_hash($newAdmin, PASSWORD_BCRYPT));
            $success = t('admin.success_pw');
        }
    }

    // ── Create new tree ───────────────────────────────────────────────────────
    if ($action === 'new_tree') {
        $title  = clean_text($_POST['tree_title'] ?? '', 255);
        $treePw = $_POST['tree_password'] ?? '';
        if ($title === '') {
            $error = t('admin.err_title_empty');
        } elseif ($treePw !== '' && mb_strlen($treePw) < 8) {
            $error = t('admin.err_tree_pw_short');
        } else {
            $hash = ($treePw !== '') ? password_hash($treePw, PASSWORD_BCRYPT) : null;
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('INSERT INTO trees (title, password_hash) VALUES (?, ?)');
                $stmt->execute([$title, $hash]);
                $treeId = (int)$db->lastInsertId();
                $db->commit();
                redirect(url('tree', ['id' => $treeId]));
            } catch (\Throwable $ex) {
                $db->rollBack();
                $error = t('member.err_unexpected');
            }
        }
    }

    // ── Sign out entirely ─────────────────────────────────────────────────────
    if ($action === 'signout') {
        session_destroy();
        redirect(url('login'));
    }
}

$trees = $db->query('SELECT * FROM trees ORDER BY title COLLATE NOCASE')->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('admin.title')) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

<?php include __DIR__ . '/../includes/nav.php'; ?>

<div class="container">

    <?php if ($error): ?>
        <div class="alert-msg alert-msg--error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-msg alert-msg--success"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════
         Trees + per-tree passwords
    ══════════════════════════════════════════════════ -->
    <div class="card form-card">
        <div class="page-header"><h1><?= e(t('index.title')) ?></h1></div>

        <?php if ($trees): ?>
            <table class="admin-tree-table">
                <thead>
                    <tr>
                        <th><?= e(t('admin.tree_title_label')) ?></th>
                        <th><?= e(t('admin.tree_pw_label')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trees as $t_row): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('tree', ['id' => $t_row['id']])) ?>"><?= e($t_row['title']) ?></a>
                            <?php if (empty($t_row['password_hash'])): ?>
                                <span class="text-warning"><?= e(t('admin.no_password')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_tree_password">
                                <input type="hidden" name="tree_id" value="<?= e($t_row['id']) ?>">
                                <input type="password" name="tree_password" class="input-sm" placeholder="<?= e(t('admin.new_pw_ph')) ?>"
                                       autocomplete="new-password" minlength="8">
                                <button type="submit" class="btn btn-secondary btn-sm"><?= e(t('shared.save')) ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-trees"><?= e(t('index.no_trees')) ?></p>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════
         Create new tree
    ══════════════════════════════════════════════════ -->
    <div class="card form-card card-stacked">
        <div class="page-header"><h1 class="card-subheading"><?= e(t('admin.create_tree')) ?></h1></div>
        <form method="post" class="form-row-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="new_tree">
            <div class="form-group form-group-inline">
                <label for="tree_title"><?= e(t('admin.tree_title_label')) ?> <span class="required">*</span></label>
                <input type="text" id="tree_title" name="tree_title"
                       value="<?= e($_POST['tree_title'] ?? '') ?>"
                       placeholder="<?= e(t('admin.tree_title_ph')) ?>"
                       maxlength="255" required>
            </div>
            <div class="form-group form-group-inline">
                <label for="new_tree_password"><?= e(t('admin.tree_pw_label')) ?></label>
                <input type="password" id="new_tree_password" name="tree_password"
                       autocomplete="new-password" minlength="8"
                       placeholder="<?= e(t('admin.new_pw_ph')) ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?= e(t('admin.create_btn')) ?></button>
        </form>
    </div>

    <!-- ══════════════════════════════════════════════════
         Change admin password
    ══════════════════════════════════════════════════ -->
    <div class="card form-card card-stacked">
        <div class="page-header"><h1 class="card-subheading"><?= e(t('admin.change_admin_pw')) ?></h1></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_admin_password">
            <div class="form-row">
                <div class="form-group">
                    <label for="new_admin_password"><?= e(t('admin.new_admin_pw')) ?></label>
                    <input type="password" id="new_admin_password" name="new_admin_password"
                           autocomplete="new-password" minlength="8">
                </div>
                <div class="form-group">
                    <label for="confirm_admin_password"><?= e(t('admin.confirm_admin_pw')) ?></label>
                    <input type="password" id="confirm_admin_password" name="confirm_admin_password"
                           autocomplete="new-password" minlength="8">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><?= e(t('admin.save_password')) ?></button>
        </form>
    </div>

</div>

</body>
</html>
