<?php

/**
 * The content pool (PLAN.md §12, D8, D16): every issued document feeds the text the doctor wrote or edited
 * into a per-slot pool, with patient details turned back into @tokens, so it can be suggested next time and
 * take the new document's values (swap on insertion). Suggestions and the library screen read it.
 */

/** Replace this document's own patient values with @tokens, longest value first, whole words only. */
function pool_tokenize(string $text, array $values, array $fieldTypes = []): string {
    $map = [];   // literal text => token
    foreach ($values as $token => $v) {
        $v = (string)$v;
        if ($v === '') continue;
        if (($fieldTypes[$token] ?? '') === 'date' || $token === 'date') {
            // stored as an ISO date; text shows d/m/Y or d-m-Y
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            if ($d && $d->format('Y-m-d') === $v) { $map[$d->format('d/m/Y')] = $token; $map[$d->format('d-m-Y')] = $token; }
            else $map[$v] = $token;
            continue;
        }
        if (mb_strlen($v) >= 3) $map[$v] = $token;
    }
    uksort($map, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($map as $literal => $token) {
        $text = preg_replace('/(?<![\p{L}\p{N}_@])' . preg_quote((string)$literal, '/') . '(?![\p{L}\p{N}_])/u', '@' . $token, $text);
    }
    return $text;
}

/** Accent-free uppercase (Greek and Latin), to compare names whatever their accents and case. */
function pool_fold(string $s): string {
    $d = class_exists('Normalizer') ? Normalizer::normalize($s, Normalizer::FORM_D) : $s;
    return mb_strtoupper(preg_replace('/\p{Mn}+/u', '', (string)($d === false ? $s : $d)));
}

/**
 * Does the text still name the patient after tokenizing (PLAN.md §7.3)? Each part of the name (4+ letters) is
 * looked for as a word stem, so the surname or first name alone and other grammatical forms are caught too
 * ("Παπαδόπουλος" -> "του Παπαδόπουλου", "ΠΑΠΑΔΟΠΟΥΛΟ"). Used by the archive seeder only (its letters are not reviewed
 * one by one); text from an issued document is pooled without it, since the doctor reviews every letter before issuing.
 */
function pool_name_left(string $text, string $name): bool {
    $t = pool_fold($text);
    foreach (preg_split('/[^\p{L}]+/u', pool_fold($name), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen($w) < 4) continue;
        $stem = mb_substr($w, 0, max(4, mb_strlen($w) - 2));
        if (preg_match('/(?<!\p{L})' . preg_quote($stem, '/') . '\p{L}{0,4}(?!\p{L})/u', $t)) return true;
    }
    return false;
}

/** The pool slot of seeded archive text under a heading (D15); pool_suggest() offers it to sections with that heading. */
function pool_archive_slot(string $heading): string {
    $h = trim(preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtolower($heading)), '_');
    return $h === '' ? 'archive.body' : 'archive.' . $h;
}

/** The texts a document authored: [slot => [text, ...]] (local edits of blocks, and free text added to sections). */
function pool_authored(array $type, array $inputs): array {
    $out = [];
    foreach (render_expand_sections($type) as $sec) {
        $slot = (string)($sec['slot'] ?? $sec['id']);
        $isList = in_array($sec['layout'] ?? 'paragraphs', ['itemize', 'enumerate', 'urgentbox'], true);
        if (!render_section_on($sec, $inputs)) continue;
        foreach (($sec['blocks'] ?? []) as $b) {
            $t = $inputs['blocks'][$b['id']]['text'] ?? null;
            if (is_string($t) && trim($t) !== '' && render_block_on($sec, $b, $inputs) && trim($t) !== trim($b['text'])) $out[$slot][] = trim($t);
        }
        foreach (($inputs['extra'][$sec['id']] ?? []) as $x) {
            $parts = $isList ? preg_split('/\n+/', trim((string)($x['text'] ?? ''))) : preg_split('/\n\s*\n/', trim((string)($x['text'] ?? '')));
            foreach ($parts as $p) if (trim($p) !== '') $out[$slot][] = trim($p);
        }
    }
    return $out;
}

/**
 * Store one text in a slot and count one more use by $docId (a document; negative = one seeded archive letter, D15).
 * Returns true when that document (or letter) had not used this text before, so feeding or seeding twice counts once.
 */
function pool_store_text(string $slot, string $text, int $docId, string $type, string $user): bool {
    $db = store_db();
    $hash = hash('sha256', $slot . "\0" . mb_strtolower(preg_replace('/\s+/u', ' ', $text)));
    $st = $db->prepare('SELECT id FROM pool_blocks WHERE slot = ? AND text_hash = ?');
    $st->execute([$slot, $hash]);
    $id = $st->fetchColumn();
    if (!$id) {
        $db->prepare("INSERT INTO pool_blocks (guid, cuser, slot, text, text_hash, tier, hidden, uses, first_type) VALUES (?,?,?,?,?, 'history', 0, 0, ?)")
           ->execute([store_uuid(), mb_substr($user, 0, 32), $slot, $text, $hash, $type]);
        $id = $db->lastInsertId();
    }
    if ($docId !== 0) {
        $has = $db->prepare('SELECT 1 FROM pool_uses WHERE pool_id = ? AND document_id = ?');
        $has->execute([$id, $docId]);
        if ($has->fetchColumn()) return false;
    }
    $db->prepare('INSERT INTO pool_uses (pool_id, document_id) VALUES (?, ?)')->execute([$id, $docId]);
    $db->prepare('UPDATE pool_blocks SET uses = uses + 1, last_used = NOW() WHERE id = ?')->execute([$id]);
    return true;
}

/** Feed an issued document into the pool. Safe to call twice (a document counts once per text). */
function pool_feed_document(int $docId): int {
    $doc = doc_load($docId);
    if (!$doc) return 0;
    $type = doc_typedef((int)$doc['typedef_version_id']);
    if (!$type) return 0;
    $values = render_values($type, $doc['inputs']);
    $ftypes = array_map(fn($f) => $f['type'] ?? 'text', $type['fields'] ?? []);
    $db = store_db();
    $n = 0;
    foreach (pool_authored($type, $doc['inputs']) as $slot => $texts) {
        foreach (array_unique($texts) as $raw) {
            $text = pool_tokenize($raw, $values, $ftypes);
            if (pool_store_text($slot, $text, $docId, (string)$doc['type_name'], (string)$doc['cuser'])) $n++;
        }
    }
    return $n;
}

/** A withdrawn (or deleted) document's text leaves the pool unless another document used it too (PLAN.md §7.4). */
function pool_remove_document(int $docId): void {
    $db = store_db();
    $ids = $db->prepare('SELECT pool_id FROM pool_uses WHERE document_id = ?');
    $ids->execute([$docId]);
    $db->prepare('DELETE FROM pool_uses WHERE document_id = ?')->execute([$docId]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $db->prepare('UPDATE pool_blocks SET uses = (SELECT COUNT(*) FROM pool_uses WHERE pool_id = ?) WHERE id = ?')->execute([$pid, $pid]);
        $db->prepare("DELETE FROM pool_blocks WHERE id = ? AND uses = 0 AND tier = 'history'")->execute([$pid]);
    }
}

/**
 * Suggestions for a slot (D16): the same slot first, then other slots of the same family (the part before the
 * first dot), then seeded archive text written under the same heading (D15, pool_archive_slot()). Within each,
 * library tier before history, then most used, then most recent. Hidden blocks are never offered.
 * $exclude = texts already in the document.
 */
function pool_suggest(string $slot, int $limit = 5, array $exclude = [], string $heading = ''): array {
    $family = strstr($slot, '.', true) ?: $slot;
    $familyLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $family) . '.%';
    $archive = trim($heading) !== '' ? pool_archive_slot($heading) : "\0";   // no heading: no archive tier
    $st = store_db()->prepare(
        "SELECT id, slot, text, tier, uses, last_used FROM pool_blocks
          WHERE hidden = 0 AND uses > 0 AND (slot = ? OR slot LIKE ? OR slot = ?)
          ORDER BY CASE WHEN slot = ? THEN 0 WHEN slot = ? THEN 2 ELSE 1 END, (tier = 'library') DESC, uses DESC, last_used DESC LIMIT 40");
    $st->execute([$slot, $familyLike, $archive, $slot, $archive]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (in_array(trim($r['text']), $exclude, true)) continue;
        $out[] = $r;
        if (count($out) >= $limit) break;
    }
    return $out;
}
