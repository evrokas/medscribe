<?php

/**
 * Issuing, withdrawing, deleting and copying documents (PLAN.md §7.4, §7.5). Plain functions over the
 * database and the DocArc client; no web request needed, so bin/check_m3.php drives them directly.
 *
 *   draft/review ─▶ code_reserved ─▶ final_compiled ─▶ approved ─▶ pdf_sent ─▶ issued ─▶ withdrawn
 *                     ▲   └─ edit again ◀──┘
 *
 * Every transition is a compare-and-swap on `status` (issue_cas), and a step that runs for seconds
 * takes `busy_until` first, so a double click or a second tab cannot run it twice. Every TeX run on a
 * document (final build, preview, rebuild) holds that lock, and the composer's save takes it too, so
 * nobody can change the inputs while a PDF is being built from them (documents.php).
 * A failed step leaves the status where it was, so the same call resumes it; DocArc calls are idempotent.
 * Every function returns ['ok' => bool, 'msg' => string, 'retry' => bool (worth trying again)].
 */

const ISSUE_EDITABLE = ['draft', 'review', 'code_reserved'];
const ISSUE_BUSY_SECONDS = 150;

/** How long a step lock lasts: longer than the slowest possible TeX build (every pass hitting its timeout). */
function issue_busy_seconds(): int {
    return max(ISSUE_BUSY_SECONDS, (MEDSCRIBE_TEX_TIMEOUT + 10) * MEDSCRIBE_TEX_MAX_PASSES + 60);
}

function issue_result(bool $ok, string $msg, bool $retry = false, array $extra = []): array {
    return ['ok' => $ok, 'msg' => $msg, 'retry' => $retry] + $extra;
}

/* ---------- links to DocArc's types and clinics (D20) ---------- */

function issue_link_get(string $kind, string $key): ?array {
    $st = store_db()->prepare('SELECT * FROM docarc_links WHERE kind = ? AND link_key = ?');
    $st->execute([$kind, $key]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** One link per (kind, key): replaces the previous one. */
function issue_link_set(string $kind, string $key, int $docarcId, ?string $label, ?string $code, string $user): void {
    $db = store_db();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM docarc_links WHERE kind = ? AND link_key = ?')->execute([$kind, $key]);
        $db->prepare('INSERT INTO docarc_links (guid, cuser, kind, link_key, docarc_id, label_seen, code_seen) VALUES (?,?,?,?,?,?,?)')
           ->execute([store_uuid(), $user, $kind, $key, $docarcId, $label, $code]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

function issue_link_clear(string $kind, string $key): void {
    store_db()->prepare('DELETE FROM docarc_links WHERE kind = ? AND link_key = ?')->execute([$kind, $key]);
}

/* ---------- state and locking ---------- */

/** status IN $from -> $to, optionally setting more columns. True when this call made the change. */
function issue_cas(int $id, array $from, string $to, array $set = []): bool {
    $cols = ['status = ?', 'udate = NOW()']; $args = [$to];
    foreach ($set as $k => $v) {
        if ($v === 'NOW()') { $cols[] = "$k = NOW()"; continue; }
        $cols[] = "$k = ?"; $args[] = $v;
    }
    $args[] = $id;
    $in = implode(',', array_fill(0, count($from), '?'));
    foreach ($from as $f) $args[] = $f;
    $st = store_db()->prepare('UPDATE documents SET ' . implode(', ', $cols) . " WHERE id = ? AND status IN ($in)");
    $st->execute($args);
    return $st->rowCount() === 1;
}

/** Take the step lock; false when another step is running (double click, second tab). */
function issue_begin(int $id): bool {
    $st = store_db()->prepare('UPDATE documents SET busy_until = DATE_ADD(NOW(), INTERVAL ' . issue_busy_seconds() . ' SECOND) WHERE id = ? AND (busy_until IS NULL OR busy_until < NOW())');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

function issue_end(int $id): void {
    store_db()->prepare('UPDATE documents SET busy_until = NULL WHERE id = ?')->execute([$id]);
}

/** Run $fn under the step lock. */
function issue_locked(int $id, callable $fn): array {
    if (!issue_begin($id)) return issue_result(false, 'Another step is running on this document. Try again in a moment.', true);
    try { return $fn(); } finally { issue_end($id); }
}

/* ---------- helpers ---------- */

/** \QRBaseURL from the document's base release (evr-gr.tex), e.g. https://doc.erns.eu/qr/ ; null if not found. */
function issue_qr_base(int $baseReleaseId): ?string {
    foreach (base_release_files($baseReleaseId) as $f) {
        if ($f['name'] === 'base/evr-gr.tex' && preg_match('/\\\\(?:providecommand|newcommand)\\\\QRBaseURL\{([^}]*)\}/', store_get($f['sha256']), $m)) return $m[1];
    }
    return null;
}

/**
 * [DocArc verification type, value] or null when the type switches it off (D22). The patient's AMKA when it is
 * valid, else a 6-digit code made ONCE per document and kept in `verify_code`: a retry (lost answer, DocArc
 * returning the existing record by idempotency key) and the final send must use the same code the patient gets.
 */
function issue_verification(array $doc, array $type): ?array {
    if (($type['verification'] ?? 'on') === 'off') return null;
    $amka = (string)($doc['inputs']['patient']['amka'] ?? '');
    if (preg_match('/^\d{11}$/', $amka)) return ['type' => 'AMKA', 'value' => $amka];
    $kept = (string)($doc['verify_code'] ?? '');
    return ['type' => 'OTHER', 'value' => preg_match('/^\d{6}$/', $kept) ? $kept : sprintf('%06d', random_int(0, 999999))];
}

/** Keep the verification value on the document BEFORE DocArc is told about it (so a retry reuses it). */
function issue_keep_verification(int $id, ?array $verify): void {
    store_db()->prepare('UPDATE documents SET verify_code = ? WHERE id = ?')->execute([$verify ? $verify['value'] : null, $id]);
}

/** DocArc's description of the record: the document's title, else the type's title, else its label (same at request and at send). */
function issue_description(array $doc, ?array $type): string {
    $t = trim((string)($doc['inputs']['settings']['title'] ?? ''));
    return $t !== '' ? $t : (string)($type['title'] ?? $type['label'] ?? '');
}

function issue_error_checks(array $type, array $inputs): array {
    [, $issues] = render_document($type, $inputs);
    return array_values(array_map(fn($c) => $c['msg'], array_filter(render_checks($type, $inputs, $issues), fn($c) => $c['level'] === 'error')));
}

/** Queue a DocArc record for deletion that could not be deleted right now (retried by bin/retry_cleanups.php). */
function issue_cleanup_enqueue(string $code, string $mode, string $error, string $user): void {
    store_db()->prepare('INSERT INTO docarc_cleanup (guid, cuser, doc_code, mode, attempts, last_error) VALUES (?,?,?,?,1,?)')
        ->execute([store_uuid(), $user, $code, $mode, mb_substr($error, 0, 255)]);
    error_log("[medscribe] DocArc record $code could not be deleted ($error); queued for retry");
}

/** Work off the queue; returns [done, still failing]. */
function issue_cleanup_run(?array $cfg = null): array {
    $db = store_db();
    $done = 0; $failed = 0;
    foreach ($db->query('SELECT * FROM docarc_cleanup WHERE done IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $r = docarc_delete_record($row['doc_code'], $row['mode'], $cfg);
        // "has a document" (409) means the record was issued after all: never delete it, drop the job
        if ($r['ok'] || $r['status'] === 409) {
            $db->prepare('UPDATE docarc_cleanup SET done = NOW(), last_error = ? WHERE id = ?')->execute([$r['ok'] ? null : 'refused: ' . $r['error'], $row['id']]);
            $done++;
        } else {
            $db->prepare('UPDATE docarc_cleanup SET attempts = attempts + 1, last_error = ? WHERE id = ?')->execute([mb_substr((string)$r['error'], 0, 255), $row['id']]);
            $failed++;
        }
    }
    return [$done, $failed];
}

/* ---------- step 1: request the code ---------- */

function issue_request_code(int $id, string $user, ?array $cfg = null): array {
    return issue_locked($id, function () use ($id, $user, $cfg) {
        $doc = doc_load($id);
        if (!$doc) return issue_result(false, 'Document not found.');
        if ($doc['status'] === 'code_reserved') return issue_result(true, 'The code is already reserved: ' . $doc['doc_code']);
        if (!in_array($doc['status'], ['draft', 'review'], true)) return issue_result(false, 'A code can only be requested for a draft.');
        $type = doc_typedef((int)$doc['typedef_version_id']);
        if (!$type) return issue_result(false, 'Type definition not found.');

        $errors = issue_error_checks($type, $doc['inputs']);
        if ($errors) return issue_result(false, 'Fix these first: ' . implode('; ', $errors));

        $link = issue_link_get('type', $doc['type_name']);
        if (!$link) return issue_result(false, 'This document type is not linked to a DocArc document type (Document types > DocArc links).');
        $types = docarc_types($cfg);
        if ($types === null) return issue_result(false, 'DocArc is not reachable. Nothing was changed.', true);
        $dt = null;
        foreach ($types as $t) if ($t['id'] === (int)$link['docarc_id']) $dt = $t;
        if (!$dt) return issue_result(false, 'The linked DocArc document type no longer exists. Link the type again.');

        $clinicLink = issue_link_get('clinic', (string)($doc['inputs']['settings']['clinic'] ?? ''));
        $verify = issue_verification($doc, $type);
        issue_keep_verification($id, $verify);
        $s = $doc['inputs']['settings'];
        $payload = [
            'idempotency_key' => 'msdoc-' . $doc['guid'] . '-t' . $dt['id'],
            'document_type_id' => $dt['id'],
            'clinic_id' => $clinicLink ? (int)$clinicLink['docarc_id'] : null,
            'description' => issue_description($doc, $type),
            'patient_name' => (string)($doc['inputs']['patient']['name'] ?? ''),
            'record_date' => (string)$s['date'],
            'requesting_user' => $user,
            'verification' => $verify,
        ];
        [$r, $rec] = docarc_create_record($payload, $cfg);
        if (!$r['ok']) return issue_result(false, 'DocArc did not create the record: ' . $r['error'] . ($r['status'] === 0 ? ' (no answer; safe to try again, no second code is created)' : ''), $r['status'] === 0 || $r['status'] >= 500);
        if (!$rec || $rec['document_type_id'] !== $dt['id']) return issue_result(false, 'DocArc answered with something unexpected.');

        // the printed QR must resolve: \QRBaseURL + code has to be the URL DocArc says
        $base = issue_qr_base((int)$doc['base_release_id']);
        if ($base !== null && $rec['url'] !== $base . $rec['token']) {
            issue_cleanup_enqueue($rec['token'], 'empty', 'url mismatch', $user);
            return issue_result(false, "DocArc's address for the code (" . $rec['url'] . ") is not the address printed in the QR ($base). Check DocArc's QR base URL setting. The empty record was queued for deletion.");
        }
        if (!issue_cas($id, ['draft', 'review'], 'code_reserved', ['doc_code' => $rec['token'], 'docarc_url' => $rec['url'], 'docarc_type_id' => $dt['id']])) {
            return issue_result(false, 'The document changed meanwhile. Reload it.');
        }
        medscribe_audit('code_requested', $id, null, $user);
        return issue_result(true, 'Code reserved: ' . $rec['token']);
    });
}

/* ---------- step 2: compile the final PDF ---------- */

function issue_compile_final(int $id, string $user): array {
    return issue_locked($id, function () use ($id, $user) {
        $doc = doc_load($id);
        if (!$doc || $doc['status'] !== 'code_reserved') return issue_result(false, 'The final PDF is built from a document with a reserved code.');
        $type = doc_typedef((int)$doc['typedef_version_id']);
        if (!$type) return issue_result(false, 'Type definition not found.');
        $errors = issue_error_checks($type, $doc['inputs']);
        if ($errors) return issue_result(false, 'Fix these first: ' . implode('; ', $errors));
        $inputs = $doc['inputs'];
        $inputs['settings']['code'] = $doc['doc_code'];
        [$tex] = render_document($type, $inputs);
        $r = compile_job(base_release_files((int)$doc['base_release_id']), ['document.tex' => $tex], 'document.tex', time(), 'issue', $user, $id);
        if (!$r['ok']) return issue_result(false, 'The final PDF failed to build: ' . ($r['error'] ?? '') . ' ' . substr($r['log_tail'] ?? '', -300), false);
        store_put($r['pdf']);
        if (!issue_cas($id, ['code_reserved'], 'final_compiled', ['final_build_id' => $r['build_id']])) return issue_result(false, 'The document changed meanwhile.');
        issue_prune_builds($id, [(int)$r['build_id']], ['preview', 'issue']);
        return issue_result(true, 'The final PDF is ready for approval.');
    });
}

/** Back from the final PDF to editing (the code stays reserved). */
function issue_edit_again(int $id): array {
    if (!issue_cas($id, ['final_compiled'], 'code_reserved', ['final_build_id' => null])) return issue_result(false, 'Only a final PDF that is not approved yet can go back to editing.');
    return issue_result(true, 'You can edit again; the code stays reserved.');
}

/* ---------- step 3 and 4: approve (freeze), then send ---------- */

function issue_approve(int $id, string $user, ?array $cfg = null): array {
    $doc = doc_load($id);
    if (!$doc || $doc['status'] !== 'final_compiled') return issue_result(false, 'Only a final PDF awaiting approval can be approved.');
    $b = store_db()->prepare("SELECT pdf_sha256, tex_sha256 FROM builds WHERE id = ? AND status = 'ok'");
    $b->execute([$doc['final_build_id']]);
    $build = $b->fetch(PDO::FETCH_ASSOC);
    if (!$build) return issue_result(false, 'The final build is missing. Build it again.');
    try { store_get($build['pdf_sha256']); store_get($build['tex_sha256']); } catch (Throwable $e) { return issue_result(false, 'The stored final PDF is damaged: ' . $e->getMessage()); }
    if (!issue_cas($id, ['final_compiled'], 'approved', ['approved_by' => $user, 'approved_at' => 'NOW()'])) return issue_result(false, 'The document changed meanwhile.');
    medscribe_audit('approved', $id, null, $user);
    return issue_send($id, $user, $cfg);
}

/** Send the approved bytes to DocArc: details, the PDF, then the .tex. Resumes where it stopped. */
function issue_send(int $id, string $user, ?array $cfg = null): array {
    return issue_locked($id, function () use ($id, $user, $cfg) {
        $doc = doc_load($id);
        if (!$doc || !in_array($doc['status'], ['approved', 'pdf_sent'], true)) return issue_result(false, 'Nothing to send.');
        $b = store_db()->prepare('SELECT * FROM builds WHERE id = ?');
        $b->execute([$doc['final_build_id']]);
        $build = $b->fetch(PDO::FETCH_ASSOC);
        if (!$build || $build['status'] !== 'ok') return issue_result(false, 'The approved build is missing. Nothing was sent.');
        try {
            $pdf = store_get($build['pdf_sha256']);      // the approved bytes, never a recompile
            $tex = store_get($build['tex_sha256']);
        } catch (Throwable $e) { return issue_result(false, 'The stored final PDF is damaged: ' . $e->getMessage() . '. Nothing was sent.'); }
        $code = $doc['doc_code'];

        if ($doc['status'] === 'approved') {
            $type = doc_typedef((int)$doc['typedef_version_id']);
            if (!$type) return issue_result(false, 'Type definition not found. Nothing was sent.');
            $s = $doc['inputs']['settings'];
            $clinicLink = issue_link_get('clinic', (string)($s['clinic'] ?? ''));
            // the record's details follow the document as approved, the verification code too (the AMKA may have been
            // corrected after the code was reserved; an OTHER code stays the one kept on the document)
            $verify = issue_verification($doc, $type);
            issue_keep_verification($id, $verify);
            $p = docarc_patch_record($code, ['description' => issue_description($doc, $type), 'patient_name' => (string)($doc['inputs']['patient']['name'] ?? ''),
                                              'record_date' => (string)$s['date'], 'clinic_id' => $clinicLink ? (int)$clinicLink['docarc_id'] : null,
                                              'verification' => $verify], $cfg);
            if (!$p['ok']) return issue_result(false, 'DocArc did not accept the record details: ' . $p['error'] . '. The document is approved; send again.', $p['status'] === 0 || $p['status'] >= 500);
            $u = docarc_put_document($code, $pdf, $code . '.pdf', $cfg);
            if (!$u['ok']) return issue_result(false, 'The PDF was not sent: ' . $u['error'] . '. The document is approved; send again (the same bytes are sent).', $u['status'] === 0 || $u['status'] >= 500);
            [, $rec] = docarc_get_record($code, $cfg);
            if (!$rec || !$rec['has_document'] || $rec['document_sha256'] !== hash('sha256', $pdf)) return issue_result(false, 'DocArc stored a different PDF than the one approved. Nothing was marked as issued.', true);
            issue_cas($id, ['approved'], 'pdf_sent');
        }

        $t = docarc_put_supplementary($code, $code . '.tex', $tex, 'LaTeX source', $cfg);
        if (!$t['ok']) return issue_result(false, 'The .tex source was not sent: ' . $t['error'] . '. The PDF is already in DocArc; send again.', $t['status'] === 0 || $t['status'] >= 500);
        [, $rec] = docarc_get_record($code, $cfg);
        $hasTex = $rec && array_filter($rec['supplementary'], fn($f) => $f['filename'] === $code . '.tex' && $f['sha256'] === hash('sha256', $tex));
        if (!$hasTex) return issue_result(false, 'DocArc does not show the .tex source. Send again.', true);

        $db = store_db();
        $db->beginTransaction();
        try {
            if (!issue_cas($id, ['pdf_sent'], 'issued', ['issued_at' => 'NOW()'])) { $db->rollBack(); return issue_result(false, 'The document changed meanwhile.'); }
            $db->prepare("INSERT INTO document_revisions (guid, cuser, document_id, revision, status, build_id, tex_sha256, pdf_sha256, inputs, reason) VALUES (?,?,?,1,'current',?,?,?,?,NULL)")
               ->execute([store_uuid(), $user, $id, $build['id'], $build['tex_sha256'], $build['pdf_sha256'], json_encode($doc['inputs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        medscribe_audit('issued', $id, 1, $user);
        pool_feed_document($id);   // D8: what the doctor wrote or edited joins the text pool, automatically
        return issue_result(true, 'Issued: ' . $code);
    });
}

/** The whole preparation in one go: request the code (if needed) and build the final PDF. */
function issue_prepare(int $id, string $user, ?array $cfg = null): array {
    $doc = doc_load($id);
    if (!$doc) return issue_result(false, 'Document not found.');
    if ($doc['status'] === 'code_reserved') {
        // a changed DocArc type link means the reserved record has the wrong type: it is part of the code
        $link = issue_link_get('type', $doc['type_name']);
        if ($link && (int)$link['docarc_id'] !== (int)$doc['docarc_type_id']) {
            $d = docarc_delete_record($doc['doc_code'], 'empty', $cfg);
            if (!$d['ok']) issue_cleanup_enqueue($doc['doc_code'], 'empty', (string)$d['error'], $user);
            store_db()->prepare("UPDATE documents SET status = 'draft', doc_code = NULL, docarc_url = NULL, docarc_type_id = NULL, verify_code = NULL, udate = NOW() WHERE id = ? AND status = 'code_reserved'")->execute([$id]);
        }
    }
    $doc = doc_load($id);
    if (in_array($doc['status'], ['draft', 'review'], true)) {
        $r = issue_request_code($id, $user, $cfg);
        if (!$r['ok']) return $r;
    }
    return issue_compile_final($id, $user);
}

/* ---------- withdraw, delete, copy ---------- */

function issue_withdraw(int $id, string $user, string $reason, ?array $cfg = null): array {
    return issue_locked($id, function () use ($id, $user, $reason, $cfg) {
        $reason = trim($reason);
        if ($reason === '') return issue_result(false, 'A reason is required.');
        $doc = doc_load($id);
        if (!$doc || $doc['status'] !== 'issued') return issue_result(false, 'Only an issued document can be withdrawn.');
        $d = docarc_delete_record($doc['doc_code'], 'all', $cfg);
        if (!$d['ok']) return issue_result(false, 'DocArc did not delete the record: ' . $d['error'] . '. The document is still issued.', $d['status'] === 0 || $d['status'] >= 500);
        if (!issue_cas($id, ['issued'], 'withdrawn', ['withdraw_reason' => mb_substr($reason, 0, 2000), 'withdrawn_at' => 'NOW()', 'withdrawn_by' => $user])) return issue_result(false, 'The document changed meanwhile.');
        medscribe_audit('withdrawn', $id, null, $user);
        pool_remove_document($id);   // its text leaves the pool unless another document used it too
        return issue_result(true, 'Withdrawn. DocArc deleted the record; the printed QR now leads nowhere.');
    });
}

/** Remove a store object when nothing references it any more (the store is shared by hash). */
function issue_gc_object(string $sha): void {
    $db = store_db();
    $refs = [
        ['SELECT COUNT(*) FROM source_versions WHERE sha256 = ?', [$sha]],
        ['SELECT COUNT(*) FROM builds WHERE pdf_sha256 = ? OR tex_sha256 = ?', [$sha, $sha]],
        ['SELECT COUNT(*) FROM builds WHERE manifest LIKE ?', ['%' . $sha . '%']],
        ['SELECT COUNT(*) FROM document_revisions WHERE pdf_sha256 = ? OR tex_sha256 = ?', [$sha, $sha]],
    ];
    foreach ($refs as [$sql, $args]) { $st = $db->prepare($sql); $st->execute($args); if ((int)$st->fetchColumn() > 0) return; }
    $p = store_object_path($sha);
    if (is_file($p)) @unlink($p);
}

/** Delete a document's builds (previews, finals, rebuilds) except $keep, then the objects nothing else uses. */
function issue_prune_builds(int $docId, array $keep, array $purposes): void {
    $db = store_db();
    $in = implode(',', array_fill(0, count($purposes), '?'));
    $st = $db->prepare("SELECT id, tex_sha256, pdf_sha256, manifest FROM builds WHERE document_id = ? AND purpose IN ($in)");
    $st->execute(array_merge([$docId], $purposes));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if (in_array((int)$b['id'], $keep, true)) continue;
        $revs = $db->prepare('SELECT COUNT(*) FROM document_revisions WHERE build_id = ?'); $revs->execute([$b['id']]);
        if ((int)$revs->fetchColumn() > 0) continue;
        $gen = [];
        foreach ((json_decode((string)$b['manifest'], true)['files'] ?? []) as $f) if (!empty($f['generated'])) $gen[] = $f['sha256'];
        $db->prepare('DELETE FROM builds WHERE id = ?')->execute([$b['id']]);
        foreach (array_filter(array_merge([$b['pdf_sha256'], $b['tex_sha256']], $gen)) as $sha) issue_gc_object($sha);
    }
}

/** Delete a draft (also one with a reserved code or an unapproved final PDF): the empty DocArc record goes too. */
function issue_delete_draft(int $id, string $user, ?array $cfg = null): array {
    return issue_locked($id, function () use ($id, $user, $cfg) {
        $doc = doc_load($id);
        if (!$doc) return issue_result(false, 'Document not found.');
        if (!in_array($doc['status'], ['draft', 'review', 'code_reserved', 'final_compiled'], true)) return issue_result(false, 'Only a draft can be deleted.');
        if ($doc['doc_code']) {
            $d = docarc_delete_record($doc['doc_code'], 'empty', $cfg);
            if (!$d['ok']) issue_cleanup_enqueue($doc['doc_code'], 'empty', (string)$d['error'], $user);   // retried later, never ignored
        }
        issue_prune_builds($id, [], ['preview', 'issue', 'test']);
        store_db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        medscribe_audit('doc_deleted', $id, null, $user);
        return issue_result(true, 'Draft deleted.');
    });
}

/** The doctor deletes a withdrawn document from MedScribe too: inputs, .tex, PDF and unshared store objects. */
function issue_delete_withdrawn(int $id, string $user): array {
    return issue_locked($id, function () use ($id, $user) {
        $doc = doc_load($id);
        if (!$doc || $doc['status'] !== 'withdrawn') return issue_result(false, 'Only a withdrawn document can be deleted this way.');
        $db = store_db();
        pool_remove_document($id);
        $db->prepare('DELETE FROM document_revisions WHERE document_id = ?')->execute([$id]);
        issue_prune_builds($id, [], ['preview', 'issue', 'test', 'rebuild']);
        $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        medscribe_audit('withdrawn_deleted', $id, null, $user);   // the event only: no patient details, no reason
        return issue_result(true, 'The withdrawn document was deleted.');
    });
}

/**
 * "Copy as new": the inputs without the patient and without a code, as a new draft. Like any new document it uses the
 * latest released version of the type and the current base release (choices for blocks that no longer exist are
 * simply ignored by the engine). Returns the new id in 'id'.
 */
function issue_copy(int $id, string $user): array {
    $doc = doc_load($id);
    if (!$doc) return issue_result(false, 'Document not found.');
    $latest = source_released_by_name('typedef/' . $doc['type_name']);
    $versionId = $latest ? (int)$latest['version_id'] : (int)$doc['typedef_version_id'];
    $in = $doc['inputs'];
    $in['patient'] = ['name' => '', 'age' => '', 'amka' => '', 'gender' => '', 'guid' => '', 'dob' => ''];
    unset($in['settings']['code'], $in['settings']['revision']);
    $in['settings']['date'] = date('Y-m-d');
    $rel = base_release_current();
    $db = store_db();
    $db->prepare("INSERT INTO documents (guid, cuser, udate, type_name, typedef_version_id, base_release_id, status, patient_name, inputs, copied_from) VALUES (?,?,NOW(),?,?,?, 'draft', '', ?, ?)")
       ->execute([store_uuid(), $user, $doc['type_name'], $versionId, $rel ? $rel['id'] : $doc['base_release_id'], json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    $new = (int)$db->lastInsertId();
    medscribe_audit('doc_copied', $new, null, $user);
    return issue_result(true, 'Copied.', false, ['id' => $new]);
}

/* ---------- history and rebuild (G5) ---------- */

function issue_revisions(int $docId): array {
    $st = store_db()->prepare('SELECT * FROM document_revisions WHERE document_id = ? ORDER BY revision DESC');
    $st->execute([$docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Rebuild a revision from its manifest; the PDF must have the same hash as the one that was issued. */
function issue_rebuild_check(int $docId, int $revision, string $user = 'system'): array {
    return issue_locked($docId, fn() => issue_rebuild_check_locked($docId, $revision, $user));
}

function issue_rebuild_check_locked(int $docId, int $revision, string $user): array {
    $st = store_db()->prepare('SELECT r.*, b.manifest FROM document_revisions r JOIN builds b ON b.id = r.build_id WHERE r.document_id = ? AND r.revision = ?');
    $st->execute([$docId, $revision]);
    $rev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rev) return issue_result(false, 'Revision not found.');
    $manifest = json_decode($rev['manifest'], true);
    try { $r = compile_from_manifest($manifest, 'rebuild', $user, $docId); }
    catch (Throwable $e) { return issue_result(false, 'The rebuild stopped: ' . $e->getMessage()); }
    if (!$r['ok']) return issue_result(false, 'The rebuild failed: ' . ($r['error'] ?? ''));
    $same = $r['pdf_sha256'] === $rev['pdf_sha256'];
    issue_prune_builds($docId, [(int)$r['build_id']], ['rebuild']);
    return issue_result($same, $same ? 'The rebuilt PDF is identical to the issued one (' . substr($rev['pdf_sha256'], 0, 12) . ').' : 'The rebuilt PDF differs from the issued one.');
}
