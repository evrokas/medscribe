<?php

/**
 * Shared UI helpers (docs/plans/composer-ui.md §5.1, §9): status words, the section naming rule. Pure functions,
 * no DB; the styles that go with them are in css/ui.css (tokens, pills).
 */

const UI_STATUS_WORDS = [
    'draft' => 'Draft', 'review' => 'Ready for review', 'code_reserved' => 'Code reserved',
    'final_compiled' => 'Final PDF ready to approve', 'approved' => 'Approved, sending', 'pdf_sent' => 'Sent, finishing',
    'issued' => 'Issued', 'withdrawn' => 'Withdrawn',
];

/** A document status in plain words (the internal value goes in a tooltip: ui_status_pill()). */
function ui_status_word(string $status): string {
    return isset(UI_STATUS_WORDS[$status]) ? t(UI_STATUS_WORDS[$status]) : $status;
}

/** The status pill: the word, coloured by status, the internal value as its tooltip. */
function ui_status_pill(string $status): string {
    $s = htmlspecialchars($status, ENT_QUOTES);
    return '<span class="pill pill-' . (isset(UI_STATUS_WORDS[$status]) ? $s : 'other') . '" title="' . $s . '">'
         . htmlspecialchars(ui_status_word($status), ENT_QUOTES) . '</span>';
}

/** Block text without its markup, for names and lists (the type editor's plainText(), type_editor.js). */
function ui_plain_text(string $s, array $fieldLabels = []): string {
    $s = preg_replace('/\{v:([^}]*)\}/u', '$1', $s);
    $s = str_replace('{br}', ' ', $s);
    $s = preg_replace('/\{([^{}|]*)\|[^{}]*\}/u', '$1', $s);
    $s = preg_replace(['/\*\*|\*/', '/\\\\ /', '/\\\\([@*{}])/'], ['', ' ', '$1'], $s);
    $s = preg_replace_callback('/(^|[^\\\\\p{L}\p{N}_@])@([a-z][a-z0-9_]*)/u',
        fn($m) => $m[1] . (isset($fieldLabels[$m[2]]) ? '[' . $fieldLabels[$m[2]] . ']' : '@' . $m[2]), $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function ui_cut(string $s, int $n): string {
    return mb_strlen($s) > $n ? preg_replace('/\s+\S*$/u', '', mb_substr($s, 0, $n - 1)) . '…' : $s;
}

/**
 * The name of a section wherever one is shown (plan §4.2; the type editor's sectionName()): label, else heading,
 * else what it holds (a medication table, or its first sentence in «»). Never the slot or layout name.
 * $sec is a section as render_expand_sections() gives it (shared components already expanded).
 */
function ui_section_name(array $sec, array $type = []): string {
    foreach (['label', 'heading'] as $k) if (trim((string)($sec[$k] ?? '')) !== '') return trim((string)$sec[$k]);
    if (($sec['layout'] ?? '') === 'medtable') return trim((string)($sec['title_page'] ?? '')) ?: t('Medication table');
    // built-in tokens by name, then the type's fields by label
    $labels = ['patient' => t('Patient'), 'age' => t('Age'), 'amka' => 'AMKA', 'clinic' => t('Clinic'), 'date' => t('Date')]
            + array_map(fn($f) => (string)($f['label'] ?? ''), $type['fields'] ?? []);
    foreach (($sec['blocks'] ?? []) as $b) {
        if (($b['format'] ?? 'markup') === 'latex') continue;
        $txt = ui_plain_text((string)($b['text'] ?? ''), array_filter($labels));
        if ($txt !== '') return '«' . ui_cut($txt, 45) . '»';
    }
    return t('Section');
}
