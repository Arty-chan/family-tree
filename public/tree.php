<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tree_builder.php';

$db     = get_db();
$treeId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM trees WHERE id = ?');
$stmt->execute([$treeId]);
$tree = $stmt->fetch();
if (!$tree) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><p>' . e(t('tree.not_found')) . ' <a href="' . e(url('login')) . '">' . e(t('shared.go_home')) . '</a></p></body></html>';
    exit;
}

require_tree_auth($treeId);

$treeData = build_tree_data($db, $treeId);
$treeDataJson = json_encode([
    'rootNodes'   => $treeData['rootNodes'],
    'cousinPairs' => $treeData['cousinPairs'],
    'allPersons'  => $treeData['allPersons'],
    'editMode'    => true,
    'urls'        => [
        'editMember' => url('member.edit', ['id' => '__ID__']),
        'photo'      => url('photo', ['id' => '__ID__']),
    ],
    'i18n'        => [
        'edit'   => t('js.edit'),
        'cousin' => t('rel_badge.cousin'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($tree['title']) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

<?php
$navClass = 'no-print';
$breadcrumbs = [['label' => $tree['title']]];
include __DIR__ . '/../includes/nav.php';
?>

<div class="container">

    <!-- ── Header ── -->
    <div class="page-header">
        <div class="tree-title-wrap">
            <h1><?= e($tree['title']) ?></h1>
            <?php if (is_admin_authed()): ?>
                <a href="<?= e(url('tree.edit', ['id' => $treeId])) ?>" class="tree-title-edit no-print" title="<?= e(t('tree.edit_title')) ?>"><?= e(t('tree.edit_title')) ?></a>
            <?php endif; ?>
        </div>

        <div class="actions no-print">
            <button id="clean-view-btn" class="btn btn-secondary" data-edit-label="<?= e(t('tree.edit_view')) ?>"><?= e(t('tree.clean_view')) ?></button>
            <a href="<?= e(url('tree.add_member', ['id' => $treeId])) ?>" class="btn btn-primary"><?= e(t('tree.add_member')) ?></a>
        </div>
    </div>

    <!-- ── Tree ── -->
    <div class="card card-flush">
        <div class="tree-outer">
            <div id="tree-container">
                <noscript>
                    <p class="noscript-msg">
                        <?= e(t('tree.noscript')) ?>
                    </p>
                </noscript>
            </div>
        </div>
    </div>

    <?php if (empty($treeData['allPersons'])): ?>
        <p class="text-muted mt-sm"><?= e(t('tree.no_members')) ?> <a href="<?= e(url('tree.add_member', ['id' => $treeId])) ?>"><?= e(t('tree.add_first')) ?></a></p>
    <?php endif; ?>

</div><!-- /container -->

<script id="tree-data" type="application/json"><?= $treeDataJson ?></script>
<script src="/js/tree.js"></script>
<script src="/js/forms.js"></script>

</body>
</html>
