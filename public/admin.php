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
$controller = new AdminController($repository, $peopleRepository, $settingsRepository, $notifier, $directorySync, SERVICE_ACCOUNT_KEY_FILE);

// Overdue email previews (shown in the notification card's preview window):
// today's real overdue devices if there are any, otherwise sample data
if (isset($_GET['preview_email'])) {
    $config = NotificationSettings::load($settingsRepository);
    $emails = new OverdueEmails();
    $overdue = $repository->active();
    $borrowers = OverdueEmails::byBorrower($overdue);

    if ($_GET['preview_email'] === 'borrower') {
        $borrowers = $borrowers ?: OverdueEmails::byBorrower(OverdueEmails::sampleOverdue(time()));
        $email = $emails->borrower(reset($borrowers), $config);
    } else {
        $overdue = $overdue ?: OverdueEmails::sampleOverdue(time());
        $status = [];
        foreach (array_keys(OverdueEmails::byBorrower($overdue)) as $userId) {
            $status[(string)$userId] = $config->notifyUsers ? OverdueEmails::BORROWER_EMAILED : OverdueEmails::BORROWER_EMAILS_OFF;
        }
        $email = $emails->admin($overdue, $status, $config, time());
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $email['html'];
    exit;
}

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

// Google directory settings (the edit form's name autocomplete follows the saved on/off switch)
$directoryEnabled = DirectorySettings::load($settingsRepository)->enabled;
$directorySettings = $controller->directoryDraft ?: DirectorySettings::load($settingsRepository);
$lastDirectorySync = $directorySync->lastResult();
$directoryPeopleCount = $peopleRepository->countActive();
try {
    $serviceAccountKey = ServiceAccountKey::fromFile(SERVICE_ACCOUNT_KEY_FILE);
} catch (InvalidArgumentException $e) {
    $serviceAccountKey = null;
}

require APP_ROOT . '/templates/admin.php';
