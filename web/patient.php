<?php

/**
 * Patient data helpers (pure functions, unit-tested in tests/static/patient_test.php) and the
 * snapshot a document keeps (PLAN.md §9, D23): what was printed, with the ZPMS guid, never synced back.
 */

/** Whole years between a date of birth and a date (both YYYY-MM-DD); null when either is invalid or the order is wrong. */
function patient_age_at(?string $dobIso, ?string $atIso): ?int {
    $dob = $dobIso ? DateTime::createFromFormat('!Y-m-d', $dobIso) : false;
    $at = $atIso ? DateTime::createFromFormat('!Y-m-d', $atIso) : false;
    if (!$dob || !$at || $dob->format('Y-m-d') !== $dobIso || $at->format('Y-m-d') !== $atIso || $dob > $at) return null;
    return (int)$dob->diff($at)->y;
}

/**
 * Check one patient row from ZPMS field by field; returns the clean row or null.
 * Accepts {guid, name, dob?, gender?, amka?}; anything malformed makes the whole row unusable,
 * and optional fields that are present but malformed are dropped to null (never trusted).
 */
function patient_clean_zpms_row($row): ?array {
    if (!is_array($row)) return null;
    $guid = $row['guid'] ?? null;
    $name = $row['name'] ?? null;
    if (!is_string($guid) || !preg_match('/^[0-9a-fA-F-]{36}$/', $guid)) return null;
    if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255) return null;
    $dob = $row['dob'] ?? null;
    $gender = $row['gender'] ?? null;
    $amka = $row['amka'] ?? null;
    return [
        'guid'   => strtolower($guid),
        'name'   => trim($name),
        'dob'    => (is_string($dob) && patient_age_at($dob, '9999-12-31') !== null) ? $dob : null,
        'gender' => in_array($gender, ['M', 'F'], true) ? $gender : null,
        'amka'   => (is_string($amka) && preg_match('/^\d{11}$/', $amka)) ? $amka : null,
    ];
}

/** The document's patient block from a ZPMS patient: what gets printed, age at the document date. */
function patient_snapshot_from_zpms(array $p, string $docDateIso): array {
    $age = patient_age_at($p['dob'] ?? null, $docDateIso);
    return [
        'name'   => $p['name'],
        'gender' => $p['gender'] ?? '',
        'age'    => $age === null ? '' : (string)$age,
        'amka'   => $p['amka'] ?? '',
        'guid'   => $p['guid'],
        'dob'    => $p['dob'] ?? '',
    ];
}
