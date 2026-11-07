<?php
// Unit tests of web/render.php (no database, no TeX). Run by tests/run_all.php.
const MEDSCRIBE_TEST = 1;
require_once __DIR__ . '/../../web/render.php';

$fail = 0; $n = 0;
function eq($got, $want, string $label): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function mk(string $text, array $values = [], string $g = '', array $fields = []): array {
    $ctx = ['values' => $values, 'gender' => $g, 'fields' => $fields, 'issues' => []];
    $out = render_markup($text, $ctx);
    return [$out, $ctx['issues']];
}

// escaping: nothing the user types can reach TeX as a command
eq(tex_escape('50% & $5 #1 a_b {x} ~ ^ \\'), '50\% \& \$5 \#1 a\_b \{x\} \textasciitilde{} \textasciicircum{} \textbackslash{}', 'escape specials');
eq(mk('\\input{/etc/passwd} \\write18{rm}')[0], '\\textbackslash{}input\\{/etc/passwd\\} \\textbackslash{}write18\\{rm\\}', 'a LaTeX command in text is inert');
eq(mk("a\0b")[0], 'ab', 'NUL removed');

// gender
eq(mk('{Ο|Η} ασθενής', [], 'M')[0], 'Ο ασθενής', 'gender M');
eq(mk('{Ο|Η} ασθενής', [], 'F')[0], 'Η ασθενής', 'gender F');
[$o, $i] = mk('{Ο|Η} ασθενής', [], '');
eq([$o, !empty($i['gender'])], ['Ο/Η ασθενής', true], 'unknown gender is flagged');

// tokens and fields
$f = ['d' => ['type' => 'date', 'label' => 'Date'], 'p' => ['type' => 'text', 'label' => 'Proc']];
eq(mk('on @d for @p.', ['d' => '2026-05-04', 'p' => 'ΜΔ 50%'], '', $f)[0], 'on 04/05/2026 for ΜΔ 50\%.', 'tokens, date format, escaped value');
[$o, $i] = mk('x @p y', ['p' => ''], '', $f);
eq(array_keys($i['missing']), ['p'], 'missing value flagged');
eq($o, 'x [Proc] y', 'missing value shows its label');
[$o, $i] = mk('x @nope y', []);
eq([$o, array_keys($i['unknown'])], ['x @nope y', ['nope']], 'unknown token kept and flagged');
eq(mk('mail a@b.gr', ['b' => 'X'])[0], 'mail a@b.gr', 'an email address is not a token');
eq(mk('\\{x|y\\}')[0], '\\{x|y\\}', 'escaped braces are literal, not a gender variant');
eq(mk('\\@p', ['p' => 'Z'])[0], '@p', 'escaped @ is literal');

// markup
eq(mk('a **b** *c* d')[0], 'a \\textbf{b} \\textit{c} d', 'bold and italic');
eq(mk('a **b')[0], 'a \\textbf{b}', 'dangling bold is closed');
eq(mk('L {v:L4-5} done')[0], 'L \\V{L4-5} done', 'spine level');
eq(mk("p1\n\np2")[0], "p1\n\np2", 'paragraph break');
eq(mk("l1\nl2")[0], "l1 l2", 'a single line break is a space');
eq(mk('Νο.\\ @n', ['n' => '108'])[0], 'Νο.\\ 108', 'backslash-space is a control space');

// blocks, groups, local edits, optional sections, layouts
$type = ['name' => 't', 'lang' => 'gr', 'fields' => [], 'sections' => [
    ['id' => 's1', 'layout' => 'paragraphs', 'blocks' => [
        ['id' => 'a', 'text' => 'A', 'default' => true],
        ['id' => 'b', 'text' => 'B', 'default' => false],
        ['id' => 'g1', 'group' => 'g', 'text' => 'G1', 'default' => true],
        ['id' => 'g2', 'group' => 'g', 'text' => 'G2', 'default' => false]]],
    ['id' => 's2', 'heading' => 'Head', 'layout' => 'itemize', 'optional' => true, 'default' => true, 'blocks' => [
        ['id' => 'i1', 'text' => 'one', 'default' => true], ['id' => 'i2', 'text' => 'two', 'default' => true]]],
    ['id' => 's3', 'layout' => 'description', 'blocks' => [['id' => 'd1', 'label' => 'T', 'text' => 'x', 'default' => true]]],
]];
$run = function (array $inp) use ($type) { $ctx = ['values' => [], 'gender' => 'M', 'fields' => [], 'issues' => []];
    return implode('|', array_map(fn($s) => trim(render_section($s, $inp, $ctx)), $type['sections'])); };
eq($run([]), "A\n\nG1|\\section*{Head}\n\\begin{itemize}\n  \\item one\n  \\item two\n\\end{itemize}|\\begin{description}\n  \\item[T] x\n\\end{description}", 'defaults and layouts');
eq(explode('|', $run(['blocks' => ['b' => ['on' => true], 'a' => ['on' => false]], 'groups' => ['g' => 'g2']]))[0], "B\n\nG2", 'choices and group change');
eq(explode('|', $run(['groups' => ['g' => '']]))[0], 'A', 'a choice group may be empty');
eq(explode('|', $run(['blocks' => ['a' => ['text' => 'Edited {Ο|Η} 100%']]]))[0], "Edited Ο 100\\%\n\nG1", 'local edit replaces the text, still escaped');
eq(explode('|', $run(['extra' => ['s1' => [['text' => 'Free & text']]]]))[0], "A\n\nG1\n\nFree \\& text", 'free text added');
eq(explode('|', $run(['sections' => ['s2' => ['on' => false]]]))[1], '', 'optional section off');
$latexType = ['id' => 'L', 'layout' => 'paragraphs', 'blocks' => [['id' => 'raw', 'format' => 'latex', 'text' => '\\textbf{x} 100%', 'default' => true]]];
$ctx = ['values' => [], 'gender' => '', 'fields' => [], 'issues' => []];
eq(trim(render_section($latexType, [], $ctx)), '\\textbf{x} 100%', 'a latex block is trusted as written');
eq(trim(render_section($latexType, ['blocks' => ['raw' => ['text' => '\\bad']]], $ctx)), '\\textbackslash{}bad', 'but a local edit of it is escaped');

// validation
eq(render_validate_type($type), [], 'valid type');
$bad = $type; $bad['sections'][0]['blocks'][] = ['id' => 'a', 'text' => '@zzz'];
eq(count(render_validate_type($bad)) >= 2, true, 'duplicate block and unknown token found');
$bad = $type; $bad['sections'][0]['blocks'][3]['default'] = true;
eq(str_contains(implode(',', render_validate_type($bad)), 'choice group g has 2'), true, 'two defaults in a group');

// whole document
$mri = json_decode(file_get_contents(__DIR__ . '/../../types/vevaiosi-mri.json'), true);
$mri = render_resolve_type($mri, fn($h) => json_decode(file_get_contents(__DIR__ . '/../../types/shared/purpose.json'), true));   // "latest" is not a hash: any value resolves in this test
eq(render_validate_type($mri), [], 'vevaiosi-mri is valid');
$inp = ['patient' => ['name' => 'Παπαδόπουλος Γιώργος', 'age' => '45', 'gender' => 'M'],
        'settings' => ['clinic' => 'Peristeri', 'date' => '2026-01-15'], 'fields' => ['surgery_date' => '2026-01-10']];
[$tex, $iss] = render_document($mri, $inp);
eq($iss, [], 'no issues for a complete document');
eq(str_contains($tex, '\\providecommand\\DocDate{\\DTMdate{2026-01-15}}') && str_contains($tex, '\\Info{Ημ. Χειρουργείου}{10-01-2026}')
   && str_contains($tex, 'Ο ασθενής υποβλήθηκε στις 10/01/2026 σε διαδερμική') && str_contains($tex, 'στο διαγνωστικό κέντρο.'), true, 'mri document content');
eq(str_contains($tex, 'DocDraft'), false, 'no draft mark outside preview');
[$tex2] = render_document($mri, $inp, ['preview' => true]);
eq(str_contains($tex2, '\\providecommand\\DocDraft{yes}') && str_contains($tex2, '\\DocCode{PREVIEW}'), true, 'preview has draft mark and placeholder code');
// the QR placeholder (PLAN docs/plans/type-editor-ui.md decision 6): previews only. It relies on the kit loading the qrcode
// package (\usepackage{qrcode} in evrtemplate.tex) and on that package's draft mode; a final copy has the real QR.
eq([str_contains($tex2, RENDER_QR_PLACEHOLDER), substr_count($tex2, RENDER_QR_PLACEHOLDER), strpos($tex2, RENDER_QR_PLACEHOLDER) < strpos($tex2, '\\input{evrtemplate}')],
   [true, 1, true], 'preview: the QR is drawn as a frame (set once, before the kit)');
eq([str_contains($tex, RENDER_QR_PLACEHOLDER), str_contains($tex, 'qrset'), str_contains($tex, 'PREVIEW')], [false, false, false], 'final copy: no QR placeholder, no PREVIEW code');
[$tex3, $iss3] = render_document($mri, ['patient' => ['name' => ''], 'settings' => []]);
$chk = render_checks($mri, ['patient' => ['name' => ''], 'settings' => []], $iss3);
eq(count($chk) >= 4, true, 'checks report missing name, date, clinic, field and gender');

// inline blocks, list intro/options, [ guard, PatientInfo without rows
$t2 = ['id' => 'p', 'layout' => 'paragraphs', 'blocks' => [['id' => 'a', 'text' => 'A', 'default' => true], ['id' => 'b', 'text' => 'B', 'inline' => true, 'default' => true], ['id' => 'c', 'text' => 'C', 'default' => true]]];
$ctx = ['values' => [], 'gender' => '', 'fields' => [], 'issues' => []];
eq(trim(render_section($t2, [], $ctx)), "A B\n\nC", 'an inline block continues the previous paragraph');
eq(trim(render_section($t2, ['blocks' => ['a' => ['on' => false]]], $ctx)), "B\n\nC", 'an inline block with nothing before it starts a paragraph');
$t3 = ['id' => 'l', 'layout' => 'enumerate', 'intro' => 'Intro:', 'list_options' => '[label=\\arabic*.]', 'blocks' => []];
eq(render_section($t3, [], $ctx), '', 'an empty list prints nothing, not even its intro');
eq(render_section($t3, ['extra' => ['l' => [['text' => "[x], a\n\n[y]."]]]], $ctx), "Intro:\n\n\\begin{enumerate}[label=\\arabic*.]\n  \\item {}[x], a\n  \\item {}[y].\n\\end{enumerate}\n", 'list items from lines, intro, options, bracket guard');
eq(render_section(['id' => 'pi', 'layout' => 'patient-info', 'blocks' => []], [], $ctx), "\\begin{PatientInfo}\n\n\\end{PatientInfo}\n", 'the patient block is printed even with no extra rows');
eq(trim(render_section(['id' => 's', 'heading' => 'H', 'heading_level' => 'subsection', 'layout' => 'paragraphs', 'blocks' => [['id' => 'x', 'text' => 'X', 'default' => true]]], [], $ctx)), "\\subsection*{H}\nX", 'subsection heading');
// medication table
$med = ['id' => 'm', 'layout' => 'medtable', 'blocks' => []];
$ctx = ['values' => [], 'gender' => '', 'fields' => [], 'issues' => []];
eq(render_section($med, [], $ctx), '', 'no rows, no table');
eq(render_section($med, ['meds' => ['m' => [['name' => 'tb Medrol', 'dose' => '4mg', 'freq' => "1 x 3{br}1 x 2{br}STOP", 'days' => '', 'notes' => '**Προσοχή** 50% & ή'], ['name' => ' ']]]], $ctx),
   "\\begin{MedTable}\n\\DrugEntry{tb Medrol}{4mg}{1 x 3\\newline 1 x 2\\newline STOP}{}{\\textbf{Προσοχή} 50\\% \\& ή}\n\\end{MedTable}\n", 'rows with a line break in a cell; escaping; an empty row is skipped');
eq(render_section($med, ['meds' => ['m' => [['name' => '\\input{/etc/passwd}']]]], $ctx), "\\begin{MedTable}\n\\DrugEntry{\\textbackslash{}input\\{/etc/passwd\\}}{}{}{}{}\n\\end{MedTable}\n", 'a command in a drug name is inert');
$lt = ['name' => 't', 'lang' => 'gr', 'shared' => ['lib' => 'h'], 'sections' => [['id' => 'm', 'layout' => 'medtable', 'library' => 'lib']]];
$lr = render_resolve_type($lt, fn($h) => ['drugs' => [['id' => 'x', 'name' => 'tb X']]]);
eq([$lr['sections'][0]['drugs'][0]['id'], render_validate_type($lr)], ['x', []], 'a drug library is attached to its section');
eq(count(render_validate_type($lt)) > 0, true, 'an unresolved library does not validate');
// page numbers
[$tx] = render_document(['name' => 't', 'lang' => 'gr', 'page_numbers' => 'all', 'sections' => []], ['patient' => ['name' => 'A'], 'settings' => ['clinic' => 'Mandra', 'date' => '2026-01-01']]);
eq(str_contains($tx, '\\providecommand\\DocPageNumbers{all}'), true, 'page numbers setting');

// shared components
$comp = ['name' => 'purpose', 'blocks' => [['id' => 'p1', 'text' => 'One'], ['id' => 'p2', 'text' => 'Two']]];
$ty = ['name' => 't', 'lang' => 'gr', 'shared' => ['purpose' => 'abc'], 'sections' => [['id' => 'pp', 'use' => 'purpose', 'default_block' => 'p2', 'layout' => 'paragraphs']]];
$res = render_resolve_type($ty, fn($h) => $h === 'abc' ? $comp : null);
eq([array_column($res['sections'][0]['blocks'], 'default'), array_unique(array_column($res['sections'][0]['blocks'], 'group'))], [[false, true], ['purpose']], 'shared component expands into a choice group with the default block on');
eq(render_validate_type($res), [], 'a resolved type validates');
eq(count(render_validate_type($ty)) > 0, true, 'an unresolved shared section does not validate');

// case features: operations and an exact undo
$ft = ['name' => 't', 'lang' => 'gr', 'features' => ['leak' => ['label' => 'Leak', 'ops' => [
        ['op' => 'enable', 'block' => 'x1'], ['op' => 'disable', 'block' => 'x2'], ['op' => 'enable', 'block' => 'g2'],
        ['op' => 'set', 'field' => 'days', 'value' => '10'], ['op' => 'add', 'section' => 's', 'text' => 'Added line.']]]],
    'fields' => ['days' => ['type' => 'text', 'label' => 'Days', 'default' => '5']],
    'sections' => [['id' => 's', 'layout' => 'paragraphs', 'blocks' => [
        ['id' => 'x1', 'text' => 'One @days', 'default' => false], ['id' => 'x2', 'text' => 'Two', 'default' => true],
        ['id' => 'g1', 'group' => 'g', 'text' => 'G1', 'default' => true], ['id' => 'g2', 'group' => 'g', 'text' => 'G2', 'default' => false]]]]];
$base = ['patient' => ['name' => 'A', 'gender' => 'M'], 'settings' => ['clinic' => 'Mandra', 'date' => '2026-01-01'], 'fields' => ['days' => '7']];
$with = render_feature_add($ft, $base, 'leak');
[$t1] = render_document($ft, $with);
eq([str_contains($t1, 'One 10'), str_contains($t1, 'Two'), str_contains($t1, 'G2'), str_contains($t1, 'G1'), str_contains($t1, 'Added line.')], [true, false, true, false, true], 'a feature switches blocks, a group choice, a field and free text');
eq($with['features'], ['leak'], 'the chip is recorded');
eq(render_feature_add($ft, $with, 'leak'), $with, 'adding a chip twice changes nothing');
eq(render_feature_remove($ft, $with, 'leak'), $base, 'removing the chip restores the document exactly');
$user = $with; $user['blocks']['x1']['on'] = false;   // the doctor then edits by hand: the removal still restores what the feature changed
eq(render_feature_remove($ft, $user, 'leak')['fields'], ['days' => '7'], 'removal restores the field the feature set');
eq(render_feature_add($ft, $base, 'unknown'), $base, 'an unknown feature does nothing');
// disable on a block of a choice group clears the choice (only if it is that block), and is undone
$ft['features']['nog'] = ['label' => 'No G1', 'ops' => [['op' => 'disable', 'block' => 'g1']]];
$ng = render_feature_add($ft, $base, 'nog');
[$t2] = render_document($ft, $ng);
eq([str_contains($t2, 'G1'), $ng['groups']['g'] ?? null], [false, ''], 'disable on the chosen block of a group clears the choice');
eq(render_feature_remove($ft, $ng, 'nog'), $base, '...and removing the chip restores it');
$g2 = $base; $g2['groups']['g'] = 'g2';
eq(render_feature_add($ft, $g2, 'nog')['groups']['g'], 'g2', 'disable on a block that is not the choice leaves the group alone');
// the undo of "add" removes only the text it added, once
$typed = $base; $typed['extra']['s'] = [['text' => 'Added line.']];   // the doctor typed the same sentence before
$both = render_feature_add($ft, $typed, 'leak');
eq(render_feature_remove($ft, $both, 'leak')['extra']['s'][0]['text'] ?? null, 'Added line.', 'identical text the doctor typed stays');
$edited = $with; $edited['extra']['s'][0]['text'] = 'Added line, edited.';
eq(render_feature_remove($ft, $edited, 'leak')['extra']['s'][0]['text'] ?? null, 'Added line, edited.', 'added text the doctor edited stays');

// "[" at the start of an item, "]" in a description label do not break the LaTeX
$lt = ['name' => 'l', 'lang' => 'gr', 'sections' => [
    ['id' => 'u', 'layout' => 'urgentbox', 'blocks' => [['id' => 'u1', 'text' => '[1] urgent', 'default' => true]]],
    ['id' => 'd', 'layout' => 'description', 'blocks' => [['id' => 'd1', 'label' => 'Α]', 'text' => 'x', 'default' => true]]],
    ['id' => 't', 'layout' => 'topics', 'topics' => [['id' => 'tp', 'title' => 'T', 'blocks' => [['id' => 't1', 'text' => '[a] b', 'default' => true]]]]]]];
[$t3] = render_document($lt, $base);
eq([str_contains($t3, '\\item {}[1] urgent'), str_contains($t3, '\\item[{Α]}] x'), str_contains($t3, '\\item {}[a] b')], [true, true, true], 'list items starting with [ are guarded, a ] in a label is braced');

// a paragraph topic: consecutive `item` blocks form a bullet list under the paragraph; urgentbox labels are heading lines
$tt = ['name' => 't', 'lang' => 'gr', 'sections' => [
    ['id' => 'tp', 'slot' => 'tp', 'layout' => 'topics', 'topics' => [['id' => 't1', 'title' => 'T', 'style' => 'paragraph', 'blocks' => [
        ['id' => 'p', 'text' => 'Μην οδηγείτε εφόσον:', 'default' => true],
        ['id' => 'i1', 'text' => 'α,', 'default' => true, 'item' => true], ['id' => 'i2', 'text' => 'β.', 'default' => true, 'item' => true],
        ['id' => 'p2', 'text' => 'Τέλος.', 'default' => true]]]]],
    ['id' => 'ub', 'slot' => 'ub', 'layout' => 'urgentbox', 'blocks' => [
        ['id' => 'h', 'text' => '**Ομάδα:**', 'default' => true, 'label' => ''], ['id' => 'u1', 'text' => 'σημείο', 'default' => true]]]]];
[$t4] = render_document($tt, $base);
eq([str_contains($t4, "Μην οδηγείτε εφόσον:\n\n    \\begin{itemize}\n        \\item α,\n        \\item β.\n    \\end{itemize}\n\n    Τέλος."),
    str_contains($t4, '\\item[] \\textbf{Ομάδα:}'), str_contains($t4, '\\item σημείο')], [true, true, true], 'paragraph topic items become a list; an urgentbox label is a heading line');

// the long Greek date format (genitive month)
eq([render_format_date('2026-02-20', 'long'), render_format_date('2026-05-01', 'long'), render_format_date('bad', 'long')], ['20 Φεβρουαρίου 2026', '1 Μαΐου 2026', ''], 'long date: Greek genitive month');

// date formats: the field's own datefmt wins, then the block's datefmt, then d/m/Y
$dt = ['fields' => ['op' => ['type' => 'date', 'label' => 'Op', 'datefmt' => 'long'], 'ad' => ['type' => 'date', 'label' => 'Adm']]];
$dctx = ['values' => ['op' => '2026-02-20', 'ad' => '2026-02-20'], 'gender' => '', 'fields' => $dt['fields'], 'issues' => []];
$dsec = ['id' => 'd', 'layout' => 'paragraphs', 'blocks' => [['id' => 'd1', 'text' => '@op @ad', 'default' => true], ['id' => 'd2', 'text' => '@op @ad', 'default' => true, 'datefmt' => 'j/n/Y']]];
eq(trim(render_section($dsec, [], $dctx)), "20 Φεβρουαρίου 2026 20/02/2026\n\n20 Φεβρουαρίου 2026 20/2/2026", 'datefmt: the field format wins everywhere; a block format serves dates without one');
eq(trim(render_section(['id' => 'x', 'layout' => 'paragraphs', 'blocks' => []], ['extra' => ['x' => [['text' => 'στις @op']]]], $dctx)), 'στις 20 Φεβρουαρίου 2026', 'datefmt: free text uses the field format');
eq(render_validate_type(['name' => 'x', 'lang' => 'gr', 'sections' => [], 'fields' => ['a' => ['type' => 'text', 'label' => 'A', 'datefmt' => 'd/m/Y']]]), ['field a: datefmt is only for date fields (type "date")'], 'datefmt only on date fields');
// trace mode tags the source; off, nothing changes
$tctx = $dctx + ['trace' => true];
eq(trim(render_section($dsec, ['blocks' => ['d2' => ['on' => false]]], $tctx)), '\\MSsrc{block:d1}{\\MSsrc{field:op}{20 Φεβρουαρίου 2026} \\MSsrc{field:ad}{20/02/2026}}', 'trace: blocks and fields are tagged');

echo $fail ? "$fail of $n render tests FAILED\n" : '';
exit($fail ? 1 : 0);
