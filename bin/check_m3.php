#!/usr/bin/env php
<?php
/**
 * M3 integration test: the whole issue lifecycle against a REAL DocArc (a clone of its repo run with
 * `php -S` on a throw-away data folder; set DOCARC_DIR, default ~/devel/docarc; skipped if absent).
 * Covers: type/clinic links, request code (idempotent), final PDF, edit again, approve + send, resume
 * after a failed send, double-click lock, frozen documents, relinked type, abandoned drafts (incl. DocArc
 * down -> queued cleanup), withdraw, delete withdrawn, copy as new, history, and G5: a rebuild after a new
 * base release gives the same PDF hash.
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/../web/render.php');
require_once(__DIR__ . '/../web/docarc_client.php');
require_once(__DIR__ . '/../web/audit.php');
require_once(__DIR__ . '/../web/documents.php');
require_once(__DIR__ . '/../web/pool.php');
require_once(__DIR__ . '/../web/issue.php');

$docarcDir = getenv('DOCARC_DIR') ?: (getenv('HOME') . '/devel/docarc');
if (!is_file("$docarcDir/public/api.php")) { echo "SKIP  no DocArc checkout with the MedScribe API at $docarcDir\n"; exit(0); }

$fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $fail; echo ($ok ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? "  ($d)" : '') . "\n"; if (!$ok) $fail++; }

// ---- a throw-away DocArc
$tmp = sys_get_temp_dir() . '/ms-m3-' . bin2hex(random_bytes(4));
foreach (['data/documents', 'data/supplementary', 'data/logs', 'config'] as $d) mkdir("$tmp/$d", 0770, true);
file_put_contents("$tmp/config/medscribe_api.php", "<?php\ndefine('DOCARC_MEDSCRIBE_API_KEY', 'm3-key');\n");
$port = random_int(20000, 40000);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$docarcDir/public", "$docarcDir/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/s.log", 'w'], 2 => ['file', "$tmp/s.log", 'w']], $pp, $docarcDir,
    ['DOCARC_DATA_DIR' => "$tmp/data", 'DOCARC_CONFIG_DIR' => "$tmp/config", 'PATH' => getenv('PATH')]);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$cfg = ['url' => "http://127.0.0.1:$port", 'key' => 'm3-key', 'upload_timeout' => 20];
$bad = ['url' => 'http://127.0.0.1:9', 'key' => 'm3-key'];
ini_set('error_log', '/dev/null');
$types = docarc_types($cfg);
$dpdo = new PDO("sqlite:$tmp/data/docarc.sqlite"); $dpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dpdo->exec("INSERT INTO clinics (name) VALUES ('Ιατρικό Περιστερίου')");
$dpdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('qr_base_url', 'https://doc.erns.eu')");
$clinics = docarc_clinics($cfg);
check('the throw-away DocArc answers', $types !== null && count($types) >= 3 && $clinics !== null);
$beb = $types[0]; $pex = $types[1];
function dstate(string $tok, array $cfg): ?array { return docarc_get_record($tok, $cfg)[1]; }

// ---- helpers: a document ready to issue
$tv = store_db()->query("SELECT v.id, v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name='typedef/vevaiosi-mri' AND v.status='released' ORDER BY v.version DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$type = json_decode(store_get($tv['sha256']), true);
$created = [];
function newdoc(array $over = [], ?int $releaseId = null): int {
    global $tv, $type, $created;
    $rel = $releaseId ? ['id' => $releaseId] : base_release_current();
    $inputs = array_replace_recursive(['patient' => ['name' => 'Παπαδόπουλος Γιώργος', 'age' => '45', 'gender' => 'M', 'amka' => '12345678901'],
        'settings' => ['clinic' => 'Peristeri', 'date' => '2026-01-15', 'title' => $type['title'], 'closing' => $type['closing'], 'stamp' => 'no'],
        'fields' => ['surgery_date' => '2026-01-10']], $over);
    $db = store_db();
    $db->prepare("INSERT INTO documents (guid, cuser, udate, type_name, typedef_version_id, base_release_id, status, patient_name, inputs) VALUES (?,?,NOW(),'vevaiosi-mri',?,?, 'draft', ?, ?)")
       ->execute([store_uuid(), 'm3test', $tv['id'], $rel['id'], $inputs['patient']['name'], json_encode($inputs, JSON_UNESCAPED_UNICODE)]);
    return $created[] = (int)$db->lastInsertId();
}
function st(int $id): string { return doc_load($id)['status']; }

// ---- 1. links
// The main document is built with an OLDER base release than the current one (G5 below): make sure one exists.
$cur0 = base_release_current();
if ((int)store_db()->query('SELECT COUNT(*) FROM base_releases')->fetchColumn() < 2) {
    $src0 = source_ensure('base/evr-gr.tex', 'base', 'evr-gr.tex');
    foreach (base_release_files((int)$cur0['id']) as $f0) if ($f0['name'] === 'base/evr-gr.tex') $g0 = store_get($f0['sha256']);
    $v0 = source_add_version($src0, $g0 . "\n% test release marker\n", 'test marker');
    base_release_create(array_map(fn($f) => (int)($f['name'] === 'base/evr-gr.tex' ? $v0['id'] : $f['version_id']), base_release_files((int)$cur0['id'])), 'test marker release');
}
$older = (int)store_db()->query('SELECT id FROM base_releases WHERE number < (SELECT MAX(number) FROM base_releases) ORDER BY number DESC LIMIT 1')->fetchColumn();
$id = newdoc([], $older);
$r = issue_request_code($id, 'doctor', $cfg);
check('no code without a type link', !$r['ok'] && str_contains($r['msg'], 'not linked') && st($id) === 'draft', $r['msg']);
issue_link_set('type', 'vevaiosi-mri', $beb['id'], $beb['label'], $beb['code'], 'doctor');
issue_link_set('clinic', 'Peristeri', $clinics[0]['id'], $clinics[0]['name'], null, 'doctor');
check('a type has exactly one link (setting it again replaces it)', (function () use ($beb, $pex) { issue_link_set('type', 'vevaiosi-mri', $pex['id'], $pex['label'], $pex['code'], 'x'); issue_link_set('type', 'vevaiosi-mri', $beb['id'], $beb['label'], $beb['code'], 'x');
    return (int)store_db()->query("SELECT COUNT(*) FROM docarc_links WHERE kind='type' AND link_key='vevaiosi-mri'")->fetchColumn() === 1; })());

// ---- 2. an incomplete document is refused before DocArc is touched
$inc = newdoc(['fields' => ['surgery_date' => '']]);
$r = issue_request_code($inc, 'doctor', $cfg);
check('missing values block the code request', !$r['ok'] && str_contains($r['msg'], 'Missing value') && st($inc) === 'draft');

// ---- 3. request the code
$r = issue_request_code($id, 'doctor', $cfg);
$d = doc_load($id);
check('request code: reserved, QR url matches \QRBaseURL + code', $r['ok'] && $d['status'] === 'code_reserved' && preg_match('/^BEB-\d{4}-[A-Z0-9]{6}$/', (string)$d['doc_code']) && $d['docarc_url'] === 'https://doc.erns.eu/qr/' . $d['doc_code'], $r['msg']);
check('the verification code is the AMKA when known', $d['verify_code'] === '12345678901');
$rec = dstate($d['doc_code'], $cfg);
check('DocArc has the empty record with a verification code', $rec && !$rec['has_document']);
check('asking again does not make a second record', issue_request_code($id, 'doctor', $cfg)['ok'] && (int)$dpdo->query('SELECT COUNT(*) FROM records')->fetchColumn() === 1);
$code = $d['doc_code'];

// ---- 4. final PDF, edit again, final PDF again
$r = issue_compile_final($id, 'doctor');
check('final PDF built', $r['ok'] && st($id) === 'final_compiled', $r['msg']);
$fb = doc_load($id)['final_build_id'];
$txt = (function () use ($fb) { $s = store_db()->prepare('SELECT pdf_sha256 FROM builds WHERE id = ?'); $s->execute([$fb]); $f = sys_get_temp_dir() . '/m3-' . bin2hex(random_bytes(3)) . '.pdf'; file_put_contents($f, store_get($s->fetchColumn())); $o = shell_exec('pdftotext ' . escapeshellarg($f) . ' - 2>/dev/null'); @unlink($f); return $o; })();
check('the final PDF carries the code and no draft mark', str_contains((string)$txt, $code) && !str_contains((string)$txt, 'ΠΡΟΣΧΕΔΙΟ'));
check('edit again keeps the code', issue_edit_again($id)['ok'] && st($id) === 'code_reserved' && doc_load($id)['doc_code'] === $code);
check('edit again is only possible before approval', !issue_edit_again($id)['ok']);
check('final PDF built again', issue_compile_final($id, 'doctor')['ok']);

// ---- 5. approve + send
$r = issue_approve($id, 'doctor', $cfg);
$d = doc_load($id);
check('approve sends and issues', $r['ok'] && $d['status'] === 'issued' && $d['approved_by'] === 'doctor' && $d['issued_at'], $r['msg']);
$b = store_db()->prepare('SELECT pdf_sha256, tex_sha256 FROM builds WHERE id = ?'); $b->execute([$d['final_build_id']]); $build = $b->fetch(PDO::FETCH_ASSOC);
$rec = dstate($code, $cfg);
check('DocArc holds exactly the approved PDF and the .tex', $rec && $rec['document_sha256'] === $build['pdf_sha256'] && count($rec['supplementary']) === 1 && $rec['supplementary'][0]['sha256'] === $build['tex_sha256'] && $rec['supplementary'][0]['filename'] === "$code.tex");
$revs = issue_revisions($id);
check('revision 1 is recorded as current', count($revs) === 1 && $revs[0]['revision'] == 1 && $revs[0]['status'] === 'current' && $revs[0]['pdf_sha256'] === $build['pdf_sha256']);
check('an issued document is frozen', !issue_edit_again($id)['ok'] && !issue_request_code($id, 'doctor', $cfg)['ok'] && !in_array($d['status'], ISSUE_EDITABLE, true));
check('issuing twice does nothing', !issue_approve($id, 'doctor', $cfg)['ok'] && !issue_send($id, 'doctor', $cfg)['ok']);
$ev = store_db()->query("SELECT event_type FROM audit_log WHERE document_id = $id")->fetchAll(PDO::FETCH_COLUMN);
check('audit: code requested, approved, issued (no patient data)', array_intersect(['code_requested', 'approved', 'issued'], $ev) === ['code_requested', 'approved', 'issued']
    && !str_contains(json_encode(store_db()->query("SELECT * FROM audit_log WHERE document_id = $id")->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE), 'Παπαδόπουλος'));

// ---- 6. G5: the document keeps the base release it was built with; a newer one does not change its rebuild
$rb = issue_rebuild_check($id, 1, 'doctor');
$man = json_decode(store_db()->query("SELECT manifest FROM builds WHERE id = " . (int)$revs[0]['build_id'])->fetchColumn(), true);
check('G5: the current base release is newer than the one the document was built with, and the old document still rebuilds to the same PDF hash',
    $rb['ok'] && (int)doc_load($id)['base_release_id'] === $older && $older !== (int)base_release_current()['id'], $rb['msg']);

// ---- 6b. the pool is fed by the issued document (D8): edited and free text, with patient details as @tokens
// ---- 7. a failed send resumes
$id2 = newdoc(['patient' => ['name' => 'Δεύτερος Ασθενής', 'amka' => '', 'age' => '50'],
               'blocks' => ['operation' => ['text' => '{Ο|Η} ασθενής Δεύτερος Ασθενής υποβλήθηκε στις 10/01/2026 σε μικροδισκεκτομή Ο4-Ο5.']],
               'extra' => ['purpose' => [['text' => 'Συνιστάται ανάπαυση για 2 εβδομάδες.']]]]);
issue_request_code($id2, 'doctor', $cfg); issue_compile_final($id2, 'doctor');
$d2 = doc_load($id2);
check('without an AMKA the verification code is a random 6 digits', preg_match('/^\d{6}$/', (string)$d2['verify_code']) === 1);
$r = issue_approve($id2, 'doctor', $bad);
check('DocArc down at send: approved, frozen, not issued, worth retrying', !$r['ok'] && $r['retry'] && st($id2) === 'approved', $r['msg']);
check('...and an approved document cannot be edited or rebuilt', !issue_edit_again($id2)['ok'] && !issue_compile_final($id2, 'doctor')['ok']);
$r = issue_send($id2, 'doctor', $cfg);
check('send again resumes and issues', $r['ok'] && st($id2) === 'issued', $r['msg']);
$pool = store_db()->query("SELECT slot, text, uses FROM pool_blocks ORDER BY slot")->fetchAll(PDO::FETCH_ASSOC);
$bySlot = array_column($pool, 'text', 'slot');
check('issue feeds the pool: edited and free text, patient name and date as @tokens', ($bySlot['certificate.operation'] ?? '') === '{Ο|Η} ασθενής @patient υποβλήθηκε στις @surgery_date σε μικροδισκεκτομή Ο4-Ο5.'
    && ($bySlot['certificate.purpose'] ?? '') === 'Συνιστάται ανάπαυση για 2 εβδομάδες.' && count($pool) === 2, json_encode($bySlot, JSON_UNESCAPED_UNICODE));
check('unchanged type blocks do not enter the pool', !in_array('certificate.materials', array_column($pool, 'slot'), true));
check('suggestions: the same slot first, then the same family (D16); tokens stay tokens', (function () { $s = pool_suggest('certificate.operation', 3); return count($s) === 2 && $s[0]['slot'] === 'certificate.operation' && str_contains($s[0]['text'], '@patient') && $s[1]['slot'] === 'certificate.purpose'; })());
$rec2 = dstate($d2['doc_code'], $cfg);
check('DocArc holds the second document once (no duplicates)', $rec2 && $rec2['has_document'] && (int)$dpdo->query("SELECT COUNT(*) FROM documents")->fetchColumn() === 2);

// ---- 8. the step lock (double click / second tab)
$id3 = newdoc();
store_db()->prepare('UPDATE documents SET busy_until = DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE id = ?')->execute([$id3]);
$r = issue_request_code($id3, 'doctor', $cfg);
check('a second step while one runs is refused', !$r['ok'] && $r['retry'] && st($id3) === 'draft' && (int)$dpdo->query('SELECT COUNT(*) FROM records')->fetchColumn() === 2, $r['msg']);
issue_end($id3);

// ---- 9. a relinked type: the empty record is replaced (the type is part of the code)
issue_request_code($id3, 'doctor', $cfg);
$old = doc_load($id3)['doc_code'];
issue_link_set('type', 'vevaiosi-mri', $pex['id'], $pex['label'], $pex['code'], 'doctor');
$r = issue_prepare($id3, 'doctor', $cfg);
$new = doc_load($id3)['doc_code'];
check('relinked type: the old empty record is deleted and a new code with the new type is requested', $r['ok'] && $new !== $old && str_starts_with($new, $pex['code'] . '-') && dstate($old, $cfg) === null && dstate($new, $cfg) !== null, "$old -> $new");
issue_link_set('type', 'vevaiosi-mri', $beb['id'], $beb['label'], $beb['code'], 'doctor');

// ---- 10. abandoned drafts: the empty DocArc record goes with them; if DocArc is down it is queued, not ignored
$r = issue_delete_draft($id3, 'doctor', $cfg);
check('deleting a draft with a reserved code deletes the empty DocArc record', $r['ok'] && !doc_load($id3) && dstate($new, $cfg) === null);
$id4 = newdoc(); issue_request_code($id4, 'doctor', $cfg); $c4 = doc_load($id4)['doc_code'];
$before = (int)store_db()->query('SELECT COUNT(*) FROM docarc_cleanup')->fetchColumn();
// make the delete fail: DocArc unreachable via $bad
$r = issue_delete_draft($id4, 'doctor', $bad);
check('DocArc down: the draft is deleted and the DocArc delete is queued for retry', $r['ok'] && !doc_load($id4) && (int)store_db()->query('SELECT COUNT(*) FROM docarc_cleanup')->fetchColumn() === $before + 1 && dstate($c4, $cfg) !== null);
[$done, $stillFailing] = issue_cleanup_run($cfg);
check('the retry removes the empty record', $done >= 1 && $stillFailing === 0 && dstate($c4, $cfg) === null);
check('an issued record is never removed by a cleanup job', (function () use ($cfg, $code) { issue_cleanup_enqueue($code, 'empty', 'test', 'x'); issue_cleanup_run($cfg); return dstate($code, $cfg) !== null; })());

// ---- 11. copy as new
$r = issue_copy($id, 'doctor');
$cp = doc_load($r['id'] ?? 0); $created[] = $r['id'] ?? 0;
check('copy as new: a draft without patient and code, linked to the original', $r['ok'] && $cp['status'] === 'draft' && $cp['doc_code'] === null && $cp['inputs']['patient']['name'] === '' && $cp['inputs']['patient']['amka'] === '' && (int)$cp['copied_from'] === $id && ($cp['inputs']['fields']['surgery_date'] ?? '') === '2026-01-10');

// ---- 12. withdraw, then delete from MedScribe
check('only an issued document can be withdrawn', !issue_withdraw($cp['id'], 'doctor', 'x', $cfg)['ok']);
check('a withdrawal needs a reason', !issue_withdraw($id2, 'doctor', '  ', $cfg)['ok'] && st($id2) === 'issued');
$r = issue_withdraw($id2, 'doctor', 'wrong patient', $cfg);
$w = doc_load($id2);
check('withdraw: DocArc deletes the whole record, MedScribe keeps the document marked withdrawn', $r['ok'] && $w['status'] === 'withdrawn' && $w['withdraw_reason'] === 'wrong patient' && dstate($w['doc_code'], $cfg) === null
    && !glob("$tmp/data/documents/{$w['doc_code']}*") && count(issue_revisions($id2)) === 1, $r['msg']);
$rev2 = issue_revisions($id2)[0];
$audit = json_encode(store_db()->query("SELECT * FROM audit_log WHERE event_type='withdrawn'")->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
check('withdrawing removes the document\'s text from the pool', (int)store_db()->query('SELECT COUNT(*) FROM pool_blocks')->fetchColumn() === 0 && (int)store_db()->query('SELECT COUNT(*) FROM pool_uses')->fetchColumn() === 0);
check('the audit log never holds the withdrawal reason', !str_contains($audit, 'wrong patient'));
check('delete needs a withdrawn document', !issue_delete_withdrawn($id, 'doctor')['ok']);
$r = issue_delete_withdrawn($id2, 'doctor');
check('delete withdrawn: the document, revisions and unshared files are gone; only the audit event remains',
    $r['ok'] && !doc_load($id2) && !issue_revisions($id2) && !is_file(store_object_path($rev2['pdf_sha256'])) && !is_file(store_object_path($rev2['tex_sha256']))
    && (int)store_db()->query("SELECT COUNT(*) FROM audit_log WHERE event_type='withdrawn_deleted' AND document_id = $id2")->fetchColumn() === 1);
check('files shared with another document are kept (the first document still rebuilds)', issue_rebuild_check($id, 1, 'doctor')['ok']);

// ---- clean up what this test made in MedScribe's database
$db = store_db();
foreach (array_unique($created) as $cid) { if (!$cid) continue; $db->prepare('DELETE FROM document_revisions WHERE document_id = ?')->execute([$cid]); issue_prune_builds($cid, [], ['preview', 'issue', 'test', 'rebuild']); $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$cid]); }
$db->prepare("DELETE FROM audit_log WHERE cuser IN ('doctor','m3test')")->execute();
$db->exec("DELETE FROM docarc_cleanup"); $db->exec("DELETE FROM pool_uses"); $db->exec("DELETE FROM pool_blocks");
$db->exec("DELETE FROM docarc_links WHERE cuser IN ('doctor','x')");
proc_terminate($srv); proc_close($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fail ? "\n$fail check(s) failed\n" : "\nM3 checks passed\n";
exit($fail ? 1 : 0);
