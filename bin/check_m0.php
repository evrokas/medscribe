#!/usr/bin/env php
<?php
/**
 * M0 acceptance check (PLAN.md §10):
 *  1. tpl-vevaiosi-mri compiled from the store (base release + type version) gives the
 *     same PDF as ~/devel/docs/scripts/build.sh under the same SOURCE_DATE_EPOCH;
 *  2. the build manifest lists every file with version and hash, and a rebuild from the
 *     manifest gives the same PDF hash;
 *  3. a LaTeX file cannot read server files outside the job (openin_any=p).
 *
 *   php bin/check_m0.php [--type=vevaiosi-mri] [--kit=~/devel/docs]
 */
require_once(__DIR__ . '/lib/cli_boot.php');

$o = getopt('', ['type::', 'kit::']);
$type = $o['type'] ?? 'vevaiosi-mri';
$docs = rtrim($o['kit'] ?? (getenv('HOME') . '/devel/docs'), '/');
$epoch = 1767225600;   // 2026-01-01T00:00:00Z
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fail;
    echo ($ok ? "PASS  " : "FAIL  ") . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fail++;
}

$rel = base_release_current();
check('a base release exists', (bool)$rel, $rel ? "B{$rel['number']}" : '');
if (!$rel) exit(1);
$files = base_release_files((int)$rel['id']);
$tv = store_db()->prepare("SELECT s.name, s.kitpath, v.version, v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name=? ORDER BY v.version DESC LIMIT 1");
$tv->execute(["type/$type"]);
$t = $tv->fetch(PDO::FETCH_ASSOC);
check("type/$type is in the store", (bool)$t);
if (!$t) exit(1);
$files[] = $t;

$r = compile_job($files, [], $t['kitpath'], $epoch);
check('compile from the store', $r['ok'], $r['ok'] ? $r['passes'] . ' passes, ' . strlen($r['pdf']) . ' bytes' : ($r['error'] ?? ''));
if (!$r['ok']) { echo $r['log_tail'] . "\n"; exit(1); }

// 1. reference build with scripts/build.sh
$out = sys_get_temp_dir() . '/ms-ref-' . bin2hex(random_bytes(4));
mkdir($out);
$cmd = sprintf('SOURCE_DATE_EPOCH=%d FORCE_SOURCE_DATE=1 %s/scripts/build.sh -o %s %s/template-kit/%s 2>&1',
    $epoch, escapeshellarg($docs), escapeshellarg($out), escapeshellarg($docs), $t['kitpath']);
exec($cmd, $lines, $code);
$refPdf = "$out/" . basename($t['kitpath'], '.tex') . '.pdf';
check('reference build.sh', $code === 0 && is_file($refPdf));
if (is_file($refPdf)) {
    $ref = file_get_contents($refPdf);
    if (hash('sha256', $ref) === $r['pdf_sha256']) {
        check('PDF is byte-identical to build.sh', true, substr($r['pdf_sha256'], 0, 12));
    } else {
        // fall back to comparing the rendered pages
        file_put_contents("$out/store.pdf", $r['pdf']);
        $same = true;
        foreach (['ref' => $refPdf, 'store' => "$out/store.pdf"] as $k => $pdf) {
            exec('pdftoppm -r 60 -png ' . escapeshellarg($pdf) . ' ' . escapeshellarg("$out/$k"));
        }
        foreach (glob("$out/ref-*.png") as $img) {
            $other = str_replace('/ref-', '/store-', $img);
            $same = $same && is_file($other) && md5_file($img) === md5_file($other);
        }
        check('PDF pages render the same as build.sh', $same, 'bytes differ, pages compared');
    }
}
exec('rm -rf ' . escapeshellarg($out));

// 2. manifest + rebuild
$m = $r['manifest'];
check('manifest lists every file with version and hash',
    count($m['files']) === count($files) && !array_filter($m['files'], fn($f) => empty($f['sha256']) || $f['version'] === null));
$again = compile_from_manifest($m);
check('rebuild from the manifest gives the same PDF hash', $again['ok'] && $again['pdf_sha256'] === $r['pdf_sha256']);

// 2b. an older base release still rebuilds to the PDF it produced (PLAN.md G5, in miniature)
$old = store_db()->prepare("SELECT manifest, pdf_sha256 FROM builds WHERE status='ok' AND purpose='test' AND id < ? AND manifest LIKE ? ORDER BY id LIMIT 1");
$old->execute([$r['build_id'], '%' . $t['kitpath'] . '%']);
if ($ob = $old->fetch(PDO::FETCH_ASSOC)) {
    $om = json_decode($ob['manifest'], true);
    $orebuilt = compile_from_manifest($om);
    check('the oldest recorded build of this type rebuilds to the same PDF hash', $orebuilt['ok'] && $orebuilt['pdf_sha256'] === $ob['pdf_sha256'],
        count($om['files']) . ' files, ' . substr($ob['pdf_sha256'], 0, 12));
}

// 3. isolation: a document must not read a server file (a plain-text marker, not PHP,
//    so a successful read cannot be mistaken for a syntax error)
$secret = sys_get_temp_dir() . '/ms-secret-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($secret, "SECRETMARKER\n");
foreach (['absolute path' => $secret, 'config file' => __APPDIR__ . '/config/db.php'] as $label => $path) {
    $evil = "\\documentclass{article}\\begin{document}\\input{" . $path . "}\\end{document}\n";
    $e = compile_job([], ['evil.tex' => $evil], 'evil.tex', $epoch, 'test');
    $leak = $e['ok'] && (str_contains($e['pdf'], 'SECRETMARKER') || str_contains($e['pdf'], 'DB_PASS'));
    check("\\input of a server file is refused ($label)", !$e['ok'] && !$leak && str_contains($e['log_tail'], 'not found'));
}
unlink($secret);

echo $fail ? "\n$fail check(s) failed\n" : "\nM0 checks passed\n";
exit($fail ? 1 : 0);
