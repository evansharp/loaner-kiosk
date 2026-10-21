<?php
require dirname(__DIR__) . '/src/bootstrap.php';

// 1. Initialize SQLite Database
try {
    $pdo = Database::connect(DB_FILE);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

$settingsRepository = new SettingsRepository($pdo);

// Opening the kiosk view always ends any admin session in this shared browser
(new AdminAuth($settingsRepository))->logout();

$repository = new CheckoutRepository($pdo);
$directory = DirectorySettings::load($settingsRepository);
$controller = new KioskController($repository, new PhotoStore(PHOTO_DIR), new PeopleRepository($pdo), $directory);

// 2. Handle POST Actions (Smart Scan Logic + Asset URL Parsing)
$state = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $controller->handlePost($_POST)
    : new KioskState();

// 3. Fetch History (All records fetched for client-side live filtering in kiosk mode)
$checkouts = $repository->all();

// 4. Quick Statistics
$totalCheckouts = $repository->countAll();
$currentlyOut = $repository->countActive();

require APP_ROOT . '/templates/kiosk.php';
