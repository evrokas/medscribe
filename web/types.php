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
    $GLOBALS['types_check_resolved'] = $resolved;   // for the preview, which renders what it checked
    return [$errors, $errors ? '' : json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"];
}

/**
 * Sample inputs for the type editor's previews: field default, else placeholder, else label (so nothing reads
 * "missing"; a date field gets a real date), a sample patient of the chosen gender, the type's own settings, and two library rows in a
 * medication table that has no default rows (so the table shows).
 */
function types_sample_inputs(array $type, string $gender): array {
    $en = ($type['lang'] ?? 'gr') === 'en';
    $name = $gender === 'F' ? ($en ? 'Jane Doe' : 'Παπαδοπούλου Μαρία') : ($en ? 'John Doe' : 'Παπαδόπουλος Γιώργος');
    $in = [
        'patient' => ['name' => $name, 'age' => '45', 'gender' => $gender, 'amka' => ''],
        'settings' => ['clinic' => RENDER_CLINICS[0], 'date' => date('Y-m-d'), 'title' => $type['title'] ?? '', 'closing' => $type['closing'] ?? '',
                       'stamp' => $type['stamp'] ?? 'no', 'paper' => $type['paper'] ?? 'a4', 'recipient' => ''],
        // a date field gets a real date (its default if that is one, else today), so its format shows in the preview
        'fields' => array_map(fn($f) => ($f['type'] ?? 'text') === 'date'
                ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['default'] ?? '')) ? $f['default'] : date('Y-m-d'))
                : (string)(($f['default'] ?? '') !== '' ? $f['default'] : (($f['placeholder'] ?? '') !== '' ? $f['placeholder'] : ($f['label'] ?? ''))),
            $type['fields'] ?? []),
    ];
    if (($type['has_patient'] ?? true) === false) $in['patient'] = ['name' => '', 'age' => '', 'gender' => '', 'amka' => ''];
    foreach ($type['sections'] as $sec) {
        if (($sec['layout'] ?? '') !== 'medtable') continue;
        $rows = $sec['default_rows'] ?? array_map(fn($d) => array_intersect_key($d, array_flip(['name', 'dose', 'freq', 'days', 'notes'])), array_slice($sec['drugs'] ?? [], 0, 2));
        if ($rows) $in['meds'][$sec['id']] = $rows;
    }
    return $in;
}

/** POST /types/{kind}/{name}/preview: the unsaved text as the composer form (mode=form) or as the printed document (mode=print), in HTML. */
function page_type_preview_post($params) {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $send = function (array $r) { echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit(); };
    if (!csrfClass::verifyRequest()) $send(['ok' => false, 'errors' => ['Invalid security token: reload the page.']]);
    $kind = (string)($params['kind'] ?? '');
    if ($kind !== 'typedef') $send(['ok' => false, 'errors' => ['Shared components have no preview of their own: open a type that uses them.']]);
    $text = str_replace("\r\n", "\n", (string)($_POST['text'] ?? ''));
    $GLOBALS['types_check_resolved'] = null;
    [$errors] = types_check('typedef', $text);
    $type = $GLOBALS['types_check_resolved'];
    if (!is_array($type)) $send(['ok' => false, 'errors' => $errors]);
    $gender = ($_POST['gender'] ?? 'M') === 'F' ? 'F' : 'M';
    $inputs = types_sample_inputs($type, $gender);
    // preview with case rules (plan §6.2): the sample with these rules applied in order; in print mode what they turn off
    // stays visible, marked, and what they turn on or add is marked too
    $features = array_values(array_filter((array)($_POST['features'] ?? []), fn($f) => is_string($f) && isset($type['features'][$f])));
    $marks = null;
    try {
        if ($features && ($_POST['mode'] ?? 'form') === 'print') {
            $rel = base_release_current();
            $labels = $rel ? render_html_kit_labels((int)$rel['id'], $type['lang'] ?? 'gr') : [];
            $with = $inputs; foreach ($features as $f) $with = render_feature_add($type, $with, $f);
            $srcs = function (string $html) { preg_match_all('/data-src="((?:block|extra):[^"]+)"/', $html, $m); return array_unique($m[1]); };
            [$h0] = render_html_document($type, $inputs, $labels);
            [$h1, $issues] = render_html_document($type, $with, $labels);
            $before = $srcs($h0); $after = $srcs($h1);
            $del = array_values(array_diff($before, $after)); $ins = array_values(array_diff($after, $before));
            // show what was turned off next to what is on: those blocks on again (outside choice groups), then marked
            // (a block of a choice group comes back as the group's choice when the rules left the group without one)
            $groupOf = [];
            foreach (render_expand_sections($type) as $sec) foreach (($sec['blocks'] ?? []) as $b) if (isset($b['group'])) $groupOf[$b['id']] = $b['group'];
            $union = $with;
            foreach ($del as $d) {
                if (!str_starts_with($d, 'block:')) continue;
                $id = substr($d, 6);
                if (!isset($groupOf[$id])) $union['blocks'][$id]['on'] = true;
                elseif (($union['groups'][$groupOf[$id]] ?? null) === '') $union['groups'][$groupOf[$id]] = $id;
            }
            [$html] = render_html_document($type, $union, $labels);
            $set = [];
            foreach ($features as $f) foreach (($type['features'][$f]['ops'] ?? []) as $op) if (($op['op'] ?? '') === 'set') $set[] = 'field:' . $op['field'];
            $marks = ['del' => $del, 'ins' => $ins, 'set' => array_values(array_unique($set))];
            $checks = render_checks($type, $with, $issues);
        } elseif (($_POST['mode'] ?? 'form') === 'print') {
            $rel = base_release_current();
            [$html, $issues] = render_html_document($type, $inputs, $rel ? render_html_kit_labels((int)$rel['id'], $type['lang'] ?? 'gr') : []);
            $checks = render_checks($type, $inputs, $issues);
        } else {
            $html = Renderer::render("composer_type_part.zetem", doc_type_part_data($type, $inputs, 0, false));
            $checks = [];
        }
    } catch (Throwable $e) {
        $send(['ok' => false, 'errors' => array_merge($errors, ['Cannot be shown: ' . $e->getMessage()])]);
    }
    $send(['ok' => true, 'errors' => $errors, 'checks' => array_values(array_filter($checks, fn($c) => $c['level'] !== 'ok')), 'html' => $html,
           'lints' => types_lint($type), 'marks' => $marks]);
}

/**
 * Test PDF of the unsaved text (plan §6.4): the sample inputs compiled exactly as a composer preview is (draft mark, QR
 * frame), in the sandbox, purpose "type-preview", tied to no document. One such build per user is kept (the previous one
 * and its store objects go); a per-user lock refuses a second build while one runs (409).
 */
function page_type_pdf_post($params) {
    global $kernel;
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $send = function (array $r, int $code = 200) { http_response_code($code); echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit(); };
    if (!csrfClass::verifyRequest()) $send(['ok' => false, 'error' => 'Invalid security token: reload the page.'], 400);
    if (($params['kind'] ?? '') !== 'typedef') $send(['ok' => false, 'error' => 'Only a type has a PDF.'], 400);
    $user = $kernel->getUserName() ?: 'user';
    $GLOBALS['types_check_resolved'] = null;
    [$errors] = types_check('typedef', str_replace("\r\n", "\n", (string)($_POST['text'] ?? '')));
    $type = $GLOBALS['types_check_resolved'];
    if (!is_array($type) || $errors) $send(['ok' => false, 'error' => 'Fix the errors first: ' . implode('; ', $errors ?: ['the text cannot be read'])]);
    $rel = base_release_current();
    if (!$rel) $send(['ok' => false, 'error' => 'No letterhead release.']);
    store_mkdir(__APPDIR__ . '/web/files/jobs');
    $lock = fopen(__APPDIR__ . '/web/files/jobs/type-pdf-' . md5($user) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) $send(['ok' => false, 'error' => 'A test PDF is being built already; wait for it.'], 409);
    try {
        $inputs = types_sample_inputs($type, ($_POST['gender'] ?? 'M') === 'F' ? 'F' : 'M');
        [$tex] = render_document($type, $inputs, ['preview' => true]);
        $t0 = microtime(true);
        $r = compile_job(base_release_files((int)$rel['id']), ['document.tex' => $tex], 'document.tex', time(), 'type-preview', $user, null);
        if ($r['ok']) store_put($r['pdf']);
        types_pdf_prune($user, (int)$r['build_id']);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    if (!$r['ok']) $send(['ok' => false, 'error' => 'The PDF failed to build: ' . ($r['error'] ?? '') . ' ' . substr((string)($r['log_tail'] ?? ''), -400)]);
    $send(['ok' => true, 'url' => rel_url('/types/typedef/' . $params['name'] . '/pdf?b=' . $r['build_id']), 'seconds' => round(microtime(true) - $t0, 1), 'passes' => $r['passes']]);
}

/** Remove this user's other type-preview builds and the store objects only they used. */
function types_pdf_prune(string $user, int $keep): void {
    $st = store_db()->prepare("SELECT id, tex_sha256, pdf_sha256, manifest FROM builds WHERE purpose = 'type-preview' AND cuser = ? AND id <> ?");
    $st->execute([$user, $keep]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $gen = [];
        foreach ((json_decode((string)$b['manifest'], true)['files'] ?? []) as $f) if (!empty($f['generated'])) $gen[] = $f['sha256'];
        store_db()->prepare('DELETE FROM builds WHERE id = ?')->execute([$b['id']]);
        foreach (array_filter(array_merge([$b['pdf_sha256'], $b['tex_sha256']], $gen)) as $sha) issue_gc_object($sha);
    }
}

/** The user's own test PDF. */
function page_type_pdf($params) {
    global $kernel;
    $st = store_db()->prepare("SELECT pdf_sha256 FROM builds WHERE id = ? AND purpose = 'type-preview' AND cuser = ? AND status = 'ok'");
    $st->execute([(int)($_GET['b'] ?? 0), $kernel->getUserName() ?: 'user']);
    $sha = $st->fetchColumn();
    if (!$sha) return error_404('No test PDF (it is replaced by the next one).');
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="test-' . preg_replace('/[^a-z0-9-]/', '', (string)$params['name']) . '.pdf"');
    header('Cache-Control: private, no-store');
    echo store_get($sha);
    exit();
}

/**
 * The types that pin a shared component (plan §6.3), from each type's latest version: the pinned component version, the
 * default choice of the sections using it, whether a section uses it at all, and the latest released component version.
 */
function types_used_by(string $component): array {
    $db = store_db();
    $versions = [];   // sha256 => component version
    $st = $db->prepare("SELECT v.sha256, v.version, v.status FROM sources s JOIN source_versions v ON v.source_id = s.id WHERE s.name = ?");
    $st->execute(['shared/' . $component]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $versions[$r['sha256']] = (int)$r['version'];
    $latest = source_released_by_name('shared/' . $component);
    $out = [];
    foreach ($db->query("SELECT id, name FROM sources WHERE kind = 'typedef' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $src) {
        $v = source_latest((int)$src['id']);
        if (!$v) continue;
        $def = json_decode(store_get($v['sha256']), true);
        $pin = $def['shared'][$component] ?? null;
        if (!is_string($pin)) continue;
        $defaults = []; $used = false;
        foreach (($def['sections'] ?? []) as $sec) {
            if (($sec['use'] ?? null) === $component) { $used = true; if (isset($sec['default_block'])) $defaults[] = (string)$sec['default_block']; }
            if (($sec['library'] ?? null) === $component) $used = true;
        }
        $out[] = ['type' => substr($src['name'], strlen('typedef/')), 'label' => (string)($def['label'] ?? $src['name']), 'version' => (int)$v['version'],
                  'status' => $v['status'], 'pinned' => $versions[$pin] ?? null, 'latest' => $latest ? (int)$latest['version'] : null,
                  'defaults' => $defaults, 'used' => $used];
    }
    return $out;
}

/** The label of every shared component (its latest version), for naming the sections that use one: [name => label]. */
function types_shared_labels(): array {
    $out = [];
    $rows = store_db()->query("SELECT s.name, v.sha256 FROM sources s JOIN source_versions v ON v.source_id = s.id
                                WHERE s.kind = 'shared' ORDER BY v.version")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {   // the last row of a source wins: its latest version
        $d = json_decode(store_get($r['sha256']), true);
        $out[substr($r['name'], strlen('shared/'))] = is_array($d) && is_string($d['label'] ?? null) ? $d['label'] : substr($r['name'], 7);
    }
    return $out;
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
    $saved = store_get($cur['sha256']);
    $def = json_decode($saved, true);
    // the redesigned editor (docs/plans/type-editor-ui.md); ?ui=classic keeps the old page until the redesign is accepted
    $template = ($_GET['ui'] ?? '') === 'classic' ? 'type_edit_classic.zetem' : 'type_edit.zetem';
    return Renderer::render($template, [
        'src' => $src, 'kind' => (string)$params['kind'], 'name' => (string)$params['name'], 'versions' => $versions,
        'shown' => $cur, 'text' => $_SESSION['type_edit_text'][$src['name']] ?? $saved, 'diff' => $diff, 'saved_text' => $saved,
        'type_label' => is_array($def) && is_string($def['label'] ?? null) && $def['label'] !== '' ? $def['label'] : (string)$params['name'],
        'shared_labels' => json_encode(types_shared_labels(), JSON_UNESCAPED_UNICODE),
        'used_by' => (string)$params['kind'] === 'shared' ? types_used_by((string)$params['name']) : [],
        // the drug libraries the type pins, for the medication-table editor (plan §4.7)
        'libraries' => json_encode((function () use ($def) {
            $out = [];
            foreach ((is_array($def) ? ($def['shared'] ?? []) : []) as $n => $sha) {
                try { $c = is_string($sha) && preg_match('/^[0-9a-f]{64}$/', $sha) ? json_decode(store_get($sha), true) : null; } catch (Throwable $e) { $c = null; }
                if (is_array($c) && isset($c['drugs'])) $out[$n] = ['label' => $c['label'] ?? $n, 'drugs' => $c['drugs']];
            }
            return $out;
        })(), JSON_UNESCAPED_UNICODE),
        'unsaved' => isset($_SESSION['type_edit_text'][$src['name']]),
        // the shown version can be saved over while it is the latest version, still a draft, and no document uses it
        'can_save_here' => $cur['status'] === 'draft' && (int)$cur['version'] === (int)$versions[0]['version'] && (int)$cur['docs'] === 0,
        'latest_released' => (function () use ($versions) { foreach ($versions as $v) if ($v['status'] === 'released') return (int)$v['version']; return 0; })(),
    ]);
}

/**
 * Review & release (plan §5.2): what changes from the version new documents use now to vN, the checks, the impact, and the
 * release with a required confirmation.
 */
function page_type_review($params) {
    $kind = (string)($params['kind'] ?? ''); $name = (string)($params['name'] ?? '');
    $src = types_source($kind, $name);
    if (!$src) return error_404('Unknown source.');
    $versions = types_versions((int)$src['id']);
    if (!$versions) return error_404('This source has no versions yet.');
    $byVer = []; foreach ($versions as $v) $byVer[(int)$v['version']] = $v;
    $n = (int)($_GET['v'] ?? 0) ?: (int)$versions[0]['version'];
    if (!isset($byVer[$n])) return error_404('Unknown version.');
    $cur = $byVer[$n];
    // compared with: the released version new documents use now; for a released version (or none released): the one before it
    $base = null;
    foreach ($versions as $v) if ($v['status'] === 'released' && (int)$v['version'] !== $n) { $base = $v; break; }
    if ($cur['status'] === 'released' || !$base) { $base = null; foreach ($versions as $v) if ((int)$v['version'] < $n) { $base = $v; break; } }
    if (isset($_GET['against']) && isset($byVer[(int)$_GET['against']])) $base = $byVer[(int)$_GET['against']];
    $text = store_get($cur['sha256']);
    $def = json_decode($text, true) ?: [];
    $labels = types_shared_labels();
    $diff = types_struct_diff($base ? (json_decode(store_get($base['sha256']), true) ?: []) : [], $def, $kind, $labels);
    // the checks: the server's own, then a render of the sample with each gender
    [$errors] = types_check($kind, $text);
    $warnings = [];
    if ($kind === 'typedef' && is_array($GLOBALS['types_check_resolved'] ?? null)) {
        $type = $GLOBALS['types_check_resolved'];
        foreach (['M' => 'male', 'F' => 'female'] as $g => $gl) {
            $inputs = types_sample_inputs($type, $g);
            [, $issues] = render_document($type, $inputs);
            foreach (render_checks($type, $inputs, $issues) as $c) if ($c['level'] === 'error') $warnings[$c['msg']][] = $gl;
        }
    }
    $checks = [];
    foreach ($errors as $e) $checks[] = ['level' => 'error', 'msg' => $e];
    foreach ($warnings as $m => $gs) $checks[] = ['level' => 'warn', 'msg' => $m . ' (sample, ' . implode(' and ', $gs) . ')'];
    if ($kind === 'typedef' && is_array($GLOBALS['types_check_resolved'] ?? null)) foreach (types_lint($GLOBALS['types_check_resolved']) as $l) $checks[] = $l;
    // the shared components it pins, as versions
    $pins = [];
    foreach (($def['shared'] ?? []) as $sn => $sha) {
        $st = store_db()->prepare("SELECT v.version, v.status FROM sources s JOIN source_versions v ON v.source_id = s.id WHERE s.name = ? AND v.sha256 = ?");
        $st->execute(['shared/' . $sn, $sha]);
        $pv = $st->fetch(PDO::FETCH_ASSOC);
        $latest = source_released_by_name('shared/' . $sn);
        $pins[] = ['name' => $sn, 'label' => $labels[$sn] ?? $sn, 'version' => $pv ? (int)$pv['version'] : null,
                   'latest' => $latest ? (int)$latest['version'] : null];
    }
    $docs = 0; foreach ($versions as $v) $docs += (int)$v['docs'];
    return Renderer::render('type_review.zetem', [
        'src' => $src, 'kind' => $kind, 'name' => $name, 'versions' => $versions, 'cur' => $cur, 'base' => $base,
        'type_label' => is_string($def['label'] ?? null) && $def['label'] !== '' ? $def['label'] : $name,
        'diff' => $diff, 'checks' => $checks, 'pins' => $pins, 'docs' => $docs,
        'has_errors' => (bool)$errors,
        'clean' => !array_filter($checks, fn($c) => $c['level'] !== 'note'),
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
    } elseif ($action === 'save_here') {
        // save into the shown draft version (no new version); refused once it is released, superseded or used
        $text = str_replace("\r\n", "\n", (string)($_POST['text'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $st = store_db()->prepare('SELECT id, version FROM source_versions WHERE source_id = ? AND version = ?');
        $st->execute([$src['id'], (int)($_POST['version'] ?? 0)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        [$errors, $bytes] = mb_strlen($text) > 500000 ? [['The text is too long.'], ''] : types_check($kind, $text);
        if (!$row) { $kernel->addStatus('error', 'Unknown version.'); }
        elseif ($errors) {
            $kernel->addStatus('error', 'Not saved: ' . implode('; ', $errors));
            $_SESSION['type_edit_text'][$src['name']] = $text;
        } elseif (!($v = source_update_draft((int)$row['id'], $bytes, $note === '' ? null : mb_substr($note, 0, 500), $user))) {
            $kernel->addStatus('error', 'Version ' . $row['version'] . ' can no longer be changed (it is released, a newer version exists, or a document uses it). Save as a new version instead.');
            $_SESSION['type_edit_text'][$src['name']] = $text;
        } else {
            unset($_SESSION['type_edit_text'][$src['name']]);
            $kernel->addStatus('status', 'Saved in version ' . $v['version'] . ' (still a draft).');
            header('location: ' . rel_url($url . '?v=' . $v['version'])); exit();
        }
    } elseif ($action === 'release') {
        // only from the review page, after the doctor confirmed reading the changes (plan §5.2)
        $st = store_db()->prepare('SELECT id, version, status FROM source_versions WHERE source_id = ? AND version = ?');
        $st->execute([$src['id'], (int)($_POST['version'] ?? 0)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) $kernel->addStatus('error', 'Unknown version.');
        elseif (($_POST['confirm'] ?? '') !== '1') {
            $kernel->addStatus('error', 'Not released: confirm on the review page that you have read the changes.');
            header('location: ' . rel_url("$url/review?v=" . $row['version'])); exit();
        } elseif ($row['status'] !== 'draft') $kernel->addStatus('error', 'Version ' . $row['version'] . ' is already released.');
        else { source_release((int)$row['id']); $kernel->addStatus('status', 'Version ' . $row['version'] . ' released: new documents use it from now on.'); }
    } elseif ($action === 'update_pin' && $kind === 'typedef') {
        // a new draft version of this type with the component pinned to its latest release (plan §6.3)
        $comp = (string)($_POST['component'] ?? '');
        $v = source_latest((int)$src['id']);
        $def = $v ? json_decode(store_get($v['sha256']), true) : null;
        $rel = preg_match('/^[a-z0-9-]{1,64}$/', $comp) ? source_released_by_name('shared/' . $comp) : null;
        if (!is_array($def) || !isset($def['shared'][$comp]) || !$rel) $kernel->addStatus('error', 'Nothing to update.');
        elseif ($def['shared'][$comp] === $rel['sha256']) $kernel->addStatus('status', "$name already uses $comp v{$rel['version']}.");
        else {
            $def['shared'][$comp] = 'latest';
            [$errors, $bytes] = types_check('typedef', json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($errors) $kernel->addStatus('error', "$name cannot use $comp v{$rel['version']}: " . implode('; ', $errors));
            else {
                $extra = trim((string)($_POST['note'] ?? ''));
                $nv = source_add_version($src, $bytes, mb_substr("Update $comp to v{$rel['version']}" . ($extra !== '' ? ": $extra" : ''), 0, 500), 'draft', $user);
                $kernel->addStatus('status', "$name v{$nv['version']} (draft) uses $comp v{$rel['version']}. Review and release it to put it into use.");
            }
        }
        $back = (string)($_POST['back'] ?? '');
        if (preg_match('#^/types/shared/[a-z0-9-]+$#', $back)) { header('location: ' . rel_url($back)); exit(); }
    } elseif ($action === 'discard') {
        unset($_SESSION['type_edit_text'][$src['name']]);
    }
    header('location: ' . rel_url($url));
    exit();
}
