#!/usr/bin/env php
<?php
/**
 * Import the evrdocs template kit into the template store (PLAN.md D19, M0).
 *
 *   php bin/import_kit.php [--kit=~/devel/docs/template-kit] [--dry-run]
 *
 * - base files (evrtemplate.tex, evr-gr.tex, evr-en.tex) and images/ become
 *   `base/...` and `asset/...` sources and, together, a base release (B1, B2, ...)
 *   when their set of versions differs from the current release;
 * - templates/tpl-*.tex and templates/letter-*.tex become `type/...` sources,
 *   left as *draft* versions (a type is released after the doctor's review).
 *   tpl-a5-2up.tex is the imposition helper, not a document type, and is skipped.
 * Re-running adds a version only for files whose bytes changed.
 */
require_once(__DIR__ . '/lib/cli_boot.php');

$o = getopt('', ['kit::', 'dry-run']);
$kit = rtrim($o['kit'] ?? (getenv('HOME') . '/devel/docs/template-kit'), '/');
$dry = isset($o['dry-run']);
if (!is_file("$kit/evrtemplate.tex")) { fwrite(STDERR, "not a kit folder: $kit\n"); exit(1); }

$rev = trim((string)shell_exec('git -C ' . escapeshellarg($kit) . ' rev-parse --short HEAD 2>/dev/null'));
$note = 'kit import' . ($rev !== '' ? " (evrdocs $rev)" : '');

$items = [];   // [name, kind, kitpath]
foreach (['evrtemplate.tex', 'evr-gr.tex', 'evr-en.tex'] as $f) $items[] = ["base/$f", 'base', $f];
foreach (glob("$kit/images/*") as $p) if (is_file($p)) $items[] = ['asset/' . basename($p), 'asset', 'images/' . basename($p)];
foreach (glob("$kit/templates/*.tex") as $p) {
    $b = basename($p, '.tex');
    if ($b === 'tpl-a5-2up') continue;
    $items[] = ['type/' . preg_replace('/^tpl-/', '', $b), 'type', 'templates/' . basename($p)];
}

$baseVersionIds = [];
$new = 0;
foreach ($items as [$name, $kind, $kitpath]) {
    $bytes = file_get_contents("$kit/$kitpath");
    if ($dry) { echo sprintf("%-40s %s\n", $name, hash('sha256', $bytes)); continue; }
    $src = source_ensure($name, $kind, $kitpath);
    $before = source_latest((int)$src['id']);
    $v = source_add_version($src, $bytes, $note);
    if (!$before || $before['id'] !== $v['id']) { $new++; echo "  $name v{$v['version']}\n"; }
    if ($kind !== 'type') $baseVersionIds[] = (int)$v['id'];
}
if ($dry) exit(0);
echo "$new new version(s)\n";

$cur = base_release_current();
$curIds = $cur ? array_map(fn($r) => (int)$r['version_id'], base_release_files((int)$cur['id'])) : [];
sort($curIds); sort($baseVersionIds);
if ($curIds === $baseVersionIds) {
    echo "base release B{$cur['number']} is up to date\n";
} else {
    $r = base_release_create($baseVersionIds, $note);
    echo "created base release B{$r['number']} (" . count($baseVersionIds) . " files)\n";
}
