<?php
/**
 * Spouse year fields partial.
 *
 * Expected variables:
 *   $currentYear  — int, for max attribute
 *   $spouseVals   — array with keys: year_married, year_separated,
 *                    year_married_approx, year_separated_approx
 *   $compact      — bool (optional), true = inline edit layout
 */
$compact = $compact ?? false;
$ym  = $spouseVals['year_married']          ?? '';
$ys  = $spouseVals['year_separated']        ?? '';
$yma = !empty($spouseVals['year_married_approx']);
$ysa = !empty($spouseVals['year_separated_approx']);

if ($compact): ?>
<div class="rel-year-row">
    <label class="rel-inline-field">
        <?= e(t('member.year_married')) ?>
        <input type="number" name="year_married" value="<?= e($ym) ?>"
               min="1" max="<?= $currentYear ?>" class="input-sm input-year">
    </label>
    <label class="checkbox-label"><input type="checkbox" name="year_married_approx" value="1"
        <?= $yma ? 'checked' : '' ?>> <?= e(t('member.approximate_short')) ?></label>
</div>
<div class="rel-year-row">
    <label class="rel-inline-field">
        <?= e(t('member.year_separated')) ?>
        <input type="number" name="year_separated" value="<?= e($ys) ?>"
               min="1" max="<?= $currentYear ?>" class="input-sm input-year">
    </label>
    <label class="checkbox-label"><input type="checkbox" name="year_separated_approx" value="1"
        <?= $ysa ? 'checked' : '' ?>> <?= e(t('member.approximate_short')) ?></label>
</div>
<?php else: ?>
<div class="form-row">
    <div class="form-group">
        <label for="year_married"><?= e(t('member.year_married')) ?></label>
        <input type="number" id="year_married" name="year_married"
               value="<?= e($ym) ?>"
               min="1" max="<?= $currentYear ?>" placeholder="<?= e(t('member.birth_year_ph')) ?>">
        <label class="checkbox-label">
            <input type="checkbox" name="year_married_approx" value="1"
                   <?= $yma ? 'checked' : '' ?>> <?= e(t('member.approximate')) ?>
        </label>
    </div>
    <div class="form-group">
        <label for="year_separated"><?= e(t('member.year_separated')) ?></label>
        <input type="number" id="year_separated" name="year_separated"
               value="<?= e($ys) ?>"
               min="1" max="<?= $currentYear ?>">
        <label class="checkbox-label">
            <input type="checkbox" name="year_separated_approx" value="1"
                   <?= $ysa ? 'checked' : '' ?>> <?= e(t('member.approximate')) ?>
        </label>
    </div>
</div>
<?php endif; ?>
