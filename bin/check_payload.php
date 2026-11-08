#!/usr/bin/env php
<?php
/**
 * Composer payload test (docs/plans/composer-ui.md §3, §8): the redesigned composer must post exactly what the old
 * one posts. For each test type a draft is made, the composer page is read the way a browser submits it (every named
 * control of the form, checked boxes and radios only, the selected option), and the stored inputs after submitting
 * the classic page (`?ui=classic`) and the default page must be identical: unchanged, and with the same user change
 * applied to both (a sentence edited, a choice changed, a value filled, a medication row added, a case feature added
 * and removed). Real HTTP through `php -S` with a temporary doctor account, removed afterwards.
 *   php bin/check_payload.php [type...]
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
$U = 'wt_payload';
function wp_cleanup(): void {
    global $U; $db = store_db(); $q = $db->quote($U);
    foreach ($db->query("SELECT id FROM documents WHERE cuser = $q")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        issue_prune_builds((int)$d, [], ['preview', 'issue', 'test', 'rebuild']); $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$d]);
    }
    $db->exec("DELETE FROM audit_log WHERE cuser = $q");
    $db->exec("DELETE ur FROM user_roles ur JOIN users us ON us.id = ur.user_id WHERE us.uname = $q");
    $db->exec("DELETE FROM users WHERE uname = $q"); $db->exec("DELETE FROM user_tokens WHERE uname = $q");
}
wp_cleanup();
$pw = 'T3st-' . bin2hex(random_bytes(6));
$db->prepare('INSERT INTO users (name, email, uname, upass, cdate, active, expired, wrongpasscount, roles) VALUES (?,?,?,?,NOW(),1,0,0,?)')->execute([$U, "$U@localhost", $U, password_hash($pw, PASSWORD_DEFAULT), 'doctor']);
$uid = (int)$db->lastInsertId();
$rid = (int)$db->query("SELECT id FROM roles WHERE name = 'doctor'")->fetchColumn();
$db->prepare('INSERT INTO user_roles (guid, cuser, user_id, role_id) VALUES (?,?,?,?)')->execute([store_uuid(), 'webtest', $uid, $rid]);
$cleaned = false;
register_shutdown_function(function () use (&$cleaned) { if (!$cleaned) { echo "\n(cleaning up after a failure)\n"; wp_cleanup(); } });

$port = random_int(20000, 40000);
$root = dirname(__DIR__);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$root/web", "$root/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = "http://127.0.0.1:$port";

$jar = tempnam(sys_get_temp_dir(), 'wpjar');
function req(string $method, string $path, string $body = ''): array {
    global $base, $jar;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $raw = (string)curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    preg_match('/^location:\s*(.+?)\r?$/mi', substr($raw, 0, $info['header_size']), $m);
    return ['status' => $info['http_code'], 'body' => substr($raw, $info['header_size']), 'location' => $m[1] ?? null];
}
function enc(array $pairs): string { return implode('&', array_map(fn($p) => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), $pairs)); }
function token(string $path): string { preg_match('/name="csrf_token" value="([^"]+)"/', req('GET', $path)['body'], $m); return $m[1] ?? ''; }

/** What a browser submits for the composer form: [[name, value], ...] in document order (no submit button). */
function form_pairs(string $html): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $form = $xp->query('//form[contains(concat(" ", normalize-space(@class), " "), " composer-form ")]')->item(0);
    if (!$form) return [];
    $out = [];
    foreach ($xp->query('.//input | .//select | .//textarea', $form) as $el) {
        $name = $el->getAttribute('name');
        if ($name === '' || $el->hasAttribute('disabled') || $xp->query('ancestor::fieldset[@disabled]', $el)->length) continue;
        $tag = strtolower($el->nodeName);
        if ($tag === 'textarea') { $out[] = [$name, $el->textContent]; continue; }
        if ($tag === 'select') {
            $sel = null; $first = null;
            foreach ($xp->query('.//option', $el) as $o) { $first ??= $o; if ($o->hasAttribute('selected')) $sel = $o; }
            $o = $sel ?? $first;
            if ($o) $out[] = [$name, $o->hasAttribute('value') ? $o->getAttribute('value') : trim($o->textContent)];
            continue;
        }
        $type = strtolower($el->getAttribute('type') ?: 'text');
        if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) continue;
        if (in_array($type, ['checkbox', 'radio'], true)) { if ($el->hasAttribute('checked')) $out[] = [$name, $el->hasAttribute('value') ? $el->getAttribute('value') : 'on']; continue; }
        $out[] = [$name, $el->getAttribute('value')];
    }
    return $out;
}
function set_pair(array $pairs, string $name, string $value): array {
    foreach ($pairs as $i => $p) if ($p[0] === $name) { $pairs[$i][1] = $value; return $pairs; }
    $pairs[] = [$name, $value]; return $pairs;
}
function stored(int $id): array { return json_decode(store_db()->query("SELECT inputs FROM documents WHERE id = $id")->fetchColumn(), true) ?: []; }
function restore(int $id, array $in): void { doc_save_inputs($id, $in); }
/** Submit the page at $ui after applying $change to what it would post; returns the stored inputs. */
function submit(int $id, string $ui, ?callable $change = null, string $action = 'save', array $extra = []): array {
    $path = "/documents/$id" . ($ui === 'classic' ? '?ui=classic' : '');
    $page = req('GET', $path)['body'];
    $pairs = form_pairs($page);
    if ($change) $pairs = $change($pairs);
    foreach ($extra as $k => $v) $pairs = set_pair($pairs, $k, $v);
    $pairs[] = ['action', $action];
    $r = req('POST', "/documents/$id", enc($pairs));
    if ($r['status'] !== 302) echo "      (POST answered {$r['status']})\n";
    return stored($id);
}
/** Key order does not matter in the stored JSON (a case feature's undo writes keys back in its own order). */
function canon($v) { if (!is_array($v)) return $v; if (!array_is_list($v)) ksort($v); return array_map('canon', $v); }
function names(array $pairs): array { $n = array_unique(array_column($pairs, 0)); sort($n); return $n; }

// log in
$r = req('POST', '/login', enc([['csrf_token', token('/login')], ['username', $U], ['password', $pw]]));
check('the test doctor logs in', $r['status'] === 302 && !str_contains((string)$r['location'], '/login'));

$types = array_slice($argv, 1) ?: ['odigies-kyfoplastiki', 'proegkrisi', 'syntagi', 'recommendation-en', 'epistoli'];
$all = array_column(doc_types(), null, 'name');
foreach ($types as $t) {
    if (!isset($all[$t])) { check("$t: a released type", false); continue; }
    $r = req('POST', '/documents/new', enc([['csrf_token', token('/documents/new')], ['type', $t], ['patient[name]', "WP $t"], ['patient[gender]', 'F'], ['patient[age]', '61'], ['clinic', 'Mandra'], ['date', '2026-03-10']]));
    preg_match('#/documents/(\d+)$#', (string)$r['location'], $m); $id = (int)($m[1] ?? 0);
    check("$t: a draft is created", $id > 0);
    if (!$id) continue;
    $type = doc_typedef((int)$db->query("SELECT typedef_version_id FROM documents WHERE id = $id")->fetchColumn());

    $c = form_pairs(req('GET', "/documents/$id?ui=classic")['body']);
    $n = form_pairs(req('GET', "/documents/$id")['body']);
    check("$t: both pages post the same field names", names($c) === names($n), implode(' ', array_diff(names($c), names($n))) . ' / ' . implode(' ', array_diff(names($n), names($c))));

    $a = submit($id, 'classic');
    $a2 = submit($id, 'classic');
    check("$t: an unchanged classic save is stable", $a === $a2);
    $b = submit($id, 'new');
    check("$t: unchanged, the new page stores the same inputs", $a === $b);

    // the same user change, applied to what each page posts
    $changes = [];
    foreach (render_expand_sections($type) as $sec) foreach (array_merge([null], $sec['blocks'] ?? []) as $bl) {
        if ($bl === null) {
            if (!isset($changes['meds']) && ($sec['layout'] ?? '') === 'medtable')
                $changes['meds'] = fn($p) => array_merge($p, [["meds[{$sec['id']}][990][name]", 'Τεστ'], ["meds[{$sec['id']}][990][dose]", '5mg'], ["meds[{$sec['id']}][990][freq]", "1x1{br}2x1"], ["meds[{$sec['id']}][990][days]", '7'], ["meds[{$sec['id']}][990][notes]", ''], ["meds[{$sec['id']}][991][name]", '']]);
            continue;
        }
        if (!isset($changes['edit']) && ($bl['format'] ?? 'markup') !== 'latex')
            $changes['edit'] = fn($p) => set_pair($p, "btext[{$bl['id']}]", rtrim(array_values(array_filter($p, fn($x) => $x[0] === "btext[{$bl['id']}]"))[0][1] ?? $bl['text']) . ' ΤΕΣΤ ΑΛΛΑΓΗΣ');
        if (!isset($changes['choice']) && isset($bl['group']))
            $changes['choice'] = fn($p) => set_pair($p, "groups[{$bl['group']}]", $bl['id'] === (array_values(array_filter($p, fn($x) => $x[0] === "groups[{$bl['group']}]"))[0][1] ?? '') ? '' : $bl['id']);
        if (!isset($changes['toggle']) && !isset($bl['group']))
            $changes['toggle'] = fn($p) => in_array("blocks_on[{$bl['id']}]", array_column($p, 0), true)
                ? array_values(array_filter($p, fn($x) => $x[0] !== "blocks_on[{$bl['id']}]")) : array_merge($p, [["blocks_on[{$bl['id']}]", '1']]);
    }
    foreach (($type['fields'] ?? []) as $fid => $f) {
        $changes['value'] = fn($p) => set_pair($p, "fields[$fid]", ($f['type'] ?? 'text') === 'date' ? '2026-02-03' : 'ΤΙΜΗ ΤΕΣΤ');
        break;
    }
    foreach ($changes as $what => $fn) {
        restore($id, $a); $x = submit($id, 'classic', $fn);
        restore($id, $a); $y = submit($id, 'new', $fn);
        check("$t: $what, the same inputs from both pages", $x === $y && $x !== $a);
        if ($what === 'meds') {
            $rows = array_merge(...array_values($y['meds'] ?? [[]]));
            check("$t: the new medication row is kept, the empty one dropped, {br} kept", $rows && end($rows)['freq'] === '1x1{br}2x1' && end($rows)['name'] === 'Τεστ');
        }
    }
    foreach (array_keys($type['features'] ?? []) as $fid) {
        foreach (['classic', 'new'] as $ui) {
            restore($id, $a);
            $on = submit($id, $ui, null, 'feature_add', ['feature' => $fid]);
            $off = submit($id, $ui, null, 'feature_remove', ['feature' => $fid]);
            $strip = fn($in) => canon(array_filter(array_diff_key($in, ['features' => 1, 'feature_undo' => 1]), fn($v) => $v !== []));   // an empty extra: [] is the same as none
            check("$t: feature $fid added and removed from the $ui page restores the choices", in_array($fid, $on['features'] ?? [], true) && $strip($off) === $strip($a),
                  getenv("VERBOSE") ? json_encode(array_filter(array_map(fn($k) => ($strip($off)[$k] ?? null) === ($strip($a)[$k] ?? null) ? null : [$k, $strip($off)[$k] ?? null, $strip($a)[$k] ?? null], array_keys($strip($off) + $strip($a)))), JSON_UNESCAPED_UNICODE) : "");
            $res[$ui] = [$on, $off];
        }
        check("$t: feature $fid, both pages store the same", $res['classic'] === $res['new']);
        break;
    }
}

wp_cleanup(); $cleaned = true;
@unlink($jar);
proc_terminate($srv); proc_close($srv);
echo $fail ? "\n$fail check(s) failed\n" : "\nPayload checks passed\n";
exit($fail ? 1 : 0);
