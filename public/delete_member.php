<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('home'));
}

csrf_validate();

$db       = get_db();
$personId = (int)($_POST['person_id'] ?? 0);

if ($personId > 0) {
    $stmt = $db->prepare('SELECT tree_id, photo_filename FROM persons WHERE id = ?');
    $stmt->execute([$personId]);
    $person = $stmt->fetch();

    if ($person) {
        require_tree_auth((int)$person['tree_id']);
        delete_photo($person['photo_filename'], (int)$person['tree_id']);
        // Relationships are deleted via ON DELETE CASCADE in SQLite
        $db->prepare('DELETE FROM persons WHERE id = ?')->execute([$personId]);
        redirect(url('tree', ['id' => (int)$person['tree_id']]));
    }
}

redirect(url('home'));
