#!/usr/bin/env php
<?php
/**
 * Write the store's type definitions back to types/*.json (and types/shared/*.json), so edits made in the web type
 * editor reach git. The opposite of bin/import_types.php; see bin/lib/types_sync.php for the exact mapping.
 *
 *   php bin/export_types.php [--check] [--latest] [--force] [name ...]
 *
 *   (default)  write the latest *released* version of each type (a type never released: its latest draft)
 *   --latest   write the newest version, also when it is a draft
 *   --check    write nothing: list what differs; exit 1 if anything would change
 *   --force    also overwrite a file that holds edits of its own (not any stored version: someone edited it by hand
 *              and did not import it); without --force such a file is left alone and reported
 *   name ...   only these types (a shared component as shared/<name> or its plain name)
 *
 * Afterwards: review with `git diff types/` and commit.
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/lib/types_sync.php');

$opts = getopt('', ['check', 'latest', 'force'], $rest);
$check = isset($opts['check']); $force = isset($opts['force']);
$only = array_slice($argv, $rest);
$rows = types_sync_status(isset($opts['latest']) ? 'latest' : 'released', $only);
if ($only && !$rows) { fwrite(STDERR, "no such type in the store: " . implode(', ', $only) . "\n"); exit(2); }

$changes = 0; $held = 0;
foreach ($rows as $r) {
    $rel = substr($r['path'], strlen(dirname(__DIR__)) + 1);
    $what = "{$r['source']} v{$r['version']} ({$r['status']})";
    $note = $r['notes'] ? '  [' . implode('; ', $r['notes']) . ']' : '';
    switch ($r['state']) {
        case 'same':
            if (!$check) echo "same      $rel\n";
            break;
        case 'unreadable':
            echo "SKIP      $rel: the file is not a valid definition; fix it or use --force$note\n";
            if ($force && !$check) { file_put_contents($r['path'], $r['text']); echo "          overwritten with $what\n"; }
            $changes++;
            break;
        case 'file-changed':
            $changes++;
            if ($check || !$force) { echo "HELD      $rel: the file has edits the store does not have (import them, or --force to overwrite with $what)$note\n"; $held++; break; }
            file_put_contents($r['path'], $r['text']); echo "write     $rel <- $what (overwrote the file's own edits)$note\n";
            break;
        case 'store-newer':
            $changes++;
            if ($check) { echo "differs   $rel: the store has $what$note\n"; break; }
            file_put_contents($r['path'], $r['text']); echo "write     $rel <- $what$note\n";
            break;
    }
}
if ($check) { echo $changes ? "$changes file(s) differ from the store\n" : "types/ and the store agree\n"; exit($changes ? 1 : 0); }
echo $changes - $held ? ($changes - $held) . " file(s) written; review with: git diff types/\n" : "nothing to write\n";
exit($held ? 1 : 0);
