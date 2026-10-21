<?php
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $pdo = Database::connect(DB_FILE);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

$settingsRepository = new SettingsRepository($pdo);
$auth = new AdminAuth($settingsRepository);

// Log out and return to the kiosk ("Open Kiosk View" and the idle timer link here)
if (isset($_GET['logout'])) {
    $auth->logout();
    header('Location: index.php');
    exit;
}

// Everything below requires a logged-in admin
if (!$auth->check()) {
    $loginError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login' && $auth->hasPassword()) {
        $loginError = $auth->login((string)($_POST['password'] ?? ''));
        if ($loginError === null) {
            header('Location: admin.php');
            exit;
        }
    }
    $passwordIsSet = $auth->hasPassword();
    require APP_ROOT . '/templates/login.php';
    exit;
}

$repository = new CheckoutRepository($pdo);
$peopleRepository = new PeopleRepository($pdo);
$photos = new PhotoStore(PHOTO_DIR, 'photo.php?f=');
$notifier = new OverdueNotifier($repository, $settingsRepository);
$directorySync = new DirectorySync($peopleRepository, $settingsRepository, SERVICE_ACCOUNT_KEY_FILE);
$controller = new AdminController($repository, $settingsRepository, $notifier, $directorySync, SERVICE_ACCOUNT_KEY_FILE);

// Handle Admin Actions (Delete / Edit / Settings)
$alert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alert = $auth->checkCsrf($_POST['csrf'] ?? null)
        ? $controller->handlePost($_POST, $_FILES)
        : new Alert('That form had expired, so nothing was changed. Please try again.', 'danger');
}
$csrfToken = $auth->csrfToken();

// Fetch all checkouts for calculations and table
$allRecords = $repository->allByCheckoutTime();

// Device & user time checked out statistics
$stats = new UsageStats($allRecords, time());
$deviceStats = $stats->deviceSeconds;
$topUsers = $stats->topUsers(5);

// Overdue notification settings (unsaved values are redisplayed if they failed validation)
$notificationSettings = $controller->settingsDraft ?: NotificationSettings::load($settingsRepository);
$lastNotification = $notifier->lastResult();

// Google directory settings
$directorySettings = $controller->directoryDraft ?: DirectorySettings::load($settingsRepository);
$lastDirectorySync = $directorySync->lastResult();
$directoryPeopleCount = $peopleRepository->countActive();
try {
    $serviceAccountKey = ServiceAccountKey::fromFile(SERVICE_ACCOUNT_KEY_FILE);
} catch (InvalidArgumentException $e) {
    $serviceAccountKey = null;
}

require APP_ROOT . '/templates/admin.php';
