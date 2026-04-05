<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin_auth();

$db     = get_db();
$treeId = (int)($_GET['id'] ?? $_POST['tree_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM trees WHERE id = ?');
$stmt->execute([$treeId]);
$tree = $stmt->fetch();
if (!$tree) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><p>' . e(t('tree.not_found')) . ' <a href="' . e(url('home')) . '">' . e(t('shared.go_home')) . '</a></p></body></html>';
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        // Delete all photos belonging to this tree first
        $stmt2 = $db->prepare('SELECT photo_filename FROM persons WHERE tree_id = ?');
        $stmt2->execute([$treeId]);
        foreach ($stmt2->fetchAll() as $row) {
            delete_photo($row['photo_filename'], $treeId);
        }
        $db->prepare('DELETE FROM trees WHERE id = ?')->execute([$treeId]);
        redirect(url('home'));
    }

    $title = clean_text($_POST['title'] ?? '', 255);
    if ($title === '') {
        $error = t('edit_tree.err_empty');
    } else {
        $db->prepare('UPDATE trees SET title = ? WHERE id = ?')->execute([$title, $treeId]);
        redirect(url('tree', ['id' => $treeId]));
    }
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('edit_tree.title_prefix')) ?> – <?= e($tree['title']) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

<?php
$breadcrumbs = [
    ['url' => url('tree', ['id' => $treeId]), 'label' => $tree['title']],
    ['label' => t('edit_tree.breadcrumb')],
];
include __DIR__ . '/../includes/nav.php';
?>

<div class="container">
    <div class="card form-card">
        <div class="page-header"><h1><?= e(t('edit_tree.heading')) ?></h1></div>

        <?php if ($error): ?>
            <div class="alert-msg alert-msg--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('tree.edit', ['id' => $treeId])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="tree_id" value="<?= e($treeId) ?>">

            <div class="form-group">
                <label for="title"><?= e(t('edit_tree.label')) ?> <span class="required">*</span></label>
                <input type="text" id="title" name="title"
                       value="<?= e($_POST['title'] ?? $tree['title']) ?>"
                       maxlength="255" required autofocus>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('shared.save')) ?></button>
                <a href="<?= e(url('tree', ['id' => $treeId])) ?>" class="btn btn-secondary"><?= e(t('shared.cancel')) ?></a>
            </div>
        </form>

        <!-- ── Delete tree ── -->
        <div class="delete-confirm">
            <p><strong><?= e(t('shared.danger_zone')) ?></strong> <?= e(t('edit_tree.danger_desc')) ?></p>
            <form method="post" action="<?= e(url('tree.edit', ['id' => $treeId])) ?>"
                  data-confirm="<?= e(t('edit_tree.confirm_delete', addslashes($tree['title']))) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="delete">
                <input type="hidden" name="tree_id" value="<?= e($treeId) ?>">
                <button type="submit" class="btn btn-danger btn-sm"><?= e(t('edit_tree.btn_delete')) ?></button>
            </form>
        </div>
    </div>
</div>

<script src="/js/forms.js"></script>

</body>
</html>
