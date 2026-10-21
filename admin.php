<?php
require __DIR__ . '/src/bootstrap.php';

try {
    $pdo = Database::connect(APP_ROOT . '/chromebooks.sqlite');
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

$repository = new CheckoutRepository($pdo);
$photos = new PhotoStore(APP_ROOT . '/checkout_verification', 'checkout_verification');
$controller = new AdminController($repository);

// Handle Admin Actions (Delete / Edit)
$alert = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $controller->handlePost($_POST)
    : null;

// Fetch all checkouts for calculations and table
$allRecords = $repository->allByCheckoutTime();

// Device & user time checked out statistics
$stats = new UsageStats($allRecords, time());
$deviceStats = $stats->deviceSeconds;
$topUsers = $stats->topUsers(5);

require __DIR__ . '/templates/admin.php';
