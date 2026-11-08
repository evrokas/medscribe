#!/usr/bin/env php
<?php
/**
 * Dev tool: screenshots of the app as a temporary doctor (or secretary), with made-up sample drafts; everything is
 * removed afterwards. Real HTTP through `php -S`, the browser through tools/shot.js.
 *   php tools/shots.php [--size=1440x1000] [--role=doctor] [--make=type1,type2] <path>=<out.png> ...
 * In a path, {type} stands for the id of the sample draft made for that type (e.g. /documents/{proegkrisi}).
 */
require_once(__DIR__ . '/../bin/lib/cli_boot.php');
require_once(__DIR__ . '/../web/render.php');
require_once(__DIR__ . '/../web/docarc_client.php');
require_once(__DIR__ . '/../web/audit.php');
require_once(__DIR__ . '/../web/documents.php');
require_once(__DIR__ . '/../web/pool.php');
require_once(__DIR__ . '/../web/issue.php');

$opt = []; $jobs = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)=(.*)$/', $a, $m)) $opt[$m[1]] = $m[2]; elseif (($k = strrpos($a, "=")) !== false) $jobs[] = [substr($a, 0, $k), substr($a, $k + 1)];   // the last = (a path may hold ?f=…)
}
$size = $opt['size'] ?? '1440x1000'; $role = ($opt['role'] ?? 'doctor') === 'secretary' ? 'secretary' : 'doctor';
$make = array_filter(explode(',', $opt['make'] ?? ''));
$U = 'wt_shots';
function ws_cleanup(): void {
    global $U; $db = store_db(); $q = $db->quote($U);
    foreach ($db->query("SELECT id FROM documents WHERE cuser = $q")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        issue_prune_builds((int)$d, [], ['preview', 'issue', 'test', 'rebuild']); $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$d]);
    }
    $db->exec("DELETE FROM audit_log WHERE cuser = $q");
    $db->exec("DELETE ur FROM user_roles ur JOIN users us ON us.id = ur.user_id WHERE us.uname = $q");
    $db->exec("DELETE FROM users WHERE uname = $q"); $db->exec("DELETE FROM user_tokens WHERE uname = $q");
}
ws_cleanup();
register_shutdown_function('ws_cleanup');
$db = store_db();
$pw = 'T3st-' . bin2hex(random_bytes(6));
$db->prepare('INSERT INTO users (name, email, uname, upass, cdate, active, expired, wrongpasscount, roles) VALUES (?,?,?,?,NOW(),1,0,0,?)')->execute(['Δρ. Δοκιμή', "$U@localhost", $U, password_hash($pw, PASSWORD_DEFAULT), $role]);
$uid = (int)$db->lastInsertId();
$db->prepare('INSERT INTO user_roles (guid, cuser, user_id, role_id) VALUES (?,?,?,?)')->execute([store_uuid(), 'webtest', $uid, (int)$db->query("SELECT id FROM roles WHERE name = '$role'")->fetchColumn()]);

$port = random_int(20000, 40000); $root = dirname(__DIR__);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$root/web", "$root/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = "http://127.0.0.1:$port"; $jar = tempnam(sys_get_temp_dir(), 'wsjar');
function req(string $path, ?array $post = null): array {
    global $base, $jar;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string)curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^location:\s*(.+?)\r?$/mi', substr($raw, 0, $hs), $m);
    return ['body' => substr($raw, $hs), 'location' => $m[1] ?? ''];
}
function tok(string $p): string { preg_match('/name="csrf_token" value="([^"]+)"/', req($p)['body'], $m); return $m[1] ?? ''; }
req('/login', ['csrf_token' => tok('/login'), 'username' => $U, 'password' => $pw]);
$ids = [];
$names = ['Μαρία Παπαδοπούλου', 'Γιώργος Νικολάου', 'Ελένη Κωνσταντίνου', 'Νίκος Αλεξίου', 'Άννα Δημητρίου'];
foreach ($make as $i => $t) {
    $r = req('/documents/new', ['csrf_token' => tok('/documents/new'), 'type' => $t, 'patient' => ['name' => $names[$i % 5], 'gender' => $i % 2 ? 'M' : 'F', 'age' => (string)(48 + 3 * $i)], 'clinic' => 'Mandra', 'date' => date('Y-m-d')]);
    if (preg_match('#/documents/(\d+)$#', $r['location'], $m)) $ids[$t] = $m[1];
}
$sess = '';
foreach (file($jar) as $l) { $f = explode("\t", trim($l)); if (count($f) >= 7) $sess = $f[5] . '=' . $f[6]; }
$args = ['node', __DIR__ . '/shot.js', $sess, $size];
foreach ($jobs as [$p, $out]) { $args[] = $base . preg_replace_callback('/\{([\w-]+)\}/', fn($m) => $ids[$m[1]] ?? '0', $p); $args[] = $out; }
if ($jobs) passthru(implode(' ', array_map('escapeshellarg', $args)));
@unlink($jar);
proc_terminate($srv); proc_close($srv);
