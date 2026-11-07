#!/usr/bin/env php
<?php
/**
 * Rename fields of type definitions in types/*.json from a list, with every use following:
 *
 *   php bin/rename_fields.php <renames.json> [--dry-run] [type ...]
 *
 * renames.json: {"<type>": {"<old id>": "<new id>", ...}, ...} (keys starting with "_" are comments). Two old ids of one
 * type with the same new id are MERGED: the first one's definition is kept, the other is dropped and its uses point to
 * the kept one. The rules are the type editor's (web/js/type_tree.js renameField):
 *   - in every text of the type, `@old` becomes `@new` as a whole token only (not @old2, not an escaped \@old);
 *   - feature operations {"field": old} follow;
 *   - a new id must be a-z 0-9 _ starting with a letter, not a built-in (patient, age, amka, clinic, date), and not a
 *     field the type already has (unless that field is renamed away in the same list).
 * All renames of a type happen in one pass, so chains and swaps are safe. The file keeps its key order and the repo's
 * formatting. Afterwards every type is checked: no old token is left, it validates, and it renders with sample values
 * without an unknown token or a missing value. Nothing is written when any check fails (or with --dry-run).
 * Then: php bin/import_types.php --release --note="..." <types>   (one new released version per type)
 */
// decided before cli_boot.php changes the working directory
$listArg = (function () use ($argv) { foreach (array_slice($argv, 1) as $a) if (!str_starts_with($a, '--')) return realpath($a) ?: $a; return ''; })();
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/lib/types_sync.php');
require_once(__DIR__ . '/../web/render.php');
require_once(__DIR__ . '/../web/documents.php');
require_once(__DIR__ . '/../web/pool.php');
require_once(__DIR__ . '/../web/types.php');

const RF_BUILTINS = ['patient', 'age', 'amka', 'clinic', 'date'];

/** The regex of whole, unescaped @tokens of these ids (the engine's tokenizer: not after a letter, digit, _, @ or \). */
function rf_token_re(array $ids): string {
    usort($ids, fn($a, $b) => strlen($b) <=> strlen($a));
    return '/(?<![\\\\\p{L}\p{N}_@])@(' . implode('|', array_map(fn($x) => preg_quote($x, '/'), $ids)) . ')(?![a-z0-9_])/u';
}

/** Rename in place: every string of $node (object/array tree from json_decode) and feature operations. Returns the use count. */
function rf_apply(&$node, string $re, array $map): int {
    $n = 0;
    if (is_string($node)) {
        $node = preg_replace_callback($re, function ($m) use ($map, &$n) { $n++; return '@' . $map[$m[1]]; }, $node);
    } elseif (is_object($node)) {
        if (isset($node->op, $node->field) && is_string($node->field) && isset($map[$node->field])) { $node->field = $map[$node->field]; $n++; }
        foreach ($node as $k => &$v) $n += rf_apply($v, $re, $map);
        unset($v);
    } elseif (is_array($node)) {
        foreach ($node as &$v) $n += rf_apply($v, $re, $map);
        unset($v);
    }
    return $n;
}

/** [new definition object, report lines, errors] for one type. */
function rf_rename_type($def, array $map): array {
    $errors = []; $report = [];
    $fields = $def->fields ?? new stdClass();
    $have = array_keys((array)$fields);
    foreach ($map as $old => $new) {
        if (!property_exists($fields, $old)) $errors[] = "no field $old";
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $new)) $errors[] = "$new: use a-z, 0-9 and _, starting with a letter";
        if (in_array($new, RF_BUILTINS, true)) $errors[] = "$new is a built-in value";
        if (in_array($new, $have, true) && !isset($map[$new])) $errors[] = "$new already exists in this type";
    }
    if ($errors) return [$def, [], $errors];
    // uses before (for the report), then one pass over everything
    $re = rf_token_re(array_keys($map));
    $uses = [];
    $probe = json_decode(json_encode($def));
    foreach ($map as $old => $new) { $one = [$old => $new]; $uses[$old] = rf_apply($probe, rf_token_re([$old]), $one); }
    $total = rf_apply($def, $re, $map);
    // the fields object: same order, renamed keys, merged duplicates dropped
    $newFields = new stdClass(); $kept = [];
    foreach ((array)$def->fields as $id => $f) {
        $to = $map[$id] ?? $id;
        if (isset($kept[$to])) { $report[] = sprintf('  %-5s -> %-20s merged into %s (%d use(s) now point to it)', $id, $to, $kept[$to], $uses[$id] ?? 0); continue; }
        $kept[$to] = $id;
        $newFields->$to = $f;
        if ($to !== $id) $report[] = sprintf('  %-5s -> %-20s %d use(s)%s', $id, $to, $uses[$id] ?? 0, ($uses[$id] ?? 0) === 0 ? '  (unused)' : '');
    }
    $def->fields = $newFields;
    $report[] = "  $total use(s) changed";
    return [$def, $report, []];
}

/** Checks of a renamed type: no old token left, valid, renders with sample values without unknown tokens or missing values. */
function rf_check_type(string $text, array $map, array $sharedLatest): array {
    $e = [];
    $left = []; foreach (array_keys($map) as $old) if (!in_array($old, array_values($map), true) && preg_match(rf_token_re([$old]), $text)) $left[] = "@$old";
    if ($left) $e[] = 'old tokens left: ' . implode(', ', $left);
    $stored = types_sync_import_form('typedef', $text, $sharedLatest);
    $def = $stored === null ? null : json_decode($stored, true);
    if (!is_array($def)) return array_merge($e, ['cannot be read as a type (unknown shared component?)']);
    try { $type = render_resolve_type($def, fn($h) => json_decode(store_get($h), true)); }
    catch (Throwable $x) { return array_merge($e, [$x->getMessage()]); }
    $e = array_merge($e, render_validate_type($type));
    foreach (['M', 'F'] as $g) {
        [, $issues] = render_document($type, types_sample_inputs($type, $g));
        if (!empty($issues['unknown'])) $e[] = 'unknown tokens: @' . implode(', @', array_keys($issues['unknown']));
        if (!empty($issues['missing'])) $e[] = 'missing values: ' . implode(', ', array_keys($issues['missing']));
    }
    return array_values(array_unique($e));
}

// options may come anywhere (getopt would stop at the list file and miss a later --dry-run)
$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$unknown = array_filter($args, fn($a) => str_starts_with($a, '--') && $a !== '--dry-run');
if ($unknown) { fwrite(STDERR, 'unknown option ' . implode(' ', $unknown) . "\n"); exit(2); }
$plain = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$onlyTypes = array_slice($plain, 1);
$list = is_file($listArg) ? json_decode((string)file_get_contents($listArg), true) : null;
if (!is_array($list)) { fwrite(STDERR, "usage: php bin/rename_fields.php <renames.json> [--dry-run] [type ...]\n"); exit(2); }

foreach ($onlyTypes as $t) if (!isset($list[$t])) { fwrite(STDERR, "$t is not in the list\n"); exit(2); }
$sharedLatest = types_sync_shared_latest();
$root = dirname(__DIR__); $out = []; $fail = 0;
foreach ($list as $type => $map) {
    if ($type[0] === '_' || ($onlyTypes && !in_array($type, $onlyTypes, true))) continue;
    $path = "$root/types/$type.json";
    echo "$type\n";
    if (!is_file($path)) { echo "  FAIL no file types/$type.json\n"; $fail++; continue; }
    $before = (string)file_get_contents($path);
    [$def, $report, $errors] = rf_rename_type(json_decode($before), $map);
    if ($errors) { foreach ($errors as $x) echo "  FAIL $x\n"; $fail++; continue; }
    foreach ($report as $l) echo "$l\n";
    $text = types_sync_format($def);
    $problems = rf_check_type($text, $map, $sharedLatest);
    if ($problems) { foreach ($problems as $x) echo "  FAIL $x\n"; $fail++; continue; }
    echo "  checks: no old token left, valid, renders (M/F) without unknown tokens or missing values\n";
    $out[$path] = $text;
}
if ($fail) { echo "\n$fail type(s) failed: nothing written\n"; exit(1); }
if ($dry) { echo "\ndry run: " . count($out) . " type(s) would be written\n"; exit(0); }
foreach ($out as $path => $text) file_put_contents($path, $text);
echo "\n" . count($out) . " type(s) written. Next: git diff types/ ; php bin/import_types.php --release --note=\"Field ids renamed\" " . implode(' ', array_map(fn($p) => basename($p, '.json'), array_keys($out))) . "\n";
