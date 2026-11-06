<?php
// The type editor's tree (web/js/type_tree.js) is tested with node: tests/js/type_tree_test.js.
passthru('node ' . escapeshellarg(__DIR__ . '/../js/type_tree_test.js') . ' 2>&1', $rc);
exit($rc ? 1 : 0);
