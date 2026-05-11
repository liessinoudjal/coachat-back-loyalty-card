<?php
/*
 * This file is the PHP Development Server router.
 * It allows the dev server to route all requests through Symfony via bin/dev-server.php
 */

if (is_file(__DIR__ . '/public' . $_SERVER['REQUEST_URI'])) {
    // Serve static files as-is
    return false;
}

// For everything else, run through our custom Symfony bootstrap
require_once __DIR__ . '/bin/dev-server.php';
