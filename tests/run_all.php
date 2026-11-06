<?php
/**
 * Test runner (PLAN.md §14; same entry point as ZPMS: bin/run_tests.sh).
 *   php tests/run_all.php [--static-only] [--tex-only]
 * static: php -l on every PHP file, YAML parse of config/ and entities, shell syntax.
 * tex:    bin/check_m0.php (store, compile, manifest, rebuild, isolation); needs the database.
 */
$root = dirname(__DIR__);
$o = getopt('', ['static-only', 'tex-only']);
$fail = 0; $n = 0;
function ok(string $label, bool $pass, string $detail = ''): void {
    global $fail, $n; $n++;
    if (!$pass) { $fail++; }
    if (!$pass || getenv('VERBOSE')) echo ($pass ? 'PASS  ' : 'FAIL  ') . $label . ($detail ? "  $detail" : '') . "\n";
}

if (!isset($o['tex-only'])) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (preg_match('#/(\.git|fw|web/core|web/cache|web/files|var|web/css/bx)/#', $p) || str_contains($p, '/web/classes/')) continue;
        if (str_ends_with($p, '.php') || str_ends_with($p, '.php.in')) {
            $out = []; exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $c);
            ok('php -l ' . substr($p, strlen($root) + 1), $c === 0, implode(' ', $out));
        } elseif (str_ends_with($p, '.sh')) {
            exec('bash -n ' . escapeshellarg($p) . ' 2>&1', $out2, $c);
            ok('bash -n ' . substr($p, strlen($root) + 1), $c === 0);
        }
    }
    foreach (array_merge(glob("$root/config/*.yaml"), glob("$root/config/*.yaml.in"), glob("$root/web/classes/yaml/*.yaml")) as $y) {
        $d = @yaml_parse_file($y);
        ok('yaml ' . substr($y, strlen($root) + 1), is_array($d));
    }
    foreach (glob("$root/tests/static/*_test.php") as $t) {
        $out = []; exec('php ' . escapeshellarg($t) . ' 2>&1', $out, $c);
        ok('unit ' . basename($t), $c === 0, implode("\n", $out));
    }
    // every route handler named in settings.info.yaml exists as a function
    $cfg = yaml_parse_file("$root/config/settings.info.yaml");
    $core = ['login', 'login_post', 'logout'];
    $src = implode("
", array_map('file_get_contents', glob("$root/web/*.php")));
    foreach ($cfg['routes'] as $name => $r) {
        $h = $r['handler'] ?? null;
        ok("route $name handler $h", $h && (in_array($h, $core, true) || preg_match('/function\s+' . preg_quote($h, '/') . '\s*\(/', $src)));
    }
}

if (!isset($o['static-only'])) {
    foreach (['check_m0', 'check_m1', 'check_m2', 'check_m3', 'check_web'] as $chk) {
        passthru('php ' . escapeshellarg("$root/bin/$chk.php"), $c);
        ok("bin/$chk.php", $c === 0);
    }
}

echo "\n" . ($n - $fail) . "/$n passed" . ($fail ? ", $fail FAILED" : '') . "\n";
exit($fail ? 1 : 0);
