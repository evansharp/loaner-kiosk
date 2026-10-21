<?php
/**
 * Sends the daily overdue-device email when it is due.
 *
 * Run every few minutes by the kiosk worker (scripts/kiosk_worker.sh). It sends
 * at most once per day, after the notify time configured on the admin page, and only
 * prints a line when it actually did something, to keep the kiosk log quiet.
 *
 * Usage: php bin/notify_overdue.php [--force]
 *   --force  Check and send now, ignoring the on/off switch, notify time and once-per-day limit
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
    $notifier = new OverdueNotifier(new CheckoutRepository($pdo), new SettingsRepository($pdo));
    $result = $notifier->run(time(), $force);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Overdue notifier error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result !== null) {
    echo '[' . date('Y-m-d H:i:s') . "] Overdue notifier: $result\n";
}
