<?php
// The date picker's parser (web/js/dates.js), tested with node: tests/js/dates_test.js.
passthru('node ' . escapeshellarg(__DIR__ . '/../js/dates_test.js') . ' 2>&1', $rc);
exit($rc);
