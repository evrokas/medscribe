<?php
// Shared start-up for bin/ scripts: database constants, the framework's classes,
// the app's class extensions and the store/compile functions. CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(__DIR__ . '/../../web');
include_once(__DIR__ . '/../../config/db.php');
require_once(__FWDIR__ . '/bootstrap.php');
if (is_file('ClassesEx.php')) include_once('ClassesEx.php');
dbConnection::init(DB_HOST, DB_USER, DB_PASS, DB_NAME);
require_once(__DIR__ . '/../../web/store.php');
require_once(__DIR__ . '/../../web/compile.php');
