<?php
/**
 * For kiosks whose launcher still runs `php -S` from the repo root without a router
 * (set up before public/ existed): PHP's built-in server sends requests for missing files
 * here, so route them into public/. Re-run bootstrap_env.sh to switch to router.php directly.
 */
require __DIR__ . '/router.php';
