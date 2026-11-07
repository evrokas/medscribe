<?php
// The chip editor in a real browser (bin/check_chips_dom.js; skipped when no Chromium is installed).
passthru('node ' . escapeshellarg(__DIR__ . '/../../bin/check_chips_dom.js') . ' 2>&1', $rc);
exit($rc ? 1 : 0);
