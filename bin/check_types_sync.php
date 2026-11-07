#!/usr/bin/env php
<?php
/**
 * bin/export_types.php against a throw-away type (typedef/wt-sync-test, file types/wt-sync-test.json, both removed at
 * the end): a missing file is written; a released version is exported and a draft only with --latest; a file with edits
 * of its own is held (and overwritten only with --force); shared pins come back as "latest"; empty {} stay objects.
 * Then the real types: every file agrees with the store (a warning, not a failure, when it does not: run the export).
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/lib/types_sync.php');

$fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $fail; echo ($ok ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? "  ($d)" : '') . "\n"; if (!$ok) $fail++; }
$root = dirname(__DIR__);
$run = function (string $args) use ($root): array { exec('cd ' . escapeshellarg($root) . " && php bin/export_types.php $args 2>&1", $out, $rc); return [implode("\n", $out), $rc]; };

$name = 'wt-sync-test'; $file = "$root/types/$name.json"; $db = store_db();
if (is_file($file)) { fwrite(STDERR, "$file exists: remove it first\n"); exit(1); }
$purpose = types_sync_shared_latest()['purpose'] ?? null;
$src = source_ensure("typedef/$name", 'typedef', "types/$name.json");
try {
    $def = ['format' => 1, 'name' => $name, 'label' => 'Sync test', 'lang' => 'gr', 'paper' => 'a4', 'fields' => [], 'shared' => ['purpose' => $purpose],
            'sections' => [['id' => 's1', 'slot' => "$name.s1", 'layout' => 'paragraphs', 'blocks' => [['id' => 'b1', 'text' => 'Ένα', 'default' => true]]]]];
    $enc = fn(array $d) => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    $v1 = source_add_version($src, $enc($def), 'v1', 'released');

    [$o, $rc] = $run("--check $name");
    check('a type with no file: --check reports it and fails', $rc === 1 && str_contains($o, 'differs'), $o);
    [$o, $rc] = $run($name);
    $j = is_file($file) ? json_decode(file_get_contents($file)) : null;
    check('export writes the file', $rc === 0 && $j && $j->sections[0]->blocks[0]->text === 'Ένα', $o);
    check('a pin to the latest shared component comes back as "latest"', $j && $j->shared->purpose === 'latest');
    check('an empty "fields" stays an object {}', str_contains((string)file_get_contents($file), '"fields": {}'));
    check('the file is in the repo style (2-space indent)', str_contains((string)file_get_contents($file), "\n  \"name\": \"$name\""));
    [$o, $rc] = $run("--check $name");
    check('then --check agrees', $rc === 0 && str_contains($o, 'agree'), $o);

    $d2 = $def; $d2['sections'][0]['blocks'][0]['text'] = 'Δύο';
    source_add_version($src, $enc($d2), 'v2', 'draft');
    [$o, $rc] = $run($name);
    check('a newer draft is not exported by default (the released version is)', json_decode(file_get_contents($file))->sections[0]->blocks[0]->text === 'Ένα', $o);
    [$o, $rc] = $run("--latest $name");
    check('--latest exports the draft', json_decode(file_get_contents($file))->sections[0]->blocks[0]->text === 'Δύο', $o);

    file_put_contents($file, str_replace('Δύο', 'ΧΕΙΡΟΚΙΝΗΤΑ', file_get_contents($file)));
    [$o, $rc] = $run("--latest $name");
    check('a file with edits of its own is held, not overwritten', $rc === 1 && str_contains($o, 'HELD') && str_contains(file_get_contents($file), 'ΧΕΙΡΟΚΙΝΗΤΑ'), $o);
    [$o, $rc] = $run("--latest --force $name");
    check('--force overwrites it', !str_contains(file_get_contents($file), 'ΧΕΙΡΟΚΙΝΗΤΑ'), $o);

    // the file of an older stored version is simply behind: written without --force
    $v3 = $d2; $v3['sections'][0]['blocks'][0]['text'] = 'Τρία'; source_release((int)source_add_version($src, $enc($v3), 'v3', 'released')['id']);
    [$o, $rc] = $run($name);
    check('a file equal to an older version is updated (store ahead, no --force needed)', $rc === 0 && json_decode(file_get_contents($file))->sections[0]->blocks[0]->text === 'Τρία', $o);
} finally {
    @unlink($file);
    $db->exec('DELETE FROM source_versions WHERE source_id = ' . (int)$src['id']); $db->exec('DELETE FROM sources WHERE id = ' . (int)$src['id']);
}

// the real types: the files and the store should agree (if not, someone saved in the web editor: export, review, commit)
$diff = array_filter(types_sync_status(), fn($r) => $r['state'] !== 'same');
foreach ($diff as $r) echo "WARN  {$r['source']}: types/ and the store differ ({$r['state']}): run php bin/export_types.php\n";
if (!$diff) echo "PASS  every types/*.json agrees with the store\n";

echo $fail ? "\n$fail check(s) failed\n" : "\nTypes sync checks passed\n";
exit($fail ? 1 : 0);
