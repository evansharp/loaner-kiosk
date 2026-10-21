<?php
/**
 * Router for PHP's built-in server: serves files from public/ and nothing else.
 *
 *   php -S localhost:8080 router.php     (from the repo root; what scripts/kiosk_worker.sh runs)
 *
 * Every request comes through here, so private/, src/, .git/ etc. are never served, and
 * unknown paths get a 404. (Without a router, `php -S -t public` answers unknown paths such
 * as /favicon.ico with public/index.php, and loading the kiosk page logs the admin out.)
 *
 * Also used, via the root index.php, by kiosks whose launcher predates public/.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$publicDir = realpath(__DIR__ . '/public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/' || $path === '') {
    $path = '/index.php';
}

$file = realpath($publicDir . $path);
if ($file === false || strpos($file, $publicDir . '/') !== 0 || !is_file($file)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

if (substr($file, -4) === '.php') {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    require $file;
    exit;
}

$contentTypes = [
    'css' => 'text/css',
    'js' => 'application/javascript',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
readfile($file);
