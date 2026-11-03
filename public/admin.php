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
$usageReport = new UsageReport($repository, $settingsRepository);
$controller = new AdminController($repository, $peopleRepository, $settingsRepository, $notifier, $directorySync, $usageReport, SERVICE_ACCOUNT_KEY_FILE);

// Email previews (shown in the preview window). The usage report uses real data for the
// chosen range; overdue emails use today's overdue devices if there are any, otherwise sample data
if (isset($_GET['preview_email'])) {
    $config = NotificationSettings::load($settingsRepository);
    if ($_GET['preview_email'] === 'report') {
        $range = isset(UsageReport::RANGES[$_GET['range'] ?? '']) ? $_GET['range'] : 'all';
        header('Content-Type: text/html; charset=utf-8');
        echo $usageReport->compose($range, time(), $config->schoolName)['html'];
        exit;
    }

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

// Usage report form (a report that wasn't sent keeps what was entered)
$reportRange = $controller->reportDraft['range'] ?? 'school_year';
$reportRecipients = $controller->reportDraft['recipients'] ?? $usageReport->savedRecipients(NotificationSettings::load($settingsRepository));
$reportLastSentAt = $usageReport->lastSentAt();
$lastReport = $usageReport->lastResult();

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
