<?php
// Unit tests of web/ui.php (status words, section names). Run by tests/run_all.php.
if (!function_exists('t')) { function t($s) { return $s; } }
require_once __DIR__ . '/../../web/ui.php';
$fail = 0; $n = 0;
function eq($got, $want, string $label): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
// every status the code can set has a word (the issue flow's statuses are in web/issue.php and web/documents.php)
$src = file_get_contents(__DIR__ . '/../../web/issue.php') . file_get_contents(__DIR__ . '/../../web/documents.php');
preg_match_all('/issue_cas\(\$id, \[([^\]]*)\], .([a-z_]+)./', $src, $m);
preg_match_all("/'([a-z_]+)'/", implode(',', $m[1]), $from);
$seen = array_unique(array_merge($m[2], $from[1]));
eq(count($seen) >= 7, true, 'the transitions were found in web/issue.php');
foreach (array_merge($seen, ['draft', 'review', 'code_reserved', 'final_compiled', 'approved', 'pdf_sent', 'issued', 'withdrawn']) as $s)
    eq(isset(UI_STATUS_WORDS[$s]), true, "status $s has a word");
eq(ui_status_word('code_reserved'), 'Code reserved', 'a word');
eq(ui_status_pill('final_compiled'), '<span class="pill pill-final_compiled" title="final_compiled">Final PDF ready to approve</span>', 'a pill');
eq(ui_status_pill('<x>'), '<span class="pill pill-other" title="&lt;x&gt;">&lt;x&gt;</span>', 'an unknown status is escaped');
// section names: label, heading, medication table, first sentence; never slot or layout
$type = ['fields' => ['days' => ['label' => 'Ημέρες κλινήρης']]];
eq(ui_section_name(['label' => 'Λ', 'heading' => 'Η', 'slot' => 's'], $type), 'Λ', 'label first');
eq(ui_section_name(['heading' => 'Οδηγίες', 'slot' => 's'], $type), 'Οδηγίες', 'then heading');
eq(ui_section_name(['layout' => 'medtable', 'slot' => 'meds'], $type), 'Medication table', 'medication table');
eq(ui_section_name(['layout' => 'medtable', 'title_page' => 'Φάρμακα'], $type), 'Φάρμακα', 'medication table with a title');
eq(ui_section_name(['layout' => 'itemize', 'slot' => 'x.s3_t1', 'blocks' => [['format' => 'latex', 'text' => '\\x'], ['text' => '**Παραμείνετε** {κλινήρης|κλινήρης} για @days ημέρες.']]], $type),
   '«Παραμείνετε κλινήρης για [Ημέρες κλινήρης]…»', 'first markup sentence, markup removed, fields by label');
eq(ui_section_name(['blocks' => [['text' => str_repeat('λέξη ', 20)]]], $type), '«λέξη λέξη λέξη λέξη λέξη λέξη λέξη λέξη…»', 'long sentence cut at a word');
eq(ui_section_name(['slot' => 'x', 'layout' => 'paragraphs'], $type), 'Section', 'nothing to name it by');
echo $fail ? "$fail of $n failed\n" : "$n passed\n";
exit($fail ? 1 : 0);
