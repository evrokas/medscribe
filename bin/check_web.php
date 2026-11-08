#!/usr/bin/env php
<?php
/**
 * Web-level checks: the real app over HTTP (`php -S` + router.php) with two temporary accounts (a doctor and a
 * secretary, removed afterwards). New documents start from the type's defaults; role access; every page answers;
 * the composer round trip; the library and type editor; CSRF is enforced.
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/../web/render.php');
require_once(__DIR__ . '/../web/docarc_client.php');
require_once(__DIR__ . '/../web/audit.php');
require_once(__DIR__ . '/../web/documents.php');
require_once(__DIR__ . '/../web/pool.php');
require_once(__DIR__ . '/../web/issue.php');

$fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $fail; echo ($ok ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? "  ($d)" : '') . "\n"; if (!$ok) $fail++; }

$db = store_db();
$pw = 'T3st-' . bin2hex(random_bytes(4));
$users = ['wt_doctor' => 'doctor', 'wt_secretary' => 'secretary'];
/** Remove everything the test accounts made: at the start (what a crashed run left) and at the end, also after a crash. */
function wt_cleanup(): void {
    $db = store_db(); $q = "('wt_doctor','wt_secretary')";
    foreach ($db->query("SELECT id FROM documents WHERE cuser IN $q")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        issue_prune_builds((int)$d, [], ['preview', 'issue', 'test', 'rebuild']); $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$d]);
    }
    foreach ($db->query("SELECT id, tex_sha256, pdf_sha256 FROM builds WHERE purpose IN ('type-preview', 'test') AND cuser IN $q")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $db->exec('DELETE FROM builds WHERE id = ' . (int)$b['id']); issue_gc_object((string)$b['pdf_sha256']); issue_gc_object((string)$b['tex_sha256']);
    }
    foreach (['typedef/wt-draft-test', 'typedef/wt-pin-test', 'shared/wt-comp'] as $n) {
        $id = $db->query('SELECT id FROM sources WHERE name = ' . $db->quote($n))->fetchColumn();
        if ($id) { $db->exec("DELETE FROM source_versions WHERE source_id = " . (int)$id); $db->exec("DELETE FROM sources WHERE id = " . (int)$id); }
    }
    $db->exec("DELETE FROM audit_log WHERE cuser IN $q");
    $db->exec("DELETE ur FROM user_roles ur JOIN users us ON us.id = ur.user_id WHERE us.uname IN $q");
    $db->exec("DELETE FROM users WHERE uname IN $q"); $db->exec("DELETE FROM user_tokens WHERE uname IN $q");
}
wt_cleanup();
foreach ($users as $u => $role) {
    $db->prepare('INSERT INTO users (name, email, uname, upass, cdate, active, expired, wrongpasscount, roles) VALUES (?,?,?,?,NOW(),1,0,0,?)')->execute([$u, "$u@localhost", $u, password_hash($pw, PASSWORD_DEFAULT), $role]);
    $uid = (int)$db->lastInsertId();
    $rid = (int)$db->query("SELECT id FROM roles WHERE name = " . $db->quote($role))->fetchColumn();
    $db->prepare('INSERT INTO user_roles (guid, cuser, user_id, role_id) VALUES (?,?,?,?)')->execute([store_uuid(), 'webtest', $uid, $rid]);
}
$cleaned = false;
register_shutdown_function(function () use (&$cleaned) { if (!$cleaned) { echo "\n(cleaning up after a failure)\n"; wt_cleanup(); } });
$port = random_int(20000, 40000);
$root = dirname(__DIR__);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$root/web", "$root/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = "http://127.0.0.1:$port";

class Client {
    public string $jar;
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'wtjar'); }
    function req(string $method, string $path, array $post = []): array {
        global $base;
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch);
        $info = curl_getinfo($ch); curl_close($ch);
        $body = substr($raw, $info['header_size']); $h = substr($raw, 0, $info['header_size']);
        preg_match('/^location:\s*(.+?)\r?$/mi', $h, $m);
        return ['status' => $info['http_code'], 'body' => $body, 'location' => $m[1] ?? null, 'headers' => $h];
    }
    function get(string $p): array { return $this->req('GET', $p); }
    function post(string $p, array $f): array { return $this->req('POST', $p, $f); }
    function token(string $p): string { preg_match('/name="csrf_token" value="([^"]+)"/', $this->get($p)['body'], $m); return $m[1] ?? ''; }
    function login(string $u, string $pw): bool { $r = $this->post('/login', ['csrf_token' => $this->token('/login'), 'username' => $u, 'password' => $pw]); return $r['status'] === 302 && !str_contains((string)$r['location'], '/login'); }
}
$docs = []; $dc = new Client(); $sc = new Client();
check('the doctor logs in', $dc->login('wt_doctor', $pw)); check('the secretary logs in', $sc->login('wt_secretary', $pw));
check('a wrong password does not', !(new Client)->login('wt_doctor', 'nope'));

// ---- logged out
$anon = new Client;
foreach (['/', '/documents', '/types', '/library', '/documents/new', '/status', '/profile'] as $p) {
    $r = $anon->get($p);
    check("logged out: $p redirects to the login page", $r['status'] === 302 && str_contains((string)$r['location'], '/login'));
}
check('logged out: the PDF preview is not served', $anon->get('/documents/1/preview.pdf')['status'] === 302);

// ---- every page answers for the doctor, and prints no PHP error
foreach (['/', '/documents', '/documents/new', '/types', '/types/links', '/types/typedef/adeia', '/types/shared/purpose', '/library', '/status', '/profile'] as $p) {
    $r = $dc->get($p);
    check("doctor: $p", $r['status'] === 200 && !preg_match('/<b>(Warning|Fatal error|Notice|Deprecated)<\/b>/', $r['body']), (string)$r['status']);
}
// ---- a new document starts from the type's defaults (not with every block switched off)
$types = ['vevaiosi-mri', 'vevaiosi-ylikon', 'adeia', 'vevaiosi-amoivis', 'gnomateysi-exetaseon'];
foreach ($types as $t) {
    $r = $dc->post('/documents/new', ['csrf_token' => $dc->token('/documents/new'), 'type' => $t, 'patient' => ['name' => "WT $t", 'gender' => 'F', 'age' => '40'], 'clinic' => 'Peristeri', 'date' => '2026-01-15']);
    preg_match('#/documents/(\d+)$#', (string)$r['location'], $m);
    $id = (int)($m[1] ?? 0); if ($id) $docs[] = $id;
    check("new $t: a draft is created and opens", $id > 0 && $dc->get("/documents/$id")['status'] === 200);
    if (!$id) continue;
    $page = $dc->get("/documents/$id")['body'];
    $def = json_decode(store_get($db->query("SELECT v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name='typedef/$t' AND v.status='released' ORDER BY v.version DESC LIMIT 1")->fetchColumn()), true);
    $defaultOn = 0; foreach ($def['sections'] as $s) foreach (($s['blocks'] ?? []) as $b) if (!empty($b['default']) && !isset($b['group'])) $defaultOn++;
    preg_match_all('/<div class="block on"[ >]/', $page, $on);
    check("new $t: the type's default-on blocks are on in the new draft ($defaultOn)", $defaultOn === 0 || count($on[0]) >= $defaultOn, count($on[0]) . ' on');
}

// ---- the documents list (composer plan §5.2): filters, search, default views; the English and patient-optional types
$r = $dc->post('/documents/new', ['csrf_token' => $dc->token('/documents/new'), 'type' => 'recommendation-en', 'patient' => ['name' => 'WT English', 'gender' => 'M'], 'clinic' => 'Mandra', 'date' => '2026-01-15']);
check('new recommendation-en (English): a draft', preg_match('#/documents/(\d+)$#', (string)$r['location'], $m) && ($docs[] = (int)$m[1]));
$r = $dc->post('/documents/new', ['csrf_token' => $dc->token('/documents/new'), 'type' => 'epistoli', 'clinic' => 'Mandra', 'date' => '2026-01-15']);
check('new epistoli with no patient (optional): a draft', preg_match('#/documents/(\d+)$#', (string)$r['location'], $m) && ($docs[] = (int)$m[1]));
$r = $dc->post('/documents/new', ['csrf_token' => $dc->token('/documents/new'), 'type' => 'adeia', 'clinic' => 'Mandra', 'date' => '2026-01-15']);
check('...a type that needs the patient refuses no name', str_ends_with((string)$r['location'], '/documents/new'));
$wtRev = $docs[1];
$db->exec("UPDATE documents SET status = 'review' WHERE id = $wtRev");
$l = $dc->get('/documents')['body'];
check('list: the doctor opens on "Ready for review" while there are any, with its count', str_contains($l, 'aria-current="page">Ready for review') && preg_match('/Ready for review <span class="ms-count">\d+/', $l) && str_contains($l, "#$wtRev &middot;") && !str_contains($l, "#{$docs[0]} &middot;"));
$l = $sc->get('/documents')['body'];
check('list: the secretary opens on drafts', str_contains($l, 'aria-current="page">Drafts') && str_contains($l, "#{$docs[0]} &middot;") && !str_contains($l, "#$wtRev &middot;"));
check('list: type labels and status words, not ids', str_contains($l, 'Βεβαίωση συμβατότητας MRI') && str_contains($l, '>Draft</span>'));
$l = $dc->get('/documents?f=all&q=' . rawurlencode('WT vevaiosi-y'))['body'];
check('list: search by patient name', str_contains($l, 'WT vevaiosi-ylikon') && !str_contains($l, 'WT adeia'));
$l = $dc->get('/documents?f=all&q=' . rawurlencode('#' . $docs[2]))['body'];
check('list: search by #number', str_contains($l, "#{$docs[2]} &middot;") && substr_count($l, 'class="ms-doc"') === 1);
$l = $dc->get('/documents?f=all&q=' . rawurlencode("%' OR 1=1 -- _"))['body'];
check('list: search text is data (no match, no error)', str_contains($l, 'No document matches') && !preg_match('/<b>(Warning|Fatal error)/', $l));
$l = $dc->get('/documents?f=issued&q=WT')['body'];
check('list: filter and search together', !str_contains($l, 'class="ms-doc"'));
$db->exec("UPDATE documents SET status = 'draft' WHERE id = $wtRev");

// ---- the secretary: can prepare, cannot issue, cannot see the library, types editor or DocArc links
$id = $docs[0] ?? 0;
check('secretary: documents list and the composer', $sc->get('/documents')['status'] === 200 && $sc->get("/documents/$id")['status'] === 200);
foreach (["/documents/$id/issue", '/library', '/types/links', '/types/typedef/adeia', '/types/typedef/adeia/review', "/documents/$id/final.pdf"] as $p) {
    $r = $sc->get($p);
    check("secretary: $p is refused", $r['status'] === 302 && str_contains((string)$r['location'], '/login') || $r['status'] === 401 || $r['status'] === 403);
}
$r = $sc->post("/documents/$id/issue", ['csrf_token' => $sc->token("/documents/$id"), 'action' => 'prepare']);
check('...the document is still a draft with no code', $db->query("SELECT status, doc_code FROM documents WHERE id = $id")->fetch(PDO::FETCH_ASSOC) === ['status' => 'draft', 'doc_code' => null]);
$r = $sc->post("/documents/$id", ['csrf_token' => $sc->token("/documents/$id"), 'action' => 'ready', 'patient' => ['name' => 'WT ready'], 'settings' => ['clinic' => 'Peristeri', 'date' => '2026-01-15'], 'blocks_on' => ['surgery_row' => 1, 'operation' => 1, 'materials' => 1], 'groups' => ['purpose' => 'purpose_center']]);
check('secretary: "ready for review" works', $db->query("SELECT status FROM documents WHERE id = $id")->fetchColumn() === 'review');

// ---- CSRF is enforced
$r = $dc->post("/documents/$id", ['action' => 'save', 'patient' => ['name' => 'NO TOKEN']]);
check('a POST without a CSRF token changes nothing', $db->query("SELECT patient_name FROM documents WHERE id = $id")->fetchColumn() !== 'NO TOKEN');
$r = $dc->post('/documents/new', ['type' => 'adeia', 'patient' => ['name' => 'NO TOKEN'], 'clinic' => 'Peristeri', 'date' => '2026-01-15']);
check('...and creates nothing', (int)$db->query("SELECT COUNT(*) FROM documents WHERE patient_name = 'NO TOKEN'")->fetchColumn() === 0);

// ---- the composer round trip with a local edit and free text, then the library
$page = $dc->get("/documents/$id")['body'];
$r = $dc->post("/documents/$id", ['csrf_token' => $dc->token("/documents/$id"), 'action' => 'save', 'patient' => ['name' => 'WT Round', 'gender' => 'M', 'age' => '50'],
    'settings' => ['clinic' => 'Mandra', 'date' => '2026-02-01', 'title' => 'Ιατρική Βεβαίωση', 'closing' => 'Ο ιατρός', 'stamp' => 'no'], 'fields' => ['surgery_date' => '2026-01-20'],
    'blocks_on' => ['surgery_row' => 1, 'operation' => 1, 'materials' => 1], 'groups' => ['purpose' => 'purpose_work'],
    'btext' => ['operation' => '{Ο|Η} ασθενής υποβλήθηκε στις @surgery_date σε ΑΛΛΑΓΗ.'], 'extra' => ['purpose' => 'Ένα επιπλέον κείμενο.']]);
$in = json_decode($db->query("SELECT inputs FROM documents WHERE id = $id")->fetchColumn(), true);
check('the composer saves choices, a local edit and free text', $in['settings']['clinic'] === 'Mandra' && $in['groups']['purpose'] === 'purpose_work'
    && str_contains($in['blocks']['operation']['text'] ?? '', 'ΑΛΛΑΓΗ') && ($in['extra']['purpose'][0]['text'] ?? '') === 'Ένα επιπλέον κείμενο.' && !isset($in['blocks']['materials']['text']));
$page = $dc->get("/documents/$id")['body'];
check('the page shows the edit marker and the free text', str_contains($page, 'edited for this document') && str_contains($page, 'Ένα επιπλέον κείμενο.'));
check('a save releases the step lock', $db->query("SELECT busy_until FROM documents WHERE id = $id")->fetchColumn() === null);

// ---- while a build holds the lock (TeX running), a save changes nothing and gives the typed form back
$db->exec("UPDATE documents SET busy_until = DATE_ADD(NOW(), INTERVAL 120 SECOND) WHERE id = $id");
$before = $db->query("SELECT inputs FROM documents WHERE id = $id")->fetchColumn();
$r = $sc->post("/documents/$id", ['csrf_token' => $sc->token("/documents/$id"), 'action' => 'save', 'patient' => ['name' => 'WT While Busy', 'gender' => 'M'],
    'settings' => ['clinic' => 'Mandra', 'date' => '2026-02-01']]);
check('locked: a save during a build changes nothing', $db->query("SELECT inputs FROM documents WHERE id = $id")->fetchColumn() === $before);
$page = $sc->get("/documents/$id")['body'];
check('...and the composer shows the typed values with a "not saved" notice', str_contains($page, 'WT While Busy') && str_contains($page, 'NOT saved'));
check('...only once', !str_contains($sc->get("/documents/$id")['body'], 'WT While Busy'));
// the Write view's sentences endpoint (composer plan §6.1): answers while the build holds the lock, saves nothing
$ud = $db->query("SELECT udate, inputs, busy_until FROM documents WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
$r = $sc->post("/documents/$id/sentences", ['csrf_token' => $sc->token("/documents/$id"), 'patient' => ['name' => 'WT Sentences', 'gender' => 'F'],
    'settings' => ['clinic' => 'Mandra', 'date' => '2026-02-01'], 'fields' => ['surgery_date' => ''], 'blocks_on' => ['operation' => 1], 'groups' => ['purpose' => 'purpose_work']]);
$j = json_decode($r['body'], true);
check('sentences: rendered for the unsaved form while a build runs, with its checks', $r['status'] === 200 && !empty($j['ok'])
    && str_contains($j['blocks']['operation']['html'] ?? '', 'cx-v-empty') && $j['blocks']['operation']['on'] === true && is_array($j['checks'] ?? null), substr($r['body'], 0, 200));
check('sentences: nothing saved, the lock untouched', $db->query("SELECT udate, inputs, busy_until FROM documents WHERE id = $id")->fetch(PDO::FETCH_ASSOC) === $ud);
$r = $sc->post("/documents/$id/sentences", ['patient' => ['name' => 'x']]);
check('sentences: CSRF is enforced', $r['status'] === 403 && empty(json_decode($r['body'], true)['ok']));
check('sentences: logged out, refused', !str_contains((new Client)->post("/documents/$id/sentences", [])['body'], '"ok":true'));
$r = $dc->post("/documents/$id", ['csrf_token' => $dc->token("/documents/$id"), 'action' => 'preview', 'patient' => ['name' => 'WT While Busy']]);
check('locked: a preview does not start either', $db->query("SELECT inputs FROM documents WHERE id = $id")->fetchColumn() === $before);
$db->exec("UPDATE documents SET inputs = JSON_SET(inputs, '$.settings.paper', 'a5') WHERE id = $id");
$busy = $db->query("SELECT busy_until FROM documents WHERE id = $id")->fetchColumn();
$r = $dc->get("/documents/$id/print.pdf");
check('locked: the two-up print waits too (409, no PDF) and leaves the lock alone', $r['status'] === 409 && !str_starts_with($r['body'], '%PDF')
    && $db->query("SELECT busy_until FROM documents WHERE id = $id")->fetchColumn() === $busy);
$db->exec("UPDATE documents SET inputs = JSON_REMOVE(inputs, '$.settings.paper') WHERE id = $id");
$before = $db->query("SELECT inputs FROM documents WHERE id = $id")->fetchColumn();
$db->exec("UPDATE documents SET busy_until = NULL WHERE id = $id");

// ---- a withdrawn document's preview PDF is the doctor's only, like its composer, history and files
$okBuild = 0;   // any successful build whose PDF is still in the store
foreach ($db->query("SELECT id, pdf_sha256 FROM builds WHERE status = 'ok' AND pdf_sha256 IS NOT NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $bb) {
    if (is_file(store_object_path($bb['pdf_sha256']))) { $okBuild = (int)$bb['id']; break; }
}
$wd = $docs[1] ?? 0;
$db->exec("UPDATE documents SET status = 'withdrawn', last_build_id = $okBuild WHERE id = $wd");
// (the framework's error_403() renders its page with HTTP 200, so check that no PDF comes back)
check('withdrawn: the secretary cannot fetch the preview PDF', !str_starts_with($sc->get("/documents/$wd/preview.pdf")['body'], '%PDF'));
check('withdrawn: the doctor can', str_starts_with($dc->get("/documents/$wd/preview.pdf")['body'], '%PDF'));
$db->exec("UPDATE documents SET status = 'draft', last_build_id = NULL WHERE id = $wd");
check('the deleted draft goes away (and takes its builds)', (function () use ($dc, $db, $id, &$docs) { $r = $dc->post("/documents/$id/delete", ['csrf_token' => $dc->token("/documents/$id")]); $docs = array_diff($docs, [$id]); return $r['status'] === 302 && !$db->query("SELECT 1 FROM documents WHERE id = $id")->fetchColumn(); })());

// ---- the type editor refuses invalid input and keeps what was typed
$page = $dc->get('/types/typedef/adeia')['body'];
$r = $dc->post('/types/typedef/adeia', ['csrf_token' => $dc->token('/types/typedef/adeia'), 'action' => 'save', 'text' => '{"name": "adeia"', 'note' => 'bad']);
check('type editor: invalid JSON is not saved', (int)$db->query("SELECT COUNT(*) FROM source_versions v JOIN sources s ON s.id=v.source_id WHERE s.name='typedef/adeia'")->fetchColumn() === 1);
$r = $dc->post('/types/typedef/adeia', ['csrf_token' => $dc->token('/types/typedef/adeia'), 'action' => 'save', 'text' => $db ? store_get($db->query("SELECT v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name='typedef/adeia' ORDER BY v.version DESC LIMIT 1")->fetchColumn()) : '', 'note' => '']);
check('type editor: a change note is required', (int)$db->query("SELECT COUNT(*) FROM source_versions v JOIN sources s ON s.id=v.source_id WHERE s.name='typedef/adeia'")->fetchColumn() === 1);

// ---- the type editor's preview: the unsaved text as the printed document (HTML) and as the composer form
$adeia = store_get($db->query("SELECT v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name='typedef/adeia' ORDER BY v.version DESC LIMIT 1")->fetchColumn());
$edited = str_replace('"title": "', '"title": "PREVIEWTEST ', $adeia);
$pv = fn($c, array $f) => json_decode($c->post('/types/typedef/adeia/preview', $f + ['csrf_token' => $dc->token('/types/typedef/adeia')])['body'], true);
$r = $pv($dc, ['text' => $edited, 'mode' => 'print', 'gender' => 'F']);
check('type preview: print shows the unsaved text, rendered by the engine', ($r['ok'] ?? false) && str_contains($r['html'] ?? '', 'PREVIEWTEST') && str_contains($r['html'], 'pv-doc') && str_contains($r['html'], 'data-src="block:') && str_contains($r['html'], 'data-src="block:') && !preg_match('/\\\\[a-z]+/i', strip_tags($r['html'])));
$r = $pv($dc, ['text' => $adeia, 'mode' => 'form']);
check('type preview: form shows the composer sections', ($r['ok'] ?? false) && str_contains($r['html'] ?? '', 'class="section"'));
$kr = store_get($db->query("SELECT v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name='typedef/odigies-kranio' AND v.status='released' ORDER BY v.version DESC LIMIT 1")->fetchColumn());
$r = $pv($dc, ['text' => $kr, 'mode' => 'form']);
check('input form: a section with nothing picked starts folded, one with picks starts open', ($r['ok'] ?? false)
    && preg_match('/class="section collapsed"/', $r['html']) && preg_match('/class="section"/', $r['html']) && substr_count($r['html'], 'class="sec-body"') === substr_count($r['html'], 'fieldset class="section'));
$r = $pv($dc, ['text' => '{"name": "adeia"', 'mode' => 'print']);
check('type preview: invalid JSON gives the error, no preview', isset($r['ok']) && !$r['ok'] && count($r['errors'] ?? []) > 0);
$r = $dc->post('/types/typedef/adeia/preview', ['text' => $adeia, 'mode' => 'print']);
check('type preview: CSRF is enforced', !(json_decode($r['body'], true)['ok'] ?? false));
check('type preview: nothing was saved', (int)$db->query("SELECT COUNT(*) FROM source_versions v JOIN sources s ON s.id=v.source_id WHERE s.name='typedef/adeia'")->fetchColumn() === 1);

// ---- preview with a case rule (plan §6.2): what it turns off stays visible, marked; what it turns on is marked
$pq = store_get($db->query("SELECT v.sha256 FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name='typedef/proegkrisi' AND v.status='released' ORDER BY v.version DESC LIMIT 1")->fetchColumn());
$pr = json_decode($dc->post('/types/typedef/proegkrisi/preview', ['csrf_token' => $dc->token('/types/typedef/proegkrisi'), 'text' => $pq, 'mode' => 'print', 'features' => [array_key_first(json_decode($pq, true)['features'] ?? ['x' => 1])]])['body'], true);
$prDel = $pr['marks']['del'] ?? []; $prIns = $pr['marks']['ins'] ?? [];
check('preview with a case rule: turned-off and turned-on sentences, both shown', !empty($pr['ok']) && $prDel && $prIns
    && !array_filter(array_merge($prDel, $prIns), fn($x) => !str_contains($pr['html'], 'data-src="' . $x . '"')), json_encode($pr['marks'] ?? null));

// ---- the type editor's test PDF (plan §6.4): one build at a time per user, served to that user only, one kept
$typePdfs = fn() => (int)$db->query("SELECT COUNT(*) FROM builds WHERE purpose = 'type-preview' AND cuser = 'wt_doctor'")->fetchColumn();
store_mkdir(dirname(__DIR__) . '/web/files/jobs');
$lockF = fopen(dirname(__DIR__) . '/web/files/jobs/type-pdf-' . md5('wt_doctor') . '.lock', 'c'); flock($lockF, LOCK_EX);
$r = $dc->post('/types/typedef/adeia/pdf', ['csrf_token' => $dc->token('/types/typedef/adeia'), 'text' => $adeia]);
check('test PDF: refused while another build of the same user runs', $r['status'] === 409, (string)$r['status']);
flock($lockF, LOCK_UN); fclose($lockF);
$tok = $dc->token('/types/typedef/adeia');
$j1 = json_decode($dc->post('/types/typedef/adeia/pdf', ['csrf_token' => $tok, 'text' => $adeia])['body'], true);
$pdfPath = parse_url((string)($j1['url'] ?? ''), PHP_URL_PATH) . '?' . parse_url((string)($j1['url'] ?? ''), PHP_URL_QUERY);
check('test PDF: built from the unsaved text and served to its user', !empty($j1['ok']) && str_starts_with($dc->get($pdfPath)['body'], '%PDF'), json_encode($j1));
check('test PDF: not served to another user', !str_starts_with($sc->get($pdfPath)['body'], '%PDF'));
$j2 = json_decode($dc->post('/types/typedef/adeia/pdf', ['csrf_token' => $tok, 'text' => $adeia])['body'], true);
check('test PDF: only the latest is kept', !empty($j2['ok']) && $typePdfs() === 1 && !str_starts_with($dc->get($pdfPath)['body'], '%PDF'));
$bad = json_decode($dc->post('/types/typedef/adeia/pdf', ['csrf_token' => $tok, 'text' => '{'])['body'], true);
check('test PDF: invalid text is refused before any build', empty($bad['ok']) && $typePdfs() === 1);
// the PDF tab gives the composer's bytes: the same inputs through the composer's own doc_preview() give the same .tex and
// letterhead files, and the PDF tab's build, rebuilt with the composer build's time stamp, the same PDF bytes
// (each build stamps the time it ran: SOURCE_DATE_EPOCH = time())
require_once(__DIR__ . '/../web/types.php');
$jt = json_decode($dc->post('/types/typedef/adeia/pdf', ['csrf_token' => $tok, 'text' => $adeia, 'gender' => 'M'])['body'], true);
$tb = $db->query('SELECT * FROM builds WHERE id = ' . (int)preg_replace('/.*[?&]b=(\d+).*/', '$1', (string)($jt['url'] ?? '')))->fetch(PDO::FETCH_ASSOC);
$r = $dc->post('/documents/new', ['csrf_token' => $dc->token('/documents/new'), 'type' => 'adeia', 'patient' => ['name' => 'WT compare', 'gender' => 'M', 'age' => '45'], 'clinic' => 'Mandra', 'date' => date('Y-m-d')]);
preg_match('#/documents/(\d+)$#', (string)$r['location'], $m); $cmpDoc = (int)($m[1] ?? 0); $docs[] = $cmpDoc;
$adeiaVid = (int)$db->query("SELECT v.id FROM sources s JOIN source_versions v ON v.source_id=s.id WHERE s.name='typedef/adeia' AND v.status='released' ORDER BY v.version DESC LIMIT 1")->fetchColumn();
$adeiaType = doc_typedef($adeiaVid);
$db->prepare('UPDATE documents SET typedef_version_id = ?, type_name = ?, base_release_id = ?, inputs = ? WHERE id = ?')
   ->execute([$adeiaVid, 'adeia', (int)base_release_current()['id'], json_encode(types_sample_inputs($adeiaType, 'M'), JSON_UNESCAPED_UNICODE), $cmpDoc]);
$keepKernel = $GLOBALS['kernel'] ?? null;
$GLOBALS['kernel'] = new class { function getUserName() { return 'wt_doctor'; } };
$cb = doc_preview(doc_load($cmpDoc), $adeiaType);
$GLOBALS['kernel'] = $keepKernel;
$mt = json_decode((string)($tb['manifest'] ?? '{}'), true); $mc = $cb['manifest'] ?? [];
$kit = fn(array $m) => array_map(fn($f) => $f['kitpath'] . ' ' . $f['sha256'], array_values(array_filter($m['files'] ?? [], fn($f) => empty($f['generated']))));
$texDiff = (function () use ($mt, $mc) {
    try { $a = explode("\n", store_get($mt['tex_sha256'])); $b = explode("\n", store_get($mc['tex_sha256'])); } catch (Throwable $e) { return $e->getMessage(); }
    return implode(' | ', array_slice(array_merge(array_map(fn($l) => "tab: $l", array_diff($a, $b)), array_map(fn($l) => "composer: $l", array_diff($b, $a))), 0, 6));
})();
check('PDF tab = composer: the same .tex for the same inputs', !empty($jt['ok']) && $cb['ok'] && ($mt['tex_sha256'] ?? 'a') === ($mc['tex_sha256'] ?? 'b'), $texDiff);
check('PDF tab = composer: the same letterhead files', $kit($mt) && $kit($mt) === $kit($mc));
$mt['source_date_epoch'] = $mc['source_date_epoch'];
$rb = compile_from_manifest($mt, 'test', 'wt_doctor');
check('PDF tab = composer: with the same time stamp, byte-identical PDFs', $rb['ok'] && $rb['pdf_sha256'] === $cb['pdf_sha256'],
      substr((string)$rb['pdf_sha256'], 0, 12) . ' vs ' . substr((string)$cb['pdf_sha256'], 0, 12) . ', time stamps ' . ($tb['source_date_epoch'] ?? '?') . '/' . $mc['source_date_epoch']);
$db->exec('DELETE FROM builds WHERE id = ' . (int)($rb['build_id'] ?? 0)); issue_gc_object((string)$rb['pdf_sha256']);
foreach ($db->query("SELECT id, tex_sha256, pdf_sha256 FROM builds WHERE purpose = 'type-preview' AND cuser = 'wt_doctor'")->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $db->exec('DELETE FROM builds WHERE id = ' . (int)$b['id']); issue_gc_object($b['pdf_sha256']); issue_gc_object($b['tex_sha256']);
}

// ---- saving into a draft version: in place while it is the latest draft; never once released or superseded (a throw-away type)
$wtName = 'wt-draft-test';
$wtDef = json_decode($adeia, true); $wtDef['name'] = $wtName;
$wtSrc = source_ensure('typedef/' . $wtName, 'typedef', 'types/' . $wtName . '.json');
$v1 = source_add_version($wtSrc, json_encode($wtDef, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", 'first', 'draft', 'wt_doctor');
$wtCount = fn() => (int)$db->query("SELECT COUNT(*) FROM source_versions WHERE source_id = " . (int)$wtSrc['id'])->fetchColumn();
$wtText = fn(int $v) => store_get($db->query("SELECT sha256 FROM source_versions WHERE source_id = " . (int)$wtSrc['id'] . " AND version = $v")->fetchColumn());
$page = $dc->get("/types/typedef/$wtName")['body'];
check('draft: the page offers "save in v1"', str_contains($page, 'value="save_here"'));
$edit = function (string $title) use ($wtDef) { $d = $wtDef; $d['title'] = $title; return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); };
$dc->post("/types/typedef/$wtName", ['csrf_token' => $dc->token("/types/typedef/$wtName"), 'action' => 'save_here', 'version' => 1, 'text' => $edit('IN PLACE'), 'note' => '']);
check('draft: saved in the same version, its note kept', $wtCount() === 1 && json_decode($wtText(1), true)['title'] === 'IN PLACE'
    && $db->query("SELECT note FROM source_versions WHERE source_id = " . (int)$wtSrc['id'])->fetchColumn() === 'first');
$dc->post("/types/typedef/$wtName", ['csrf_token' => $dc->token("/types/typedef/$wtName"), 'action' => 'save', 'text' => $edit('V2'), 'note' => 'second']);
$dc->post("/types/typedef/$wtName", ['csrf_token' => $dc->token("/types/typedef/$wtName"), 'action' => 'save_here', 'version' => 1, 'text' => $edit('NOT ALLOWED'), 'note' => '']);
check('draft: an older draft cannot be saved over once a newer version exists', $wtCount() === 2 && json_decode($wtText(1), true)['title'] === 'IN PLACE');
// releasing: only from the review page, with the confirmation (plan §5.2)
$rv = $dc->get("/types/typedef/$wtName/review?v=2");
check('review page: lists the change and asks for the confirmation', $rv['status'] === 200 && str_contains($rv['body'], 'V2') && str_contains($rv['body'], 'name="confirm"'));
$wtStatus = fn(int $v) => $db->query("SELECT status FROM source_versions WHERE source_id = " . (int)$wtSrc['id'] . " AND version = $v")->fetchColumn();
$dc->post("/types/typedef/$wtName", ['csrf_token' => $dc->token("/types/typedef/$wtName/review?v=2"), 'action' => 'release', 'version' => 2]);
check('release without the confirmation is refused', $wtStatus(2) === 'draft');
$dc->post("/types/typedef/$wtName", ['csrf_token' => $dc->token("/types/typedef/$wtName/review?v=2"), 'action' => 'release', 'version' => 2, 'confirm' => '1']);
check('release with the confirmation', $wtStatus(2) === 'released');
check('released: the page no longer offers "save in"', !str_contains($dc->get("/types/typedef/$wtName")['body'], 'value="save_here"'));
$dc->post("/types/typedef/$wtName", ['csrf_token' => $dc->token("/types/typedef/$wtName"), 'action' => 'save_here', 'version' => 2, 'text' => $edit('NOT ALLOWED'), 'note' => '']);
check('released: a released version never changes', $wtCount() === 2 && json_decode($wtText(2), true)['title'] === 'V2');
$db->exec("DELETE FROM source_versions WHERE source_id = " . (int)$wtSrc['id']); $db->exec("DELETE FROM sources WHERE id = " . (int)$wtSrc['id']);

// ---- shared components: Used by and "Update pin" (plan §6.3), on a throw-away component and type
$cSrc = source_ensure('shared/wt-comp', 'shared', 'types/shared/wt-comp.json');
$comp = fn(string $t) => json_encode(['format' => 1, 'name' => 'wt-comp', 'label' => 'WT', 'group' => 'wt-comp', 'blocks' => [['id' => 'a', 'text' => $t]]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
$c1 = source_add_version($cSrc, $comp('A one'), 'first', 'released', 'wt_doctor');
$pDef = json_decode($adeia, true); $pDef['name'] = 'wt-pin-test'; $pDef['shared'] = ['wt-comp' => $c1['sha256']];
foreach ($pDef['sections'] as &$ps) if (isset($ps['use'])) { $ps['use'] = 'wt-comp'; $ps['default_block'] = 'a'; } unset($ps);
$pSrc = source_ensure('typedef/wt-pin-test', 'typedef', 'types/wt-pin-test.json');
source_add_version($pSrc, json_encode($pDef, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", 'first', 'released', 'wt_doctor');
$ub = $dc->get('/types/shared/wt-comp')['body'];
check('used by: the type that pins the component is listed, no update offered while it is current', str_contains($ub, 'wt-pin-test') && !str_contains($ub, 'form="pin-wt-pin-test"'));
$c2 = source_add_version($cSrc, $comp('A two'), 'second', 'released', 'wt_doctor');
$ub = $dc->get('/types/shared/wt-comp')['body'];
check('used by: a newer release offers "Update pin"', str_contains($ub, 'form="pin-wt-pin-test"'));
$dc->post('/types/typedef/wt-pin-test', ['csrf_token' => $dc->token('/types/shared/wt-comp'), 'action' => 'update_pin', 'component' => 'wt-comp', 'back' => '/types/shared/wt-comp']);
$pv = $db->query("SELECT version, status, note, sha256 FROM source_versions WHERE source_id = " . (int)$pSrc['id'] . " ORDER BY version DESC")->fetchAll(PDO::FETCH_ASSOC);
$pNew = json_decode(store_get($pv[0]['sha256']), true); $pOld = json_decode(store_get($pv[1]['sha256'] ?? $pv[0]['sha256']), true);
$pOld['shared']['wt-comp'] = $c2['sha256'];
check('update pin: a new draft version of the type with the new hash, nothing else changed', count($pv) === 2 && $pv[0]['status'] === 'draft'
    && $pNew['shared']['wt-comp'] === $c2['sha256'] && $pNew == $pOld && str_starts_with($pv[0]['note'], 'Update wt-comp to v2'), json_encode([$pv[0]['note'] ?? '', count($pv)]));
foreach ([$pSrc, $cSrc] as $x) { $db->exec("DELETE FROM source_versions WHERE source_id = " . (int)$x['id']); $db->exec("DELETE FROM sources WHERE id = " . (int)$x['id']); }

// ---- the archive seeder runs when called from the app root with a relative path (it once did nothing: the boot changes folder)
$seedTmp = sys_get_temp_dir() . '/ms-seed-' . bin2hex(random_bytes(4)); mkdir($seedTmp);
file_put_contents("$seedTmp/x.tex", "\\providecommand\\PatientName{Δοκιμή Τεστ}\n\\begin{document}\nΗ θεραπεία συνεχίζεται κανονικά για δύο εβδομάδες.\n\\end{document}\n");
$seedOut = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php bin/seed_archive.php ' . escapeshellarg(substr($seedTmp, 0)) . ' --dry-run 2>&1');
check('archive seeder: a dry run reads the letters and reports', str_contains((string)$seedOut, 'would seed 1 new text use(s) from 1 letter(s)'), trim((string)$seedOut));
$seedBad = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php bin/seed_archive.php no/such/folder --dry-run 2>&1; echo rc=$?');
check('archive seeder: a missing folder is reported', str_contains((string)$seedBad, 'not a folder') && str_contains((string)$seedBad, 'rc=2'));
unlink("$seedTmp/x.tex"); rmdir($seedTmp);

// ---- clean up (also run by the shutdown handler when the script dies on the way)
foreach ($docs as $d) { issue_prune_builds($d, [], ['preview', 'issue', 'test', 'rebuild']); $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$d]); }
wt_cleanup(); $cleaned = true;
proc_terminate($srv); proc_close($srv);
echo $fail ? "\n$fail check(s) failed\n" : "\nWeb checks passed\n";
exit($fail ? 1 : 0);
