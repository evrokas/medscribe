#!/usr/bin/env php
<?php
/**
 * Import type definitions (types/*.json) into the template store as `typedef/<name>` sources
 * (PLAN.md D18). Each is validated first. A new version is made only when the bytes change.
 *
 *   php bin/import_types.php [--release]     --release also marks the new versions released
 *
 * Definitions are released so documents can start from them; the doctor reviews the wording
 * of each type in the app's type editor (M4). The JSON is stored byte for byte.
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/../web/render.php');

$release = isset(getopt('', ['release'])['release']);
$fail = 0;

// Shared components first (types/shared/*.json -> released `shared/<name>` sources). A type's "shared" map may
// say "latest"; it is replaced by the SHA-256 of the latest released version, so the stored type is pinned.
$sharedHash = [];
foreach (glob(__DIR__ . '/../types/shared/*.json') as $f) {
    $bytes = file_get_contents($f);
    $comp = json_decode($bytes, true);
    if (!is_array($comp) || empty($comp['name']) || (empty($comp['blocks']) && empty($comp['drugs']))) { echo "FAIL shared/" . basename($f) . ": invalid\n"; $fail++; continue; }
    $src = source_ensure('shared/' . $comp['name'], 'shared', 'types/shared/' . basename($f));
    $before = source_latest((int)$src['id']);
    $v = source_add_version($src, $bytes, 'shared component import', $release ? 'released' : 'draft');
    if ($release) source_release((int)$v['id']);
    $sharedHash[$comp['name']] = $v['sha256'];
    echo (!$before || $before['id'] !== $v['id'] ? 'new   ' : 'same  ') . "shared/{$comp['name']} v{$v['version']}\n";
}

foreach (glob(__DIR__ . '/../types/*.json') as $f) {
    $bytes = file_get_contents($f);
    $def = json_decode($bytes, true);
    if (!is_array($def)) { echo "FAIL " . basename($f) . ": invalid JSON\n"; $fail++; continue; }
    foreach (($def['shared'] ?? []) as $name => $ref) {
        if ($ref === 'latest') { if (!isset($sharedHash[$name])) { echo "FAIL " . basename($f) . ": unknown shared component $name\n"; $fail++; continue 2; } $def['shared'][$name] = $sharedHash[$name]; }
    }
    $bytes = json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";   // what is stored: pinned
    try { $resolved = render_resolve_type($def, fn($h) => json_decode(store_get($h), true)); } catch (Throwable $e) { echo "FAIL " . basename($f) . ": " . $e->getMessage() . "\n"; $fail++; continue; }
    $problems = render_validate_type($resolved);
    if ($problems) { echo "FAIL " . basename($f) . ": " . implode('; ', $problems) . "\n"; $fail++; continue; }
    $src = source_ensure('typedef/' . $def['name'], 'typedef', 'types/' . basename($f));
    $before = source_latest((int)$src['id']);
    $isDraft = !empty($def['draft']);   // new types wait for the doctor's review (M8)
    $v = source_add_version($src, $bytes, 'type definition import', $release && !$isDraft ? 'released' : 'draft');
    if ($release && !$isDraft) source_release((int)$v['id']);
    echo (!$before || $before['id'] !== $v['id'] ? 'new   ' : 'same  ') . "typedef/{$def['name']} v{$v['version']}\n";
}
exit($fail ? 1 : 0);
