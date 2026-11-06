<?php
// Unit tests of the pure parts of web/pool.php (swap on insertion). Run by tests/run_all.php.
require_once __DIR__ . '/../../web/render.php';
require_once __DIR__ . '/../../web/pool.php';
$fail = 0; $n = 0;
function eq($got, $want, string $label): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL  $label\n      got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n      want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$v = ['patient' => 'Ιωάννου Γεώργιος', 'age' => '45', 'amka' => '12345678901', 'surgery_date' => '2026-01-10', 'level' => 'Ο5-Ι1', 'procedure' => 'μικροδισκεκτομή'];
$t = ['surgery_date' => 'date'];
eq(pool_tokenize('Ο κ. Ιωάννου Γεώργιος, μετά τη μικροδισκεκτομή Ο5-Ι1 στις 10/01/2026', $v, $t), 'Ο κ. @patient, μετά τη @procedure @level στις @surgery_date', 'name, field values and a date become tokens');
eq(pool_tokenize('10-01-2026 και 10/01/2026', $v, $t), '@surgery_date και @surgery_date', 'both date formats');
eq(pool_tokenize('ηλικίας 45 ετών', $v, $t), 'ηλικίας 45 ετών', 'a short value (the age) is left alone: too ambiguous');
eq(pool_tokenize('ΑΜΚΑ 12345678901', $v, $t), 'ΑΜΚΑ @amka', 'AMKA');
eq(pool_tokenize('μικροδισκεκτομήσεις', $v, $t), 'μικροδισκεκτομήσεις', 'only whole words');
eq(pool_tokenize('x', ['patient' => ''], []), 'x', 'empty values are skipped');
eq(pool_tokenize('Ιωάννου Γεώργιος Ιωάννου', ['patient' => 'Ιωάννου Γεώργιος', 'x' => 'Ιωάννου'], []), '@patient @x', 'the longest value wins first');

$type = ['sections' => [
    ['id' => 'a', 'slot' => 'care.wound', 'layout' => 'paragraphs', 'blocks' => [['id' => 'b1', 'text' => 'Default', 'default' => true], ['id' => 'b2', 'text' => 'Other', 'default' => true]]],
    ['id' => 'l', 'slot' => 'care.items', 'layout' => 'itemize', 'blocks' => []],
    ['id' => 'o', 'slot' => 'care.opt', 'layout' => 'paragraphs', 'optional' => true, 'default' => false, 'blocks' => [['id' => 'b3', 'text' => 'Opt', 'default' => true]]]]];
$inputs = ['blocks' => ['b1' => ['text' => 'Edited default'], 'b2' => ['text' => 'Other']],
           'extra' => ['a' => [['text' => "Free one\n\nFree two"]], 'l' => [['text' => "item a\nitem b"]], 'o' => [['text' => 'in an off section']]]];
eq(pool_authored($type, $inputs), ['care.wound' => ['Edited default', 'Free one', 'Free two'], 'care.items' => ['item a', 'item b']],
   'authored text: edited blocks (not unchanged ones), free paragraphs, list lines; nothing from an off section');
echo $fail ? "$fail of $n pool tests FAILED\n" : '';
exit($fail ? 1 : 0);
