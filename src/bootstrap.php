<?php
// Set timezone (Adjust to your local timezone if needed)
date_default_timezone_set('America/Vancouver');

// Project root (where chromebooks.sqlite and checkout_verification/ live)
define('APP_ROOT', dirname(__DIR__));

// Simple class autoloader: class Foo -> src/Foo.php
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
