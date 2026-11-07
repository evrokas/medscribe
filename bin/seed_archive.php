#!/usr/bin/env php
<?php
/**
 * Seed the text pool from old letters (PLAN.md D15). ONE-OFF, run by the doctor on the real archive:
 *   php bin/seed_archive.php <folder with .tex letters> [--dry-run]
 * For each letter it reads the patient's name and AMKA from the letter itself, takes the plain-text paragraphs
 * and list items of the body (anything with LaTeX commands MedScribe cannot express as text is skipped), turns
 * the patient's name and the AMKA back into @tokens and stores each text under a slot named after the nearest
 * heading (pool_archive_slot(): the composer offers it to sections with the same heading). A text in which any part
 * of the name is still found (surname alone, another grammatical form) is dropped, never stored (pool_name_left()).
 * Letters that still carry the template's placeholder name are skipped. Each letter counts as one pseudo-document
 * (a negative id from its content), so running the script twice on the same letters changes nothing.
 * The texts enter the pool as *history* (suggested, never default); nothing leaves this server.
 * Run it with --dry-run first: it prints the texts it would store, to read before seeding.
 * These letters contain real patients' details: the script reads them and writes only the tokenised text.
 */
// decided before cli_boot.php changes the working directory (a relative path would no longer resolve after it)
$seedMain = PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__);
$seedDir = isset($argv[1]) ? (realpath($argv[1]) ?: $argv[1]) : '';
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/../web/render.php');
require_once(__DIR__ . '/../web/documents.php');
require_once(__DIR__ . '/../web/pool.php');

/** Pure: the pool candidates of one letter: [[slot, text], ...]. */
function seed_letter_texts(string $tex): array {
    if (!preg_match('/\\\\providecommand\s*\\\\PatientName\s*\{([^}]*)\}/', $tex, $m)) return [];
    $name = trim($m[1]);
    if ($name === '' || $name === 'Επώνυμο Όνομα') return [];
    preg_match('/^\s*\\\\providecommand\s*\\\\(?:PatientAMKA|AMKA)\s*\{(\d{11})\}/m', $tex, $am);
    $values = ['patient' => $name, 'amka' => $am[1] ?? ''];
    $parts = explode('\\begin{document}', $tex, 2);
    $body = explode('\\end{document}', $parts[1] ?? '')[0];
    $slot = pool_archive_slot(''); $out = []; $inInfo = false;
    foreach (explode("\n", $body) as $ln) {
        $t = trim($ln);
        if ($t === '' || $t[0] === '%') continue;
        if (preg_match('/^\\\\begin\{PatientInfo\}/', $t)) { $inInfo = true; continue; }
        if (preg_match('/^\\\\end\{PatientInfo\}/', $t)) { $inInfo = false; continue; }
        if ($inInfo) continue;
        if (preg_match('/^\\\\(?:sub)?section\*?\{(.*)\}$/', $t, $h)) { $slot = pool_archive_slot($h[1]); continue; }
        $t = preg_replace('/^\\\\item(\[[^\]]*\])?\s*/', '', $t);
        $t = preg_replace('/\s%.*$/u', '', $t);
        $t = preg_replace('/\\\\textbf\{([^{}]*)\}/', '**$1**', $t);
        $t = str_replace(['\\%', '\\&'], ['%', '&'], $t);
        if (preg_match('/\\\\[a-zA-Z]|[{}$~^_#]/', preg_replace('/\\\\ /', '', $t))) continue;   // anything with LaTeX left is skipped
        if (mb_strlen($t) < 15 || !preg_match('/\p{L}{3}/u', $t)) continue;
        $t = pool_tokenize($t, $values);
        if (pool_name_left($t, $name)) continue;   // part of the patient's name is still in it
        $out[] = [$slot, $t];
    }
    return $out;
}

if ($seedMain) {
    $dir = $seedDir;
    $dry = in_array('--dry-run', $argv, true);
    if (!is_dir($dir)) { fwrite(STDERR, ($dir !== '' ? "not a folder: $dir\n" : '') . "usage: php bin/seed_archive.php <folder> [--dry-run]\n"); exit(2); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $letters = 0; $texts = 0;
    foreach ($files as $f) {
        if (strtolower($f->getExtension()) !== 'tex') continue;
        $src = (string)file_get_contents($f->getPathname());
        $c = seed_letter_texts($src);
        if (!$c) continue;
        $letters++;
        $letterId = -(1 + (int)hexdec(substr(hash('sha256', $src), 0, 7)));   // the same letter is the same pseudo-document
        foreach ($c as [$slot, $text]) {
            if ($dry) { echo "$slot\t$text\n"; $texts++; continue; }
            if (pool_store_text($slot, $text, $letterId, 'archive', 'seed')) $texts++;
        }
    }
    echo ($dry ? 'would seed' : 'seeded') . " $texts new text use(s) from $letters letter(s)\n";
}
