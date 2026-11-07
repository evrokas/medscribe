<?php

/**
 * HTML preview of a document (the type editor's "Print" view): what the PDF would contain, shown at once
 * without running TeX. It does not lay out its own copy of the rules: it runs the real engine
 * (render_document) and turns the body LaTeX it produced into HTML. Only the constructs the engine and the
 * kit emit are understood (headings, lists, PatientInfo/\Info, UrgentBox, MedTable/\DrugEntry, \V, bold,
 * italics, escapes); any other command shows its text. The letterhead, QR and page breaks are not drawn:
 * the PDF stays the reference. Pure functions apart from render_html_kit_labels().
 */

require_once __DIR__ . '/render.php';

/** The kit's labels and doctor details (\Lbl..., \Dr...) from evr-<lang>.tex of a base release: [name => text]. */
function render_html_kit_labels(int $releaseId, string $lang): array {
    $want = $lang === 'en' ? 'evr-en.tex' : 'evr-gr.tex';
    foreach (base_release_files($releaseId) as $f) {
        if (basename((string)$f['kitpath']) !== $want) continue;
        return render_html_parse_labels(store_get($f['sha256']));
    }
    return [];
}

/** \providecommand\LblX{...} / \DrX{...} lines -> [LblX => plain text]. */
function render_html_parse_labels(string $tex): array {
    $out = [];
    if (preg_match_all('/^\\\\providecommand\\\\((?:Lbl|Dr)[A-Za-z]+)\{(.*)\}\s*(?:%.*)?$/m', $tex, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $out[$x[1]] = html_entity_decode(strip_tags(render_html_tex($x[2], [])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $out;
}

/** [html, issues]: the whole document as an HTML fragment (date, title, body, closing). */
function render_html_document(array $type, array $inputs, array $labels): array {
    [$tex, $issues] = render_document($type, $inputs, ['trace' => true]);   // \MSsrc tags: a click finds the source
    $a = strpos($tex, "\\begin{document}"); $b = strrpos($tex, "\\end{document}");
    $body = ($a !== false && $b !== false) ? substr($tex, $a + 16, $b - $a - 16) : '';
    $s = $inputs['settings'] ?? []; $p = $inputs['patient'] ?? [];
    $env = ['labels' => $labels, 'patient' => $p, 'date' => render_format_date((string)($s['date'] ?? ''))];
    $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $title = (string)($s['title'] ?? ($type['title'] ?? ''));
    $closing = (string)($s['closing'] ?? ($type['closing'] ?? ''));
    $out = '<div class="pv-doc pv-' . $h((string)($s['paper'] ?? ($type['paper'] ?? 'a4'))) . '">';
    $out .= '<div class="pv-letterhead">' . $h($labels['DrName'] ?? '') . (($labels['DrSpecialty'] ?? '') !== '' ? ' · ' . $h($labels['DrSpecialty']) : '') . '</div>';
    if ($env['date'] !== '') $out .= '<div class="pv-date">' . $h($env['date']) . '</div>';
    if ($title !== '') $out .= '<div class="pv-title"><span class="pv-src" data-src="title">' . $h($title) . '</span></div>';
    $out .= render_html_tex($body, $env);
    if ($closing !== '') {
        $out .= '<div class="pv-closing"><span class="pv-src" data-src="closing">' . $h($closing) . '</span><br>' . $h(trim(($labels['DrName'] ?? '') . ', ' . ($labels['DrDegree'] ?? ''), ', '))
              . (($labels['DrSpecialty'] ?? '') !== '' ? '<br>' . $h($labels['DrSpecialty']) : '') . '</div>';
    }
    if (($s['stamp'] ?? ($type['stamp'] ?? 'no')) === 'yes') $out .= '<div class="pv-stamp">[' . (($type['lang'] ?? 'gr') === 'en' ? 'signature and stamp' : 'υπογραφή και σφραγίδα') . ']</div>';
    return [$out . '</div>', $issues];
}

/** LaTeX (as the engine writes it) -> HTML. $env: labels, patient {name, age, amka}, date. */
function render_html_tex(string $tex, array $env): string {
    $pos = 0;
    $html = render_html_parse($tex, $pos, null, $env);
    return render_html_paragraphs($html);
}

// markers inside the parse (never in escaped text: control characters are not emitted by the engine)
const RH_PAR = "\x03";     // a paragraph break
const RH_ITEM = "\x01";    // \item, followed by its label and RH_END (label RH_NOLABEL = none)
const RH_END = "\x02";
const RH_NOLABEL = "\x04";

/** Wrap the runs of inline content between paragraph breaks and block elements in <p>. */
function render_html_paragraphs(string $html): string {
    $out = '';
    foreach (explode(RH_PAR, $html) as $seg) {
        $seg = trim(str_replace([RH_ITEM, RH_END, RH_NOLABEL], '', $seg));
        if ($seg === '') continue;
        $out .= preg_match('/^<(h\d|ul|ol|dl|table|div|hr)\b/', $seg) ? $seg : '<p>' . $seg . '</p>';
    }
    return $out;
}

/** Inside a list item or a table cell, paragraph breaks become line breaks. */
function render_html_inline(string $html): string {
    $x = str_replace(RH_PAR, '<br>', $html);
    $x = preg_replace('/(\s*<br>\s*)+(?=<(?:ul|ol|dl|table|div|hr)\b)/', '', $x);      // no line break before a block
    $x = preg_replace('/(?<=<\/ul>|<\/ol>|<\/dl>|<\/table>|<\/div>)(\s*<br>\s*)+/', '', $x);
    return trim(preg_replace('/^(\s*<br>\s*)+|(\s*<br>\s*)+$/', '', $x));
}

function render_html_parse(string $s, int &$i, ?string $until, array $env): string {
    $out = ''; $n = strlen($s);
    $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $block = fn(string $x) => RH_PAR . $x . RH_PAR;
    while ($i < $n) {
        $c = $s[$i];
        if ($until === '}' && $c === '}') { $i++; return $out; }
        if ($c === '{') { $i++; $out .= render_html_parse($s, $i, '}', $env); continue; }
        if ($c === '}') { $i++; continue; }
        if ($c === '%') { while ($i < $n && $s[$i] !== "\n") $i++; continue; }
        if ($c === '~') { $out .= '&nbsp;'; $i++; continue; }
        if ($c === "\n") {
            $j = $i; while ($j < $n && ($s[$j] === "\n" || $s[$j] === ' ' || $s[$j] === "\t")) $j++;
            $out .= substr_count(substr($s, $i, $j - $i), "\n") > 1 ? RH_PAR : ' ';
            $i = $j; continue;
        }
        if ($c === '-' && substr($s, $i, 3) === '---') { $out .= '—'; $i += 3; continue; }
        if ($c === '-' && substr($s, $i, 2) === '--') { $out .= '–'; $i += 2; continue; }
        if ($c === '`' && substr($s, $i, 2) === '``') { $out .= '“'; $i += 2; continue; }
        if ($c === "'" && substr($s, $i, 2) === "''") { $out .= '”'; $i += 2; continue; }
        if ($c === '&' || $c === '$' || $c === '#' || $c === '^' || $c === '_') { $i++; continue; }   // table/math syntax of raw blocks
        if ($c !== '\\') {
            $j = $i; while ($j < $n && strpos("\\{}%~\n-`'&\$#^_", $s[$j]) === false) $j++;
            if ($j === $i) $j++;
            $out .= $h(substr($s, $i, $j - $i)); $i = $j; continue;
        }
        // a command
        $i++;
        if ($i >= $n) break;
        if (!ctype_alpha($s[$i])) {
            $ch = $s[$i++];
            if ($ch === '\\') { render_html_opt($s, $i); $out .= '<br>'; }
            elseif ($ch === ' ') $out .= ' ';
            elseif (strpos('%&$#_{}', $ch) !== false) $out .= $h($ch);
            elseif ($ch === ',') $out .= '&thinsp;';
            continue;
        }
        $j = $i; while ($j < $n && ctype_alpha($s[$j])) $j++;
        $cmd = substr($s, $i, $j - $i); $i = $j;
        if ($i < $n && $s[$i] === '*') $i++;                 // \section* and friends
        switch ($cmd) {
            case 'begin':
                $name = render_html_arg($s, $i, $env, true);
                $out .= render_html_env($name, $s, $i, $env);
                break;
            case 'end':
                render_html_arg($s, $i, $env, true);
                return $out;                                   // the caller's environment is closed
            case 'item':
                $lab = render_html_opt($s, $i, $env);
                $out .= RH_ITEM . ($lab === null ? RH_NOLABEL : $lab) . RH_END;
                break;
            case 'section': $out .= $block('<h3>' . render_html_inline(render_html_arg($s, $i, $env)) . '</h3>'); break;
            case 'subsection': case 'subsubsection': $out .= $block('<h4>' . render_html_inline(render_html_arg($s, $i, $env)) . '</h4>'); break;
            case 'textbf': $out .= '<strong>' . render_html_arg($s, $i, $env) . '</strong>'; break;
            case 'textit': case 'emph': $out .= '<em>' . render_html_arg($s, $i, $env) . '</em>'; break;
            case 'underline': $out .= '<u>' . render_html_arg($s, $i, $env) . '</u>'; break;
            case 'textsubscript': $out .= '<sub>' . render_html_arg($s, $i, $env) . '</sub>'; break;
            case 'textsuperscript': $out .= '<sup>' . render_html_arg($s, $i, $env) . '</sup>'; break;
            case 'V': $out .= preg_replace('/(\d+(?:[-\/]\d+)*)/', '<sub>$1</sub>', render_html_arg($s, $i, $env)); break;
            case 'textcolor': render_html_arg($s, $i, $env, true); $out .= render_html_arg($s, $i, $env); break;
            case 'MSsrc':
                // where the text came from (trace mode): a span per paragraph, so the <p> wrapping stays well formed
                $src = $h(render_html_arg($s, $i, $env, true));
                $open = '<span class="pv-src" data-src="' . $src . '">';
                $out .= $open . str_replace(RH_PAR, '</span>' . RH_PAR . $open, render_html_arg($s, $i, $env)) . '</span>';
                break;
            case 'Info':
                $k = render_html_inline(render_html_arg($s, $i, $env)); $v = render_html_inline(render_html_arg($s, $i, $env));
                $out .= '<tr><th>' . $k . ':</th><td>' . $v . '</td></tr>';
                break;
            case 'DrugEntry':
                $cells = []; for ($k = 0; $k < 5; $k++) $cells[] = render_html_inline(render_html_arg($s, $i, $env));
                $out .= '<tr><td><strong>' . $cells[0] . '</strong></td><td>' . $cells[1] . '</td><td>' . $cells[2] . '</td><td>' . $cells[3] . '</td><td><em>' . $cells[4] . '</em></td></tr>';
                break;
            case 'newline': case 'linebreak': $out .= '<br>'; break;
            case 'par': $out .= RH_PAR; break;
            case 'newpage': case 'clearpage': case 'pagebreak': $out .= $block('<hr class="pv-page">'); break;
            case 'textbackslash': $out .= '\\'; break;
            case 'textasciitilde': $out .= '~'; break;
            case 'textasciicircum': $out .= '^'; break;
            case 'ldots': case 'dots': $out .= '…'; break;
            case 'S': $out .= '§'; break;
            case 'quad': case 'qquad': case 'hfill': $out .= ' '; break;
            case 'today': case 'DocDate': $out .= $h($env['date'] ?? ''); break;
            case 'PatientName': $out .= $h((string)($env['patient']['name'] ?? '')); break;
            case 'PatientAge': $out .= $h((string)($env['patient']['age'] ?? '')); break;
            case 'PatientAMKA': $out .= $h((string)($env['patient']['amka'] ?? '')); break;
            case 'vspace': case 'hspace': case 'label': case 'linespread': case 'color': case 'includegraphics':
            case 'addvspace': case 'enlargethispage':
                render_html_opt($s, $i); render_html_arg($s, $i, $env, true); break;
            case 'setlength': case 'addtolength':
                render_html_arg($s, $i, $env, true); render_html_arg($s, $i, $env, true); break;
            default:
                if (isset($env['labels'][$cmd])) { $out .= $h($env['labels'][$cmd]); break; }
                // an unknown command (font size, \centering, \noindent, ...): show the text of its arguments
                render_html_opt($s, $i, $env);
                while ($i < $n && $s[$i] === '{') $out .= render_html_arg($s, $i, $env);
        }
    }
    return $out;
}

/** The next {argument} as HTML (or as raw text with $raw); '' if there is none. */
function render_html_arg(string $s, int &$i, array $env, bool $raw = false): string {
    $n = strlen($s);
    while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\n")) $i++;
    if ($i >= $n || $s[$i] !== '{') return '';
    if ($raw) {
        $depth = 0; $start = $i;
        for (; $i < $n; $i++) {
            if ($s[$i] === '\\') { $i++; continue; }
            if ($s[$i] === '{') $depth++;
            elseif ($s[$i] === '}' && --$depth === 0) { $i++; return substr($s, $start + 1, $i - $start - 2); }
        }
        return substr($s, $start + 1);
    }
    $i++;
    return render_html_parse($s, $i, '}', $env);
}

/** An optional [argument]: HTML (raw text when no $env is given), or null when absent. */
function render_html_opt(string $s, int &$i, ?array $env = null): ?string {
    $n = strlen($s);
    if ($i >= $n || $s[$i] !== '[') return null;
    $depth = 0; $start = $i;
    for (; $i < $n; $i++) {
        if ($s[$i] === '\\') { $i++; continue; }
        if ($s[$i] === '{') $depth++;
        elseif ($s[$i] === '}') $depth--;
        elseif ($s[$i] === ']' && $depth === 0) { $i++; break; }
    }
    $inner = substr($s, $start + 1, $i - $start - 2);
    if ($env === null) return $inner;
    $k = 0;
    return render_html_inline(render_html_parse($inner, $k, null, $env));
}

/** \begin{name}...\end{name} as HTML. */
function render_html_env(string $name, string $s, int &$i, array $env): string {
    $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $opt = render_html_opt($s, $i, $env);                       // [leftmargin=*] or the UrgentBox heading
    $body = render_html_parse($s, $i, null, $env);
    $items = function (string $body): array {                   // [[label|null, html], ...]
        $out = [];
        foreach (array_slice(explode(RH_ITEM, $body), 1) as $part) {
            [$lab, $text] = array_pad(explode(RH_END, $part, 2), 2, '');
            $out[] = [$lab === RH_NOLABEL ? null : $lab, render_html_inline($text)];
        }
        return $out;
    };
    $li = fn(array $it) => $it[0] === null ? '<li>' . $it[1] . '</li>'
        : '<li class="pv-label"><span class="pv-mark">' . $it[0] . '</span> ' . $it[1] . '</li>';
    $wrap = fn(string $x) => RH_PAR . $x . RH_PAR;
    switch ($name) {
        case 'itemize': return $wrap('<ul>' . implode('', array_map($li, $items($body))) . '</ul>');
        case 'enumerate':
            return $wrap('<ol>' . implode('', array_map(fn($it) => $it[0] === null ? '<li>' . $it[1] . '</li>' : '<li class="pv-label"><span class="pv-mark">' . $it[0] . '</span> ' . $it[1] . '</li>', $items($body))) . '</ol>');
        case 'description':
            return $wrap('<dl>' . implode('', array_map(fn($it) => '<dt>' . ($it[0] ?? '') . '</dt><dd>' . $it[1] . '</dd>', $items($body))) . '</dl>');
        case 'UrgentBox':
            $head = $opt ?? $h($env['labels']['LblUrgent'] ?? 'ΕΠΕΙΓΟΝ');
            $lis = implode('', array_map(fn($it) => $it[0] !== null ? '<li class="pv-head">' . ($it[0] !== '' ? '<strong>' . $it[0] . '</strong> ' : '') . $it[1] . '</li>' : '<li>' . $it[1] . '</li>', $items($body)));
            return $wrap('<div class="pv-urgent"><div class="pv-urgent-h">' . $head . '</div><ul>' . $lis . '</ul></div>');
        case 'PatientInfo':
            $p = $env['patient'] ?? []; $l = $env['labels'] ?? [];
            $rows = '<tr><th>' . $h($l['LblPatientName'] ?? 'Όνομα ασθενούς') . ':</th><td>' . $h((string)($p['name'] ?? '')) . '</td></tr>';
            if (trim((string)($p['age'] ?? '')) !== '') $rows .= '<tr><th>' . $h($l['LblPatientAge'] ?? 'Ηλικία') . ':</th><td>' . $h((string)$p['age']) . '</td></tr>';
            preg_match_all('/<tr>.*?<\/tr>/s', $body, $m);
            return $wrap('<table class="pv-info">' . $rows . implode('', $m[0]) . '</table>');
        case 'MedTable':
            $l = $env['labels'] ?? [];
            $th = implode('', array_map(fn($k, $d) => '<th>' . $h($l[$k] ?? $d) . '</th>', ['LblMedDrug', 'LblMedDose', 'LblMedFreq', 'LblMedDays', 'LblMedNotes'], ['Φάρμακο', 'Δοσολογία', 'Συχνότητα', 'Ημέρες', 'Οδηγίες']));
            preg_match_all('/<tr>.*?<\/tr>/s', $body, $m);
            return $wrap('<table class="pv-med"><thead><tr>' . $th . '</tr></thead><tbody>' . implode('', $m[0]) . '</tbody></table>');
        case 'center': return $wrap('<div class="pv-center">' . render_html_paragraphs($body) . '</div>');
        default: return $wrap('<div>' . render_html_paragraphs($body) . '</div>');
    }
}
