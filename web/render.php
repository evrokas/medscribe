<?php

/**
 * Rendering engine (PLAN.md §7.1): a type definition + a document's inputs -> a complete,
 * standalone .tex in the kit's style. Pure functions, no database, no web request, so they
 * are unit-tested on their own (tests/static/render_test.php).
 *
 * TYPE DEFINITION (JSON, versioned in the store as `typedef/<name>`):
 *   name, label, lang (gr|en), paper (a4|a5), title, closing, stamp (yes|no), has_patient? (false = a practice document with no patient; "optional" = a patient may be given, the name is not required),
 *   fields: {id: {type: text|date|number, label, default?, placeholder?, required?}}
 *   reviewed?: date  the doctor reviewed the wording (an imported type: its kit file is no longer the reference)
 *   titles?: [..]  closings?: [..]   alternatives offered for the title / closing (the first is the default)
 *   shared?: {name: sha256}          shared components this type uses, pinned by hash (see render_resolve_type)
 *   sections: [{id, slot, heading?, heading_level? (section|subsection), layout, optional?, default?, placeholder? (grey hint in the
 *               composer's free-text box, never printed), label? (the section's name in the composer when it has no heading),
 *               blocks: [...] | use: name + default_block (the blocks come from a shared component)}]
 *     layout: paragraphs | itemize | enumerate | description | patient-info | urgentbox | medtable
 *             (topics: an enumerated list of topics, each `{id, title, style: itemize|paragraph, blocks}`; a topic with no active block is left out)
 *             (medtable: rows from inputs.meds, chosen from a shared drug library: section `library` = component name;
 *              `intro` = markup printed above the table when it has rows)
 *     block:  {id, text, default (on/off), group? (choice group: at most one on), label?
 *              (description term / patient-info row label), format? markup|latex,
 *              datefmt? (date format inside this block: a PHP date() format, or "long" = 20 Φεβρουαρίου 2026)}
 *
 * BLOCK TEXT MARKUP (everything else is escaped, so text can never break the build):
 *   {Ο|Η}      gender variant: first = male, second = female, from the patient's gender
 *   @name      a field of the type, or a built-in: patient, age, amka, clinic, date
 *   {v:L4-5}   spine level, set with \V{...}        {br}  line break inside a table cell
 *   **bold**   *italic*   blank line = new paragraph, a single line break = a space
 *   \\<space> = a normal (not sentence-end) space;  to print a literal * or @ or { use a backslash
 *
 * DOCUMENT INPUTS (JSON in documents.inputs):
 *   patient {prefix?, name, age, amka, gender (M|F|''), guid?}
 *   settings {clinic, title, closing, stamp, paper, date (YYYY-MM-DD), code?}
 *   fields {id: value}
 *   blocks {blockId: {on?: bool, text?: string}}          choices and local edits (D9)
 *   groups {groupId: blockId|''}                          the chosen block of a choice group
 *   sections {sectionId: {on: bool}}                      optional sections
 *   extra {sectionId: [{text}]}                           free text added to a section (in a list layout: one item per line)
 *   meds {sectionId: [{name, dose, freq, days, notes}]}   the rows of a medtable section (cells use the same markup; {br} = line break in a cell)
 */

const RENDER_CLINICS = ['Mandra', 'Peristeri', 'Bioclinic', 'Psychikou', 'Metropolitan', 'Dunant', 'Euroclinic'];
const RENDER_LAYOUTS = ['paragraphs', 'itemize', 'enumerate', 'description', 'patient-info', 'urgentbox', 'medtable', 'topics'];

/**
 * Expand `use` sections with the blocks of their shared component (PLAN.md §4: shared components are
 * stored and versioned once). $load(sha256) returns the component's decoded JSON {name, blocks: [...]}.
 * Every block of a component is an alternative in one choice group named after the component; the one
 * named by the section's `default_block` (if any) is on by default.
 */
function render_resolve_type(array $type, callable $load): array {
    foreach ($type['sections'] as &$sec) {
        if (isset($sec['library'])) {
            $sha = $type['shared'][$sec['library']] ?? null;
            $comp = is_string($sha) ? $load($sha) : null;
            if (!is_array($comp) || !isset($comp['drugs'])) throw new RuntimeException('library ' . $sec['library'] . ' is missing');
            $sec['drugs'] = $comp['drugs'];
            continue;
        }
        if (!isset($sec['use'])) continue;
        $sha = $type['shared'][$sec['use']] ?? null;
        $comp = is_string($sha) ? $load($sha) : null;
        if (!is_array($comp) || !isset($comp['blocks'])) throw new RuntimeException('shared component ' . $sec['use'] . ' is missing');
        $blocks = [];
        foreach ($comp['blocks'] as $b) {
            $b['group'] = $comp['group'] ?? $sec['use'];
            $b['default'] = ($b['id'] === ($sec['default_block'] ?? null));
            $b['from'] = 'shared:' . $sec['use'];
            $blocks[] = $b;
        }
        $sec['blocks'] = $blocks;
    }
    unset($sec);
    return $type;
}

/**
 * CASE FEATURES (PLAN.md D17, §12.3). A type may define `features: {id: {label, ops: [...]}}`; the doctor adds a
 * feature to a document as a chip and its operations change the document's choices:
 *   {op: enable, block}   switch a block on (a block of a choice group becomes the group's choice)
 *   {op: disable, block}  switch a block off (a block of a choice group: the group's choice is cleared if it is this block)
 *   {op: set, field, value}   set a field
 *   {op: add, section, text}  add free text to a section (markup, @tokens allowed)
 * What each operation replaced is remembered in inputs.feature_undo[feature], so removing the chip puts the
 * document back exactly as it was (added free text is removed only while it is still as the chip added it). Chips stack: a later chip wins. The app proposes, the doctor edits: the
 * operations only set the same choices the doctor can set by hand, and never write clinical wording.
 */
function render_feature_add(array $type, array $inputs, string $feature): array {
    $def = $type['features'][$feature] ?? null;
    if (!$def || in_array($feature, $inputs['features'] ?? [], true)) return $inputs;
    $undo = [];
    $blockGroup = []; $groupDefault = [];
    foreach (render_expand_sections($type) as $sec) foreach (($sec['blocks'] ?? []) as $b) if (isset($b['group'])) {
        $blockGroup[$b['id']] = $b['group'];
        if (!empty($b['default'])) $groupDefault[$b['group']] = $b['id'];
    }
    foreach (($def['ops'] ?? []) as $op) {
        switch ($op['op'] ?? '') {
            case 'enable': case 'disable':
                $id = $op['block']; $on = $op['op'] === 'enable';
                if (isset($blockGroup[$id])) {
                    // a block of a choice group: enable makes it the choice; disable clears the choice, if it is this block
                    $g = $blockGroup[$id];
                    $chosen = array_key_exists($g, $inputs['groups'] ?? []) ? $inputs['groups'][$g] : ($groupDefault[$g] ?? '');
                    if (!$on && $chosen !== $id) break;
                    $undo[] = ['t' => 'group', 'k' => $g, 'had' => array_key_exists($g, $inputs['groups'] ?? []), 'v' => $inputs['groups'][$g] ?? null];
                    $inputs['groups'][$g] = $on ? $id : '';
                } else {
                    $undo[] = ['t' => 'block', 'k' => $id, 'had' => isset($inputs['blocks'][$id]['on']), 'v' => $inputs['blocks'][$id]['on'] ?? null];
                    $inputs['blocks'][$id]['on'] = $on;
                }
                break;
            case 'set':
                $undo[] = ['t' => 'field', 'k' => $op['field'], 'had' => array_key_exists($op['field'], $inputs['fields'] ?? []), 'v' => $inputs['fields'][$op['field']] ?? null];
                $inputs['fields'][$op['field']] = (string)$op['value'];
                break;
            case 'add':
                $sec = $op['section']; $cur = $inputs['extra'][$sec][0]['text'] ?? '';
                $undo[] = ['t' => 'extra', 'k' => $sec, 'text' => $op['text']];
                $inputs['extra'][$sec] = [['text' => trim($cur . "\n\n" . $op['text'])]];
                break;
        }
    }
    $inputs['features'] = array_merge($inputs['features'] ?? [], [$feature]);
    $inputs['feature_undo'][$feature] = $undo;
    return $inputs;
}

/** Remove a feature chip and undo its operations (in reverse order). */
function render_feature_remove(array $type, array $inputs, string $feature): array {
    if (!in_array($feature, $inputs['features'] ?? [], true)) return $inputs;
    foreach (array_reverse($inputs['feature_undo'][$feature] ?? []) as $u) {
        switch ($u['t']) {
            case 'block': if ($u['had']) $inputs['blocks'][$u['k']]['on'] = $u['v']; else unset($inputs['blocks'][$u['k']]['on']); break;
            case 'group': if ($u['had']) $inputs['groups'][$u['k']] = $u['v']; else unset($inputs['groups'][$u['k']]); break;
            case 'field': if ($u['had']) $inputs['fields'][$u['k']] = $u['v']; else unset($inputs['fields'][$u['k']]); break;
            case 'extra':
                // remove the text this chip added, once (the last copy: it was appended); identical text the doctor typed
                // stays, and if the doctor edited the added text it is theirs now and stays too
                $t = $inputs['extra'][$u['k']][0]['text'] ?? '';
                $pos = $u['text'] === '' ? false : strrpos($t, $u['text']);
                if ($pos === false) break;
                $t = trim(preg_replace('/\n{3,}/', "\n\n", substr($t, 0, $pos) . substr($t, $pos + strlen($u['text']))));
                if ($t === '') unset($inputs['extra'][$u['k']]); else $inputs['extra'][$u['k']] = [['text' => $t]];
                break;
        }
    }
    $inputs['features'] = array_values(array_diff($inputs['features'], [$feature]));
    unset($inputs['feature_undo'][$feature]);
    foreach (($inputs['blocks'] ?? []) as $id => $v) if ($v === []) unset($inputs['blocks'][$id]);
    foreach (['blocks', 'groups', 'fields', 'extra', 'feature_undo', 'features'] as $k) if (isset($inputs[$k]) && $inputs[$k] === []) unset($inputs[$k]);
    return $inputs;
}

/** Escape plain text for LaTeX. */
function tex_escape(string $s): string {
    $map = ['\\' => '\\textbackslash{}', '&' => '\\&', '%' => '\\%', '$' => '\\$', '#' => '\\#', '_' => '\\_',
            '{' => '\\{', '}' => '\\}', '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}'];
    $s = str_replace("\0", '', $s);
    return strtr($s, $map);
}

function render_format_date(string $iso, string $fmt = 'd/m/Y'): string {
    $d = DateTime::createFromFormat('!Y-m-d', $iso);
    if (!$d || $d->format('Y-m-d') !== $iso) return '';
    if ($fmt === 'long') {   // "20 Φεβρουαρίου 2026" (Greek month in the genitive, as dates are written in a letter)
        $m = ['Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];
        return $d->format('j') . ' ' . $m[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
    }
    return $d->format($fmt);
}

/** Built-in tokens and field values for a document, as plain (unescaped) strings. */
function render_values(array $type, array $inputs): array {
    $p = $inputs['patient'] ?? [];
    $s = $inputs['settings'] ?? [];
    $v = [
        'patient' => trim((string)($p['name'] ?? '')),
        'age'     => trim((string)($p['age'] ?? '')),
        'amka'    => trim((string)($p['amka'] ?? '')),
        'clinic'  => (string)($s['clinic'] ?? ''),
        'date'    => render_format_date((string)($s['date'] ?? '')),
    ];
    foreach (($type['fields'] ?? []) as $id => $f) {
        $val = $inputs['fields'][$id] ?? ($f['default'] ?? '');
        $v[$id] = trim((string)$val);
    }
    return $v;
}

/** Split markup into tokens. Returns [['t' => kind, ...], ...]. */
function render_tokenize(string $text): array {
    $re = '/(\\\\[\\\\*@{}|]|\\\\ |\{br\}|\{v:[^{}]*\}|\{[^{}|]*\|[^{}]*\}|(?<![\w@])@[a-z][a-z0-9_]*|\*\*|\*|\n\n+|\n)/u';
    $parts = preg_split($re, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $x) {
        if ($x === '**') $out[] = ['t' => 'bold'];
        elseif ($x === '*') $out[] = ['t' => 'italic'];
        elseif ($x[0] === "\n") $out[] = ['t' => strlen($x) > 1 ? 'par' : 'nl'];
        elseif ($x === '\\ ') $out[] = ['t' => 'cspace'];
        elseif ($x === '{br}') $out[] = ['t' => 'br'];
        elseif (preg_match('/^\\\\([\\\\*@{}|])$/', $x, $m)) $out[] = ['t' => 'text', 'v' => $m[1]];
        elseif (preg_match('/^\{v:([^{}]*)\}$/', $x, $m)) $out[] = ['t' => 'level', 'v' => $m[1]];
        elseif (preg_match('/^\{([^{}|]*)\|([^{}]*)\}$/', $x, $m)) $out[] = ['t' => 'gender', 'm' => $m[1], 'f' => $m[2]];
        elseif ($x[0] === '@' && strlen($x) > 1) $out[] = ['t' => 'token', 'v' => substr($x, 1)];
        else $out[] = ['t' => 'text', 'v' => $x];
    }
    return $out;
}

/**
 * Markup -> LaTeX. $ctx: values (token => string), gender (M|F|''), datefmt, fields (type fields),
 * and is filled with 'issues' (unknown tokens, missing values, unresolved gender).
 */
function render_markup(string $text, array &$ctx): string {
    $out = ''; $bold = false; $ital = false;
    foreach (render_tokenize($text) as $tk) {
        switch ($tk['t']) {
            case 'text':   $out .= tex_escape($tk['v']); break;
            case 'level':  $out .= '\\V{' . tex_escape($tk['v']) . '}'; break;
            case 'bold':   $out .= $bold ? '}' : '\\textbf{'; $bold = !$bold; break;
            case 'italic': $out .= $ital ? '}' : '\\textit{'; $ital = !$ital; break;
            case 'par':    $out .= "\n\n"; break;
            case 'nl':     $out .= ' '; break;                 // a single line break inside a paragraph is a space
            case 'br':     $out .= '\\newline '; break;
            case 'cspace': $out .= '\\ '; break;              // \\  = a normal space after a full stop ("Νο.\\ 108")
            case 'gender':
                $g = $ctx['gender'] ?? '';
                if ($g === 'M') $out .= tex_escape($tk['m']);
                elseif ($g === 'F') $out .= tex_escape($tk['f']);
                else { $ctx['issues']['gender'] = true; $out .= tex_escape($tk['m'] . '/' . $tk['f']); }
                break;
            case 'token':
                $name = $tk['v'];
                if (!array_key_exists($name, $ctx['values'])) { $ctx['issues']['unknown'][$name] = true; $out .= tex_escape('@' . $name); break; }
                $val = $ctx['values'][$name];
                $ftype = $ctx['fields'][$name]['type'] ?? ($name === 'date' ? 'date' : 'text');
                if ($ftype === 'date' && $val !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    $val = render_format_date($val, $ctx['datefmt'] ?? 'd/m/Y');
                }
                if ($val === '') {
                    $ctx['issues']['missing'][$name] = true;
                    $out .= tex_escape('[' . ($ctx['fields'][$name]['label'] ?? $name) . ']');
                } else {
                    $out .= tex_escape($val);
                }
                break;
        }
    }
    if ($ital) $out .= '}';
    if ($bold) $out .= '}';
    return $out;
}

/** Is this block on in this document? (local choice > choice group > default) */
function render_block_on(array $section, array $block, array $inputs): bool {
    $id = $block['id'];
    if (isset($block['group'])) {
        $g = $block['group'];
        if (isset($inputs['groups']) && array_key_exists($g, $inputs['groups'])) return $inputs['groups'][$g] === $id;
        return !empty($block['default']);
    }
    if (isset($inputs['blocks'][$id]) && array_key_exists('on', $inputs['blocks'][$id])) return (bool)$inputs['blocks'][$id]['on'];
    return !empty($block['default']);
}

function render_section_on(array $section, array $inputs): bool {
    if (isset($inputs['sections'][$section['id']]['on'])) return (bool)$inputs['sections'][$section['id']]['on'];
    return !isset($section['optional']) || !$section['optional'] || !empty($section['default']);
}

/** The block's text for this document: the local edit if there is one. */
function render_block_text(array $block, array $inputs): string {
    $e = $inputs['blocks'][$block['id']]['text'] ?? null;
    return is_string($e) ? $e : (string)$block['text'];
}

/** The rows of a medtable section as \DrugEntry lines ('' when there are none). */
function render_medtable(array $section, array $inputs, array &$ctx): string {
    $rows = [];
    foreach (($inputs['meds'][$section['id']] ?? []) as $r) {
        if (trim((string)($r['name'] ?? '')) === '') continue;
        $cells = [];
        foreach (['name', 'dose', 'freq', 'days', 'notes'] as $k) {
            $c = $ctx; $c['datefmt'] = 'd/m/Y';
            $cells[] = '{' . render_markup(trim((string)($r[$k] ?? '')), $c) . '}';
            $ctx['issues'] = $c['issues'] ?? [];
        }
        $rows[] = '\\DrugEntry' . implode('', $cells);
    }
    $title = isset($section['title_page']) && $rows ? "\\newpage\n\\begin{center}\n\\LARGE\\textbf{" . tex_escape($section['title_page']) . "}\n\\end{center}\n\\vspace{0.5cm}\n\n" : '';
    $intro = $rows && isset($section['intro']) && $section['intro'] !== '' ? render_markup($section['intro'], $ctx) . "\n\n" : '';
    return $rows ? $title . $intro . "\\begin{MedTable}\n" . implode("\n", $rows) . "\n\\end{MedTable}\n" : '';
}

/**
 * The sections as the composer shows them: a `topics` section becomes one pseudo-section per topic (its own slot,
 * blocks and free text), so editing, suggestions and the pool work per topic.
 */
function render_expand_sections(array $type): array {
    $out = [];
    foreach ($type['sections'] as $sec) {
        if (($sec['layout'] ?? '') !== 'topics') { $out[] = $sec; continue; }
        foreach ($sec['topics'] as $i => $tp) {
            $out[] = ['id' => $tp['id'], 'slot' => $tp['slot'] ?? ($sec['slot'] ?? $sec['id']) . '.' . $tp['id'], 'heading' => $tp['title'], 'layout' => ($tp['style'] ?? 'itemize') === 'paragraph' ? 'paragraphs' : 'itemize',
                      'blocks' => $tp['blocks'] ?? [], 'topic_of' => $sec['id'], 'group_heading' => $i === 0 ? ($sec['heading'] ?? '') : ''];
        }
    }
    return $out;
}

/** An enumerated list of topics (the postop letters): \item \textbf{Title} + bullets or a paragraph. */
function render_topics(array $section, array $inputs, array &$ctx): string {
    $items = [];
    foreach (($section['topics'] ?? []) as $tp) {
        $parts = [];
        foreach (($tp['blocks'] ?? []) as $b) {
            if (!render_block_on($section, $b, $inputs)) continue;
            $raw = render_block_text($b, $inputs);
            $c = $ctx; $c['datefmt'] = 'd/m/Y';
            $tex = (($b['format'] ?? 'markup') === 'latex' && !isset($inputs['blocks'][$b['id']]['text'])) ? $raw : render_markup($raw, $c);
            $ctx['issues'] = $c['issues'] ?? [];
            if (trim($tex) !== '') $parts[] = $tex;
        }
        foreach (($inputs['extra'][$tp['id']] ?? []) as $x) {
            foreach (preg_split('/\n+/', trim((string)($x['text'] ?? ''))) as $line) {
                $c = $ctx; $t = render_markup($line, $c); $ctx['issues'] = $c['issues'] ?? [];
                if (trim($t) !== '') $parts[] = $t;
            }
        }
        if (!$parts) continue;
        $head = '  \\item \\textbf{' . tex_escape($tp['title']) . '}';
        $items[] = ($tp['style'] ?? 'itemize') === 'paragraph'
            ? $head . "\n\n    " . implode("\n\n    ", $parts)
            : $head . "\n    \\begin{itemize}\n" . implode("\n", array_map(fn($p) => '      \\item ' . render_item_guard($p) . $p, $parts)) . "\n    \\end{itemize}";
    }
    if (!$items) return '';
    $head = isset($section['heading']) && $section['heading'] !== '' ? '\\section*{' . tex_escape($section['heading']) . "}\n" : '';
    return $head . '\\begin{enumerate}' . (string)($section['list_options'] ?? '') . "\n" . (($section['list_preamble'] ?? '') === '\\setlength{\\parskip}{6pt}' ? "\\setlength{\\parskip}{6pt}\n" : '') . implode("\n", $items) . "\n\\end{enumerate}\n";
}

/** "{}" when an \item's text starts with "[" (LaTeX would read it as the item's optional label), else "". */
function render_item_guard(string $tex): string {
    return str_starts_with(ltrim($tex), '[') ? '{}' : '';
}

/** One section as LaTeX; '' when off or empty. */
function render_section(array $section, array $inputs, array &$ctx): string {
    if (!render_section_on($section, $inputs)) return '';
    $layout = $section['layout'] ?? 'paragraphs';
    if ($layout === 'medtable') return render_medtable($section, $inputs, $ctx);
    if ($layout === 'topics') return render_topics($section, $inputs, $ctx);
    $items = [];   // [label|null, latex]
    $blocks = $section['blocks'] ?? [];
    foreach ($blocks as $b) {
        if (!render_block_on($section, $b, $inputs)) continue;
        $c = $ctx; $c['datefmt'] = $b['datefmt'] ?? 'd/m/Y';
        $raw = render_block_text($b, $inputs);
        $tex = (($b['format'] ?? 'markup') === 'latex' && !isset($inputs['blocks'][$b['id']]['text']))
             ? $raw : render_markup($raw, $c);
        $ctx['issues'] = $c['issues'] ?? [];
        $label = isset($b['label']) ? render_markup($b['label'], $ctx) : null;
        if (trim($tex) === '') continue;
        // an inline block continues the previous paragraph of this section (consecutive source lines in the kit)
        if (!empty($b['inline']) && $layout === 'paragraphs' && $items) { $items[count($items) - 1][1] .= ' ' . $tex; continue; }
        $items[] = [$label, $tex, $b];
    }
    $isList = in_array($layout, ['itemize', 'enumerate', 'urgentbox'], true);
    foreach (($inputs['extra'][$section['id']] ?? []) as $x) {
        // paragraphs: one block of text; lists: one item per line
        $texts = $isList ? preg_split('/\n+/', trim((string)($x['text'] ?? ''))) : [(string)($x['text'] ?? '')];
        foreach ($texts as $one) {
            $c = $ctx; $c['datefmt'] = 'd/m/Y';
            $tex = render_markup($one, $c);
            $ctx['issues'] = $c['issues'] ?? [];
            if (trim($tex) !== '') $items[] = [null, $tex, null];
        }
    }
    if (($ctx['has_patient'] ?? true) === false && $layout === 'patient-info') return '';   // practice documents have no patient
    if (!$items && $layout !== 'patient-info') return '';   // the patient block always prints the name

 $cmd = ($section['heading_level'] ?? 'section') === 'subsection' ? 'subsection' : 'section';
    $star = !empty($section['numbered']) ? '' : '*';
    $head = isset($section['heading']) && $section['heading'] !== '' ? "\\$cmd$star{" . tex_escape($section['heading']) . "}\n" : '';
    switch ($layout) {
        case 'paragraphs':
            return $head . implode("\n\n", array_column($items, 1)) . "\n";
        case 'itemize': case 'enumerate':
            // "\item [" would read the bracket as an option: guard it with {}
            // a block may carry a trusted `bullet` (LaTeX, from the kit import) replacing the list marker
            $lines = array_map(fn($i) => '  \\item' . (isset($i[2]['bullet']) ? '[' . $i[2]['bullet'] . ']' : '') . ' ' . render_item_guard($i[1]) . $i[1], $items);
            $opt = (string)($section['list_options'] ?? '');
            $intro = isset($section['intro']) && $section['intro'] !== '' ? render_markup($section['intro'], $ctx) . "\n\n" : '';
            $pre = ($section['list_preamble'] ?? '') === '\\setlength{\\parskip}{6pt}' ? "\\setlength{\\parskip}{6pt}\n" : '';
            return $head . $intro . "\\begin{{$layout}}$opt\n" . $pre . implode("\n", $lines) . "\n\\end{{$layout}}\n";
        case 'description':
            // a "]" inside the label would end the optional argument: brace the label then
            $lines = array_map(fn($i) => '  \\item[' . (str_contains((string)$i[0], ']') ? '{' . $i[0] . '}' : ($i[0] ?? '')) . '] ' . render_item_guard($i[1]) . $i[1], $items);
            return $head . "\\begin{description}\n" . implode("\n", $lines) . "\n\\end{description}\n";
        case 'patient-info':
            $lines = array_map(fn($i) => '  \\Info{' . ($i[0] ?? '') . '}{' . $i[1] . '}', $items);
            return $head . "\\begin{PatientInfo}\n" . implode("\n", $lines) . "\n\\end{PatientInfo}\n";
        case 'urgentbox':
            $lines = array_map(fn($i) => '  \\item ' . render_item_guard($i[1]) . $i[1], $items);
            $opt = isset($section['heading']) && $section['heading'] !== '' ? '[' . tex_escape($section['heading']) . ']' : '';
            return "\\begin{UrgentBox}$opt\n" . implode("\n", $lines) . "\n\\end{UrgentBox}\n";
    }
    throw new InvalidArgumentException("unsupported layout $layout");
}

/** Validate a type definition; returns a list of problems (empty = fine). */
/** Must a document of this type name a patient? (has_patient: true/absent = yes; false = no patient; "optional" = may.) */
function render_patient_required(array $type): bool {
    return ($type['has_patient'] ?? true) === true;
}

function render_validate_type(array $t): array {
    $e = [];
    if (!in_array($t['has_patient'] ?? true, [true, false, 'optional'], true)) $e[] = 'has_patient must be true, false or "optional"';
    foreach (['name', 'lang', 'sections'] as $k) if (!isset($t[$k])) $e[] = "missing $k";
    if (!in_array($t['lang'] ?? '', ['gr', 'en'], true)) $e[] = 'lang must be gr or en';
    $seenS = []; $seenB = [];
    foreach (['titles', 'closings'] as $k) if (isset($t[$k]) && (!is_array($t[$k]) || array_filter($t[$k], fn($x) => !is_string($x)))) $e[] = "$k must be a list of strings";
    foreach (($t['sections'] ?? []) as $s) {
        if (empty($s['id'])) { $e[] = 'section without id'; continue; }
        if (isset($seenS[$s['id']])) $e[] = "duplicate section {$s['id']}";
        $seenS[$s['id']] = 1;
        if (!in_array($s['layout'] ?? 'paragraphs', RENDER_LAYOUTS, true)) $e[] = "section {$s['id']}: bad layout";
        if (!in_array($s['heading_level'] ?? 'section', ['section', 'subsection'], true)) $e[] = "section {$s['id']}: bad heading_level";
        if (isset($s['use']) && empty($s['blocks'])) $e[] = "section {$s['id']}: shared component {$s['use']} not resolved";
        if (($s['layout'] ?? '') === 'medtable' && isset($s['library']) && empty($s['drugs'])) $e[] = "section {$s['id']}: library {$s['library']} not resolved";
        $groupOn = [];
        $allBlocks = $s['blocks'] ?? [];
        foreach (($s['topics'] ?? []) as $tp) foreach (($tp['blocks'] ?? []) as $tb) $allBlocks[] = $tb;
        foreach ($allBlocks as $b) {
            if (empty($b['id']) || !isset($b['text'])) { $e[] = "section {$s['id']}: block without id/text"; continue; }
            if (isset($seenB[$b['id']])) $e[] = "duplicate block {$b['id']}";
            $seenB[$b['id']] = 1;
            if (isset($b['group']) && !empty($b['default'])) $groupOn[$b['group']] = ($groupOn[$b['group']] ?? 0) + 1;
            foreach (render_tokenize($b['text']) as $tk) {
                if ($tk['t'] === 'token' && !in_array($tk['v'], ['patient', 'age', 'amka', 'clinic', 'date'], true) && !isset($t['fields'][$tk['v']])) {
                    $e[] = "block {$b['id']}: unknown token @{$tk['v']}";
                }
            }
        }
        foreach ($groupOn as $g => $n) if ($n > 1) $e[] = "section {$s['id']}: choice group $g has $n defaults";
    }
    return $e;
}

/**
 * Checks shown in the preview (PLAN.md §7.3): returns [['level' => error|warn, 'msg' => ...]].
 * Issuing is blocked while any error remains (M3).
 */
function render_checks(array $type, array $inputs, array $issues): array {
    $c = [];
    $p = $inputs['patient'] ?? [];
    if (render_patient_required($type) && trim((string)($p['name'] ?? '')) === '') $c[] = ['level' => 'error', 'msg' => 'Patient name is empty'];
    foreach (array_keys($issues['missing'] ?? []) as $n) {
        $c[] = ['level' => 'error', 'msg' => 'Missing value: ' . ($type['fields'][$n]['label'] ?? $n)];
    }
    foreach (array_keys($issues['unknown'] ?? []) as $n) $c[] = ['level' => 'error', 'msg' => "Unknown token @$n"];
    if (!empty($issues['gender'])) $c[] = ['level' => 'error', 'msg' => 'The patient\'s gender is needed for the Greek text'];
    if (empty($inputs['settings']['date'])) $c[] = ['level' => 'error', 'msg' => 'Document date is empty'];
    if (!in_array($inputs['settings']['clinic'] ?? '', RENDER_CLINICS, true)) $c[] = ['level' => 'error', 'msg' => 'Clinic is not set'];
    return $c;
}

/**
 * The complete .tex for a document.
 * @param array $opts preview => bool (draft mark + placeholder QR code)
 * @return array [tex, issues]
 */
function render_document(array $type, array $inputs, array $opts = []): array {
    $s = $inputs['settings'] ?? [];
    $p = $inputs['patient'] ?? [];
    $lang = $type['lang'] ?? 'gr';
    $ctx = ['values' => render_values($type, $inputs), 'gender' => (string)($p['gender'] ?? ''),
            'fields' => $type['fields'] ?? [], 'issues' => [], 'has_patient' => ($type['has_patient'] ?? true) !== false];

    $body = [];
    foreach ($type['sections'] as $sec) {
        $t = render_section($sec, $inputs, $ctx);
        if ($t !== '') $body[] = $t;
    }

    $paper = $s['paper'] ?? ($type['paper'] ?? 'a4');
    $date = (string)($s['date'] ?? '');
    $code = !empty($opts['preview']) ? 'PREVIEW' : (string)($s['code'] ?? '');
    $prefix = ['M' => $lang === 'gr' ? 'Ο' : 'Mr', 'F' => $lang === 'gr' ? 'Η' : 'Ms'][$p['gender'] ?? ''] ?? '';
    $pc = fn(string $n, string $v) => '\\providecommand\\' . $n . '{' . $v . "}\n";

    $tex  = '\\documentclass[10pt,' . ($lang === 'gr' ? 'greek' : 'british') . "]{article}\n";
    $tex .= "\\usepackage[useregional]{datetime2}\n";
    $tex .= $pc('DocLang', $lang) . $pc('Clinic', (string)($s['clinic'] ?? ''));
    $tex .= $pc('DocCode', tex_escape($code));
    if (!empty($opts['preview'])) $tex .= $pc('DocDraft', 'yes');
    if ($paper !== 'a4') $tex .= $pc('DocPaper', $paper);
    if ($prefix !== '') $tex .= $pc('PatientPrefix', $prefix);
    $tex .= $pc('PatientName', tex_escape((string)($p['name'] ?? '')));
    $tex .= $pc('PatientAge', tex_escape((string)($p['age'] ?? '')));
    $tex .= $pc('PatientAMKA', tex_escape((string)($p['amka'] ?? '')));   // always defined: imported LaTeX blocks may use it
    $tex .= $pc('DocDate', render_format_date($date) !== '' ? '\\DTMdate{' . $date . '}' : '');
    $tex .= $pc('DocTitle', tex_escape((string)($s['title'] ?? ($type['title'] ?? ''))));
    $tex .= $pc('DocClosing', tex_escape((string)($s['closing'] ?? ($type['closing'] ?? ''))));
    $tex .= $pc('DocStamp', ($s['stamp'] ?? ($type['stamp'] ?? 'no')) === 'yes' ? 'yes' : 'no');
    if (in_array($s['page_numbers'] ?? ($type['page_numbers'] ?? ''), ['all', 'yes', 'no'], true)) $tex .= $pc('DocPageNumbers', $s['page_numbers'] ?? $type['page_numbers']);
    if (!empty($type['recipient'])) $tex .= $pc('Recipient', tex_escape((string)($s['recipient'] ?? '')));
    if (!empty($s['revision'])) $tex .= $pc('DocRevision', tex_escape((string)$s['revision']));
    $tex .= "\\input{evrtemplate}\n\n\\begin{document}\n\n" . implode("\n", $body) . "\n\\end{document}\n";
    return [$tex, $ctx['issues']];
}
