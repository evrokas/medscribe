<?php

/**
 * Pages for issuing (doctor only, PLAN.md D21, §8), history, DocArc links. The logic is in issue.php.
 */

function issue_is_doctor(): bool {
    return in_array('doctor', medscribe_user_roles(), true);
}

function issue_user(): string {
    return $GLOBALS['kernel']->getUserName() ?: 'user';
}

function issue_redirect(string $path): void {
    header('location: ' . rel_url($path));
    exit();
}

function page_issue($params) {
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc) return error_404('Document not found.');
    if (!issue_is_doctor()) return error_403('Only the doctor issues documents.');
    $type = doc_typedef((int)$doc['typedef_version_id']);
    if (!$type) return error_404('Type definition not found.');
    [, $issues] = render_document($type, $doc['inputs']);
    $checks = render_checks($type, $doc['inputs'], $issues);
    $link = issue_link_get('type', $doc['type_name']);
    $clinicLink = issue_link_get('clinic', (string)($doc['inputs']['settings']['clinic'] ?? ''));
    $b = null;
    if ($doc['final_build_id']) {
        $st = store_db()->prepare('SELECT id, pdf_sha256 FROM builds WHERE id = ?');
        $st->execute([$doc['final_build_id']]);
        $b = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return Renderer::render("issue.zetem", [
        'doc' => $doc, 'type_label' => $type['label'] ?? $type['name'], 'checks' => $checks,
        'blocking' => count(array_filter($checks, fn($c) => $c['level'] === 'error')),
        'docarc_on' => docarc_enabled(), 'type_link' => $link, 'clinic_link' => $clinicLink,
        'clinic' => $doc['inputs']['settings']['clinic'] ?? '',
        'final_url' => $b ? '/documents/' . $id . '/final.pdf?b=' . $b['id'] : null,
        'qr_base' => issue_qr_base((int)$doc['base_release_id']),
        'revisions' => issue_revisions($id),
        'busy' => $doc['busy_until'] && strtotime($doc['busy_until']) > time(),
        // the step strip (composer plan §5.4) and why the first step cannot run yet
        'step' => in_array($doc['status'], ['draft', 'review', 'code_reserved'], true) ? 1 : (in_array($doc['status'], ['final_compiled', 'approved', 'pdf_sent'], true) ? 2 : 3),
        'blocked' => array_values(array_filter([
            docarc_enabled() ? null : t('DocArc is not configured (config/docarc_api.php).'),
            $link ? null : t('This document type is not linked to a DocArc type yet.'),
            ($n = count(array_filter($checks, fn($c) => $c['level'] === 'error'))) ? $n . ' ' . ($n === 1 ? t('check fails: fix it in the document first.') : t('checks fail: fix them in the document first.')) : null,
        ])),
    ]);
}

function page_issue_post($params) {
    global $kernel;
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc) return error_404('Document not found.');
    if (!issue_is_doctor()) return error_403('Only the doctor issues documents.');
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token.'); issue_redirect("/documents/$id/issue"); }
    $user = issue_user();
    $action = (string)($_POST['action'] ?? '');
    switch ($action) {
        case 'prepare':  $r = issue_prepare($id, $user); break;
        case 'edit':     $r = issue_edit_again($id); if ($r['ok']) issue_redirect("/documents/$id"); break;
        case 'approve':  $r = issue_approve($id, $user); break;
        case 'send':     $r = issue_send($id, $user); break;
        case 'withdraw': $r = issue_withdraw($id, $user, (string)($_POST['reason'] ?? '')); break;
        case 'delete_withdrawn':
            if (($_POST['confirm'] ?? '') !== 'DELETE') { $r = issue_result(false, 'Type DELETE to confirm.'); break; }
            $r = issue_delete_withdrawn($id, $user);
            if ($r['ok']) { $kernel->addStatus('status', $r['msg']); issue_redirect('/documents'); }
            break;
        case 'copy':
            $r = issue_copy($id, $user);
            if ($r['ok']) { $kernel->addStatus('status', 'Copied. Choose the patient for this new draft.'); issue_redirect('/documents/' . $r['id']); }
            break;
        default: $r = issue_result(false, 'Unknown action.');
    }
    $kernel->addStatus($r['ok'] ? 'status' : 'error', $r['msg']);
    issue_redirect("/documents/$id/issue");
}

/** The final PDF awaiting approval (or the approved/issued one), for the doctor. */
function page_document_final_pdf($params) {
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc || !$doc['final_build_id']) return error_404('No final PDF.');
    if (!issue_is_doctor()) return error_403('Only the doctor can open the final PDF.');
    $st = store_db()->prepare("SELECT pdf_sha256 FROM builds WHERE id = ? AND status = 'ok'");
    $st->execute([$doc['final_build_id']]);
    $sha = $st->fetchColumn();
    if (!$sha) return error_404('No final PDF.');
    return issue_send_file(store_get($sha), 'application/pdf', ($doc['doc_code'] ?: "final-$id") . '.pdf');
}

function issue_send_file(string $bytes, string $type, string $name): void {
    header('Content-Type: ' . $type);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit();
}

function page_document_history($params) {
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc) return error_404('Document not found.');
    if (in_array($doc['status'], ['withdrawn'], true) && !issue_is_doctor()) return error_403('Only the doctor can open a withdrawn document.');
    if (in_array($doc['status'], ['issued', 'withdrawn'], true)) medscribe_audit('doc_opened', $id);   // as the composer: issued documents only
    $revs = issue_revisions($id);
    foreach ($revs as &$r) {
        $m = store_db()->prepare('SELECT manifest FROM builds WHERE id = ?'); $m->execute([$r['build_id']]);
        $man = json_decode((string)$m->fetchColumn(), true) ?: [];
        $r['files'] = array_values(array_filter($man['files'] ?? [], fn($f) => empty($f['generated'])));
        $r['env'] = $man['env']['xelatex'] ?? '';
        $r['epoch'] = $man['source_date_epoch'] ?? null;
        $r['by'] = $r['cuser'];
    }
    unset($r);
    return Renderer::render("history.zetem", ['doc' => $doc, 'revs' => $revs, 'is_doctor' => issue_is_doctor()]);
}

function page_document_history_post($params) {
    global $kernel;
    $id = (int)($params['id'] ?? 0);
    if (!doc_load($id)) return error_404('Document not found.');
    if (!issue_is_doctor()) return error_403('Only the doctor can rebuild.');
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token.'); issue_redirect("/documents/$id/history"); }
    $r = issue_rebuild_check($id, (int)($_POST['revision'] ?? 0), issue_user());
    $kernel->addStatus($r['ok'] ? 'status' : 'error', $r['msg']);
    issue_redirect("/documents/$id/history");
}

/** An approved revision's PDF or .tex, exactly as sent to DocArc. */
function page_document_revision_file($params) {
    $id = (int)($params['id'] ?? 0);
    $doc = doc_load($id);
    if (!$doc) return error_404('Document not found.');
    if ($doc['status'] === 'withdrawn' && !issue_is_doctor()) return error_403('Only the doctor can open a withdrawn document.');
    $st = store_db()->prepare('SELECT * FROM document_revisions WHERE document_id = ? AND revision = ?');
    $st->execute([$id, (int)($params['rev'] ?? 0)]);
    $rev = $st->fetch(PDO::FETCH_ASSOC);
    $what = $params['what'] ?? '';
    if (!$rev || !in_array($what, ['pdf', 'tex'], true)) return error_404('Not found.');
    medscribe_audit('file_downloaded', $id, (int)$rev['revision']);
    $code = $doc['doc_code'] ?: "doc-$id";
    return $what === 'pdf'
        ? issue_send_file(store_get($rev['pdf_sha256']), 'application/pdf', $code . '.pdf')
        : issue_send_file(store_get($rev['tex_sha256']), 'text/plain; charset=utf-8', $code . '.tex');
}

/** Types > DocArc links (D20): which DocArc type each MedScribe type uses, and which DocArc clinic each \Clinic key. */
function page_docarc_links($params) {
    $types = []; foreach (store_db()->query("SELECT name FROM sources WHERE kind = 'typedef' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $n) $types[] = substr($n, 8);
    $dtypes = docarc_enabled() ? docarc_types() : null;
    $dclinics = docarc_enabled() ? docarc_clinics() : null;
    $rows = [];
    foreach ($types as $t) $rows[] = ['kind' => 'type', 'key' => $t, 'link' => issue_link_get('type', $t)];
    foreach (RENDER_CLINICS as $c) $rows[] = ['kind' => 'clinic', 'key' => $c, 'link' => issue_link_get('clinic', $c)];
    return Renderer::render("docarc_links.zetem", ['rows' => $rows, 'dtypes' => $dtypes ?? [], 'dclinics' => $dclinics ?? [],
        'enabled' => docarc_enabled(), 'reachable' => $dtypes !== null && $dclinics !== null]);
}

function page_docarc_links_post($params) {
    global $kernel;
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token.'); issue_redirect('/types/links'); }
    $dtypes = docarc_types(); $dclinics = docarc_clinics();
    if ($dtypes === null || $dclinics === null) { $kernel->addStatus('error', 'DocArc is not reachable; the links were not changed.'); issue_redirect('/types/links'); }
    $user = issue_user();
    foreach (($_POST['type'] ?? []) as $key => $val) {
        if (!is_string($key) || !preg_match('/^[a-z0-9-]{1,64}$/', $key)) continue;
        $val = (int)$val;
        $cur = issue_link_get('type', $key);
        if ($val === 0) { if ($cur) issue_link_clear('type', $key); continue; }
        if ($cur && (int)$cur['docarc_id'] === $val) continue;   // unchanged: keep who linked it and when
        foreach ($dtypes as $t) if ($t['id'] === $val) issue_link_set('type', $key, $val, $t['label'], $t['code'], $user);
    }
    foreach (($_POST['clinic'] ?? []) as $key => $val) {
        if (!in_array($key, RENDER_CLINICS, true)) continue;
        $val = (int)$val;
        $cur = issue_link_get('clinic', $key);
        if ($val === 0) { if ($cur) issue_link_clear('clinic', $key); continue; }
        if ($cur && (int)$cur['docarc_id'] === $val) continue;
        foreach ($dclinics as $c) if ($c['id'] === $val) issue_link_set('clinic', $key, $val, $c['name'], null, $user);
    }
    $kernel->addStatus('status', 'Links saved.');
    issue_redirect('/types/links');
}
