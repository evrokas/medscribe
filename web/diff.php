<?php

/**
 * Line diff between two texts (PLAN.md §5.2: "a text diff between any two versions of a source"),
 * longest-common-subsequence based; pure function, unit-tested in tests/static/diff_test.php.
 * Returns a list of ['op' => ' ' | '+' | '-', 'text' => line].
 */
function diff_lines(string $a, string $b): array {
    $x = $a === '' ? [] : explode("\n", rtrim($a, "\n"));
    $y = $b === '' ? [] : explode("\n", rtrim($b, "\n"));
    $n = count($x); $m = count($y);
    // trim the common head and tail so the table stays small for big files with a small change
    $head = 0;
    while ($head < $n && $head < $m && $x[$head] === $y[$head]) $head++;
    $tail = 0;
    while ($tail < $n - $head && $tail < $m - $head && $x[$n - 1 - $tail] === $y[$m - 1 - $tail]) $tail++;
    $xs = array_slice($x, $head, $n - $head - $tail);
    $ys = array_slice($y, $head, $m - $head - $tail);
    $p = count($xs); $q = count($ys);
    if ($p * $q > 4000000) throw new RuntimeException('the files are too different to diff here');
    $L = array_fill(0, $p + 1, array_fill(0, $q + 1, 0));
    for ($i = $p - 1; $i >= 0; $i--) for ($j = $q - 1; $j >= 0; $j--) {
        $L[$i][$j] = $xs[$i] === $ys[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    }
    $out = [];
    for ($i = 0; $i < $head; $i++) $out[] = ['op' => ' ', 'text' => $x[$i]];
    $i = $j = 0;
    while ($i < $p && $j < $q) {
        if ($xs[$i] === $ys[$j]) { $out[] = ['op' => ' ', 'text' => $xs[$i]]; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $out[] = ['op' => '-', 'text' => $xs[$i]]; $i++; }
        else { $out[] = ['op' => '+', 'text' => $ys[$j]]; $j++; }
    }
    while ($i < $p) $out[] = ['op' => '-', 'text' => $xs[$i++]];
    while ($j < $q) $out[] = ['op' => '+', 'text' => $ys[$j++]];
    for ($k = $n - $tail; $k < $n; $k++) $out[] = ['op' => ' ', 'text' => $x[$k]];
    return $out;
}

/** Only the changed lines with $context lines around them, "@@" between separate hunks. */
function diff_compact(array $ops, int $context = 2): array {
    $keep = array_fill(0, count($ops), false);
    foreach ($ops as $i => $o) if ($o['op'] !== ' ') for ($k = max(0, $i - $context); $k <= min(count($ops) - 1, $i + $context); $k++) $keep[$k] = true;
    $out = []; $gap = false;
    foreach ($ops as $i => $o) {
        if ($keep[$i]) { if ($gap && $out) $out[] = ['op' => '@', 'text' => '…']; $out[] = $o; $gap = false; } else $gap = true;
    }
    return $out;
}
