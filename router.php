<?php

/**
 * Dev-only router shim for `php -S`, emulating web/.htaccess's mod_rewrite
 * rule (RewriteRule ^(.*)$ index.php?$1 [QSA,L,PT]) - PHP's built-in server
 * doesn't read .htaccess, so without this every non-"/" request leaves
 * $_SERVER['QUERY_STRING'] empty (RequestClass reads QUERY_STRING directly
 * as the route path - see core/router/Request.php) and every route falls
 * back to whichever one matches an empty path. Production Apache never
 * uses this file.
 *
 * Usage: php -S localhost:8000 -t web router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fullPath = __DIR__ . '/web' . $path;

if ($path !== '/' && file_exists($fullPath) && !is_dir($fullPath)) {
    return false;
}

$_SERVER['QUERY_STRING'] = ltrim($path, '/');

// PHP's built-in server invents a PATH_INFO-style PHP_SELF/SCRIPT_NAME for
// router-script requests that don't match a real file - "/index.php" +
// the requested path (e.g. "/index.php/el/phase1-check") - since it
// treats index.php as the "matched" script or the rest of the path as
// trailing PATH_INFO. Kernel::rel_url() derives its root path from
// PHP_SELF's directory portion, so left alone this makes every asset URL
// resolve relative to a nonexistent "/index.php/el/" directory instead of
// site root. Apache's real rewrite rule never does this - PHP_SELF there
// is always cleanly "/index.php" - so pin it here to match production.
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

chdir(__DIR__ . '/web');
require __DIR__ . '/web/index.php';
