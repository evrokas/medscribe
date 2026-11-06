#!/usr/bin/env php
<?php
/**
 * M2 checks on MedScribe's side of the ZPMS link: the client against a stand-in ZPMS
 * (tests/lib/mock_zpms.php): key errors, 404, malformed JSON, a timeout, wrong types, off switch.
 * (The real endpoints are tested in the ZPMS repo: tests/functional/medscribe_api.php.)
 */
chdir(__DIR__ . '/../web');
require_once __DIR__ . '/../web/zpms_client.php';

$fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $fail; echo ($ok ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? "  ($d)" : '') . "\n"; if (!$ok) $fail++; }

$port = random_int(20000, 40000);
$log = tempnam(sys_get_temp_dir(), 'mockzpms');
$proc = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/../tests/lib/mock_zpms.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$cfg = ['url' => "http://127.0.0.1:$port", 'key' => 'good-key'];
$good = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
$prev = ini_set('error_log', '/dev/null');   // the client logs failures on purpose

check('enabled with a url and key', zpms_enabled($cfg));
check('off without a key, with a placeholder key, with a non-http url', !zpms_enabled(['url' => $cfg['url'], 'key' => '']) && !zpms_enabled(['url' => $cfg['url'], 'key' => '<<key>>']) && !zpms_enabled(['url' => 'file:///etc', 'key' => 'k']));
check('off: every call returns null, no request is made', zpms_get_patient($good, ['url' => 'http://127.0.0.1:1', 'key' => '']) === null);

$p = zpms_get_patient($good, $cfg);
check('fetch a patient', $p === ['guid' => $good, 'name' => 'Μαρία Δοκιμή', 'dob' => '1980-03-15', 'gender' => 'F', 'amka' => '22222222222']);
check('fetch is case-insensitive on the guid', zpms_get_patient(strtoupper($good), $cfg)['guid'] === $good);
check('wrong key -> null (401)', zpms_get_patient($good, ['url' => $cfg['url'], 'key' => 'bad']) === null);
check('unknown patient -> null (404)', zpms_get_patient('bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb', $cfg) === null);
check('malformed JSON -> null', zpms_get_patient('cccccccc-1111-4111-8111-cccccccccccc', $cfg) === null);
$t0 = microtime(true);
check('a slow ZPMS times out after about 3 s -> null', zpms_get_patient('dddddddd-1111-4111-8111-dddddddddddd', $cfg) === null && microtime(true) - $t0 < 5, round(microtime(true) - $t0, 1) . ' s');
check('a patient with the wrong guid or malformed fields -> null', zpms_get_patient('eeeeeeee-1111-4111-8111-eeeeeeeeeeee', $cfg) === null);
check('a malformed guid never reaches ZPMS', zpms_get_patient('../../etc/passwd', $cfg) === null);
check('503 from ZPMS -> null', zpms_get_patient($good, ['url' => $cfg['url'] . '/unavailable', 'key' => 'good-key']) === null);
check('nothing listening -> null', zpms_get_patient($good, ['url' => 'http://127.0.0.1:9', 'key' => 'k']) === null);

$m = zpms_search_patients('Μαρ', $cfg);
check('search keeps only well-formed rows and only guid/name/amka', $m === [
    ['guid' => $good, 'name' => 'Μαρία Δοκιμή', 'amka' => '22222222222'],
    ['guid' => 'ffffffff-2222-4222-8222-ffffffffffff', 'name' => 'Μάριος Άλλος', 'amka' => null]]);
check('search: a one-character term is not sent', zpms_search_patients('a', $cfg) === []);
check('search: failure -> null', zpms_search_patients('Μαρ', ['url' => $cfg['url'], 'key' => 'bad']) === null);
check('search: no matches -> empty list', zpms_search_patients('Zzz', $cfg) === []);

proc_terminate($proc); proc_close($proc); @unlink($log);
echo $fail ? "\n$fail check(s) failed\n" : "\nM2 client checks passed\n";
exit($fail ? 1 : 0);
