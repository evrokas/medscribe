<?php
/**
 * Keeping types/*.json and the store in step (used by bin/export_types.php and bin/check_m1.php).
 *
 * The repo's JSON files are the source a git checkout keeps; the type editor saves into the store only. The importer
 * (bin/import_types.php) goes file -> store: shared components byte for byte, type definitions re-encoded with every
 * shared "latest" pinned to that component's SHA-256. The export goes the other way and undoes exactly that: a pin
 * equal to the component's latest released version is written back as "latest", and the file keeps the repo's
 * formatting (2-space indentation, Unicode as is, empty {} kept as objects), so an unchanged type exports to the
 * same bytes and a changed one to a small diff.
 *
 * Needs cli_boot.php (store functions).
 */

/** The repo's JSON style: pretty, 2 spaces, Unicode and slashes unescaped, final newline. $o from json_decode($x) (objects). */
function types_sync_format($o): string {
    $j = json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return preg_replace_callback('/^(?: {4})+/m', fn($m) => str_repeat('  ', intdiv(strlen($m[0]), 4)), $j) . "\n";
}

/** SHA-256 of the latest released version of each shared component: [name => sha]. */
function types_sync_shared_latest(): array {
    $out = [];
    $rows = store_db()->query("SELECT s.name, v.sha256 FROM sources s JOIN source_versions v ON v.source_id = s.id
                                WHERE s.kind = 'shared' AND v.status = 'released' ORDER BY v.version")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $out[substr($r['name'], strlen('shared/'))] = $r['sha256'];   // the last row wins: the latest
    return $out;
}

/** The file path of a source: types/<name>.json or types/shared/<name>.json. */
function types_sync_path(string $sourceName): string {
    [$kind, $name] = explode('/', $sourceName, 2);
    return dirname(__DIR__, 2) . '/types/' . ($kind === 'shared' ? 'shared/' : '') . $name . '.json';
}

/** What the importer would store for these file bytes (its pinned form), or null when they cannot be read as such. */
function types_sync_import_form(string $kind, string $bytes, array $sharedLatest): ?string {
    if ($kind === 'shared') return $bytes;
    $def = json_decode($bytes, true);
    if (!is_array($def)) return null;
    foreach (($def['shared'] ?? []) as $n => $ref) if ($ref === 'latest') { if (!isset($sharedLatest[$n])) return null; $def['shared'][$n] = $sharedLatest[$n]; }
    return json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

/**
 * The file text for a stored version: shared components as stored; type definitions with "latest" for pins that are the
 * component's latest release (other pins stay hashes: the type really uses an older component), in the repo's style.
 * Returns [text, notes].
 */
function types_sync_file_form(string $kind, string $stored, array $sharedLatest): array {
    if ($kind === 'shared') return [$stored, []];
    $o = json_decode($stored);
    $notes = [];
    if (isset($o->shared) && is_object($o->shared)) {
        foreach ($o->shared as $n => $sha) {
            if (is_string($sha) && ($sharedLatest[$n] ?? null) === $sha) $o->shared->$n = 'latest';
            elseif (is_string($sha) && $sha !== 'latest') $notes[] = "keeps shared/$n pinned to an older version (" . substr($sha, 0, 12) . '…)';
        }
    }
    // a save through the web editor decodes to arrays, so an empty {} came back as []: these keys are objects
    foreach (['fields', 'features', 'shared'] as $k) if (isset($o->$k) && $o->$k === []) $o->$k = new stdClass();
    return [types_sync_format($o), $notes];
}

/**
 * The state of every typedef/shared source against its file.
 * $which: 'released' (the latest released version; a source never released: its latest draft) or 'latest' (the newest).
 * Returns rows: [source, kind, path, version, status, text (the file it should be), notes, state], where state is
 *   same | store-newer (the file is an older stored version or missing) | file-changed (the file holds edits the store does
 *   not have: never overwritten without --force) | unreadable
 */
function types_sync_status(string $which = 'released', array $only = []): array {
    $db = store_db();
    $sharedLatest = types_sync_shared_latest();
    $rows = [];
    $srcs = $db->query("SELECT id, name, kind FROM sources WHERE kind IN ('typedef', 'shared') ORDER BY kind DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($srcs as $s) {
        $short = substr($s['name'], strpos($s['name'], '/') + 1);
        if ($only && !in_array($short, $only, true) && !in_array($s['name'], $only, true)) continue;
        $vs = $db->prepare('SELECT version, status, sha256 FROM source_versions WHERE source_id = ? ORDER BY version DESC');
        $vs->execute([$s['id']]);
        $versions = $vs->fetchAll(PDO::FETCH_ASSOC);
        if (!$versions) continue;
        $pick = $versions[0];
        if ($which === 'released') foreach ($versions as $v) if ($v['status'] === 'released') { $pick = $v; break; }
        [$text, $notes] = types_sync_file_form($s['kind'], store_get($pick['sha256']), $sharedLatest);
        $path = types_sync_path($s['name']);
        $have = is_file($path) ? (string)file_get_contents($path) : null;
        if ($have === $text) $state = 'same';
        elseif ($have === null) $state = 'store-newer';
        else {
            // is the file one of the stored versions (then the store is simply ahead), or does it hold edits of its own?
            $form = types_sync_import_form($s['kind'], $have, $sharedLatest);
            $known = $form !== null && in_array(hash('sha256', $form), array_column($versions, 'sha256'), true);
            $state = $form === null ? 'unreadable' : ($known ? 'store-newer' : 'file-changed');
        }
        $rows[] = ['source' => $s['name'], 'kind' => $s['kind'], 'path' => $path, 'version' => (int)$pick['version'],
                   'status' => $pick['status'], 'text' => $text, 'notes' => $notes, 'state' => $state];
    }
    return $rows;
}
