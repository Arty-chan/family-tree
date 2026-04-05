<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('home'));
}

csrf_validate();

$db    = get_db();
$relId = (int)($_POST['rel_id'] ?? 0);
$back  = $_POST['redirect_to'] ?? url('home');

// Validate the redirect target (only allow relative paths within the app)
if (!preg_match('#^/[a-zA-Z0-9/_\-]+/$#', $back)) {
    $back = url('home');
}

if ($relId > 0) {
    $stmt = $db->prepare('SELECT tree_id FROM relationships WHERE id = ?');
    $stmt->execute([$relId]);
    $rel = $stmt->fetch();
    if ($rel) {
        require_tree_auth((int)$rel['tree_id']);
        $db->prepare('DELETE FROM relationships WHERE id = ?')->execute([$relId]);
    }
}

redirect($back);
