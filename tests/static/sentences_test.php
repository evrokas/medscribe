<?php
// Unit tests of web/sentences.php (the Write view's rendered sentences, composer plan §8). Run by tests/run_all.php.
// For every type (types/*.json, shared components loaded from types/shared/) with a male, a female and an unknown
// gender, values filled and empty: no markup is left over, and each block's text equals that block's text in the
// engine's HTML print view (render_html_document), which is made from the same LaTeX as the PDF.
const MEDSCRIBE_TEST = 1;
if (!function_exists('t')) { function t($s) { return $s; } }
require_once __DIR__ . '/../../web/sentences.php';
$fail = 0; $n = 0;
function ok(bool $c, string $label): void { global $fail, $n; $n++; if (!$c) { $fail++; echo "FAIL  $label\n"; } }
$root = dirname(__DIR__, 2);
$norm = fn(string $s) => trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
function texts_by_block(string $html): array {
    $dom = new DOMDocument(); libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>'); libxml_clear_errors();
    $out = [];
    foreach ((new DOMXPath($dom))->query('//*[starts-with(@data-src, "block:")]') as $e) {
        $id = substr($e->getAttribute('data-src'), 6); $out[$id] = ($out[$id] ?? '') . ' ' . $e->textContent;
    }
    return $out;
}
foreach (glob("$root/types/*.json") as $file) {
    $def = json_decode(file_get_contents($file), true);
    foreach (array_keys($def['shared'] ?? []) as $k) $def['shared'][$k] = $k;
    $type = render_resolve_type($def, fn($k) => json_decode(file_get_contents("$root/types/shared/$k.json"), true));
    $name = basename($file, '.json');
    foreach (['M', 'F', ''] as $g) foreach ([true, false] as $filled) {
        $in = ['patient' => ['name' => 'Δοκιμή Τεστ', 'age' => '50', 'gender' => $g, 'amka' => ''], 'settings' => ['clinic' => 'Mandra', 'date' => '2026-03-04'],
               'fields' => [], 'blocks' => [], 'groups' => []];
        foreach (($type['fields'] ?? []) as $id => $f) $in['fields'][$id] = !$filled ? '' : (($f['type'] ?? 'text') === 'date' ? '2026-02-01' : 'Τιμή ' . $id);
        foreach (render_expand_sections($type) as $sec) foreach (($sec['blocks'] ?? []) as $b) {
            if (isset($b['group'])) $in['groups'][$b['group']] ??= $b['id']; else $in['blocks'][$b['id']]['on'] = true;
        }
        $label = "$name gender=" . ($g ?: '-') . ($filled ? ' filled' : ' empty');
        $s = sentences_for($type, $in);
        [$doc] = render_html_document($type, $in, []);
        $want = texts_by_block($doc);
        foreach ($s as $id => $x) {
            $text = $norm(strip_tags($x['html']));
            if (!$x['latex']) {
                ok(!preg_match('/(?<![\w@\\\\])@[a-z][a-z0-9_]*/', $text), "$label $id: no @token left: $text");
                ok(!str_contains($text, '**') && !preg_match('/\{[^{}|]*\|[^{}]*\}/u', $text), "$label $id: no markup left: $text");
            }
            if ($g !== '') ok(!str_contains($x['html'], 'cx-g'), "$label $id: gender resolved");
            if (!$x['on'] || !isset($want[$id])) continue;
            // the print view shows an empty value as [Label] and a missing gender as m/f: the same words without brackets
            $a = $norm(strip_tags(preg_replace('/<span class="cx-v cx-v-empty[^"]*" data-field="[^"]*" title="[^"]*">(.*?)<\/span>/u', '[$1]', $x['html'])));
            $b = $norm($want[$id]);
            ok($b === '' || str_contains($b, $a) || str_contains($a, $b), "$label $id: same text as the print view\n      write: $a\n      print: $b");
        }
    }
}
// gender marks and value chips
$t = ['fields' => ['days' => ['label' => 'Ημέρες', 'required' => true], 'd' => ['label' => 'Ημ.', 'type' => 'date', 'datefmt' => 'long']],
      'sections' => [['id' => 's', 'blocks' => [['id' => 'b', 'text' => '{Ο|Η} ασθενής, **@days** ημέρες, @d.', 'default' => true]]]]];
$x = sentences_for($t, ['patient' => ['gender' => ''], 'fields' => ['days' => '', 'd' => '2026-02-20']])['b']['html'];
ok(str_contains($x, '<span class="cx-g"') && str_contains($x, 'Ο/Η</span>'), 'no gender: both forms, marked');
ok(str_contains($x, '<strong><span class="cx-v cx-v-empty cx-v-req" data-field="days" title="@days">Ημέρες</span></strong>'), 'empty required value: its label as a chip, bold kept: ' . $x);
ok(str_contains($x, '<span class="cx-v" data-field="d" title="@d">20 Φεβρουαρίου 2026</span>'), 'a date value in its format');
$x = sentences_for($t, ['patient' => ['gender' => 'F'], 'fields' => ['days' => '<b>3</b>', 'd' => '']])['b']['html'];
ok(str_starts_with($x, 'Η ασθενής') && str_contains($x, '&lt;b&gt;3&lt;/b&gt;'), 'gender resolved; a value is escaped: ' . $x);
[$tex] = render_document($t, ['patient' => ['gender' => ''], 'settings' => ['date' => '2026-01-01', 'clinic' => 'Mandra']]);
ok(!str_contains($tex, 'MSsrc'), 'the PDF source has no marks');
echo $fail ? "$fail of $n sentence tests FAILED\n" : (getenv('VERBOSE') ? "$n sentence tests passed\n" : '');
exit($fail ? 1 : 0);
