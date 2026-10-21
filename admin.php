<?php
require __DIR__ . '/src/bootstrap.php';

try {
    $pdo = Database::connect(APP_ROOT . '/chromebooks.sqlite');
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

$repository = new CheckoutRepository($pdo);
$settingsRepository = new SettingsRepository($pdo);
$photos = new PhotoStore(APP_ROOT . '/checkout_verification', 'checkout_verification');
$notifier = new OverdueNotifier($repository, $settingsRepository);
$controller = new AdminController($repository, $settingsRepository, $notifier);

// Handle Admin Actions (Delete / Edit / Settings)
$alert = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $controller->handlePost($_POST)
    : null;

// Fetch all checkouts for calculations and table
$allRecords = $repository->allByCheckoutTime();

// Device & user time checked out statistics
$stats = new UsageStats($allRecords, time());
$deviceStats = $stats->deviceSeconds;
$topUsers = $stats->topUsers(5);

// Overdue notification settings (unsaved values are redisplayed if they failed validation)
$notificationSettings = $controller->settingsDraft ?: NotificationSettings::load($settingsRepository);
$lastNotification = $notifier->lastResult();

require __DIR__ . '/templates/admin.php';
