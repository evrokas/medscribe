#!/usr/bin/env php
<?php
// Debug one imported type: php bin/check_auto.php <name>  (prints the first differences or the TeX error)
require_once(__DIR__ . '/lib/cli_boot.php'); require_once(__DIR__ . '/../web/render.php');
$name = $argv[1] ?? ''; $c = json_decode(file_get_contents(__DIR__ . '/../tests/fixtures/auto/cases.json'), true)[$name] ?? null;
if (!$c) { echo "unknown\n"; exit(1); }
$st = store_db()->prepare("SELECT v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name=? AND v.status='released' ORDER BY v.version DESC LIMIT 1"); $st->execute(["typedef/$name"]);
$type = render_resolve_type(json_decode(store_get($st->fetchColumn()), true), fn($h) => json_decode(store_get($h), true));
$inputs = ['patient' => ['name' => 'Παπαδόπουλος Γιώργος', 'age' => '45', 'gender' => 'M', 'amka' => '12345678901'], 'settings' => ['clinic' => 'Peristeri', 'date' => '2026-01-15', 'title' => $type['title'], 'closing' => $type['closing'], 'stamp' => $type['stamp'] ?? 'no', 'recipient' => $c['recipient'] ?? ''], 'fields' => $c['fields']];
foreach ($type['sections'] as $sec) if (($sec['layout'] ?? '') === 'medtable' && !empty($sec['default_rows'])) $inputs['meds'][$sec['id']] = $sec['default_rows'];
[$tex, $iss] = render_document($type, $inputs);
file_put_contents('/tmp/claude-1000/mine.tex', $tex);
$files = base_release_files((int)base_release_current()['id']);
$a = compile_job($files, ['document.tex' => $tex], 'document.tex', 1767225600);
$b = compile_job($files, ['document.tex' => file_get_contents(__DIR__ . "/../tests/fixtures/auto/$name.tex")], 'document.tex', 1767225600);
echo 'mine: ' . ($a['ok'] ? 'ok' : 'FAILED ' . substr($a['log_tail'] ?? '', -500)) . "\nref: " . ($b['ok'] ? 'ok' : 'FAILED ' . substr($b['log_tail'] ?? '', -500)) . "\n";
if ($a['ok'] && $b['ok']) { file_put_contents('/tmp/claude-1000/a.pdf', $a['pdf']); file_put_contents('/tmp/claude-1000/b.pdf', $b['pdf']);
  echo shell_exec('pdftotext -layout /tmp/claude-1000/a.pdf /tmp/claude-1000/a.txt; pdftotext -layout /tmp/claude-1000/b.pdf /tmp/claude-1000/b.txt; diff /tmp/claude-1000/a.txt /tmp/claude-1000/b.txt | head -12'); echo $a['pdf_sha256'] === $b['pdf_sha256'] ? "IDENTICAL\n" : "differs\n"; }
echo 'issues: ' . json_encode($iss, JSON_UNESCAPED_UNICODE) . "\n";
