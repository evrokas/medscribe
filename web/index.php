<?php

include_once(__DIR__ . "/../config/db.php");       // database parameters

require_once(__FWDIR__ . '/bootstrap.php');
require_once(__DIR__ . '/store.php');
require_once(__DIR__ . '/compile.php');
require_once(__DIR__ . '/render.php');
require_once(__DIR__ . '/render_html.php');
require_once(__DIR__ . '/zpms_client.php');
require_once(__DIR__ . '/docarc_client.php');
require_once(__DIR__ . '/audit.php');
require_once(__DIR__ . '/ui.php');
require_once(__DIR__ . '/documents.php');
require_once(__DIR__ . '/pool.php');
require_once(__DIR__ . '/issue.php');
require_once(__DIR__ . '/issue_pages.php');
require_once(__DIR__ . '/print.php');
require_once(__DIR__ . '/diff.php');
require_once(__DIR__ . '/type_diff.php');
require_once(__DIR__ . '/types.php');
require_once(__DIR__ . '/pages.php');

ini_set('session.gc_maxlifetime', 3600);

$kernel = new Kernel($_SERVER, "../config");

$kernel->boot([
    'csrf_login'      => true,
    'csrf_webforms'   => true,
    'login_lockout'   => true,
    'login_redirect'  => '/login',
    'default_language' => 'en',
]);
