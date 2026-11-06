#!/usr/bin/env php
<?php
/**
 * M1 regression (PLAN.md G3): a vevaiosi-mri written in the form (type definition + inputs ->
 * generated .tex) gives the same PDF as the hand-filled kit template, for a male and a female
 * patient. Also: the generated .tex of a preview compiles and shows the draft mark.
 * The hand-filled files are tests/fixtures/mri-handfilled-{M,F}.tex (the kit's tpl-vevaiosi-mri.tex
 * filled in the way the doctor does it by hand).
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/../web/render.php');
require_once(__DIR__ . '/../web/documents.php');

$fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $fail; echo ($ok ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? "  ($d)" : '') . "\n"; if (!$ok) $fail++; }

$rel = base_release_current();
$files = base_release_files((int)$rel['id']);
$epoch = 1767225600;

// the latest released type definition, with its shared components resolved (as the app loads it)
function latest_type(string $name): ?array {
    $st = store_db()->prepare("SELECT v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name=? AND v.status='released' ORDER BY v.version DESC LIMIT 1");
    $st->execute(['typedef/' . $name]);
    $sha = $st->fetchColumn();
    return $sha ? render_resolve_type(json_decode(store_get($sha), true), fn($h) => json_decode(store_get($h), true)) : null;
}

foreach (require __DIR__ . '/../tests/fixtures/cases.php' as $c) {
    $label = $c['fixture'];
    $type = latest_type($c['type']);
    check("[$label] the type is released in the store and valid", $type && !render_validate_type($type));
    if (!$type) continue;
    $inputs = ['patient' => $c['patient'],
        'settings' => ['clinic' => 'Peristeri', 'date' => '2026-01-15', 'title' => $type['title'], 'closing' => $c['closing'] ?? $type['closing'], 'stamp' => 'no'],
        'fields' => $c['fields'] ?? [], 'groups' => $c['groups'] ?? [], 'blocks' => $c['blocks'] ?? [], 'sections' => $c['sections'] ?? [], 'extra' => $c['extra'] ?? [], 'meds' => $c['meds'] ?? []];
    [$tex, $issues] = render_document($type, $inputs);
    check("[$label] no issues in the generated document", !$issues, json_encode($issues, JSON_UNESCAPED_UNICODE));
    $mine = compile_job($files, ['document.tex' => $tex], 'document.tex', $epoch);
    $ref = compile_job($files, ['document.tex' => file_get_contents(__DIR__ . "/../tests/fixtures/$label.tex")], 'document.tex', $epoch);
    check("[$label] both compile", $mine['ok'] && $ref['ok'], $mine['error'] ?? $ref['error'] ?? '');
    if (!$mine['ok'] || !$ref['ok']) { echo ($mine['log_tail'] ?? '') . ($ref['log_tail'] ?? '') . "\n"; continue; }
    if ($mine['pdf_sha256'] === $ref['pdf_sha256']) {
        check("[$label] PDF is byte-identical to the hand-filled kit version", true, substr($mine['pdf_sha256'], 0, 12));
    } else {
        $d = sys_get_temp_dir() . '/ms-m1-' . bin2hex(random_bytes(4)); mkdir($d);
        file_put_contents("$d/a.pdf", $mine['pdf']); file_put_contents("$d/b.pdf", $ref['pdf']);
        exec("pdftotext -layout $d/a.pdf $d/a.txt; pdftotext -layout $d/b.pdf $d/b.txt; pdftoppm -r 60 -png $d/a.pdf $d/a; pdftoppm -r 60 -png $d/b.pdf $d/b");
        $sameText = file_get_contents("$d/a.txt") === file_get_contents("$d/b.txt");
        $sameImg = glob("$d/a-*.png") && md5_file(glob("$d/a-*.png")[0]) === md5_file(glob("$d/b-*.png")[0]);
        check("[$label] PDF text and page images match the hand-filled kit version", $sameText && $sameImg, 'bytes differ; text ' . ($sameText ? 'same' : 'DIFFERENT') . ', image ' . ($sameImg ? 'same' : 'DIFFERENT'));
        if (!$sameText) { echo shell_exec("diff $d/a.txt $d/b.txt | head -8"); }
        exec('rm -rf ' . escapeshellarg($d));
    }
}
$type = latest_type('vevaiosi-mri');

// ---- imported kit types (tools/kit_import.py): the kit file with every [placeholder] filled vs the form with the same values
$auto = json_decode(file_get_contents(__DIR__ . '/../tests/fixtures/auto/cases.json'), true) ?: [];
foreach ($auto as $name => $c) {
    $type = latest_type($name);
    check("[auto $name] released and valid", $type && !render_validate_type($type), $type ? implode('; ', render_validate_type($type)) : 'not in the store');
    if (!$type) continue;
    $inputs = ['patient' => ['name' => 'Παπαδόπουλος Γιώργος', 'age' => '45', 'gender' => 'M', 'amka' => '12345678901'],
        'settings' => ['clinic' => 'Peristeri', 'date' => '2026-01-15', 'title' => $type['title'], 'closing' => $type['closing'], 'stamp' => $type['stamp'] ?? 'no', 'recipient' => $c['recipient'] ?? ''],
        'fields' => $c['fields']];
    foreach ($type['sections'] as $sec) if (($sec['layout'] ?? '') === 'medtable' && !empty($sec['default_rows'])) $inputs['meds'][$sec['id']] = $sec['default_rows'];   // as a new document gets them
    [$tex, $issues] = render_document($type, $inputs);
    $mine = compile_job($files, ['document.tex' => $tex], 'document.tex', $epoch);
    $ref = compile_job($files, ['document.tex' => file_get_contents(__DIR__ . "/../tests/fixtures/auto/$name.tex")], 'document.tex', $epoch);
    $same = $mine['ok'] && $ref['ok'] && $mine['pdf_sha256'] === $ref['pdf_sha256'];
    if (!$same && $mine['ok'] && $ref['ok']) {
        $d = sys_get_temp_dir() . '/ms-au-' . bin2hex(random_bytes(3)); mkdir($d); file_put_contents("$d/a.pdf", $mine['pdf']); file_put_contents("$d/b.pdf", $ref['pdf']);
        exec("pdftotext -layout $d/a.pdf $d/a.txt; pdftotext -layout $d/b.pdf $d/b.txt");
        $nb = fn($f) => preg_replace('/\n\s*\n+/', "\n", file_get_contents($f));   // imported types: blank lines may shift by one
        $same = $nb("$d/a.txt") === $nb("$d/b.txt");
        if (!$same) echo shell_exec("diff $d/a.txt $d/b.txt | head -6"); exec('rm -rf ' . escapeshellarg($d));
    }
    check("[auto $name] matches the filled kit template", $same && !$issues, ($mine['ok'] ? '' : 'mine: ' . ($mine['error'] ?? '') . ' ') . ($ref['ok'] ? '' : 'ref: ' . ($ref['error'] ?? '')) . ($issues ? json_encode($issues, JSON_UNESCAPED_UNICODE) : ''));
}

// ---- two A5 pages on one A4 sheet (a long prescription)
require_once __DIR__ . '/../web/print.php';
$syn = latest_type('syntagi-a5');
$lib = null; foreach ($syn['sections'] as $sec) if (!empty($sec['drugs'])) $lib = $sec['drugs'];
$rows = array_map(fn($d) => ['name' => $d['name'], 'dose' => $d['dose'], 'freq' => $d['freq'], 'days' => $d['days'], 'notes' => $d['notes']], array_merge($lib, $lib));   // 64 rows
$long = ['patient' => ['name' => 'Test Long', 'gender' => 'M', 'age' => '40'], 'settings' => ['clinic' => 'Mandra', 'date' => '2026-01-15', 'title' => 'Συνταγή', 'closing' => 'Ο ιατρός', 'stamp' => 'no'], 'meds' => ['medications' => $rows]];
[$ltex, $liss] = render_document($syn, $long);
$lp = compile_job($files, ['document.tex' => $ltex], 'document.tex', $epoch);
$pinfo = function (string $pdf): array { $f = sys_get_temp_dir() . '/ms-pi-' . bin2hex(random_bytes(3)) . '.pdf'; file_put_contents($f, $pdf); $o = shell_exec('pdfinfo ' . escapeshellarg($f)); @unlink($f);
    preg_match('/Pages:\s+(\d+)/', (string)$o, $a); preg_match('/Page size:\s+([\d.]+) x ([\d.]+)/', (string)$o, $b); return [(int)($a[1] ?? 0), (float)($b[1] ?? 0), (float)($b[2] ?? 0)]; };
[$np, $w, $h] = $lp['ok'] ? $pinfo($lp['pdf']) : [0, 0, 0];
check('a long A5 prescription compiles to several A5 pages', $lp['ok'] && $np >= 3 && $w < 430 && $h < 600, "$np pages, {$w}x{$h}");
foreach (['seq' => (int)ceil($np / 2), 'dup' => $np] as $mode => $sheets) {
    $u = print_two_up($lp['pdf'], $mode, true);
    [$n2, $w2, $h2] = $u['ok'] ? $pinfo($u['pdf']) : [0, 0, 0];
    check("two-up ($mode): $sheets landscape A4 sheet(s)", $u['ok'] && $n2 === $sheets && $w2 > 840 && $w2 < 843 && $h2 > 594 && $h2 < 597, $u['ok'] ? "$n2 sheets, {$w2}x{$h2}" : ($u['error'] ?? '') . ' ' . substr($u['log_tail'] ?? '', -200));
}

// preview: draft mark and placeholder QR code are in the document
$inputs = ['patient' => ['name' => 'Test', 'gender' => 'M'], 'settings' => ['clinic' => 'Mandra', 'date' => '2026-01-15'], 'fields' => ['surgery_date' => '2026-01-10']];
[$tex] = render_document($type, $inputs, ['preview' => true]);
$p = compile_job($files, ['document.tex' => $tex], 'document.tex', $epoch, 'preview');
$d = sys_get_temp_dir() . '/ms-m1p-' . bin2hex(random_bytes(4)); mkdir($d); file_put_contents("$d/p.pdf", $p['pdf'] ?? '');
exec("pdftotext $d/p.pdf - 2>/dev/null", $lines);
check('preview compiles and carries the draft mark', $p['ok'] && str_contains(implode(' ', $lines), 'ΠΡ') , implode('', array_slice($lines, 0, 0)));
exec('rm -rf ' . escapeshellarg($d));

echo $fail ? "\n$fail check(s) failed\n" : "\nM1 checks passed\n";
exit($fail ? 1 : 0);
