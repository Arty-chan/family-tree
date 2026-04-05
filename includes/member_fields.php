<?php
if (!defined('DB_PATH')) exit;
/**
 * Shared member form fields partial.
 *
 * Expected variables set by the including file:
 *   bool        $isEdit      true = edit form, false = add form
 *   array       $fieldVals   ['name'=>, 'birth_year'=>, 'death_year'=>,
 *                              'birth_year_approx'=>, 'death_year_approx'=>]
 *   array|null  $person      full person row (only needed when $isEdit = true)
 *   int         $currentYear current year for max attribute on year inputs
 */
?>

<div class="form-group">
    <label for="name"><?= e(t('member.name')) ?> <span class="required">*</span></label>
    <input type="text" id="name" name="name"
           value="<?= e($fieldVals['name']) ?>"
           maxlength="255" required autofocus>
</div>

<div class="form-row">
    <div class="form-group">
        <label for="birth_year"><?= e(t('member.birth_year')) ?></label>
        <input type="number" id="birth_year" name="birth_year"
               value="<?= e($fieldVals['birth_year']) ?>"
               min="1" max="<?= $currentYear ?>" placeholder="<?= e(t('member.birth_year_ph')) ?>">
        <label class="checkbox-label">
            <input type="checkbox" name="birth_year_approx" value="1"
                   <?= !empty($fieldVals['birth_year_approx']) ? 'checked' : '' ?>> <?= e(t('member.approximate')) ?>
        </label>
    </div>
    <div class="form-group">
        <label for="death_year"><?= e(t('member.death_year')) ?></label>
        <input type="number" id="death_year" name="death_year"
               value="<?= e($fieldVals['death_year']) ?>"
               min="1" max="<?= $currentYear ?>" placeholder="<?= e(t('member.death_year_ph')) ?>">
        <label class="checkbox-label">
            <input type="checkbox" name="death_year_approx" value="1"
                   <?= !empty($fieldVals['death_year_approx']) ? 'checked' : '' ?>> <?= e(t('member.approximate')) ?>
        </label>
    </div>
</div>

<div class="form-group">
    <label><?= e(t('member.photo')) ?></label>

    <?php if ($isEdit && !empty($person['photo_filename'])): ?>
        <div class="photo-preview" id="photo-preview">
            <img id="photo-preview-img" src="<?= e(url('photo', ['id' => $person['id']])) ?>" alt="<?= e(t('member.current_photo')) ?>">
            <div>
                <p class="form-caption"><?= e(t('member.current_photo')) ?></p>
                <label class="checkbox-label">
                    <input type="checkbox" name="remove_photo" value="1"> <?= e(t('member.remove_photo')) ?>
                </label>
            </div>
        </div>
        <p class="form-caption"><?= e(t('member.replace_photo')) ?></p>
    <?php elseif ($isEdit): ?>
        <div class="photo-preview" id="photo-preview">
            <span class="photo-emoji">&#x1F46A;</span>
            <span class="text-muted-sm"><?= e(t('member.no_photo')) ?></span>
        </div>
    <?php endif; ?>

    <input type="file" id="photo-input" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
    <p class="form-hint"><?= e(t('member.photo_hint')) ?></p>
</div>
