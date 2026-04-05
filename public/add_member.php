<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$db     = get_db();
$treeId = (int)($_GET['tree_id'] ?? $_POST['tree_id'] ?? 0);
require_tree_auth($treeId);
$currentYear = (int)date('Y');

$stmt = $db->prepare('SELECT * FROM trees WHERE id = ?');
$stmt->execute([$treeId]);
$tree = $stmt->fetch();
if (!$tree) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><p>' . e(t('tree.not_found')) . ' <a href="' . e(url('home')) . '">' . e(t('shared.go_home')) . '</a></p></body></html>';
    exit;
}

// Existing members for the relationship dropdown
$stmt = $db->prepare('SELECT id, name, birth_year FROM persons WHERE tree_id = ? ORDER BY name COLLATE NOCASE');
$stmt->execute([$treeId]);
$members = $stmt->fetchAll();

$error = null;
$vals  = ['name' => '', 'birth_year' => '', 'death_year' => '',
          'birth_year_approx' => '', 'death_year_approx' => '',
          'rel_type' => 'child', 'rel_person_id' => '',
          'year_married' => '', 'year_separated' => '',
          'year_married_approx' => '', 'year_separated_approx' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $vals['name']          = clean_text($_POST['name'] ?? '', 255);
    $vals['birth_year']    = $_POST['birth_year']    ?? '';
    $vals['death_year']    = $_POST['death_year']    ?? '';
    $vals['birth_year_approx']  = !empty($_POST['birth_year_approx'])  ? 1 : 0;
    $vals['death_year_approx']  = !empty($_POST['death_year_approx'])  ? 1 : 0;
    $vals['rel_type']      = $_POST['rel_type']      ?? '';
    $vals['rel_person_id'] = $_POST['rel_person_id'] ?? '';
    $vals['year_married']  = $_POST['year_married']  ?? '';
    $vals['year_separated']= $_POST['year_separated']?? '';
    $vals['year_married_approx']   = !empty($_POST['year_married_approx'])   ? 1 : 0;
    $vals['year_separated_approx'] = !empty($_POST['year_separated_approx']) ? 1 : 0;

    $birthYear = clean_year($vals['birth_year']);
    $deathYear = clean_year($vals['death_year']);
    $relType   = in_array($vals['rel_type'], ['child','parent','spouse','cousin'], true)
                 ? $vals['rel_type'] : '';
    $relPerson = (int)($vals['rel_person_id']);

    if ($vals['name'] === '') {
        $error = t('member.err_name');
    } elseif ($birthYear && $deathYear && $deathYear < $birthYear) {
        $error = t('member.err_death_year');
    } else {
        try {
            // Photo upload
            $photo = null;
            if (!empty($_FILES['photo']['name'])) {
                $photo = handle_photo_upload($_FILES['photo'], $vals['name'], $treeId);
            }

            $db->beginTransaction();

            $stmt = $db->prepare(
                'INSERT INTO persons (tree_id, name, birth_year, death_year, photo_filename, birth_year_approx, death_year_approx)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$treeId, $vals['name'], $birthYear, $deathYear, $photo,
                            $vals['birth_year_approx'], $vals['death_year_approx']]);
            $newId = (int)$db->lastInsertId();

            // Relationship
            if ($relType && $relPerson > 0) {
                // Verify the target member belongs to this tree
                $chk = $db->prepare('SELECT id FROM persons WHERE id = ? AND tree_id = ?');
                $chk->execute([$relPerson, $treeId]);
                if ($chk->fetch()) {
                    // Enforce max 2 parents per child
                    $blocked = false;
                    if ($relType === 'child') {
                        $pc = $db->prepare('SELECT COUNT(*) FROM relationships WHERE tree_id=? AND person2_id=? AND relationship_type=?');
                        $pc->execute([$treeId, $newId, 'parent_child']);
                        if ((int)$pc->fetchColumn() >= 2) { $error = t('member.err_2parents_this'); $blocked = true; }
                    } elseif ($relType === 'parent') {
                        $pc = $db->prepare('SELECT COUNT(*) FROM relationships WHERE tree_id=? AND person2_id=? AND relationship_type=?');
                        $pc->execute([$treeId, $relPerson, 'parent_child']);
                        if ((int)$pc->fetchColumn() >= 2) { $error = t('member.err_2parents_that'); $blocked = true; }
                    }
                    if (!$blocked) {
                        saveRelationship($db, $treeId, $newId, $relPerson, $relType,
                                         clean_year($vals['year_married']),
                                         clean_year($vals['year_separated']),
                                         $vals['year_married_approx'],
                                         $vals['year_separated_approx']);
                    }
                }
            }

            $db->commit();
            if (($_POST['after'] ?? '') === 'another') {
                redirect(url('tree.add_member', ['id' => $treeId]));
            } else {
                redirect(url('tree', ['id' => $treeId]));
            }

        } catch (RuntimeException $ex) {
            if ($db->inTransaction()) $db->rollBack();
            $error = $ex->getMessage();
        } catch (\Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            $error = t('member.err_unexpected');
        }
    }
}

// saveRelationship() is now in includes/db_connect.php
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('member.add_title')) ?> – <?= e($tree['title']) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

<?php
$breadcrumbs = [
    ['url' => url('tree', ['id' => $treeId]), 'label' => $tree['title']],
    ['label' => t('member.add')],
];
include __DIR__ . '/../includes/nav.php';
?>

<div class="container">
<div class="card form-card">
    <div class="page-header"><h1><?= e(t('member.add_title')) ?></h1></div>

    <?php if ($error): ?>
        <div class="alert-msg alert-msg--error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('tree.add_member', ['id' => $treeId])) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="tree_id" value="<?= e($treeId) ?>">

        <?php
            $isEdit      = false;
            $fieldVals   = ['name' => $vals['name'], 'birth_year' => $vals['birth_year'], 'death_year' => $vals['death_year'],
                           'birth_year_approx' => $vals['birth_year_approx'], 'death_year_approx' => $vals['death_year_approx']];
            include __DIR__ . '/../includes/member_fields.php';
        ?>

        <!-- ── Relationship ── -->
        <?php if (!empty($members)): ?>
        <div class="form-section">
            <div class="form-section-title"><?= e(t('member.rel_section')) ?></div>

            <div class="form-row">
                <div class="form-group">
                    <label for="rel_type"><?= e(t('member.rel_type')) ?></label>
                    <select id="rel_type" name="rel_type">
                        <option value=""><?= e(t('member.rel_none')) ?></option>
                        <option value="child"  <?= $vals['rel_type']==='child'  ? 'selected':'' ?>><?= e(t('member.rel_child')) ?></option>
                        <option value="parent" <?= $vals['rel_type']==='parent' ? 'selected':'' ?>><?= e(t('member.rel_parent')) ?></option>
                        <option value="spouse" <?= $vals['rel_type']==='spouse' ? 'selected':'' ?>><?= e(t('member.rel_spouse')) ?></option>
                        <option value="cousin" <?= $vals['rel_type']==='cousin' ? 'selected':'' ?>><?= e(t('member.rel_cousin')) ?></option>
                    </select>
                </div>
                <div class="form-group" id="rel-person-wrap">
                    <label for="rel_person_id"><?= e(t('member.rel_member')) ?></label>
                    <select id="rel_person_id" name="rel_person_id">
                        <option value=""><?= e(t('member.rel_choose')) ?></option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?= e($m['id']) ?>"
                                <?= (string)$vals['rel_person_id']===(string)$m['id'] ? 'selected':'' ?>>
                                <?= e($m['name']) ?><?= $m['birth_year'] ? ' (' . $m['birth_year'] . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="spouse-fields" class="hidden">
                <?php $compact = false; $spouseVals = $vals; include __DIR__ . '/../includes/spouse_fields.php'; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" name="after" value="back" class="btn btn-primary"><?= e(t('member.add')) ?></button>
            <button type="submit" name="after" value="another" class="btn btn-secondary"><?= e(t('member.btn_add_another')) ?></button>
            <a href="<?= e(url('tree', ['id' => $treeId])) ?>" class="btn btn-secondary"><?= e(t('shared.cancel')) ?></a>
        </div>
    </form>
</div>
</div><!-- /container -->

<script src="/js/photo-preview.js"></script>
<script src="/js/forms.js"></script>

</body>
</html>
