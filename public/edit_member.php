<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$db       = get_db();
$personId = (int)($_GET['id'] ?? $_POST['person_id'] ?? 0);
$currentYear = (int)date('Y');

// Load person
$stmt = $db->prepare('SELECT p.*, t.title AS tree_title FROM persons p JOIN trees t ON t.id = p.tree_id WHERE p.id = ?');
$stmt->execute([$personId]);
$person = $stmt->fetch();
if (!$person) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><p>' . e(t('member.not_found')) . ' <a href="' . e(url('home')) . '">' . e(t('shared.go_home')) . '</a></p></body></html>';
    exit;
}

$treeId = (int)$person['tree_id'];
require_tree_auth($treeId);

// Other members in the same tree (for relationship dropdown)
$stmt = $db->prepare('SELECT id, name, birth_year FROM persons WHERE tree_id = ? AND id != ? ORDER BY name COLLATE NOCASE');
$stmt->execute([$treeId, $personId]);
$otherMembers = $stmt->fetchAll();

// Existing relationships for this person
$existingRels = loadRelationships($db, $personId, $treeId);

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = $_POST['action'] ?? '';

    // ── Update person details ────────────────────────────────────────────────
    if ($action === 'update') {
        $name      = clean_text($_POST['name'] ?? '', 255);
        $birthYear = clean_year($_POST['birth_year'] ?? '');
        $deathYear = clean_year($_POST['death_year'] ?? '');
        $birthYearApprox = !empty($_POST['birth_year_approx']) ? 1 : 0;
        $deathYearApprox = !empty($_POST['death_year_approx']) ? 1 : 0;

        if ($name === '') {
            $error = t('member.err_name');
        } elseif ($birthYear && $deathYear && $deathYear < $birthYear) {
            $error = t('member.err_death_year');
        } else {
            try {
                $currentPhoto = $person['photo_filename'];

                if (!empty($_FILES['photo']['name'])) {
                    // Upload new → delete old
                    $newPhoto = handle_photo_upload($_FILES['photo'], $name, $treeId);
                    delete_photo($currentPhoto, $treeId);
                    $currentPhoto = $newPhoto;
                } elseif (!empty($_POST['remove_photo'])) {
                    delete_photo($currentPhoto, $treeId);
                    $currentPhoto = null;
                }
                // else: keep existing photo

                $db->prepare(
                    'UPDATE persons SET name=?, birth_year=?, death_year=?, photo_filename=?, birth_year_approx=?, death_year_approx=? WHERE id=?'
                )->execute([$name, $birthYear, $deathYear, $currentPhoto, $birthYearApprox, $deathYearApprox, $personId]);

                // Refresh person array
                $stmt = $db->prepare('SELECT p.*, t.title AS tree_title FROM persons p JOIN trees t ON t.id = p.tree_id WHERE p.id = ?');
                $stmt->execute([$personId]);
                $person = $stmt->fetch();
                if (($_POST['after'] ?? '') === 'stay') {
                    $success = t('member.success_saved');
                } else {
                    redirect(url('tree', ['id' => $treeId]));
                }
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            }
        }
    }

    // ── Update relationship (spouse years) ───────────────────────────────────
    if ($action === 'update_rel') {
        $relId = (int)($_POST['rel_id'] ?? 0);
        if ($relId > 0) {
            $chk = $db->prepare('SELECT id, tree_id, relationship_type FROM relationships WHERE id = ? AND tree_id = ?');
            $chk->execute([$relId, $treeId]);
            $rel = $chk->fetch();
            if ($rel && $rel['relationship_type'] === 'spouse') {
                $ym  = clean_year($_POST['year_married']   ?? '');
                $ys  = clean_year($_POST['year_separated'] ?? '');
                $yma = !empty($_POST['year_married_approx'])   ? 1 : 0;
                $ysa = !empty($_POST['year_separated_approx']) ? 1 : 0;
                $db->prepare(
                    'UPDATE relationships SET year_married=?, year_separated=?, year_married_approx=?, year_separated_approx=? WHERE id=?'
                )->execute([$ym, $ys, $yma, $ysa, $relId]);
                $existingRels = loadRelationships($db, $personId, $treeId);
                $success = t('member.success_saved');
            }
        }
    }

    // ── Add relationship ─────────────────────────────────────────────────────
    if ($action === 'add_rel') {
        $relType   = in_array($_POST['rel_type'] ?? '', ['child','parent','spouse','cousin'], true)
                     ? $_POST['rel_type'] : '';
        $relPerson = (int)($_POST['rel_person_id'] ?? 0);

        if (!$relType || !$relPerson) {
            $error = t('member.err_select_rel');
        } else {
            // Verify target belongs to this tree
            $chk = $db->prepare('SELECT id FROM persons WHERE id = ? AND tree_id = ?');
            $chk->execute([$relPerson, $treeId]);
            if (!$chk->fetch()) {
                $error = t('member.err_invalid');
            } else {
                // Prevent obvious duplicate (same pair, same type)
                $dupCheck = $db->prepare(
                    'SELECT id FROM relationships
                     WHERE tree_id=? AND relationship_type=?
                       AND ((person1_id=? AND person2_id=?) OR (person1_id=? AND person2_id=?))'
                );
                $dupCheck->execute([$treeId, typeToStored($relType),
                                    $personId, $relPerson,
                                    $relPerson, $personId]);
                if ($dupCheck->fetch()) {
                    $error = t('member.err_duplicate');
                } else {
                    // Enforce max 2 parents per child
                    $blocked = false;
                    if ($relType === 'child') {
                        $pc = $db->prepare('SELECT COUNT(*) FROM relationships WHERE tree_id=? AND person2_id=? AND relationship_type=?');
                        $pc->execute([$treeId, $personId, 'parent_child']);
                        if ((int)$pc->fetchColumn() >= 2) { $error = t('member.err_2parents_this'); $blocked = true; }
                    } elseif ($relType === 'parent') {
                        $pc = $db->prepare('SELECT COUNT(*) FROM relationships WHERE tree_id=? AND person2_id=? AND relationship_type=?');
                        $pc->execute([$treeId, $relPerson, 'parent_child']);
                        if ((int)$pc->fetchColumn() >= 2) { $error = t('member.err_2parents_that'); $blocked = true; }
                    }
                    if (!$blocked) {
                    $ym = clean_year($_POST['year_married']   ?? '');
                    $ys = clean_year($_POST['year_separated'] ?? '');
                    $yma = !empty($_POST['year_married_approx'])   ? 1 : 0;
                    $ysa = !empty($_POST['year_separated_approx']) ? 1 : 0;
                    saveRelationship($db, $treeId, $personId, $relPerson, $relType, $ym, $ys, $yma, $ysa);
                    $existingRels = loadRelationships($db, $personId, $treeId);
                    $success = t('member.success_rel');
                    }
                }
            }
        }
    }
}

// Helper functions are now in includes/db_connect.php
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('member.edit_title')) ?> – <?= e($person['name']) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

<?php
$breadcrumbs = [
    ['url' => url('tree', ['id' => $treeId]), 'label' => $person['tree_title']],
    ['label' => t('member.breadcrumb_edit') . ' ' . $person['name']],
];
include __DIR__ . '/../includes/nav.php';
?>

<div class="container">

    <?php if ($error): ?>
        <div class="alert-msg alert-msg--error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-msg alert-msg--success"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════
         Person details form
    ══════════════════════════════════════════════════ -->
    <div class="card form-card">
        <div class="page-header"><h1><?= e(t('member.edit_title')) ?></h1></div>

        <form method="post" action="<?= e(url('member.edit', ['id' => $personId])) ?>"
              enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action"    value="update">
            <input type="hidden" name="person_id" value="<?= e($personId) ?>">

            <?php
             $isEdit      = true;
             $fieldVals   = ['name' => $person['name'], 'birth_year' => $person['birth_year'] ?? '', 'death_year' => $person['death_year'] ?? '',
                            'birth_year_approx' => $person['birth_year_approx'] ?? 0, 'death_year_approx' => $person['death_year_approx'] ?? 0];
             include __DIR__ . '/../includes/member_fields.php';
            ?>

            <div class="form-actions">
                <button type="submit" name="after" value="back" class="btn btn-primary"><?= e(t('shared.save')) ?></button>
                <button type="submit" name="after" value="stay" class="btn btn-secondary"><?= e(t('member.btn_save_stay')) ?></button>
                <a href="<?= e(url('tree', ['id' => $treeId])) ?>" class="btn btn-secondary"><?= e(t('member.btn_back_tree')) ?></a>
            </div>
        </form>
    </div><!-- /card -->

    <!-- ══════════════════════════════════════════════════
         Relationships
    ══════════════════════════════════════════════════ -->
    <div class="card form-card">
        <div class="page-header"><h1 class="card-subheading"><?= e(t('member.relationships')) ?></h1></div>

        <!-- Existing -->
        <?php if ($existingRels): ?>
            <ul class="rel-list">
                <?php foreach ($existingRels as $rel): ?>
                    <li>
                        <span class="rel-type-badge"><?= e(relBadge($rel['relationship_type'])) ?></span>
                        <?php if ($rel['relationship_type'] === 'spouse'): ?>
                            <?php
                                $otherName = ((int)$rel['person1_id'] === $personId) ? $rel['name2'] : $rel['name1'];
                                $yearsText = formatSpouseYears($rel);
                            ?>
                            <span class="rel-spouse-name flex-fill"><?= e($otherName) ?></span>
                            <!-- Years read view -->
                            <span class="rel-years-read" data-rel="<?= e($rel['id']) ?>">
                                <?php if ($yearsText): ?>
                                    <span class="marriage-years-inline"><?= $yearsText ?></span>
                                <?php endif; ?>
                                <button type="button" class="btn btn-secondary btn-sm rel-edit-btn"><?= e(t('member.edit_title_short')) ?></button>
                            </span>
                            <!-- Years edit form (hidden) -->
                            <form method="post" action="<?= e(url('member.edit', ['id' => $personId])) ?>"
                                  class="rel-spouse-form hidden" data-rel="<?= e($rel['id']) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_rel">
                                <input type="hidden" name="person_id" value="<?= e($personId) ?>">
                                <input type="hidden" name="rel_id" value="<?= e($rel['id']) ?>">
                                <?php $compact = true; $spouseVals = $rel; include __DIR__ . '/../includes/spouse_fields.php'; ?>
                                <div class="rel-year-row">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?= e(t('shared.save')) ?></button>
                                    <button type="button" class="btn btn-secondary btn-sm rel-cancel-btn"><?= e(t('shared.cancel')) ?></button>
                                </div>
                            </form>
                        <?php else: ?>
                            <span class="flex-fill"><?= e(relDescription($rel, $personId)) ?></span>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('relationship.delete')) ?>" class="m-0"
                              data-confirm="<?= e(t('shared.confirm_delete_rel')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="rel_id"      value="<?= e($rel['id']) ?>">
                            <input type="hidden" name="redirect_to" value="<?= e(url('member.edit', ['id' => $personId])) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm"><?= e(t('member.remove_rel')) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted mb-md"><?= e(t('member.no_rels')) ?></p>
        <?php endif; ?>

        <!-- Add new relationship -->
        <?php if (!empty($otherMembers)): ?>
        <div class="form-section">
            <div class="form-section-title"><?= e(t('member.add_rel')) ?></div>

            <form method="post" action="<?= e(url('member.edit', ['id' => $personId])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action"    value="add_rel">
                <input type="hidden" name="person_id" value="<?= e($personId) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="rel_type"><?= e(t('member.rel_type')) ?></label>
                        <select id="rel_type" name="rel_type">
                            <option value="child"  selected><?= e(t('member.rel_child')) ?></option>
                            <option value="parent"><?= e(t('member.rel_parent')) ?></option>
                            <option value="spouse"><?= e(t('member.rel_spouse')) ?></option>
                            <option value="cousin"><?= e(t('member.rel_cousin')) ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rel_person_id"><?= e(t('member.rel_member')) ?></label>
                        <select id="rel_person_id" name="rel_person_id">
                            <option value=""><?= e(t('member.rel_choose')) ?></option>
                            <?php foreach ($otherMembers as $m): ?>
                                <option value="<?= e($m['id']) ?>"><?= e($m['name']) ?><?= $m['birth_year'] ? ' (' . $m['birth_year'] . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="spouse-fields" class="hidden">
                    <?php $compact = false; $spouseVals = []; include __DIR__ . '/../includes/spouse_fields.php'; ?>
                </div>

                <button type="submit" class="btn btn-secondary"><?= e(t('member.add_rel')) ?></button>
            </form>
        </div>
        <?php endif; ?>
    </div><!-- /card -->

    <!-- ══════════════════════════════════════════════════
         Delete member
    ══════════════════════════════════════════════════ -->
    <div class="card form-card">
        <div class="delete-confirm">
            <p><strong><?= e(t('shared.danger_zone')) ?></strong> <?= e(t('member.danger_desc')) ?></p>
            <form method="post" action="<?= e(url('member.delete')) ?>"
                  data-confirm="<?= e(addslashes(t('member.confirm_delete', $person['name']))) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="person_id" value="<?= e($personId) ?>">
                <button type="submit" class="btn btn-danger btn-sm"><?= e(t('member.btn_delete')) ?></button>
            </form>
        </div>
    </div>

</div><!-- /container -->

<script src="/js/photo-preview.js"></script>
<script src="/js/forms.js"></script>

</body>
</html>
