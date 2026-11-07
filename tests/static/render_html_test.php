<?php
// Unit tests of web/render_html.php (the type editor's HTML "Print" preview). Run by tests/run_all.php.
const MEDSCRIBE_TEST = 1;
require_once __DIR__ . '/../../web/render_html.php';
$fail = 0; $n = 0;
function eq($got, $want, string $label): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
$env = ['labels' => ['LblPatientName' => 'Όνομα ασθενούς', 'LblUrgent' => 'ΕΠΕΙΓΟΝ'], 'patient' => ['name' => 'Α Β', 'age' => '40'], 'date' => '01/02/2026'];
eq(render_html_tex("\\section*{Τίτλος}\nΚείμενο \\textbf{έντονο} και \\textit{πλάγιο}.\n\nΔεύτερη.", $env),
   '<h3>Τίτλος</h3><p>Κείμενο <strong>έντονο</strong> και <em>πλάγιο</em>.</p><p>Δεύτερη.</p>', 'heading, bold, italic, paragraphs');
eq(render_html_tex('50\\% \\& a\\_b \\textbackslash{} <x>', $env), '<p>50% &amp; a_b \\ &lt;x&gt;</p>', 'escapes and HTML escaping');
eq(render_html_tex("\\begin{itemize}[leftmargin=*]\n  \\item ένα\n  \\item {}[δύο]\n\\end{itemize}\n", $env), '<ul><li>ένα</li><li>[δύο]</li></ul>', 'itemize with options and an item guard');
eq(render_html_tex("\\begin{description}\n  \\item[Όρος] κείμενο\n\\end{description}", $env), '<dl><dt>Όρος</dt><dd>κείμενο</dd></dl>', 'description');
eq(render_html_tex("\\begin{PatientInfo}\n  \\Info{Διάγνωση}{δισκοκήλη \\V{Ο4-5}}\n\\end{PatientInfo}", $env),
   '<table class="pv-info"><tr><th>Όνομα ασθενούς:</th><td>Α Β</td></tr><tr><th>Ηλικία:</th><td>40</td></tr><tr><th>Διάγνωση:</th><td>δισκοκήλη Ο<sub>4-5</sub></td></tr></table>', 'PatientInfo adds name and age; spine level');
eq(render_html_tex("\\begin{UrgentBox}\n  \\item[Κεφαλή] πυρετός\n  \\item ρίγος\n\\end{UrgentBox}", $env),
   '<div class="pv-urgent"><div class="pv-urgent-h">ΕΠΕΙΓΟΝ</div><ul><li class="pv-head"><strong>Κεφαλή</strong> πυρετός</li><li>ρίγος</li></ul></div>', 'UrgentBox with the kit heading and a heading line');
eq(render_html_tex("\\begin{MedTable}\n\\DrugEntry{tb A}{500mg}{1 x 2}{5}{σημ.}\n\\end{MedTable}", ['labels' => []] + $env),
   '<table class="pv-med"><thead><tr><th>Φάρμακο</th><th>Δοσολογία</th><th>Συχνότητα</th><th>Ημέρες</th><th>Οδηγίες</th></tr></thead><tbody><tr><td><strong>tb A</strong></td><td>500mg</td><td>1 x 2</td><td>5</td><td><em>σημ.</em></td></tr></tbody></table>', 'MedTable');
eq(render_html_tex("\\begin{enumerate}\n  \\item \\textbf{Θέμα}\n\n    Παράγραφος\n    \\begin{itemize}\n        \\item α\n    \\end{itemize}\n\\end{enumerate}", $env),
   '<ol><li><strong>Θέμα</strong><br>Παράγραφος<ul><li>α</li></ul></li></ol>', 'a topic: paragraph and nested list');
eq(render_html_tex('\\large\\centering{Χ} \\vspace{2mm}\\unknown{Υ} \\PatientName', $env), '<p>Χ Υ Α Β</p>', 'unknown commands show their text; lengths are dropped');
eq(render_html_parse_labels("\\providecommand\\LblUrgent{ΕΠΕΙΓΟΝ -- Μεταβείτε}\n\\providecommand\\DrName{Ε. Ρ.} % x\n\\providecommand\\Other{z}\n"), ['LblUrgent' => 'ΕΠΕΙΓΟΝ – Μεταβείτε', 'DrName' => 'Ε. Ρ.'], 'kit labels');
echo $fail ? "$fail of $n render_html tests FAILED\n" : '';
exit($fail ? 1 : 0);
