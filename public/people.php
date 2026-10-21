<?php
// Name autocomplete: directory people matching ?q=
// Active people only, unless a logged-in admin asks for former ones too (&include_inactive=1)
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

$settingsRepository = new SettingsRepository($pdo);
if (!DirectorySettings::load($settingsRepository)->enabled || strlen($query) < 2) {
    echo '[]';
    exit;
}

$includeInactive = !empty($_GET['include_inactive']) && (new AdminAuth($settingsRepository))->check();

echo json_encode((new PeopleRepository($pdo))->search($query, 8, $includeInactive), JSON_UNESCAPED_UNICODE);
