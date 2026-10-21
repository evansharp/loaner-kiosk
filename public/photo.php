<?php
// Serves checkout verification photos (stored outside the web root) to logged-in admins only
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $pdo = Database::connect(DB_FILE);
} catch (PDOException $e) {
    http_response_code(500);
    exit;
}

$auth = new AdminAuth(new SettingsRepository($pdo));
if (!$auth->check()) {
    http_response_code(403);
    exit;
}

$file = (new PhotoStore(PHOTO_DIR))->resolve((string)($_GET['f'] ?? ''));
if ($file === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
readfile($file);
