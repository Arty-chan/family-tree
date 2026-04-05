<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$personId = (int)($_GET['id'] ?? 0);
if ($personId <= 0) {
    http_response_code(404);
    exit;
}

$db   = get_db();
$stmt = $db->prepare('SELECT tree_id, photo_filename FROM persons WHERE id = ?');
$stmt->execute([$personId]);
$person = $stmt->fetch();

if (!$person || !$person['photo_filename']) {
    http_response_code(404);
    exit;
}

// Require authentication for the specific tree
require_tree_auth((int)$person['tree_id']);

$path = UPLOADS_DIR . $person['tree_id'] . '/' . basename($person['photo_filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

// Serve the image with caching headers
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
