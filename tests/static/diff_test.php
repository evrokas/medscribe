<?php
// Unit tests of web/diff.php. Run by tests/run_all.php.
require_once __DIR__ . '/../../web/diff.php';
$fail = 0; $n = 0;
function eq($got, $want, string $label): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL  $label\n      got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n      want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$fmt = fn(array $d) => implode('|', array_map(fn($o) => $o['op'] . $o['text'], $d));
eq($fmt(diff_lines("a\nb\nc\n", "a\nb\nc\n")), ' a| b| c', 'identical');
eq($fmt(diff_lines("a\nb\nc\n", "a\nX\nc\n")), ' a|-b|+X| c', 'one line changed');
eq($fmt(diff_lines("a\nc\n", "a\nb\nc\n")), ' a|+b| c', 'a line added');
eq($fmt(diff_lines("a\nb\nc\n", "a\nc\n")), ' a|-b| c', 'a line removed');
eq($fmt(diff_lines('', "x\n")), '+x', 'from empty');
eq($fmt(diff_lines("x\n", '')), '-x', 'to empty');
eq($fmt(diff_lines("Ελληνικά\nγραμμή\n", "Ελληνικά\nαλλαγή\n")), ' Ελληνικά|-γραμμή|+αλλαγή', 'Greek text');
eq($fmt(diff_lines("1\n2\n3\n4\n5\n6\n7\n8\n", "1\n2\n3\nX\n5\n6\n7\n8\n")), ' 1| 2| 3|-4|+X| 5| 6| 7| 8', 'a change in the middle');
eq($fmt(diff_compact(diff_lines("1\n2\n3\n4\n5\n6\n7\n8\n9\n10\n", "1\n2\n3\n4\nX\n6\n7\n8\n9\n10\n"), 1)), ' 4|-5|+X| 6', 'compact keeps the context');
eq($fmt(diff_compact(diff_lines("1\n2\n3\n4\n5\n6\n7\n8\n9\n", "X\n2\n3\n4\n5\n6\n7\n8\nY\n"), 1)), '-1|+X| 2|@…| 8|-9|+Y', 'two hunks are separated');
echo $fail ? "$fail of $n diff tests FAILED\n" : '';
exit($fail ? 1 : 0);
