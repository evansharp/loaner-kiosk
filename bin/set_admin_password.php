<?php
/**
 * Sets (or changes) the admin page password, and clears any failed-login lockout.
 *
 * Usage: php bin/set_admin_password.php
 *   Prompts for the password twice. It can also be piped in on one line (no confirmation).
 */

// This file sits inside the repo; never run it from a browser
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

const MIN_PASSWORD_LENGTH = 8;

function promptHidden(string $label): string
{
    echo $label;
    system('stty -echo');
    $value = fgets(STDIN);
    system('stty echo');
    echo "\n";
    return rtrim((string)$value, "\r\n");
}

$interactive = stream_isatty(STDIN);

if ($interactive) {
    $password = promptHidden('New admin password: ');
    if (promptHidden('Repeat it: ') !== $password) {
        fwrite(STDERR, "The passwords didn't match; nothing was changed.\n");
        exit(1);
    }
} else {
    $password = rtrim((string)fgets(STDIN), "\r\n");
}

if (strlen($password) < MIN_PASSWORD_LENGTH) {
    fwrite(STDERR, 'The password must be at least ' . MIN_PASSWORD_LENGTH . " characters; nothing was changed.\n");
    exit(1);
}

$pdo = Database::connect(DB_FILE);
(new AdminAuth(new SettingsRepository($pdo)))->setPassword($password);

echo "Admin password saved.\n";
