<?php

/**
 * Document pages (M1): list, new, composer (form + preview), delete of drafts.
 * Rendering is in render.php, storage in store.php, compiling in compile.php.
 */

require_once(__DIR__ . '/ui.php');   // section names, status words

const DOC_STATUSES = ['draft', 'review', 'code_reserved'];   // the statuses in which the content can still be edited

function doc_redirect(string $path): void {
    header('location: ' . rel_url($path));
    exit();
}

/** Type definitions that documents can start from: latest released version of each typedef. */
function doc_types(): array {
    $rows = store_db()->query(
        "SELECT s.name, v.id AS version_id, v.version, v.sha256
           FROM sources s JOIN source_versions v ON v.source_id = s.id
          WHERE s.kind = 'typedef' AND v.status = 'released'
            AND v.version = (SELECT MAX(version) FROM source_versions WHERE source_id = s.id AND status = 'released')
          ORDER BY s.name")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $def = json_decode(store_get($r['sha256']), true);
        if (is_array($def)) $out[] = ['name' => substr($r['name'], 8), 'version_id' => (int)$r['version_id'], 'version' => (int)$r['version'], 'def' => $def];
    }
    return $out;
}

function doc_typedef(int $versionId): ?array {
    $st = store_db()->prepare('SELECT sha256 FROM source_versions WHERE id = ?');
    $st->execute([$versionId]);
    $sha = $st->fetchColumn();
    $def = $sha ? json_decode(store_get($sha), true) : null;
    if (!is_array($def)) return null;
    // shared components are pinned by hash in the definition
    return render_resolve_type($def, fn($h) => json_decode(store_get($h), true));
}

function doc_load(int $id): ?array {
    $st = store_db()->prepare('SELECT * FROM documents WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    $d['inputs'] = json_decode($d['inputs'], true) ?: [];
    return $d;
}

function doc_save_inputs(int $id, array $inputs, ?string $status = null): void {
    $name = mb_substr(trim((string)($inputs['patient']['name'] ?? '')), 0, 255);
    $sql = 'UPDATE documents SET inputs = ?, patient_name = ?, udate = NOW()' . ($status ? ', status = ?' : '') . ' WHERE id = ?';
    $args = [json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $name];
    if ($status) $args[] = $status;
    $args[] = $id;
    store_db()->prepare($sql)->execute($args);
}

function doc_clean_date(?string $s): string {
    $s = trim((string)$s);
    return render_format_date($s) !== '' ? $s : '';
}

function doc_text(?string $s, int $max = 20000): string {
    return mb_substr(str_replace("\r\n", "\n", (string)$s), 0, $max);
}

/** Read the composer form (all values are validated and clamped). */
function doc_inputs_from_post(array $type, array $post): array {
    $p = $post['patient'] ?? [];
    $s = $post['settings'] ?? [];
    $in = [
        'patient' => [
            'name'   => mb_substr(trim((string)($p['name'] ?? '')), 0, 255),
            'age'    => mb_substr(trim((string)($p['age'] ?? '')), 0, 16),
            'amka'   => preg_replace('/\D/', '', (string)($p['amka'] ?? '')) ?: '',
            'gender' => in_array($p['gender'] ?? '', ['M', 'F'], true) ? $p['gender'] : '',
            // the ZPMS link (hidden fields): kept only when well-formed
            'guid'   => preg_match('/^[0-9a-f-]{36}$/', (string)($p['guid'] ?? '')) ? $p['guid'] : '',
            'dob'    => doc_clean_date($p['dob'] ?? ''),
        ],
        'settings' => [
            'clinic'  => in_array($s['clinic'] ?? '', RENDER_CLINICS, true) ? $s['clinic'] : '',
            'date'    => doc_clean_date($s['date'] ?? ''),
            'title'   => mb_substr(trim((string)($s['title'] ?? '')), 0, 255),
            'closing' => mb_substr(trim((string)($s['closing'] ?? '')), 0, 255),
            'stamp'   => ($s['stamp'] ?? 'no') === 'yes' ? 'yes' : 'no',
            'recipient' => mb_substr(trim((string)($s['recipient'] ?? '')), 0, 500),
            'page_numbers' => in_array($s['page_numbers'] ?? '', ['all', 'yes', 'no'], true) ? $s['page_numbers'] : ($type['page_numbers'] ?? ''),
        ],
        'fields' => [], 'blocks' => [], 'groups' => [], 'sections' => [], 'extra' => [],
    ];
    foreach (($type['fields'] ?? []) as $id => $f) {
        $v = trim((string)($post['fields'][$id] ?? ''));
        $in['fields'][$id] = ($f['type'] ?? 'text') === 'date' ? doc_clean_date($v) : mb_substr($v, 0, 1000);
    }
    foreach (render_expand_sections($type) as $sec) {
        if (!empty($sec['optional'])) $in['sections'][$sec['id']] = ['on' => isset($post['sections_on'][$sec['id']])];
        foreach (($sec['blocks'] ?? []) as $b) {
            $id = $b['id'];
            if (isset($b['group'])) {
                $g = $b['group'];
                if (!isset($in['groups'][$g])) {
                    $choice = (string)($post['groups'][$g] ?? '');
                    $valid = array_column(array_filter($sec['blocks'], fn($x) => ($x['group'] ?? null) === $g), 'id');
                    $in['groups'][$g] = in_array($choice, $valid, true) ? $choice : '';
                }
            } else {
                $in['blocks'][$id]['on'] = isset($post['blocks_on'][$id]);
            }
            // a trusted raw-LaTeX block (format: latex, from the kit import) is not edited here: edited text would be
            // escaped as markup and print its LaTeX commands; it is changed in the type editor (the doctor's)
            if (isset($post['btext'][$id]) && ($b['format'] ?? 'markup') !== 'latex') {
                $t = doc_text($post['btext'][$id]);
                if (trim(str_replace("\r", '', $t)) !== trim($b['text'])) $in['blocks'][$id]['text'] = $t;
            }
        }
        if (($sec['layout'] ?? '') === 'medtable') {
            $rows = [];
            foreach (array_slice(array_values((array)($post['meds'][$sec['id']] ?? [])), 0, 60) as $r) {
                if (!is_array($r) || trim((string)($r['name'] ?? '')) === '') continue;
                $rows[] = array_map(fn($k) => mb_substr(trim(str_replace("\r\n", "\n", (string)($r[$k] ?? ''))), 0, 500), ['name' => 'name', 'dose' => 'dose', 'freq' => 'freq', 'days' => 'days', 'notes' => 'notes']);
            }
            $in['meds'][$sec['id']] = $rows;
        }
        $x = trim(doc_text($post['extra'][$sec['id']] ?? ''));
        if ($x !== '') $in['extra'][$sec['id']] = [['text' => $x]];
    }
    return $in;
}

/** What the composer shows: each section with its blocks' effective text and state. */
function doc_view_model(array $type, array $inputs): array {
    $secs = [];
    foreach (render_expand_sections($type) as $sec) {
        $blocks = [];
        foreach (($sec['blocks'] ?? []) as $b) {
            $edited = isset($inputs['blocks'][$b['id']]['text']);
            $blocks[] = [
                'id' => $b['id'], 'label' => $b['label'] ?? '', 'group' => $b['group'] ?? '',
                'on' => render_block_on($sec, $b, $inputs),
                'text' => render_block_text($b, $inputs), 'edited' => $edited, 'original' => $b['text'],
                'in_group' => isset($b['group']), 'is_latex' => ($b['format'] ?? 'markup') === 'latex' && !$edited,
            ];
        }
        $secs[] = [
            'id' => $sec['id'], 'heading' => $sec['heading'] ?? '', 'slot' => $sec['slot'] ?? $sec['id'],
            'name' => ui_section_name($sec, $type),   // never the slot or layout (plan §4.2)
            'src' => (isset($sec['topic_of']) ? 'topic:' : 'section:') . $sec['id'],   // the type editor's preview finds it in the definition
            'layout' => $sec['layout'] ?? 'paragraphs', 'optional' => !empty($sec['optional']),
            'on' => render_section_on($sec, $inputs), 'blocks' => $blocks, 'is_med' => ($sec['layout'] ?? '') === 'medtable', 'meds' => $inputs['meds'][$sec['id']] ?? [],
            'drug_groups' => (function () use ($sec) { $g = []; foreach (($sec['drugs'] ?? []) as $d) $g[$d['group'] ?? ''][] = $d; return $g; })(),
            'drug_json' => json_encode($sec['drugs'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'is_list' => in_array($sec['layout'] ?? 'paragraphs', ['itemize', 'enumerate', 'urgentbox'], true),
            'extra' => $inputs['extra'][$sec['id']][0]['text'] ?? '', 'placeholder' => (string)($sec['placeholder'] ?? ''), 'label' => (string)($sec['label'] ?? ''),
            'rows' => empty($sec['blocks']) ? 8 : 3,
            'suggestions' => pool_suggest((string)($sec['slot'] ?? $sec['id']), 4, array_merge(array_column($blocks, 'text'), [trim((string)($inputs['extra'][$sec['id']][0]['text'] ?? ''))]), (string)($sec['heading'] ?? '')),
            'none_on' => !array_filter($blocks, fn($b) => $b['in_group'] && $b['on']),
            // folded at first when it has items to pick and none is picked (a free-text-only section stays open)
            'collapsed' => (!empty($sec['optional']) && !render_section_on($sec, $inputs)) || ($blocks || ($sec['layout'] ?? '') === 'medtable') && !array_filter($blocks, fn($b) => $b['on'])
                           && trim((string)($inputs['extra'][$sec['id']][0]['text'] ?? '')) === '' && empty($inputs['meds'][$sec['id']]),
            'summary' => ($sec['layout'] ?? '') === 'medtable' ? count($inputs['meds'][$sec['id']] ?? []) . ' ' . t('rows')
                         : ($blocks ? count(array_filter($blocks, fn($b) => $b['on'])) . '/' . count($blocks) . ' ' . t('on') : ''),
        ];
    }
    return $secs;
}

/** The variables of composer_type_part.zetem (the composer's values, case features and sections). */
function doc_type_part_data(array $type, array $inputs, int $docId, bool $locked, bool $withSentences = false): array {
    return [
        'doc_id' => $docId, 'locked' => $locked,
        // the Write view's rendered sentences (web/sentences.php), next to each block's textarea; the type editor's Form preview has none
        'sentences' => $withSentences ? sentences_for($type, $inputs) : [],
        'fields' => doc_field_rows($type, $inputs), 'sections' => doc_view_model($type, $inputs),
        'feature_defs' => array_map(fn($id, $f) => ['id' => $id, 'label' => $f['label'] ?? $id, 'on' => in_array($id, $inputs['features'] ?? [], true)],
                                    array_keys($type['features'] ?? []), array_values($type['features'] ?? [])),
    ];
}

/** Fields with their current values for the form. */
function doc_field_rows(array $type, array $inputs): array {
    $rows = [];
    foreach (($type['fields'] ?? []) as $id => $f) {
        $rows[] = ['id' => $id, 'label' => $f['label'] ?? $id, 'type' => $f['type'] ?? 'text', 'placeholder' => $f['placeholder'] ?? '',
                   'required' => !empty($f['required']), 'value' => $inputs['fields'][$id] ?? ($f['default'] ?? '')];
    }
    return $rows;
}

function doc_preview(array $doc, array $type): array {
    [$tex, $issues] = render_document($type, $doc['inputs'], ['preview' => true]);
    $files = base_release_files((int)$doc['base_release_id']);
    $epoch = time();
    $r = compile_job($files, ['document.tex' => $tex], 'document.tex', $epoch, 'preview', $GLOBALS['kernel']->getUserName() ?: 'user', (int)$doc['id']);
    if ($r['ok']) store_put($r['pdf']);
    store_db()->prepare('UPDATE documents SET last_build_id = ? WHERE id = ?')->execute([$r['build_id'], $doc['id']]);
    issue_prune_builds((int)$doc['id'], [(int)$r['build_id']], ['preview']);   // only the latest preview is kept
    return $r;
}

/* ----- handlers ----- */

/** The documents list's filters (plan §5.2): name => [label, statuses]. "Drafts" also holds documents half-way through issuing. */
function doc_list_filters(): array {
    return [
        'all' => [t('All'), []],
        'review' => [t('Ready for review'), ['review']],
        'draft' => [t('Drafts'), ['draft', 'code_reserved', 'final_compiled', 'approved', 'pdf_sent']],
        'issued' => [t('Issued'), ['issued']],
        'withdrawn' => [t('Withdrawn'), ['withdrawn']],
    ];
}

/** "Today 11:18", "Yesterday 09:02", else the date. */
function doc_when(?string $ts): string {
    if (!$ts || !($u = strtotime($ts))) return '';
    $d = date('Y-m-d', $u);
    if ($d === date('Y-m-d')) return t('Today') . ' ' . date('H:i', $u);
    if ($d === date('Y-m-d', strtotime('-1 day'))) return t('Yesterday') . ' ' . date('H:i', $u);
    return date('d/m/Y', $u);
}

function page_documents($params) {
    $filters = doc_list_filters();
    $db = store_db();
    $counts = ['review' => (int)$db->query("SELECT COUNT(*) FROM documents WHERE status = 'review'")->fetchColumn()];
    // the default view: the doctor's list opens on the documents handed over when there are any; the secretary's on drafts
    $f = (string)($_GET['f'] ?? '');
    if (!isset($filters[$f])) $f = issue_is_doctor() ? ($counts['review'] > 0 ? 'review' : 'all') : 'draft';
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $where = []; $args = [];
    if ($filters[$f][1]) { $where[] = 'status IN (' . implode(',', array_fill(0, count($filters[$f][1]), '?')) . ')'; $args = $filters[$f][1]; }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(patient_name LIKE ? OR doc_code LIKE ? OR id = ?)'; array_push($args, $like, $like, ctype_digit(ltrim($q, '#')) ? (int)ltrim($q, '#') : 0);
    }
    $st = $db->prepare("SELECT id, type_name, status, patient_name, doc_code, cuser, cdate, udate,
                               JSON_UNQUOTE(JSON_EXTRACT(inputs, '$.settings.clinic')) AS clinic
                          FROM documents" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY COALESCE(udate, cdate) DESC LIMIT 200');
    $st->execute($args);
    $types = doc_types();
    $labels = array_column(array_map(fn($t) => ['n' => $t['name'], 'l' => $t['def']['label'] ?? $t['name']], $types), 'l', 'n');
    $docs = array_map(fn($d) => $d + ['type_label' => $labels[$d['type_name']] ?? $d['type_name'], 'when' => doc_when($d['udate'] ?: $d['cdate']),
                                      'when_full' => (string)($d['udate'] ?: $d['cdate'])], $st->fetchAll(PDO::FETCH_ASSOC));
    return Renderer::render("documents_list.zetem", [
        'docs' => $docs, 'types' => count($types), 'f' => $f, 'q' => $q, 'counts' => $counts, 'is_doctor' => issue_is_doctor(),
        'filters' => array_map(fn($k, $v) => ['id' => $k, 'label' => $v[0], 'on' => $k === $f, 'n' => $counts[$k] ?? null,
                                              'url' => '/documents?f=' . $k . ($q !== '' ? '&q=' . rawurlencode($q) : '')], array_keys($filters), $filters),
    ]);
}

/** The types offered on the new-document page, by label, with what the page needs to know about each. */
function doc_new_types(): array {
    $out = array_map(fn($t) => [
        'name' => $t['name'], 'label' => $t['def']['label'] ?? $t['name'],
        'patient' => ($t['def']['has_patient'] ?? true) === false ? 'no' : (($t['def']['has_patient'] ?? true) === 'optional' ? 'optional' : 'yes'),
        'lang' => ($t['def']['lang'] ?? 'gr') === 'en' ? 'en' : 'gr', 'paper' => strtoupper((string)($t['def']['paper'] ?? 'a4')),
    ], doc_types());
    usort($out, fn($a, $b) => strcoll($a['label'], $b['label']));
    return $out;
}

function page_document_new($params) {
    $prefill = null; $zpmsError = false;
    $guid = strtolower(trim((string)($_GET['patient'] ?? '')));
    if ($guid !== '') {
        $p = zpms_get_patient($guid);
        if ($p) $prefill = patient_snapshot_from_zpms($p, date('Y-m-d'));
        else $zpmsError = true;   // ZPMS off, unreachable, or no such patient: the form stays blank, typing by hand still works
    }
    return Renderer::render("document_new.zetem", [
        'zpms' => zpms_enabled(), 'zpms_error' => $zpmsError, 'prefill' => $prefill ?? [],
        'types' => doc_new_types(), 'chosen' => (string)($_GET['type'] ?? ''),
        'clinics' => RENDER_CLINICS, 'today' => date('Y-m-d'),
    ]);
}

/** JSON for the new-document page's patient search box (the key stays on the server). */
function page_document_patient_search($params) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!zpms_enabled()) { echo json_encode(['enabled' => false, 'matches' => []]); exit(); }
    // ?guid=: one patient's snapshot, so the new-document page fills the patient in place (the type, clinic
    // and date already chosen stay as they are). Age is computed at the date on the form.
    $guid = strtolower(trim((string)($_GET['guid'] ?? '')));
    if ($guid !== '') {
        $p = zpms_get_patient($guid);
        $date = doc_clean_date($_GET['date'] ?? '') ?: date('Y-m-d');
        echo json_encode(['enabled' => true, 'ok' => $p !== null, 'patient' => $p ? patient_snapshot_from_zpms($p, $date) : null], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $m = zpms_search_patients((string)($_GET['q'] ?? ''));
    echo json_encode(['enabled' => true, 'ok' => $m !== null, 'matches' => $m ?? []], JSON_UNESCAPED_UNICODE);
    exit();
}

function page_document_new_post($params) {
    global $kernel;
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token. Please try again.'); doc_redirect('/documents/new'); }
    $types = array_column(doc_types(), null, 'name');
    $t = $types[$_POST['type'] ?? ''] ?? null;
    $rel = base_release_current();
    if (!$t || !$rel) { $kernel->addStatus('error', 'Unknown document type or no base release.'); doc_redirect('/documents/new'); }
    $pat = $_POST['patient'] ?? [];
    if (render_patient_required($t['def']) && trim((string)($pat['name'] ?? '')) === '') { $kernel->addStatus('error', 'The patient name is required for this type.'); doc_redirect('/documents/new'); }
    $docDate = doc_clean_date($_POST['date'] ?? '') ?: date('Y-m-d');
    $dobOk = doc_clean_date($pat['dob'] ?? '');
    if (trim((string)($pat['age'] ?? '')) === '' && $dobOk !== '') {
        $a = patient_age_at($dobOk, $docDate);
        if ($a !== null) $pat['age'] = (string)$a;
    }
    // A new document starts from the type's defaults: only the patient and the settings are set here. (Going through
    // doc_inputs_from_post() would switch every block off: no checkbox is posted by this form.)
    $clean = doc_inputs_from_post($t['def'], ['patient' => $pat, 'settings' => [
        'clinic' => $_POST['clinic'] ?? '', 'date' => $docDate,
        'title' => $t['def']['title'] ?? '', 'closing' => $t['def']['closing'] ?? '', 'stamp' => $t['def']['stamp'] ?? 'no', 'page_numbers' => $t['def']['page_numbers'] ?? '']]);
    $inputs = ['patient' => $clean['patient'], 'settings' => $clean['settings']];
    foreach ($t['def']['sections'] as $sec) if (($sec['layout'] ?? '') === 'medtable' && !empty($sec['default_rows'])) $inputs['meds'][$sec['id']] = $sec['default_rows'];
    $db = store_db();
    $db->prepare("INSERT INTO documents (guid, cuser, udate, type_name, typedef_version_id, base_release_id, status, patient_name, inputs) VALUES (?,?,NOW(),?,?,?, 'draft', ?, ?)")
       ->execute([store_uuid(), $kernel->getUserName() ?: 'user', $t['name'], $t['version_id'], $rel['id'], $inputs['patient']['name'],
                  json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $newId = (int)$db->lastInsertId();
    medscribe_audit('doc_created', $newId);
    doc_redirect('/documents/' . $newId);
}

function page_document($params) {
    $doc = doc_load((int)($params['id'] ?? 0));
    if (!$doc) return error_404('Document not found.');
    if ($doc['status'] === 'withdrawn' && !issue_is_doctor()) return error_403('Only the doctor can open a withdrawn document.');
    if (in_array($doc['status'], ['issued', 'withdrawn'], true)) medscribe_audit('doc_opened', (int)$doc['id']);
    $type = doc_typedef((int)$doc['typedef_version_id']);
    if (!$type) return error_404('Type definition not found.');
    // a save refused while a build held the lock: show what was typed, not what is stored (shown once)
    $unsaved = $_SESSION['doc_unsaved'][$doc['id']] ?? null;
    unset($_SESSION['doc_unsaved'][$doc['id']]);
    if (is_array($unsaved) && in_array($doc['status'], DOC_STATUSES, true)) {
        foreach (['features', 'feature_undo'] as $k) if (isset($doc['inputs'][$k])) $unsaved[$k] = $doc['inputs'][$k];
        $doc['inputs'] = $unsaved;
    } else {
        $unsaved = null;
    }
    [, $issues] = render_document($type, $doc['inputs']);
    $build = null;
    if ($doc['last_build_id']) {
        $st = store_db()->prepare('SELECT id, status, pdf_sha256, cdate, log_tail FROM builds WHERE id = ?');
        $st->execute([$doc['last_build_id']]);
        $build = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $locked = !in_array($doc['status'], DOC_STATUSES, true);
    $typePart = Renderer::render("composer_type_part.zetem", doc_type_part_data($type, $doc['inputs'], (int)$doc['id'], $locked, ($_GET['ui'] ?? '') !== 'classic'));
    $p = $doc['inputs']['patient'] ?? []; $set = $doc['inputs']['settings'] ?? [];
    $checks = render_checks($type, $doc['inputs'], $issues);
    return Renderer::render(($_GET['ui'] ?? '') === 'classic' ? "document_edit_classic.zetem" : "document_edit.zetem", [
        'is_gr' => ($type['lang'] ?? 'gr') !== 'en', 'patient_optional' => ($type['has_patient'] ?? true) === 'optional',
        'initials' => mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim((string)($p['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY), 0, 2)))),
        'date_shown' => !empty($set['date']) ? date('d/m/Y', strtotime($set['date'])) : '',
        'release_number' => (int)(array_column(store_db()->query("SELECT id, number FROM base_releases")->fetchAll(PDO::FETCH_ASSOC), 'number', 'id')[$doc['base_release_id']] ?? 0),
        'n_checks' => count($checks),
        // the PDF is out of date when the document was saved after it was built (the page marks it too as soon as the form changes)
        'stale' => $build && $doc['udate'] && strtotime((string)$doc['udate']) > strtotime((string)$build['cdate']) + 1,
        'build_when' => $build ? doc_when((string)$build['cdate']) : '',
        'type_part' => $typePart,
        'doc' => $doc, 'type_label' => $type['label'] ?? $type['name'], 'p' => $doc['inputs']['patient'] ?? [], 's' => $doc['inputs']['settings'] ?? [],
        'releases' => store_db()->query("SELECT id, number FROM base_releases WHERE status = 'released' ORDER BY number DESC")->fetchAll(PDO::FETCH_ASSOC),
        'tokens' => array_merge(['patient', 'age', 'amka', 'clinic', 'date'], array_keys($type['fields'] ?? [])),
        // the chip editor's known names: built-ins and the type's fields, with the labels the chips show
        'known_json' => json_encode(['patient' => t('Patient'), 'age' => t('Age'), 'amka' => 'AMKA', 'clinic' => t('Clinic'), 'date' => t('Date')]
                                    + array_map(fn($f) => (string)($f['label'] ?? ''), $type['fields'] ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'has_recipient' => !empty($type['recipient']), 'has_patient' => ($type['has_patient'] ?? true) !== false,
        'titles' => $type['titles'] ?? [], 'closings' => $type['closings'] ?? [],
        'clinics' => RENDER_CLINICS, 'checks' => $checks,
        'build' => $build, 'locked' => $locked,
        'pdf_url' => ($build && $build['status'] === 'ok') ? '/documents/' . $doc['id'] . '/preview.pdf?b=' . $build['id'] : null,
        'ready' => $doc['status'] === 'review',
        'busy' => $doc['busy_until'] && strtotime($doc['busy_until']) > time(), 'unsaved' => $unsaved !== null,
        'from_zpms' => !empty($doc['inputs']['patient']['guid']),
        'is_doctor' => issue_is_doctor(), 'is_a5' => ($doc['inputs']['settings']['paper'] ?? ($type['paper'] ?? 'a4')) === 'a5',
    ]);
}

function page_document_post($params) {
    global $kernel;
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc) return error_404('Document not found.');
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token. Please try again.'); doc_redirect("/documents/$id"); }
    if (!in_array($doc['status'], DOC_STATUSES, true)) { $kernel->addStatus('error', 'This document is locked.'); doc_redirect("/documents/$id"); }
    $type = doc_typedef((int)$doc['typedef_version_id']);
    if (!$type) return error_404('Type definition not found.');

    $posted = doc_inputs_from_post($type, $_POST);
    $action = (string)($_POST['action'] ?? 'save');
    $relId = (int)($_POST['base_release_id'] ?? 0);

    // Everything below runs under the document's step lock, the same one the issue steps take: while any TeX
    // build of this document is running (preview, final PDF, rebuild) the inputs cannot change under it.
    $r = issue_locked($id, function () use ($id, $type, $posted, $action, $relId, $kernel) {
        $doc = doc_load($id);   // again, under the lock: the status may have moved while we waited
        if (!$doc || !in_array($doc['status'], DOC_STATUSES, true)) return issue_result(false, 'This document is locked.');
        $stored = $doc['inputs'];
        $doc['inputs'] = $posted;
        foreach (['features', 'feature_undo'] as $k) if (isset($stored[$k])) $doc['inputs'][$k] = $stored[$k];   // chips are not form fields
        // case features (D17): a chip's operations are applied, or undone, to the choices just posted
        if ($action === 'feature_add' && isset($type['features'][$_POST['feature'] ?? ''])) $doc['inputs'] = render_feature_add($type, $doc['inputs'], (string)$_POST['feature']);
        if ($action === 'feature_remove') $doc['inputs'] = render_feature_remove($type, $doc['inputs'], (string)($_POST['feature'] ?? ''));
        // the letterhead version (D10): any released base release, only before the code is reserved (the code's QR
        // address was checked against the letterhead chosen at that moment)
        if ($relId && $relId !== (int)$doc['base_release_id']) {
            $ok = store_db()->prepare("SELECT 1 FROM base_releases WHERE id = ? AND status = 'released'"); $ok->execute([$relId]);
            if ($ok->fetchColumn()) {
                if ($doc['status'] === 'code_reserved') {
                    $kernel->addStatus('warning', 'The letterhead version was not changed: the code is reserved (the final PDF is built with the current choice, so change it before requesting the code).');
                } else {
                    store_db()->prepare('UPDATE documents SET base_release_id = ? WHERE id = ?')->execute([$relId, $id]);
                    $doc['base_release_id'] = $relId;   // a preview in this same request uses the new choice
                }
            }
        }
        $status = null;
        // Handing over to the doctor is the secretary's step (PLAN.md §8); the doctor can set it back to draft.
        if (in_array($doc['status'], ['draft', 'review'], true)) {
            if ($action === 'ready') $status = 'review';
            if ($action === 'draft') $status = 'draft';
        }
        doc_save_inputs($id, $doc['inputs'], $status);

        if ($action === 'preview') {
            $b = doc_preview($doc, $type);   // XeLaTeX runs while we still hold the lock
            return issue_result($b['ok'], $b['ok'] ? 'Preview built.' : 'The preview failed: ' . ($b['error'] ?? 'unknown error'));
        }
        return issue_result(true, $action === 'ready' ? 'Marked ready for review.' : 'Saved.');
    });

    if (!$r['ok'] && $r['retry']) {
        // another step holds the lock (a build is running): nothing was saved; give the typed form back
        $_SESSION['doc_unsaved'][$id] = $posted;
        $r['msg'] = 'Not saved: a PDF of this document is being built right now. Your changes are still in the form; save again in a moment.';
    }
    $kernel->addStatus($r['ok'] ? 'status' : 'error', $r['msg']);
    doc_redirect("/documents/$id" . (($_GET['ui'] ?? '') === 'classic' ? '?ui=classic' : ''));
}

/**
 * POST /documents/{id}/sentences (composer plan §6.1, §6.2): the composer's unsaved form in, its rendered sentences and
 * checks out, as JSON. Saves nothing, takes no lock and builds no PDF, so it also answers while a build runs.
 */
function page_document_sentences($params) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit(); };
    $doc = doc_load((int)($params['id'] ?? 0));
    if (!$doc) $fail(404, 'Document not found.');
    if ($doc['status'] === 'withdrawn' && !issue_is_doctor()) $fail(403, 'Only the doctor can open a withdrawn document.');
    if (!csrfClass::verifyRequest()) $fail(403, 'Invalid security token.');
    $type = doc_typedef((int)$doc['typedef_version_id']);
    if (!$type) $fail(404, 'Type definition not found.');
    $inputs = doc_inputs_from_post($type, $_POST);
    foreach (['features', 'feature_undo'] as $k) if (isset($doc['inputs'][$k])) $inputs[$k] = $doc['inputs'][$k];
    [, $issues] = render_document($type, $inputs);
    echo json_encode(['ok' => true, 'blocks' => sentences_for($type, $inputs), 'checks' => render_checks($type, $inputs, $issues)],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function page_document_pdf($params) {
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc || !$doc['last_build_id']) return error_404('No preview yet.');
    if ($doc['status'] === 'withdrawn' && !issue_is_doctor()) return error_403('Only the doctor can open a withdrawn document.');
    $st = store_db()->prepare("SELECT pdf_sha256 FROM builds WHERE id = ? AND status = 'ok'");
    $st->execute([$doc['last_build_id']]);
    $sha = $st->fetchColumn();
    if (!$sha) return error_404('No preview yet.');
    $pdf = store_get($sha);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="preview-' . $id . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit();
}

function page_document_delete($params) {
    global $kernel;
    $id = (int)($params['id'] ?? 0);
    if (!doc_load($id)) return error_404('Document not found.');
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token.'); doc_redirect("/documents/$id"); }
    $r = issue_delete_draft($id, $kernel->getUserName() ?: 'user');
    $kernel->addStatus($r['ok'] ? 'status' : 'error', $r['msg']);
    doc_redirect($r['ok'] ? '/documents' : "/documents/$id");
}

/** The latest PDF of a document: the issued revision, else the final build, else the last preview. */
function doc_current_pdf(array $doc): ?string {
    $db = store_db();
    if (in_array($doc['status'], ['issued', 'withdrawn'], true)) {
        $st = $db->prepare('SELECT pdf_sha256 FROM document_revisions WHERE document_id = ? ORDER BY revision DESC LIMIT 1');
        $st->execute([$doc['id']]);
        if ($sha = $st->fetchColumn()) return store_get($sha);
    }
    foreach (['final_build_id', 'last_build_id'] as $col) {
        if (!$doc[$col]) continue;
        $st = $db->prepare("SELECT pdf_sha256 FROM builds WHERE id = ? AND status = 'ok'");
        $st->execute([$doc[$col]]);
        if ($sha = $st->fetchColumn()) return store_get($sha);
    }
    return null;
}

/** Two A5 pages on one A4 sheet, from the document's latest PDF. */
function page_document_print($params) {
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc) return error_404('Document not found.');
    if ($doc['status'] === 'withdrawn' && !issue_is_doctor()) return error_403('Only the doctor can open a withdrawn document.');
    $type = doc_typedef((int)$doc['typedef_version_id']);
    if (($doc['inputs']['settings']['paper'] ?? ($type['paper'] ?? 'a4')) !== 'a5') return error_404('Only A5 documents can be printed two to a sheet.');
    // Under the document's lock like every TeX run: the PDF it reads can't change half-way, and its build
    // pruning can't race a preview's.
    $out = issue_locked($id, function () use ($id) {
        $doc = doc_load($id);
        $pdf = $doc ? doc_current_pdf($doc) : null;
        if ($pdf === null) return issue_result(false, 'Build a preview first.', false, ['missing' => true]);
        $r = print_two_up($pdf, ($_GET['mode'] ?? 'seq') === 'dup' ? 'dup' : 'seq', ($_GET['cut'] ?? '1') !== '0', $id, $GLOBALS['kernel']->getUserName() ?: 'user');
        issue_prune_builds($id, [(int)($r['build_id'] ?? 0)], ['print']);
        if (!$r['ok']) return issue_result(false, 'The print layout failed: ' . ($r['error'] ?? ''));
        return issue_result(true, '', false, ['pdf' => $r['pdf']]);
    });
    if (!$out['ok']) {
        if (!empty($out['missing'])) return error_404($out['msg']);
        if ($out['retry']) http_response_code(409);
        return error_500($out['msg']);
    }
    $r = $out;
    if (in_array($doc['status'], ['issued', 'withdrawn'], true)) medscribe_audit('file_downloaded', $id, 1);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . ($doc['doc_code'] ?: "doc-$id") . '-2up.pdf"');
    header('Content-Length: ' . strlen($r['pdf']));
    header('Cache-Control: private, no-store');
    echo $r['pdf'];
    exit();
}
