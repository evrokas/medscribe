<?php
/**
 * What changed between two versions of a type definition or shared component, in the doctor's terms
 * (docs/plans/type-editor-ui.md §5.2, §6.5): sections, topics, blocks, fields, case rules and settings matched by id,
 * reported as added / removed / changed / moved, grouped by the section they belong to. Pure functions (no DB);
 * tests/static/type_diff_test.php.
 */

/** A block's or heading's text without its markup, for lists. */
function types_plain(string $s, array $fields = []): string {
    $s = preg_replace('/\{v:([^}]*)\}/u', '$1', $s);
    $s = str_replace('{br}', ' ', $s);
    $s = preg_replace('/\{([^{}|]*)\|[^{}]*\}/u', '$1', $s);
    $s = str_replace(['**', '*', '\\ '], ['', '', ' '], $s);
    $s = preg_replace('/\\\\([@*{}])/u', '$1', $s);
    $s = preg_replace_callback('/(^|[^\\\\\p{L}\p{N}_@])@([a-z][a-z0-9_]*)/u',
        fn($m) => $m[1] . (isset($fields[$m[2]]['label']) ? '[' . $fields[$m[2]]['label'] . ']' : '@' . $m[2]), $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
function types_cut(string $s, int $n): string { return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s; }

/** The name of a section, the editor's rule (web/js/type_editor.js sectionName): label, heading, then what it holds. */
function types_section_name(array $sec, array $type, array $sharedLabels = []): string {
    foreach (['label', 'heading'] as $k) if (is_string($sec[$k] ?? null) && $sec[$k] !== '') return $sec[$k];
    $layout = (string)($sec['layout'] ?? '');
    if (!empty($sec['use'])) return 'Shared: ' . ($sharedLabels[$sec['use']] ?? $sec['use']);
    if ($layout === 'medtable') return (string)($sec['title_page'] ?? '') ?: 'Medication table';
    $blocks = is_array($sec['blocks'] ?? null) ? $sec['blocks'] : [];
    if ($layout === 'patient-info') {
        $rows = array_values(array_filter(array_map(fn($b) => (string)($b['label'] ?? ''), $blocks)));
        return types_cut('Patient info' . ($rows ? ' · ' . implode(', ', $rows) : ''), 60);
    }
    if ($layout === 'topics' && !empty($sec['topics'])) {
        $t = array_values(array_filter(array_map(fn($x) => (string)($x['title'] ?? ''), $sec['topics'])));
        if ($t) return types_cut('Topics: ' . implode(', ', $t), 60);
    }
    foreach ($blocks as $b) {
        $txt = types_plain((string)($b['text'] ?? ''), $type['fields'] ?? []);
        if ($txt !== '' && ($b['format'] ?? '') !== 'latex') return '«' . types_cut($txt, 45) . '»';
    }
    return ($layout ?: 'empty') . ' section';
}

/** Word-level diff of two texts: [['op' => '=', '-', '+', 't' => text], ...] (whitespace kept with the words). */
function types_word_diff(string $old, string $new): array {
    $a = preg_split('/(\s+)/u', $old, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $b = preg_split('/(\s+)/u', $new, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $n = count($a); $m = count($b);
    if ($n * $m > 250000) return [['op' => '-', 't' => $old], ['op' => '+', 't' => $new]];   // too long to align: whole texts
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) for ($j = $m - 1; $j >= 0; $j--)
        $L[$i][$j] = $a[$i] === $b[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    $out = []; $i = $j = 0;
    $push = function (string $op, string $t) use (&$out) {
        if ($out && $out[count($out) - 1]['op'] === $op) $out[count($out) - 1]['t'] .= $t; else $out[] = ['op' => $op, 't' => $t];
    };
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) { $push('=', $a[$i]); $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $push('-', $a[$i]); $i++; }
        else { $push('+', $b[$j]); $j++; }
    }
    for (; $i < $n; $i++) $push('-', $a[$i]);
    for (; $j < $m; $j++) $push('+', $b[$j]);
    return $out;
}

/** The ids of $ids (in their new order) that moved: the ones outside a longest increasing run of their old positions. */
function types_moved(array $oldIds, array $newIds): array {
    $pos = array_flip($oldIds);
    $seq = array_values(array_filter($newIds, fn($id) => isset($pos[$id])));
    $n = count($seq); if ($n < 2) return [];
    $len = array_fill(0, $n, 1); $prev = array_fill(0, $n, -1); $best = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $i; $j++) if ($pos[$seq[$j]] < $pos[$seq[$i]] && $len[$j] + 1 > $len[$i]) { $len[$i] = $len[$j] + 1; $prev[$i] = $j; }
        if ($len[$i] > $len[$best]) $best = $i;
    }
    $keep = []; for ($k = $best; $k >= 0; $k = $prev[$k]) $keep[$seq[$k]] = true;
    return array_values(array_filter($seq, fn($id) => !isset($keep[$id])));
}

/** The keys whose values differ between two objects (except $skip): [key => [old, new]] with null for a missing key. */
function types_key_changes(array $a, array $b, array $skip = []): array {
    $out = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        if (in_array($k, $skip, true)) continue;
        $x = $a[$k] ?? null; $y = $b[$k] ?? null;
        if ($x !== $y) $out[$k] = [$x, $y];
    }
    return $out;
}
function types_show_value($v): string {
    if ($v === null) return '(not set)';
    if (is_bool($v)) return $v ? 'yes' : 'no';
    if (is_string($v)) return $v;
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * The structural diff. Returns ['groups' => [['title' => ..., 'items' => [item, ...]], ...], 'count' => n] where an item is
 *   ['op' => added|removed|changed|moved, 'what' => block|section|topic|field|rule|setting|entry, 'id', 'label',
 *    'text' => [old, new] (texts; for a word diff), 'keys' => [key => [old, new]] (other changes), 'note' => string]
 * Groups: "Document settings", "Fields", "Case rules", then one per section in the new version's order, then removed sections.
 */
function types_struct_diff(array $a, array $b, string $kind = 'typedef', array $sharedLabels = []): array {
    $groups = [];
    $add = function (string $title, array $item) use (&$groups) { $groups[$title][] = $item; };

    if ($kind === 'shared') {
        foreach (types_key_changes($a, $b, ['blocks', 'drugs']) as $k => $ch)
            $add('Component', ['op' => $ch[0] === null ? 'added' : ($ch[1] === null ? 'removed' : 'changed'), 'what' => 'setting', 'id' => $k, 'label' => $k, 'keys' => [$k => $ch]]);
        $list = isset($b['drugs']) || isset($a['drugs']) ? 'drugs' : 'blocks';
        $old = []; foreach (($a[$list] ?? []) as $e) $old[(string)($e['id'] ?? '')] = $e;
        $new = []; foreach (($b[$list] ?? []) as $e) $new[(string)($e['id'] ?? '')] = $e;
        $title = $list === 'drugs' ? 'Drugs' : 'Sentences';
        $label = fn($e) => $list === 'drugs' ? trim(($e['name'] ?? '') . ' ' . ($e['dose'] ?? '')) : types_cut(types_plain((string)($e['text'] ?? '')), 80);
        foreach ($new as $id => $e) {
            if (!isset($old[$id])) { $add($title, ['op' => 'added', 'what' => 'entry', 'id' => $id, 'label' => $label($e), 'text' => $list === 'blocks' ? [null, (string)($e['text'] ?? '')] : null]); continue; }
            $kc = types_key_changes($old[$id], $e, $list === 'blocks' ? ['text'] : []);
            $tc = $list === 'blocks' && ($old[$id]['text'] ?? '') !== ($e['text'] ?? '');
            if ($kc || $tc) $add($title, ['op' => 'changed', 'what' => 'entry', 'id' => $id, 'label' => $label($e), 'keys' => $kc, 'text' => $tc ? [(string)($old[$id]['text'] ?? ''), (string)($e['text'] ?? '')] : null]);
        }
        foreach ($old as $id => $e) if (!isset($new[$id])) $add($title, ['op' => 'removed', 'what' => 'entry', 'id' => $id, 'label' => $label($e)]);
        foreach (types_moved(array_keys($old), array_keys($new)) as $id) $add($title, ['op' => 'moved', 'what' => 'entry', 'id' => $id, 'label' => $label($new[$id]), 'note' => 'its place in the list changed']);
        return types_diff_out($groups, ['Component', $title]);
    }

    // settings
    foreach (types_key_changes($a, $b, ['sections', 'fields', 'features', 'shared']) as $k => $ch)
        $add('Document settings', ['op' => $ch[0] === null ? 'added' : ($ch[1] === null ? 'removed' : 'changed'), 'what' => 'setting', 'id' => $k, 'label' => $k, 'keys' => [$k => $ch]]);
    foreach (types_key_changes($a['shared'] ?? [], $b['shared'] ?? []) as $k => $ch)
        $add('Document settings', ['op' => 'changed', 'what' => 'setting', 'id' => "shared/$k", 'label' => 'Shared component ' . ($sharedLabels[$k] ?? $k),
                                   'note' => $ch[0] === null ? 'now used' : ($ch[1] === null ? 'no longer used' : 'pinned to another version')]);
    // fields; a removed and an added field with the same definition are one field renamed (the editor's rename)
    $fa = $a['fields'] ?? []; $fb = $b['fields'] ?? [];
    $renamed = [];   // new key => old key
    $gone = array_diff_key($fa, $fb);
    foreach (array_diff_key($fb, $fa) as $k => $f) foreach ($gone as $ok => $of) if ($of === $f && !in_array($ok, $renamed, true)) { $renamed[$k] = $ok; break; }
    foreach ($fb as $k => $f) {
        if (isset($renamed[$k])) $add('Fields', ['op' => 'renamed', 'what' => 'field', 'id' => $k, 'label' => (string)($f['label'] ?? $k), 'note' => '@' . $renamed[$k] . ' → @' . $k . ' (its uses follow)']);
        elseif (!isset($fa[$k])) $add('Fields', ['op' => 'added', 'what' => 'field', 'id' => $k, 'label' => (string)($f['label'] ?? $k)]);
        elseif ($kc = types_key_changes($fa[$k], $f)) $add('Fields', ['op' => 'changed', 'what' => 'field', 'id' => $k, 'label' => (string)($f['label'] ?? $k), 'keys' => $kc]);
    }
    foreach ($fa as $k => $f) if (!isset($fb[$k]) && !in_array($k, $renamed, true)) $add('Fields', ['op' => 'removed', 'what' => 'field', 'id' => $k, 'label' => (string)($f['label'] ?? $k)]);
    $faKeys = array_map(fn($k) => array_search($k, $renamed, true) ?: $k, array_keys($fa));   // old order under the new names
    foreach (types_moved($faKeys, array_keys($fb)) as $k) $add('Fields', ['op' => 'moved', 'what' => 'field', 'id' => $k, 'label' => (string)($fb[$k]['label'] ?? $k), 'note' => 'its place on the form changed']);
    // case rules
    $ra = $a['features'] ?? []; $rb = $b['features'] ?? [];
    foreach ($rb as $k => $r) {
        if (!isset($ra[$k])) $add('Case rules', ['op' => 'added', 'what' => 'rule', 'id' => $k, 'label' => (string)($r['label'] ?? $k), 'note' => count($r['ops'] ?? []) . ' operation(s)']);
        elseif ($ra[$k] !== $r) $add('Case rules', ['op' => 'changed', 'what' => 'rule', 'id' => $k, 'label' => (string)($r['label'] ?? $k), 'keys' => types_key_changes($ra[$k], $r)]);
    }
    foreach ($ra as $k => $r) if (!isset($rb[$k])) $add('Case rules', ['op' => 'removed', 'what' => 'rule', 'id' => $k, 'label' => (string)($r['label'] ?? $k)]);

    // sections, topics and blocks, by id; a block's place = section/topic it is in
    $index = function (array $t) {
        $secs = []; $tops = []; $blocks = []; $order = ['secs' => [], 'tops' => [], 'blocks' => []];
        foreach (($t['sections'] ?? []) as $s) {
            $sid = (string)($s['id'] ?? ''); $secs[$sid] = $s; $order['secs'][] = $sid;
            foreach (($s['blocks'] ?? []) as $bl) { $blocks[(string)($bl['id'] ?? '')] = ['b' => $bl, 'sec' => $sid, 'topic' => null]; $order['blocks'][$sid][] = (string)($bl['id'] ?? ''); }
            foreach (($s['topics'] ?? []) as $tp) {
                $tid = (string)($tp['id'] ?? ''); $tops[$tid] = ['t' => $tp, 'sec' => $sid]; $order['tops'][$sid][] = $tid;
                foreach (($tp['blocks'] ?? []) as $bl) { $blocks[(string)($bl['id'] ?? '')] = ['b' => $bl, 'sec' => $sid, 'topic' => $tid]; $order['blocks']["$sid/$tid"][] = (string)($bl['id'] ?? ''); }
            }
        }
        return [$secs, $tops, $blocks, $order];
    };
    [$sa, $ta, $ba, $oa] = $index($a);
    [$sb, $tb, $bb, $ob] = $index($b);
    $secName = fn(string $sid, bool $old = false) => $old ? types_section_name($sa[$sid], $a, $sharedLabels) : types_section_name($sb[$sid], $b, $sharedLabels);
    $blockLabel = fn(array $bl, array $t) => types_cut(types_plain((string)($bl['text'] ?? ''), $t['fields'] ?? []), 90) ?: '(empty)';

    foreach ($sb as $sid => $s) {
        $g = $secName($sid);
        if (!isset($sa[$sid])) {
            $nb = count($s['blocks'] ?? []); foreach (($s['topics'] ?? []) as $tp) $nb += count($tp['blocks'] ?? []);
            $add($g, ['op' => 'added', 'what' => 'section', 'id' => $sid, 'label' => $g, 'note' => ($s['layout'] ?? '') . ' · ' . $nb . ' block(s)']);
            continue;
        }
        if ($kc = types_key_changes($sa[$sid], $s, ['blocks', 'topics'])) $add($g, ['op' => 'changed', 'what' => 'section', 'id' => $sid, 'label' => $g, 'keys' => $kc]);
    }
    foreach (types_moved($oa['secs'], $ob['secs']) as $sid) $add($secName($sid), ['op' => 'moved', 'what' => 'section', 'id' => $sid, 'label' => $secName($sid), 'note' => 'its place in the document changed']);
    foreach ($tb as $tid => $x) {
        $g = $secName($x['sec']); $label = (string)($x['t']['title'] ?? $tid);
        if (!isset($ta[$tid])) { $add($g, ['op' => 'added', 'what' => 'topic', 'id' => $tid, 'label' => $label, 'note' => count($x['t']['blocks'] ?? []) . ' block(s)']); continue; }
        if ($kc = types_key_changes($ta[$tid]['t'], $x['t'], ['blocks'])) $add($g, ['op' => 'changed', 'what' => 'topic', 'id' => $tid, 'label' => $label, 'keys' => $kc]);
        if ($ta[$tid]['sec'] !== $x['sec']) $add($g, ['op' => 'moved', 'what' => 'topic', 'id' => $tid, 'label' => $label, 'note' => 'from «' . $secName($ta[$tid]['sec'], true) . '»']);
    }
    foreach ($ob['tops'] as $sid => $ids) foreach (types_moved($oa['tops'][$sid] ?? [], $ids) as $tid) $add($secName($sid), ['op' => 'moved', 'what' => 'topic', 'id' => $tid, 'label' => (string)($tb[$tid]['t']['title'] ?? $tid), 'note' => 'its place in the section changed']);
    foreach ($bb as $bid => $x) {
        $g = $secName($x['sec']);
        $where = $x['topic'] !== null ? (string)($tb[$x['topic']]['t']['title'] ?? $x['topic']) : '';
        if (!isset($ba[$bid])) { $add($g, ['op' => 'added', 'what' => 'block', 'id' => $bid, 'label' => $blockLabel($x['b'], $b), 'where' => $where, 'text' => [null, (string)($x['b']['text'] ?? '')], 'keys' => types_key_changes([], $x['b'], ['id', 'text'])]); continue; }
        $old = $ba[$bid]['b'];
        $tc = ($old['text'] ?? '') !== ($x['b']['text'] ?? '');
        $kc = types_key_changes($old, $x['b'], ['text']);
        if ($tc || $kc) $add($g, ['op' => 'changed', 'what' => 'block', 'id' => $bid, 'label' => $blockLabel($x['b'], $b), 'where' => $where, 'text' => $tc ? [(string)($old['text'] ?? ''), (string)($x['b']['text'] ?? '')] : null, 'keys' => $kc]);
        if ($ba[$bid]['sec'] !== $x['sec'] || $ba[$bid]['topic'] !== $x['topic'])
            $add($g, ['op' => 'moved', 'what' => 'block', 'id' => $bid, 'label' => $blockLabel($x['b'], $b), 'where' => $where,
                      'note' => 'from «' . $secName($ba[$bid]['sec'], true) . ($ba[$bid]['topic'] !== null ? ' › ' . ($ta[$ba[$bid]['topic']]['t']['title'] ?? $ba[$bid]['topic']) : '') . '»']);
    }
    foreach ($ob['blocks'] as $key => $ids) {
        foreach (types_moved($oa['blocks'][$key] ?? [], $ids) as $bid) {
            $x = $bb[$bid];
            $add($secName($x['sec']), ['op' => 'moved', 'what' => 'block', 'id' => $bid, 'label' => $blockLabel($x['b'], $b), 'where' => $x['topic'] !== null ? (string)($tb[$x['topic']]['t']['title'] ?? '') : '', 'note' => 'its place changed']);
        }
    }
    // removed: under their old section (a removed section takes its contents with it)
    foreach ($ta as $tid => $x) if (!isset($tb[$tid]) && isset($sb[$x['sec']])) $add($secName($x['sec']), ['op' => 'removed', 'what' => 'topic', 'id' => $tid, 'label' => (string)($x['t']['title'] ?? $tid), 'note' => count($x['t']['blocks'] ?? []) . ' block(s) with it']);
    foreach ($ba as $bid => $x) {
        if (isset($bb[$bid]) || !isset($sb[$x['sec']]) || ($x['topic'] !== null && !isset($tb[$x['topic']]))) continue;
        $add($secName($x['sec']), ['op' => 'removed', 'what' => 'block', 'id' => $bid, 'label' => $blockLabel($x['b'], $a), 'text' => [(string)($x['b']['text'] ?? ''), null],
                                   'where' => $x['topic'] !== null ? (string)($ta[$x['topic']]['t']['title'] ?? '') : '']);
    }
    $removedSecs = [];
    foreach ($sa as $sid => $s) if (!isset($sb[$sid])) {
        $nb = count($s['blocks'] ?? []); foreach (($s['topics'] ?? []) as $tp) $nb += count($tp['blocks'] ?? []);
        $add('Removed sections', ['op' => 'removed', 'what' => 'section', 'id' => $sid, 'label' => $secName($sid, true), 'note' => $nb . ' block(s) with it']);
    }
    $order = ['Document settings', 'Fields', 'Case rules'];
    foreach (array_keys($sb) as $sid) $order[] = $secName($sid);
    $order[] = 'Removed sections';
    return types_diff_out($groups, $order);
}

function types_diff_out(array $groups, array $order): array {
    $out = []; $count = 0;
    foreach (array_unique($order) as $title) {
        if (empty($groups[$title])) continue;
        $items = $groups[$title];
        $out[] = ['title' => $title, 'items' => $items];
        $count += count($items);
    }
    return ['groups' => $out, 'count' => $count];
}

/**
 * Lints (plan §6.6): things worth a look that never block a save. $type is the resolved definition (shared blocks in
 * place). Returns [['level' => 'warn'|'note', 'msg' => ..., 'src' => preview source or null], ...].
 */
function types_lint(array $type): array {
    $out = [];
    $tokenRe = '/(?<![\\\\\p{L}\p{N}_@])@([a-z][a-z0-9_]*)/u';
    $fields = $type['fields'] ?? [];
    $uses = array_fill_keys(array_keys($fields), 0);
    $ids = ['block' => [], 'section' => []];
    $count = function ($s) use (&$uses, $tokenRe) {
        if (!is_string($s) || !preg_match_all($tokenRe, $s, $m)) return;
        foreach ($m[1] as $n) if (isset($uses[$n])) $uses[$n]++;
    };
    $blocks = [];   // [block, section]
    foreach (($type['sections'] ?? []) as $sec) {
        $ids['section'][(string)($sec['id'] ?? '')] = true;
        foreach (['intro', 'heading', 'placeholder'] as $k) $count($sec[$k] ?? null);
        foreach (($sec['blocks'] ?? []) as $b) $blocks[] = [$b, $sec];
        foreach (($sec['topics'] ?? []) as $tp) { $ids['section'][(string)($tp['id'] ?? '')] = true; foreach (($tp['blocks'] ?? []) as $b) $blocks[] = [$b, $sec]; }
        foreach (($sec['default_rows'] ?? []) as $r) foreach ((array)$r as $v) $count($v);
    }
    foreach ($blocks as [$b, $sec]) {
        $ids['block'][(string)($b['id'] ?? '')] = true;
        $count($b['text'] ?? null); $count($b['label'] ?? null);
        if (isset($b['datefmt']) && empty($b['from'])) {
            preg_match_all($tokenRe, (string)($b['text'] ?? '') . ' ' . (string)($b['label'] ?? ''), $m);
            $dated = array_filter($m[1], fn($n) => $n === 'date' || (($fields[$n]['type'] ?? '') === 'date'));
            if (!$dated) $out[] = ['level' => 'note', 'msg' => 'Block ' . $b['id'] . ' has a date format (' . $b['datefmt'] . ') but no date in it', 'src' => 'block:' . $b['id']];
        }
    }
    foreach (($type['features'] ?? []) as $fid => $f) {
        foreach (($f['ops'] ?? []) as $op) {
            if (isset($op['field'])) $uses[$op['field']] = ($uses[$op['field']] ?? 0) + 1;
            $count($op['text'] ?? null);
            $label = (string)($f['label'] ?? $fid);
            if (isset($op['block']) && !isset($ids['block'][$op['block']])) $out[] = ['level' => 'warn', 'msg' => "Case rule «{$label}»: block {$op['block']} does not exist", 'src' => null];
            if (isset($op['section']) && !isset($ids['section'][$op['section']])) $out[] = ['level' => 'warn', 'msg' => "Case rule «{$label}»: section {$op['section']} does not exist", 'src' => null];
            if (isset($op['field']) && !isset($fields[$op['field']])) $out[] = ['level' => 'warn', 'msg' => "Case rule «{$label}»: field @{$op['field']} does not exist", 'src' => null];
        }
    }
    foreach ($uses as $n => $c) if ($c === 0 && isset($fields[$n])) $out[] = ['level' => 'warn', 'msg' => 'Field «' . ($fields[$n]['label'] ?? $n) . "» (@$n) is not used in any text", 'src' => "field:$n"];
    foreach (array_keys($type['shared'] ?? []) as $sn) {
        $used = false;
        foreach (($type['sections'] ?? []) as $sec) if (($sec['use'] ?? null) === $sn || ($sec['library'] ?? null) === $sn) $used = true;
        if (!$used) $out[] = ['level' => 'warn', 'msg' => "Shared component «{$sn}» is pinned but no section uses it", 'src' => null];
    }
    return $out;
}
