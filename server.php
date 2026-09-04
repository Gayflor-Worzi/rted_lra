<?php

/**
 * Router for the PHP built-in development server (used by Render free tier).
 * Serves real files from public/ directly and routes everything else to
 * the Laravel front controller.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';