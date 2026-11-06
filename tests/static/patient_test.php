<?php
// Unit tests of web/patient.php (pure functions). Run by tests/run_all.php.
require_once __DIR__ . '/../../web/patient.php';
$fail = 0; $n = 0;
function eq($got, $want, string $label): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
// age at the document date, not today
eq(patient_age_at('1980-03-15', '2026-03-14'), 45, 'the day before the birthday');
eq(patient_age_at('1980-03-15', '2026-03-15'), 46, 'on the birthday');
eq(patient_age_at('2024-02-29', '2025-02-28'), 0, 'leap-day birth, before the 1st birthday');
eq(patient_age_at('2024-02-29', '2025-03-01'), 1, 'leap-day birth, after');
eq(patient_age_at('1980-03-15', '1979-01-01'), null, 'document before birth');
eq(patient_age_at('1980-02-31', '2026-01-01'), null, 'invalid date of birth');
eq(patient_age_at(null, '2026-01-01'), null, 'no date of birth');
eq(patient_age_at('1980-03-15', ''), null, 'no document date');

$g = 'AAAAAAAA-1111-4111-8111-AAAAAAAAAAAA';
eq(patient_clean_zpms_row(['guid' => $g, 'name' => ' Μαρία ', 'dob' => '1980-03-15', 'gender' => 'F', 'amka' => '22222222222']),
   ['guid' => strtolower($g), 'name' => 'Μαρία', 'dob' => '1980-03-15', 'gender' => 'F', 'amka' => '22222222222'], 'a good row is cleaned');
eq(patient_clean_zpms_row(['guid' => $g, 'name' => 'X', 'dob' => 'garbage', 'gender' => 'Z', 'amka' => '12']),
   ['guid' => strtolower($g), 'name' => 'X', 'dob' => null, 'gender' => null, 'amka' => null], 'malformed optional fields become null');
eq(patient_clean_zpms_row(['guid' => 'nope', 'name' => 'X']), null, 'bad guid rejected');
eq(patient_clean_zpms_row(['guid' => $g, 'name' => '']), null, 'empty name rejected');
eq(patient_clean_zpms_row(['guid' => $g, 'name' => ['x']]), null, 'non-string name rejected');
eq(patient_clean_zpms_row(['guid' => $g, 'name' => str_repeat('x', 300)]), null, 'overlong name rejected');
eq(patient_clean_zpms_row('str'), null, 'non-array rejected');

$p = patient_clean_zpms_row(['guid' => $g, 'name' => 'Μαρία', 'dob' => '1980-03-15', 'gender' => 'F', 'amka' => null]);
eq(patient_snapshot_from_zpms($p, '2026-01-15'), ['name' => 'Μαρία', 'gender' => 'F', 'age' => '45', 'amka' => '', 'guid' => strtolower($g), 'dob' => '1980-03-15'], 'snapshot with age at the document date');
$p['dob'] = null; $p['gender'] = null;
eq(patient_snapshot_from_zpms($p, '2026-01-15')['age'], '', 'no dob: age left empty');
echo $fail ? "$fail of $n patient tests FAILED\n" : '';
exit($fail ? 1 : 0);
