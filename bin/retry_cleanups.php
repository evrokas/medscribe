#!/usr/bin/env php
<?php
/**
 * Retry the DocArc record deletions that failed when drafts were abandoned (PLAN.md §7.4). Run from cron,
 * e.g. every 10 minutes:  php bin/retry_cleanups.php
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/../web/render.php');
require_once(__DIR__ . '/../web/docarc_client.php');
require_once(__DIR__ . '/../web/audit.php');
require_once(__DIR__ . '/../web/documents.php');
require_once(__DIR__ . '/../web/pool.php');
require_once(__DIR__ . '/../web/issue.php');
if (!docarc_enabled()) { echo "DocArc is not configured; nothing to do\n"; exit(0); }
[$done, $failed] = issue_cleanup_run();
echo "cleanups: $done done, $failed still failing\n";
exit($failed ? 1 : 0);
