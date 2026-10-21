<?php
require __DIR__ . '/src/bootstrap.php';

// 1. Initialize SQLite Database
try {
    $pdo = Database::connect(APP_ROOT . '/chromebooks.sqlite');
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

$repository = new CheckoutRepository($pdo);
$controller = new KioskController($repository, new PhotoStore(APP_ROOT . '/checkout_verification', 'checkout_verification'));

// 2. Handle POST Actions (Smart Scan Logic + Asset URL Parsing)
$state = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $controller->handlePost($_POST)
    : new KioskState();

// 3. Fetch History (All records fetched for client-side live filtering in kiosk mode)
$checkouts = $repository->all();

// 4. Quick Statistics
$totalCheckouts = $repository->countAll();
$currentlyOut = $repository->countActive();

require __DIR__ . '/templates/kiosk.php';
