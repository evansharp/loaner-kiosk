<?php
// Set timezone (Adjust to your local timezone if needed)
date_default_timezone_set('America/Vancouver');

// Project root (the git repo). Only public/ is served to browsers.
define('APP_ROOT', dirname(__DIR__));

// Data that must never be committed or served: database, photos, Google key (gitignored)
define('PRIVATE_DIR', APP_ROOT . '/private');
define('DB_FILE', PRIVATE_DIR . '/chromebooks.sqlite');
define('PHOTO_DIR', PRIVATE_DIR . '/checkout_verification');
define('SERVICE_ACCOUNT_KEY_FILE', PRIVATE_DIR . '/google-service-account.json');

// Simple class autoloader: class Foo -> src/Foo.php
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Create private/ and move in data that older versions kept in the repo root
if (!is_dir(PRIVATE_DIR)) {
    mkdir(PRIVATE_DIR, 0700, true);
}
// (SQLite's journal/WAL files go first, so the database never arrives without them.
// @: the kiosk page and background jobs can race to move the same file; one of them wins.)
$legacyNames = [
    'chromebooks.sqlite-journal', 'chromebooks.sqlite-wal', 'chromebooks.sqlite-shm',
    'chromebooks.sqlite', 'checkout_verification',
];
foreach ($legacyNames as $legacyName) {
    if (file_exists(APP_ROOT . "/$legacyName") && !file_exists(PRIVATE_DIR . "/$legacyName")) {
        @rename(APP_ROOT . "/$legacyName", PRIVATE_DIR . "/$legacyName");
    }
}
