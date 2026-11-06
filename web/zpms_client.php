<?php

/**
 * Client for ZPMS's patient API (see ../zpms/web/api_patients.php and ../zpms/CLAUDE.md). Same pattern
 * as the other family clients: server to server only, one static key in a header, short timeouts, and it
 * FAILS CLOSED: any network error, non-200 status or malformed answer returns null and is logged with
 * error_log(), never thrown. Each row is checked field by field (patient_clean_zpms_row()).
 * If config/zpms_api.php is absent or the key blank, the integration is off and every call returns null.
 */

require_once __DIR__ . '/patient.php';

if (is_file(__DIR__ . '/../config/zpms_api.php')) {
    include_once __DIR__ . '/../config/zpms_api.php';
}

/** Connection settings: from $cfg (tests) or the config constants; null when the integration is off. */
function zpms_config(?array $cfg = null): ?array {
    $url = $cfg['url'] ?? (defined('ZPMS_API_URL') ? ZPMS_API_URL : '');
    $key = $cfg['key'] ?? (defined('ZPMS_API_KEY') ? ZPMS_API_KEY : '');
    if (!is_string($url) || !is_string($key) || $url === '' || $key === '' || str_contains($url, '<<') || str_contains($key, '<<')) return null;
    if (!preg_match('#^https?://#i', $url)) return null;
    return ['url' => rtrim($url, '/'), 'key' => $key];
}

function zpms_enabled(?array $cfg = null): bool {
    return zpms_config($cfg) !== null;
}

/** GET a path; returns the decoded JSON array, or null on any failure. */
function zpms_get(string $path, ?array $cfg = null): ?array {
    $c = zpms_config($cfg);
    if (!$c) return null;
    $ch = curl_init($c['url'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['X-MedScribe-Api-Key: ' . $c['key'], 'Accept: application/json'],
        CURLOPT_MAXFILESIZE => 1048576,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) { error_log('[zpms-client] request failed: ' . $err); return null; }
    if ($status !== 200) { error_log("[zpms-client] HTTP $status"); return null; }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) { error_log('[zpms-client] malformed JSON'); return null; }
    return $json;
}

/** Search patients by name or AMKA (2+ characters). Returns clean rows [guid, name, amka] or null on failure. */
function zpms_search_patients(string $term, ?array $cfg = null): ?array {
    $term = trim($term);
    if (mb_strlen($term) < 2) return [];
    $json = zpms_get('/api/v1/patients/search/' . rawurlencode($term), $cfg);
    if ($json === null || !isset($json['matches']) || !is_array($json['matches'])) return null;
    $out = [];
    foreach ($json['matches'] as $row) {
        $p = patient_clean_zpms_row($row);
        if ($p) $out[] = ['guid' => $p['guid'], 'name' => $p['name'], 'amka' => $p['amka']];
    }
    return array_slice($out, 0, 10);
}

/** One patient by guid. Returns the clean row, or null (unknown patient, ZPMS down, off, or a malformed answer). */
function zpms_get_patient(string $guid, ?array $cfg = null): ?array {
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $guid)) return null;
    $json = zpms_get('/api/v1/patients/' . rawurlencode($guid), $cfg);
    if ($json === null) return null;
    $p = patient_clean_zpms_row($json['patient'] ?? null);
    // the answer must be about the patient we asked for
    return ($p && $p['guid'] === strtolower($guid)) ? $p : null;
}
