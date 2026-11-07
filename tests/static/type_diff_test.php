<?php
// The structural diff of type versions (web/type_diff.php), on a real type with known edits.
require_once __DIR__ . '/../../web/type_diff.php';
$fail = 0; $n = 0;
function eq($got, $want, string $label): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL  $label\n  got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n  want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; } }
$a = json_decode(file_get_contents(__DIR__ . '/../../types/odigies-kyfoplastiki.json'), true);
$flat = function (array $d) { $o = []; foreach ($d['groups'] as $g) foreach ($g['items'] as $i) $o[] = $g['title'] . ' | ' . $i['op'] . ' ' . $i['what'] . ' ' . $i['id']; return $o; };

eq(types_struct_diff($a, $a), ['groups' => [], 'count' => 0], 'no change, no items');

// one block edited, one moved within its topic, one moved to another topic, one added, a setting changed
$b = $a;
$b['sections'][2]['topics'][0]['blocks'][0]['text'] .= ' Νέο.';
$x = $b['sections'][2]['topics'][1]['blocks'][0]; $b['sections'][2]['topics'][1]['blocks'][0] = $b['sections'][2]['topics'][1]['blocks'][1]; $b['sections'][2]['topics'][1]['blocks'][1] = $x;
$mv = array_pop($b['sections'][2]['topics'][2]['blocks']); $b['sections'][2]['topics'][3]['blocks'][] = $mv;
$b['sections'][2]['topics'][3]['blocks'][] = ['id' => 'new_b', 'text' => 'Καινούργια @level.', 'default' => true];
$b['title'] = 'Οδηγίες';
$d = types_struct_diff($a, $b);
$t0 = $a['sections'][2]['topics'][1]['blocks'][0]['id'];
eq($flat($d), [
  'Document settings | changed setting title',
  'Γενικές Οδηγίες | changed block ' . $a['sections'][2]['topics'][0]['blocks'][0]['id'],
  'Γενικές Οδηγίες | moved block ' . $mv['id'],
  'Γενικές Οδηγίες | added block new_b',
  'Γενικές Οδηγίες | moved block ' . $t0,
], 'edit, move inside a topic, move to another topic, add, setting');
$ch = $d['groups'][1]['items'][0];
eq([$ch['text'][1], $ch['keys']], [$b['sections'][2]['topics'][0]['blocks'][0]['text'], []], 'a changed text carries old and new, no other key');
eq($d['groups'][1]['items'][1]['note'], 'from «Γενικές Οδηγίες › ' . $a['sections'][2]['topics'][2]['title'] . '»', 'a block moved to another topic says from where');
eq($d['groups'][1]['items'][2]['label'], 'Καινούργια [Επίπεδο].', 'labels are plain text with field labels');

// a field renamed (as the editor does it): the field is removed + added, and its uses change
$c = json_decode(str_replace(['"bedrest_days"', '@bedrest_days'], ['"bed_rest_days"', '@bed_rest_days'], json_encode($a, JSON_UNESCAPED_UNICODE)), true);
$fc = $flat(types_struct_diff($a, $c));
eq([in_array('Fields | renamed field bed_rest_days', $fc, true), count(array_filter($fc, fn($l) => str_contains($l, 'changed block'))), count($fc)],
   [true, 1, 2], 'a field rename: one "renamed" item, one block text changed, nothing else (' . implode('; ', $fc) . ')');

// a section removed, a section moved, a topic removed
$e = $a; $gone = array_splice($e['sections'], 9, 1)[0]; $s4 = array_splice($e['sections'], 4, 1)[0]; array_unshift($e['sections'], $s4); array_pop($e['sections'][3]['topics']);
$fe = $flat(types_struct_diff($a, $e));
eq([in_array('Removed sections | removed section ' . $gone['id'], $fe, true), in_array('Επανέλεγχοι | moved section ' . $s4['id'], $fe, true),
    count(array_filter($fe, fn($l) => str_contains($l, 'removed topic'))), count(array_filter($fe, fn($l) => str_contains($l, 'removed block')))],
   [true, true, 1, 0], 'removed section (its blocks not listed one by one), moved section, removed topic');

// word diff
eq(types_word_diff('Παραμείνετε κλινήρης για 3 ημέρες.', 'Παραμείνετε ξαπλωμένοι για 3 ημέρες.'),
   [['op' => '=', 't' => 'Παραμείνετε '], ['op' => '-', 't' => 'κλινήρης'], ['op' => '+', 't' => 'ξαπλωμένοι'], ['op' => '=', 't' => ' για 3 ημέρες.']], 'word diff');
eq(types_moved(['a', 'b', 'c', 'd'], ['b', 'c', 'd', 'a']), ['a'], 'one item moved to the end: only it is "moved"');
eq(types_section_name(['layout' => 'paragraphs', 'blocks' => [['text' => '**Μην** οδηγείτε {μόνος|μόνη} @x.']]], ['fields' => ['x' => ['label' => 'X']]]), '«Μην οδηγείτε μόνος [X].»', 'section name: the editor\'s rule');

// a shared component
$p = json_decode(file_get_contents(__DIR__ . '/../../types/shared/purpose.json'), true); $q = $p;
$q['blocks'][0]['text'] = 'Άλλο κείμενο.'; array_pop($q['blocks']);
eq($flat(types_struct_diff($p, $q, 'shared')), ['Sentences | changed entry ' . $p['blocks'][0]['id'], 'Sentences | removed entry ' . end($p['blocks'])['id']], 'a shared component: changed and removed sentences');

echo $fail ? "$fail of $n type diff tests FAILED\n" : '';
exit($fail ? 1 : 0);
