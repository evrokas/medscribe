<?php

/**
 * Rendered sentences for the composer's Write view (docs/plans/composer-ui.md §4.2, §6.1): each block's text as it will
 * read, as HTML: markup applied, gender resolved, values filled in as chips. Not a second renderer: the block goes
 * through the engine's render_markup() (trace mode marks the fields) and the LaTeX it gives through the HTML converter
 * of the type editor's print view (render_html_tex()). Pure functions, no DB.
 *
 * A value prints as <span class="cx-v" data-field="id">value</span>; an empty one as its label,
 * <span class="cx-v cx-v-empty[ cx-v-req]" data-field="id">Label</span>. Without the patient's gender a {m|f} pair prints
 * both forms in <span class="cx-g">m/f</span>.
 */

require_once __DIR__ . '/render.php';
require_once __DIR__ . '/render_html.php';

/** The engine's context for the blocks of a document (as render_document() makes it), in trace mode. */
function sentences_ctx(array $type, array $inputs): array {
    return ['values' => render_values($type, $inputs), 'gender' => (string)($inputs['patient']['gender'] ?? ''),
            'fields' => $type['fields'] ?? [], 'issues' => [], 'has_patient' => ($type['has_patient'] ?? true) !== false,
            'trace' => true, 'mark_gender' => true];
}

/** One piece of markup as display HTML. */
function sentences_html(string $markup, array $ctx, array $type, bool $latex = false): string {
    $tex = $latex ? $markup : render_markup($markup, $ctx);
    $html = render_html_tex($tex, ['labels' => [], 'patient' => [], 'date' => '']);
    // a single paragraph shows inline; several keep their <p>
    if (substr_count($html, '<p>') === 1 && str_starts_with($html, '<p>') && str_ends_with($html, '</p>')) $html = substr($html, 3, -4);
    $values = $ctx['values'];
    return preg_replace_callback('/<span class="pv-src" data-src="(field|gender):([^"]*)">(.*?)<\/span>/su', function ($m) use ($values, $type) {
        if ($m[1] === 'gender') return '<span class="cx-g" title="' . htmlspecialchars(t('Set the patient\'s gender'), ENT_QUOTES) . '">' . $m[3] . '</span>';
        $id = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'); $f = $type['fields'][$id] ?? [];
        $hid = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        if (($values[$id] ?? '') === '') {
            return '<span class="cx-v cx-v-empty' . (!empty($f['required']) ? ' cx-v-req' : '') . '" data-field="' . $hid . '" title="@' . $hid . '">'
                 . htmlspecialchars((string)($f['label'] ?? $id), ENT_QUOTES, 'UTF-8') . '</span>';
        }
        return '<span class="cx-v" data-field="' . $hid . '" title="@' . $hid . '">' . $m[3] . '</span>';
    }, $html);
}

/**
 * Every block of the document: [block id => ['html' => display HTML, 'label' => label HTML or '', 'on' => bool,
 * 'edited' => bool, 'latex' => bool]]. Blocks that are off are rendered too (the Write view shows them dimmed).
 */
function sentences_for(array $type, array $inputs): array {
    $base = sentences_ctx($type, $inputs);
    $out = [];
    foreach (render_expand_sections($type) as $sec) {
        foreach (($sec['blocks'] ?? []) as $b) {
            $c = $base; $c['datefmt'] = $b['datefmt'] ?? null; $c['src'] = 'block:' . $b['id'];
            $edited = isset($inputs['blocks'][$b['id']]['text']);
            $latex = ($b['format'] ?? 'markup') === 'latex' && !$edited;
            $out[$b['id']] = [
                'html' => sentences_html(render_block_text($b, $inputs), $c, $type, $latex),
                'label' => isset($b['label']) && $b['label'] !== '' ? sentences_html((string)$b['label'], $c, $type) : '',
                'on' => render_block_on($sec, $b, $inputs), 'edited' => $edited, 'latex' => $latex,
            ];
        }
    }
    return $out;
}
