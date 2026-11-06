<?php

/**
 * Client for DocArc's MedScribe API (DocArc: includes/api_medscribe.php). Family pattern: server to
 * server, key in a header, fail closed. Every call returns
 *   ['ok' => bool, 'status' => int (0 = no answer), 'json' => ?array, 'error' => ?string]
 * and never throws, so the issue flow (web/issue.php) can tell a retryable failure (status 0, 5xx) from a
 * refusal (4xx) and resume where it stopped. Lookups time out after 3 s, uploads after DOCARC_UPLOAD_TIMEOUT.
 * Without config/docarc_api.php (or with a blank key) the integration is off and every call fails with 'off'.
 */

if (is_file(__DIR__ . '/../config/docarc_api.php')) {
    include_once __DIR__ . '/../config/docarc_api.php';
}

function docarc_config(?array $cfg = null): ?array {
    $url = $cfg['url'] ?? (defined('DOCARC_API_URL') ? DOCARC_API_URL : '');
    $key = $cfg['key'] ?? (defined('DOCARC_API_KEY') ? DOCARC_API_KEY : '');
    $up = $cfg['upload_timeout'] ?? (defined('DOCARC_UPLOAD_TIMEOUT') ? DOCARC_UPLOAD_TIMEOUT : 30);
    if (!is_string($url) || !is_string($key) || $url === '' || $key === '' || str_contains($url, '<<') || str_contains($key, '<<')) return null;
    if (!preg_match('#^https?://#i', $url)) return null;
    return ['url' => rtrim($url, '/'), 'key' => $key, 'upload_timeout' => max(3, (int)$up)];
}

function docarc_enabled(?array $cfg = null): bool {
    return docarc_config($cfg) !== null;
}

/**
 * One request. $opts: json (array body) | raw (string body), timeout (seconds, default 3).
 */
function docarc_call(string $method, string $path, array $opts = [], ?array $cfg = null): array {
    $c = docarc_config($cfg);
    if (!$c) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'off'];
    $headers = ['X-MedScribe-Api-Key: ' . $c['key'], 'Accept: application/json'];
    $ch = curl_init($c['url'] . '/api/v1/' . ltrim($path, '/'));
    $o = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 3), CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 2097152,
    ];
    if (isset($opts['json'])) { $o[CURLOPT_POSTFIELDS] = json_encode($opts['json'], JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
    elseif (isset($opts['raw'])) { $o[CURLOPT_POSTFIELDS] = $opts['raw']; $headers[] = 'Content-Type: application/octet-stream'; }
    $o[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $o);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) { error_log("[docarc-client] $method $path failed: $err"); return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'no answer: ' . $err]; }
    $json = json_decode((string)$body, true);
    $ok = $status >= 200 && $status < 300 && is_array($json);
    if (!$ok) error_log("[docarc-client] $method $path -> HTTP $status");
    return ['ok' => $ok, 'status' => $status, 'json' => is_array($json) ? $json : null,
            'error' => $ok ? null : (is_array($json) && isset($json['error']) ? (string)$json['error'] : "HTTP $status")];
}

/** DocArc's document types, as clean rows [id, code, label], or null on failure. */
function docarc_types(?array $cfg = null): ?array {
    $r = docarc_call('GET', 'types', [], $cfg);
    if (!$r['ok'] || !isset($r['json']['types']) || !is_array($r['json']['types'])) return null;
    $out = [];
    foreach ($r['json']['types'] as $t) {
        if (is_array($t) && is_int($t['id'] ?? null) && is_string($t['code'] ?? null) && is_string($t['label'] ?? null)) {
            $out[] = ['id' => $t['id'], 'code' => $t['code'], 'label' => $t['label']];
        }
    }
    return $out;
}

/** DocArc's clinics as [id, name], or null on failure. */
function docarc_clinics(?array $cfg = null): ?array {
    $r = docarc_call('GET', 'clinics', [], $cfg);
    if (!$r['ok'] || !isset($r['json']['clinics']) || !is_array($r['json']['clinics'])) return null;
    $out = [];
    foreach ($r['json']['clinics'] as $c) {
        if (is_array($c) && is_int($c['id'] ?? null) && is_string($c['name'] ?? null)) $out[] = ['id' => $c['id'], 'name' => $c['name']];
    }
    return $out;
}

/** Check a record state returned by DocArc; returns the clean record or null. */
function docarc_clean_record($rec): ?array {
    if (!is_array($rec) || !is_string($rec['token'] ?? null) || !preg_match('/^[A-Za-z0-9\-]{6,64}$/', $rec['token'])) return null;
    if (!is_string($rec['url'] ?? null) || !is_int($rec['document_type_id'] ?? null) || !is_bool($rec['has_document'] ?? null)) return null;
    return [
        'token' => $rec['token'], 'url' => $rec['url'], 'document_type_id' => $rec['document_type_id'],
        'has_document' => $rec['has_document'],
        'document_sha256' => is_string($rec['document']['sha256'] ?? null) ? $rec['document']['sha256'] : null,
        'supplementary' => array_values(array_filter(array_map(
            fn($f) => is_array($f) && is_string($f['filename'] ?? null) ? ['filename' => $f['filename'], 'sha256' => $f['sha256'] ?? null] : null,
            is_array($rec['supplementary'] ?? null) ? $rec['supplementary'] : []))),
    ];
}

/** Request a code: create (or fetch, by idempotency key) the empty record. Returns [result, clean record|null]. */
function docarc_create_record(array $payload, ?array $cfg = null): array {
    $r = docarc_call('POST', 'records', ['json' => $payload], $cfg);
    return [$r, $r['ok'] ? docarc_clean_record($r['json']['record'] ?? null) : null];
}

function docarc_get_record(string $token, ?array $cfg = null): array {
    $r = docarc_call('GET', 'records/' . rawurlencode($token), [], $cfg);
    return [$r, $r['ok'] ? docarc_clean_record($r['json']['record'] ?? null) : null];
}

function docarc_patch_record(string $token, array $fields, ?array $cfg = null): array {
    return docarc_call('PATCH', 'records/' . rawurlencode($token), ['json' => $fields], $cfg);
}

function docarc_put_document(string $token, string $pdf, string $filename, ?array $cfg = null): array {
    $c = docarc_config($cfg);
    return docarc_call('PUT', 'records/' . rawurlencode($token) . '/document?filename=' . rawurlencode($filename), ['raw' => $pdf, 'timeout' => $c['upload_timeout'] ?? 30], $cfg);
}

function docarc_put_supplementary(string $token, string $filename, string $bytes, string $description, ?array $cfg = null): array {
    $c = docarc_config($cfg);
    return docarc_call('POST', 'records/' . rawurlencode($token) . '/supplementary?filename=' . rawurlencode($filename) . '&description=' . rawurlencode($description),
        ['raw' => $bytes, 'timeout' => $c['upload_timeout'] ?? 30], $cfg);
}

/** mode 'empty' (abandoned draft) or 'all' (withdraw). A 404 counts as done: the record is gone either way. */
function docarc_delete_record(string $token, string $mode, ?array $cfg = null): array {
    $q = $mode === 'all' ? '?mode=all&confirm=' . rawurlencode($token) : '?mode=empty';
    $r = docarc_call('DELETE', 'records/' . rawurlencode($token) . $q, [], $cfg);
    if ($r['status'] === 404) $r['ok'] = true;
    return $r;
}
