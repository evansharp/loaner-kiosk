<?php
/**
 * Syncs people from the Google directory when due.
 *
 * Run every few minutes by the kiosk worker (scripts/kiosk_worker.sh). It syncs every
 * 6 hours (retrying failures after 30 minutes) while the directory is turned on, and
 * only prints a line when it actually did something, to keep the kiosk log quiet.
 *
 * Usage: php bin/sync_directory.php [--force]
 *   --force  Sync now, ignoring the on/off switch and schedule
 */

// This file sits inside the repo; never run it from a browser
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$force = in_array('--force', $argv, true);

try {
    $pdo = Database::connect(DB_FILE);
    $sync = new DirectorySync(new PeopleRepository($pdo), new SettingsRepository($pdo), SERVICE_ACCOUNT_KEY_FILE);
    $result = $sync->run(time(), $force);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Directory sync error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result !== null) {
    echo '[' . date('Y-m-d H:i:s') . "] Directory sync: $result\n";
}
