<?php
/**
 * Compatibility router for kiosks whose launcher still runs `php -S` from the repo root
 * (set up before the public/ folder existed). PHP's built-in server sends requests for
 * missing files here, so serve them from public/ instead.
 *
 * Re-running bootstrap_env.sh switches the kiosk to `php -S -t public`, which never uses this file.
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
