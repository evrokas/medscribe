<?php

/**
 * Type editor (PLAN.md §5, M4): versions of type definitions and shared components, a JSON editor that
 * validates before it saves, draft -> released, and a diff between any two versions. Doctor only.
 * New documents use the latest RELEASED version; a draft is for trying a change.
 */

function types_source(string $kind, string $name): ?array {
    if (!in_array($kind, ['typedef', 'shared'], true) || !preg_match('/^[a-z0-9-]{1,64}$/', $name)) return null;
    $st = store_db()->prepare('SELECT * FROM sources WHERE name = ?');
    $st->execute(["$kind/$name"]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function types_versions(int $sourceId): array {
    $st = store_db()->prepare('SELECT v.*, (SELECT COUNT(*) FROM documents d WHERE d.typedef_version_id = v.id) AS docs FROM source_versions v WHERE v.source_id = ? ORDER BY v.version DESC');
    $st->execute([$sourceId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validate the text of a type definition or shared component. For a type, "latest" in its `shared` map is
 * pinned to the hash of the latest released version of that component, and the type is resolved and checked.
 * Returns [errors, normalized bytes to store].
 */
function types_check(string $kind, string $text): array {
    $def = json_decode($text, true);
    if (!is_array($def)) return [['The text is not valid JSON: ' . json_last_error_msg()], ''];
    if ($kind === 'shared') {
        $e = [];
        if (empty($def['name'])) $e[] = 'missing name';
        if ((empty($def['blocks']) || !is_array($def['blocks'])) && (empty($def['drugs']) || !is_array($def['drugs']))) $e[] = 'missing blocks (or drugs)';
        $ids = [];
        foreach (array_merge($def['blocks'] ?? [], $def['drugs'] ?? []) as $b) {
            if (empty($b['id']) || (!isset($b['text']) && !isset($b['name']))) { $e[] = 'an entry has no id or text/name'; continue; }
            if (isset($ids[$b['id']])) $e[] = "duplicate block {$b['id']}";
            $ids[$b['id']] = 1;
        }
        return [$e, $e ? '' : json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"];
    }
    foreach (($def['shared'] ?? []) as $n => $ref) {
        if ($ref === 'latest') {
            $s = source_released_by_name('shared/' . $n);
            if (!$s) return [["no released shared component '$n'"], ''];
            $def['shared'][$n] = $s['sha256'];
        }
    }
    try { $resolved = render_resolve_type($def, fn($h) => json_decode(store_get($h), true)); }
    catch (Throwable $e) { return [[$e->getMessage()], '']; }
    $errors = render_validate_type($resolved);
    return [$errors, $errors ? '' : json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"];
}

function page_type_edit($params) {
    $src = types_source((string)($params['kind'] ?? ''), (string)($params['name'] ?? ''));
    if (!$src) return error_404('Unknown source.');
    $versions = types_versions((int)$src['id']);
    if (!$versions) return error_404('This source has no versions yet.');
    $shown = (int)($_GET['v'] ?? 0) ?: (int)$versions[0]['version'];
    $cur = null; foreach ($versions as $v) if ((int)$v['version'] === $shown) $cur = $v;
    if (!$cur) return error_404('Unknown version.');
    $diff = null;
    $a = (int)($_GET['a'] ?? 0); $b = (int)($_GET['b'] ?? 0);
    if ($a && $b) {
        $va = $vb = null; foreach ($versions as $v) { if ((int)$v['version'] === $a) $va = $v; if ((int)$v['version'] === $b) $vb = $v; }
        if ($va && $vb) {
            try { $diff = ['a' => $a, 'b' => $b, 'lines' => diff_compact(diff_lines(store_get($va['sha256']), store_get($vb['sha256'])), 3)]; }
            catch (Throwable $e) { $diff = ['a' => $a, 'b' => $b, 'lines' => [['op' => '@', 'text' => $e->getMessage()]]]; }
        }
    }
    return Renderer::render("type_edit.zetem", [
        'src' => $src, 'kind' => (string)$params['kind'], 'name' => (string)$params['name'], 'versions' => $versions,
        'shown' => $cur, 'text' => $_SESSION['type_edit_text'][$src['name']] ?? store_get($cur['sha256']), 'diff' => $diff,
        'latest_released' => (function () use ($versions) { foreach ($versions as $v) if ($v['status'] === 'released') return (int)$v['version']; return 0; })(),
    ]);
}

function page_type_edit_post($params) {
    global $kernel;
    $kind = (string)($params['kind'] ?? ''); $name = (string)($params['name'] ?? '');
    $src = types_source($kind, $name);
    if (!$src) return error_404('Unknown source.');
    $url = "/types/$kind/$name";
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token.'); header('location: ' . rel_url($url)); exit(); }
    $user = $kernel->getUserName() ?: 'user';
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $note = trim((string)($_POST['note'] ?? ''));
        $text = str_replace("\r\n", "\n", (string)($_POST['text'] ?? ''));
        if (mb_strlen($text) > 500000) { $kernel->addStatus('error', 'The text is too long.'); }
        elseif ($note === '') { $kernel->addStatus('error', 'A change note is required.'); $_SESSION['type_edit_text'][$src['name']] = $text; }
        else {
            [$errors, $bytes] = types_check($kind, $text);
            if ($errors) {
                $kernel->addStatus('error', 'Not saved: ' . implode('; ', $errors));
                $_SESSION['type_edit_text'][$src['name']] = $text;   // keep what was typed
            } else {
                $before = source_latest((int)$src['id']);
                $v = source_add_version($src, $bytes, mb_substr($note, 0, 500), 'draft', $user);
                unset($_SESSION['type_edit_text'][$src['name']]);
                $kernel->addStatus('status', ($before && $before['id'] === $v['id']) ? 'No change: the text equals the latest version.' : 'Saved as version ' . $v['version'] . ' (draft). Release it to use it for new documents.');
            }
        }
    } elseif ($action === 'release') {
        $st = store_db()->prepare('SELECT id, version FROM source_versions WHERE source_id = ? AND version = ?');
        $st->execute([$src['id'], (int)($_POST['version'] ?? 0)]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) { source_release((int)$row['id']); $kernel->addStatus('status', 'Version ' . $row['version'] . ' released.'); }
    } elseif ($action === 'discard') {
        unset($_SESSION['type_edit_text'][$src['name']]);
    }
    header('location: ' . rel_url($url));
    exit();
}
