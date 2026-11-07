<?php
// The chip editor's core and the type editor's naming rule, tested with node: tests/js/type_chips_test.js.
passthru('node ' . escapeshellarg(__DIR__ . '/../js/type_chips_test.js') . ' 2>&1', $rc);
exit($rc ? 1 : 0);
