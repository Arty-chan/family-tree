<?php
if (!defined('DB_PATH')) {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/db_connect.php';
}
if (!function_exists('is_authenticated')) {
    require_once __DIR__ . '/auth.php';
}
/**
 * Shared navigation bar partial.
 *
 * Expected variables (all optional):
 *   array  $breadcrumbs  – [['label'=>…, 'url'=>…], …]  url is optional per crumb
 *   string $navClass     – extra CSS class(es) for <nav>, e.g. 'no-print'
 */
$navClass   = $navClass   ?? '';
$breadcrumbs = $breadcrumbs ?? [];
?>
<nav<?= $navClass ? ' class="' . e($navClass) . '"' : '' ?>>
    <div class="nav-left">
        <a href="<?= e(url('home')) ?>" class="site-title"><?= e(t('shared.site_title')) ?></a>
        <?php foreach ($breadcrumbs as $crumb): ?>
            <span class="breadcrumb-sep">›</span>
            <?php if (!empty($crumb['url'])): ?>
                <a href="<?= e($crumb['url']) ?>" class="breadcrumb-link"><?= e($crumb['label']) ?></a>
            <?php else: ?>
                <span class="breadcrumb-current"><?= e($crumb['label']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="nav-right no-print">
        <form method="post" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_lang">
            <button type="submit" class="btn btn-nav"><?= get_lang() === 'en' ? '🇨🇳 中文' : '🇬🇧 English' ?></button>
        </form>
        <?php if (is_admin_authed()): ?>
            <a href="<?= e(url('home')) ?>" class="btn btn-nav"><?= e(t('nav.admin')) ?></a>
        <?php endif; ?>
        <?php if (is_authenticated()): ?>
            <form method="post" action="<?= e(url('login')) ?>" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="signout">
                <button type="submit" class="btn btn-nav"><?= e(t('nav.signout')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</nav>
