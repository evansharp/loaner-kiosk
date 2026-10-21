<?php
// Name autocomplete for the kiosk: active directory people matching ?q=
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$query = trim((string)($_GET['q'] ?? ''));

try {
    $pdo = Database::connect(DB_FILE);
} catch (PDOException $e) {
    http_response_code(500);
    echo '[]';
    exit;
}

if (!DirectorySettings::load(new SettingsRepository($pdo))->enabled || strlen($query) < 2) {
    echo '[]';
    exit;
}

echo json_encode((new PeopleRepository($pdo))->search($query, 8), JSON_UNESCAPED_UNICODE);
