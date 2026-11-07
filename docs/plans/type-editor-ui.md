# Type editor UI redesign: plan

**Status:** in progress (2026-11-07). Built: phases 0b, 1–9; phase 10 in part (see Progress). The classic editor stays at `?ui=classic` until the doctor accepts the new one. See the "Progress" notes at the end of §7.
**Scope:** the type editor (`/types/typedef/{name}` and `/types/shared/{name}`) and its release step,
plus the preview QR placeholder (§6.7), which also changes the composer's preview.
**Mockups:** canvas https://claude.ai/artifact/DvTosfM1j78w77o9FtvS6i (private to the owner). The same
screens are committed as source under `docs/plans/type-editor-ui/mockups/*.dc.html`. They are plain HTML
and inline CSS inside a `<x-dc>` wrapper; read them for layout, colours and wording, don't serve them.
`Release.dc.html` and `Rename.dc.html` still use an earlier teal skin: build them in the blue style of the others.

Read this whole file and `CLAUDE.md` (M4 "Type editor" and "Tree view of the type editor") before
changing anything. The current code is the authority on behaviour; this plan changes how it is
presented, plus the few backend additions listed in §6.

---

## 1. Decisions already taken (by the doctor, 2026-11-07)

1. **Keep the app's top bar.** No dark sidebar, no app-wide shell change. The redesign lives inside
   the page area of the existing ZeusFW layout. The visual style (white panels, blue accent, tabs,
   card rows, preview with letterhead feel) comes from the mockups, scoped to the type editor's CSS.
2. **Fields show as chips in instruction text.** `@bedrest_days` is shown as a chip with the field's
   label ("Ημέρες κλινήρης"). **Hovering (or focusing) a chip shows the stored text**, e.g.
   `@bedrest_days`, so the doctor can always see what is saved. Only fields become chips; `{Ο|Η}`,
   `{v:L4-5}`, `{br}`, `**bold**` stay as typed text with colour highlighting.
3. **The plan lives in `docs/plans/`** (this file).
4. **The editing toolbar offers only what the block markup supports** (`web/render.php` header):
   bold, italic, insert field, gender variant, spine level, add instruction, add alternative. No
   underline, alignment, links, images, tables, lists inside a block, or AI. Lists come from the
   section layout.
5. **The unit of editing is the real one.** A *block* is one sentence/bullet with its own on/off,
   choice group and preview source. A *topic* (layout `topics`) holds blocks. The proposal from the
   design zip (`medscribe-ui-redesign.zip`) treated a topic as one multi-sentence "instruction"; that is
   wrong for this data and must not be built.
6. **Previews show a rectangle with a note where the QR code goes; only the final review copy gets the
   real QR** (decided 2026-11-07). Applies to every draft preview (composer preview, the type editor's
   PDF tab). The final PDF the doctor approves (`issue.php`, step `final_compiled`) keeps the real QR,
   built exactly as today. Reason: computing the QR in TeX is the main cost of a ~12 s preview. See §6.7.

## 2. Invariants (must still hold after every phase)

- The JSON text (the form's `textarea[name=text]`) stays the **single source of truth**. Every new view
  edits the parsed tree from `web/js/type_tree.js` (key order kept, `_`-prefixed props are UI state)
  and calls its `sync()`. Nothing else holds a second copy of the definition.
- Save rules unchanged (`page_type_edit_post`): new version needs a change note; `save_here` only for
  the latest draft with no documents (`can_save_here`); released versions never change; a refused save
  hands the text back (`$_SESSION['type_edit_text']`).
- Release semantics unchanged: new documents use the latest **released** version; existing documents
  keep theirs; releasing a shared component changes no type (types pin by hash).
- Server-side validation unchanged and authoritative (`types_check()`, `render_validate_type()`). The
  browser may warn earlier, never decide.
- Renames go through the existing functions (`renameField`, `renameRef`, `renameId`, `renameGroup` in
  `type_tree.js`): whole unescaped `@tokens` only, feature operations followed, `\@x` and `@x_longer`
  untouched.
- The preview uses the existing engine path (`page_type_preview_post` → `render_html_document()` /
  `composer_type_part.zetem`). No second renderer. Preview ↔ source mapping (`\MSsrc{kind:id}`,
  `data-src`, `tt.locate`) keeps working both ways.
- No text is normalised: Greek, punctuation, `\ `, `{br}`, raw LaTeX (`format: latex`) round-trip
  byte-for-byte. `tests/js/type_tree_test.js` (every type file round-trips) must keep passing.
- Doctor-only access (routes have `access: doctor`); CSRF on every POST.
- Plain PHP, vanilla JS, plain CSS. No framework, no build step, no new library.
- UI strings in English through `t()` (the Greek interface is postponed).

## 3. Page layout

Inside the existing top bar:

- **Header row:** breadcrumb `Types ›`, the type's `label`, a status pill (`Draft vN` amber /
  `Released vN` green), an "Unsaved changes" marker (client-side: text differs from what was loaded),
  and buttons **Save…**, **Review & release**, plus **History** (opens the review page, §5.9).
  A `beforeunload` warning while unsaved.
- **Editor panel** (white, rounded), split in two:
  - left pane with tabs **Structure · Content · Fields · Case rules · JSON** (for a shared component:
    **Content · Used by · JSON**). Tab counts come from the definition (sections, fields, features).
  - right pane: the editor for what is selected in the left pane.
- **Preview panel** on the right, tabs **Preview · Form · PDF**, Male/Female switch, refresh.
- The existing `splitpane`/`splitter.js` stays between editor and preview (its width is remembered).
  Below 900px the panels stack (existing rule).
- Last-used tab and selection are remembered per type in `localStorage` (wrapped in try/catch, like
  `type_preview.js`).

### Naming a section (used everywhere a section is listed)

Real sections often have no heading (7 of 10 in `odigies-kyfoplastiki`). Name = `heading`, else
`label`, else the first block's text in «» (markup stripped, ~45 chars), else
`<layout> section`; for `patient-info` use "Patient info · " + the row labels. `use:` sections show
"Shared: <component label>"; `medtable` shows `title_page` or "Medication table". One pure JS function,
unit-tested.

## 4. What each tab shows (mockup screen in brackets)

### 4.1 Structure tab (screen 2 · Topic.dc.html)

- Ordered list of sections, each a row: drag handle, icon by layout, name (§3), a short meta
  (`topics · 4 topics`, `paragraphs · 1`, `optional`, `medtable · drug-library`), ⋯ menu
  (Move up/down, Duplicate, Delete, Edit content). Topics listed under their section.
- "+ Add section" with a layout picker; "+ Add topic" under a topics section.
- Selecting a section opens **Section properties**: Heading, Name on the input form (`label`, with the
  hint "needed only when there is no heading"), Layout, Optional (switch) and Default on (for optional
  sections), heading level, free-text placeholder. Layout-specific keys in an **Advanced** tab:
  `id`, `slot`, `list_options`, `list_preamble`, `bullet`, `intro`, `title_page`, `library`,
  `default_rows`, `use`, `default_block`. Every key present in the JSON must be reachable here or in
  the JSON tab; nothing is dropped.
- Changing layout between `topics` and any other layout is not automatic (topics own their blocks):
  refuse with an explanation; other layout changes just set the key.
- Type-level settings (title + alternatives `titles`, closing + `closings`, `lang`, `paper`, `stamp`,
  `has_patient`, `page_numbers`, `reviewed`) in a "Document settings" item at the top of the list.

### 4.2 Content tab (screen 1 · Main.dc.html; choice groups: screen A · Groups.dc.html)

- Left: an outline (sections → topics), with on-count per section (`13/14`).
- Right: the selected topic or section as a document-like editor:
  - heading (section heading or topic title) editable in place, large;
  - one row per block: on-by-default switch, the text editor (§4.3), ⋯ menu (Set as default, Move
    into/out of a choice group, Duplicate, Delete, Advanced: id, `datefmt`, `label`, `item`, `format`).
  - blocks sharing a `group` are drawn as one **Alternatives** box with radio buttons (default choice,
    or "None of these by default"), group name shown, "Rename group" (uses `renameGroup`).
  - `patient-info` / `description` / `urgentbox` blocks show their `label` as an editable term before
    the text.
  - `format: latex` blocks are shown read-only with a "Raw LaTeX" badge; editing them happens in
    Advanced (same rule as the composer).
  - toolbar: **B**, *I*, @ Field (picker of the type's fields by label + built-ins), {Ο|Η}, spine level,
    + Instruction, + Alternative. Buttons insert the existing markup at the caret.
- Footer line: counts and the block ids range (for the technically minded).

### 4.3 The instruction text editor (chips)

The riskiest piece: it is where text can be damaged. Build it as a small, self-contained module with
its own tests before using it anywhere.

- A `contenteditable` element per block. Text is shown as typed except whole unescaped `@field`
  tokens of this type's fields (or built-ins), which become non-editable chip spans
  (`contenteditable=false`, `data-token="bedrest_days"`, text = field label,
  `title="@bedrest_days"` and an `aria-label` "Field Ημέρες κλινήρης, @bedrest_days").
- **Hover / focus on a chip shows the stored token** (the decision above): a small tooltip with
  `@bedrest_days`, not only `title` (which is slow); keyboard focus shows it too.
- Serialising back: text nodes as-is + `@` + `data-token` for chips. Never rely on `innerText`
  (browsers insert newlines); walk the nodes. Paste is plain text only (strip HTML), then re-chip.
- Same token rule as the renderer/rename: the regex from `tokenRe()` in `type_tree.js` (reuse it,
  don't copy it). Unknown `@names` stay plain text with a red underline and a warning.
- Newlines: a block text may contain a blank line (new paragraph) or single newlines; keep them
  exactly (render `\n` as `<br>` inside the editable, serialise back to `\n`).
- **Escape hatch:** ⋯ → "Edit as plain text" swaps the row to a `<textarea>` with the raw text.
- **Self-check on open (decided 2026-11-07).** Before a block is shown as chips, the editor builds the
  chip DOM from the stored text and serialises it straight back. If the result is not byte-identical
  to the stored text, that block opens as a plain `<textarea>` instead, with a small grey note
  "Shown as plain text: this text cannot be shown with chips safely" and a `console.warn` naming the
  block id. The check runs again after every edit, before the text is written back to the JSON: if the
  editor would write something that does not re-read to itself, it switches that row to plain text,
  keeps what the doctor typed in the textarea, and writes nothing until the doctor edits it there.
  No text is ever saved through the chip path without passing this check. The fallback is per block,
  so one odd text does not disable chips for the whole type.
- **Test (required before Phase 3 ships):** for every block text in every `types/*.json` and
  `types/shared/*.json`: text → editor DOM → text is byte-identical (the existing Node tests have no DOM:
  keep the serialise/parse logic DOM-free and testable in Node, and check the DOM part in Chromium on the server). Plus cases: `\@x`,
  `@x_longer`, `x@x`, `@x.`, `{μόνος|μόνη}`, `**@x**`, `{br}`, `\ `, trailing spaces, CRLF in paste.

### 4.4 Fields tab (screen C · Fields.dc.html)

- Table: label (with Required badge), key (monospace), type, sample/hint (default, else placeholder),
  number of uses (whole tokens in all block texts + feature `set` ops; reuse `fieldUses`). A field used
  0 times gets a warning badge.
- Side editor for the selected field: label, key (read-only + **Rename…**), type (text/date/number),
  date format (only for dates: list = `DATEFMTS` from `type_tree.js` + "Custom PHP pattern"; hint
  "a format set here wins everywhere the field is used"), default, placeholder, required, and
  **Used in** (section name · block number · the sentence, each clickable → Content tab).
- "+ Add field": key validated (`^[a-z][a-z0-9_]*$`, no clash with fields or built-ins
  `patient, age, amka, clinic, date`).
- **Rename dialog** (screen 4 · Rename.dc.html): new key, then a dry run that lists what changes
  (N tokens with their sentences, N feature operations) and what is deliberately left (`\@x`,
  `@x_longer`), then "Rename everywhere". Implement the dry run by running `renameField` on a clone.
  Note in the dialog: documents already made keep their own type version and values.

### 4.5 Case rules tab (screen B · Rules.dc.html)

- List of `features` (chip label, number of operations); "+ New case rule".
- Selected rule: chip label (`label`), and its `ops` as sentences:
  `Turns off «sentence…» (section)`, `Turns on …`, `Sets field <label> to "value"`,
  `Adds text to <section>: «…»`. For a block in a choice group say what happens to the group
  ("clears the choice if this one is chosen" for disable, "becomes the choice" for enable), matching
  `render_feature_add()`.
- Add buttons per op type open a picker of blocks (grouped by section, searchable by text) / fields /
  sections. Operations pointing to ids that no longer exist are shown red ("missing target").
- Explanatory note: a rule never writes its own clinical wording; `add` text is the doctor's.
- Preview checkbox **"With this rule"**: preview the sample with the rule applied, removed sentences
  struck through in red, added ones in green (needs §6.2).

### 4.6 Shared components (screen D · Shared.dc.html)

- `/types/shared/{name}` with tabs **Content · Used by · JSON**.
- Content for a `blocks` component: the alternatives (they are one choice group by definition),
  per line the id and "default in N types". Deleting or renaming an id that some type uses as
  `default_block` is refused in the browser with the list of types (the server check stays as is).
- Content for a `drugs` component (the drug library): grouped table (group, name, dose, freq, days,
  notes); cells show `{br}` as line breaks, edit as multi-line text.
- **Used by** (needs §6.3): each type whose latest version pins this component: the pinned version
  (resolved from the hash), its `default_block`, and an **Update pin** button when a newer released
  version exists. Types that pin it but have no section using it are flagged.
- Impact note: releasing a component changes no type; "Update pin" makes a new type version (change
  note required) that needs its own release.

### 4.7 Medication table sections (screen E · Meds.dc.html)

- Section editor for `layout: medtable`: library (select from shared `drugs` components), page title
  (`title_page`), text above the table (`intro`, markup), **default rows** (`default_rows`) as an
  editable table with add/remove/reorder.
- Right column: the library, grouped with counts and search; "+ Default row" copies the entry's
  name/dose/freq/days/notes (a copy: later library edits don't change it; say so).

### 4.8 Preview panel (screens 1, A, B)

- Tabs **Preview** (current Print HTML), **Form** (current form preview), **PDF** (new, §6.4).
- Male/Female switch, refresh, "updated n s ago". Keep the last good preview when the text is invalid
  (current behaviour).
- **Issues bar** above the preview: errors red, warnings amber, each with **Go to source** when the
  issue knows its source (§6.1). Note under the paper: "HTML approximation: no letterhead, QR or page
  breaks; the PDF tab is the reference."
- The preview paper gets the letterhead *look* (name/title header line, section heading bands) via
  CSS only; the real letterhead is only in the PDF tab.
- Source linking: hover shows ⌖, click selects the most specific source in the current tab (field
  chip > block > topic > section); selecting a row highlights its text in the preview. If the source
  has no output with the sample values (block off, section optional off), say so.

### 4.9 JSON tab

- The current JSON textarea **and** the current tree (keep `type_tree.js` UI as the "expert" view;
  it already supports every key). Syntax errors show line/column (the parser already names the line).
  Switching away from JSON with invalid text is blocked with the error shown.

## 5. Save and release

### 5.1 Save dialog
"Save…" opens a dialog: the change note (required for a new version; optional when saving into the
draft), and the two actions exactly as today (`save_here` when allowed, `save`), with the existing
explanatory sentences. Validation errors come back as today (flash + text handed back) and are also
shown in the issues bar.

### 5.2 Review & release page (screen 3 · Release.dc.html)

New GET page `/types/{kind}/{name}/review?v=N` (doctor):
- Versions list: version, note, user, date, status, documents using it (existing `types_versions()`).
- **What changes** from the latest released version to vN, grouped by section/topic (§6.5), each
  item: added / removed / changed block (old → new text, word-level highlight), changed fields,
  changed rules, changed settings. A link "raw JSON diff" shows the existing `diff_lines` output.
- **Checks:** `types_check()`; render with the Male and the Female sample (`types_sample_inputs`) and
  list `render_checks()` warnings with Go to source; shared pins resolved.
- Impact text: "Documents created from now on use vN. The N existing documents keep their version.
  vN cannot be edited after release." For a shared component: "No type changes until its pin is
  updated."
- A required checkbox "I have read the changes and the print preview", then **Release vN** (POST
  `action=release`, now also requiring `confirm=1`; the old one-click Release in the history table is
  removed). **Keep as draft** goes back.

## 6. Backend additions

1. **Issues with sources.** `render_document()` issues (`missing`, `unknown`, `gender`) record the
   block/field id where found; `render_checks()` passes it as `src` (`block:s3_t1_b1`, `field:x`).
   Additive: existing callers ignore the key.
2. **Preview with case rules.** `page_type_preview_post` accepts `features[]`; apply
   `render_feature_add()` to the sample inputs in order. Also `diff=1` returns both renderings (or
   a list of block ids turned on/off) so the browser can strike/colour them.
3. **Used-by for shared components.** A function listing, for each typedef source, its latest version's
   `shared[name]` hash → the component version with that hash (`source_versions.sha256`), plus
   `default_block`. A POST action `update_pin` on a type: load its latest version, set
   `shared[name] = 'latest'`, run `types_check()` (which pins it), save as a new draft version with the
   note "Update <name> to vN" (+ the doctor's note).
4. **Test PDF from the editor** (PDF tab and "Test PDF" button). Compile the unsaved text with the
   sample inputs through the existing path (`render_document` → `compile_job` in the sandbox, with
   `\DocDraft` set and the QR placeholder of §6.7, as the composer preview does), purpose `type-preview`, not
   tied to a document. Keep only the latest such build per type and user, and clean its store objects
   like `issue_gc_object()` does. Serialise per type with a short lock so double clicks don't run two
   XeLaTeX jobs. Show progress; time it before and after §6.7.
5. **Structural diff.** `types_struct_diff(array $a, array $b): array` in a new `web/type_diff.php`:
   match sections/topics/blocks/fields/features by id; report added/removed/moved/changed with paths
   and names (§3 naming rule, in PHP). Pure function, unit-tested in `tests/static/type_diff_test.php`
   on real type pairs (e.g. a type file vs. the same with one block edited, one moved, one field
   renamed).
6. **Lints (warnings only, never block a save):** field used 0 times; shared component pinned but not
   used; `datefmt` on a block without a date token; feature op with a missing target; section with
   neither heading nor label. Computed in PHP (`types_lint()`), shown in the issues bar and on the
   review page.
7. **QR placeholder in previews (decision 6).** Today a preview (`render_document(..., ['preview' => true])`)
   sets `\DocCode{PREVIEW}`, so the kit still computes a real QR code (of the word PREVIEW) with the
   pure-TeX `qrcode` package, level H, on every XeLaTeX pass, in the header and again at the end of A4
   documents.
   - **Change:** in preview mode only, `render_document()` also emits, before `\input{evrtemplate}`, an
     `\AtBeginDocument{...}` that replaces `\qrcode` with a framed rectangle of the same size
     (`\evrQRmm` square) holding a short note in the document's language, e.g. «QR στην τελική έκδοση» /
     "QR in final copy" (wording to the doctor; it must fit a 14–20 mm square in 5–6 pt). The
     rectangle replaces both QR positions. The header layout must not move: same box size, same anchor.
   - **No kit change needed** if the override works; it relies on two kit names, `\qrcode` (package)
     and `\evrQRmm` (kit). Write that coupling in a comment and in a test. If the override proves
     fragile, the alternative is a kit switch (`\providecommand\DocQR{real|placeholder}` in
     `evrtemplate.tex`), which is a change to the kit in `~/devel/docs` (evrdocs) and needs the doctor's
     approval, like `\DocDraft` before it.
   - **Final copy unchanged:** the final compile (`issue.php`, `render_document()` without `preview`)
     uses the real `\DocCode` and the real `qrcode` drawing, so its PDF stays byte-identical to the kit
     build and `bin/check_m1.php` is unaffected. A rebuild (`compile_from_manifest()`) of a final PDF
     must give the same bytes as before (G5).
   - **Guards:** a unit test in `tests/static/render_test.php`: preview tex contains the placeholder and
     final tex contains neither the placeholder nor `PREVIEW`. In the issue flow, refuse to send a PDF to
     DocArc whose `.tex` contains the placeholder (belt and braces). Check that the two-up print
     (`web/print.php`) uses the final PDF of an issued document, never a preview.
   - **Measure** (on the server, before and after): XeLaTeX time per pass and number of passes for one
     A4 and one A5 document, with the real QR and with the placeholder. Record the numbers in
     `CLAUDE.md`. Two further speed-ups are **not decided** and stay out unless the numbers call for
     them: reusing the previous preview's `.aux` (often 1 pass instead of 2), and making sure the
     sandbox sees the fontconfig cache (otherwise fonts are re-scanned on every run).

## 7. Phases

Each phase ends with `bin/run_tests.sh` green (static + tex), `bin/check_web.php` green, `php -l` on
touched files, `node --check` on touched JS, and a manual pass in a browser on the server (Chromium/
Playwright if available) on `odigies-kyfoplastiki`, `proegkrisi`, `adeia`, `syntagi`, `shared/purpose`,
`shared/drug-library`. Commit per phase; update `CLAUDE.md` at the end of each phase with what exists.

| Phase | Content | Exit check |
|---|---|---|
| 0 | Read the code paths in §2; run the full test suite and note the baseline; record timing of a preview and a PDF build. | Baseline written in the phase commit message. |
| 0b | QR placeholder in previews (§6.7) with its tests and the before/after timing. Independent of the editor work; can ship first. | Composer preview shows the rectangle; final PDF and `check_m1.php` unchanged; render test guards pass. |
| 1 | Shell inside the top bar: header, status pill, unsaved marker, Save dialog (§5.1), tab bar, JSON tab = today's editor unchanged. New CSS file `web/css/type-editor.css` (scoped `.te-*`). | Every current action works from the new shell; tree test passes. |
| 2 | Structure tab (§4.1) incl. naming rule and Document settings. | Reorder/add/duplicate/delete equal to the tree; round-trip test. |
| 3 | Chip text editor module (§4.3) with its round-trip test, then the Content tab (§4.2) incl. choice groups and latex blocks. | Byte-identical round trip of every block text; self-check fallback shown for a non-round-tripping block; G3 regression unchanged (`bin/check_m1.php`). |
| 4 | Fields tab and rename dialog (§4.4). | Rename tests incl. dry-run counts; `bin/rename_fields.php` behaviour unchanged. |
| 5 | Preview panel (§4.8), issues with sources (§6.1), lints (§6.6), PDF tab (§6.4). | Go-to-source for missing/unknown tokens; PDF tab builds in the sandbox; no leftover builds. |
| 6 | Case rules tab (§4.5) + preview with rules (§6.2). | `proegkrisi` RF rule and `odigies-auxeniki` Διαρροή ΕΝΥ shown as sentences; with/without preview correct. |
| 7 | Shared components: Content, Used by, Update pin (§4.6, §6.3). | Update pin creates a draft type version with the new hash; nothing else changes. |
| 8 | Medication table editor (§4.7). | `syntagi` and `odigies-kyfoplastiki` default rows edit and round-trip. |
| 9 | Review & release page and structural diff (§5.2, §6.5); remove one-click release. | `type_diff_test.php`; release refused without `confirm=1`; secretary gets 403/redirect. |
| 10 | Polish: keyboard access (tabs, menus, Move up/down instead of drag, chips focusable), focus rings, contrast, long Greek labels, 1280/1024/narrow widths, empty states (no sections, no fields, no rules). | Manual checklist below. |

Keep the old editor reachable at `?ui=classic` until Phase 10 is accepted, then remove it.

### Progress

- **Phase 0 baseline (2026-11-07):** static suite 51/51, `check_web`, `check_types_sync`, `check_m1` green.
- **Phase 0b done (2026-11-07), built differently from §6.7:** the qrcode package's own **draft mode**
  (`\AtBeginDocument{\qrset{draft}}`, `RENDER_QR_PLACEHOLDER` in `web/render.php`) draws an empty frame of the QR's
  exact size at both positions. No redefinition of `\qrcode`, no coupling to `\evrQRmm`, no kit change. Trade-off: the
  frame has no "QR in final copy" note (the draft mark already says it is a preview). Guards: `render_test.php`
  (preview has it once, before the kit; a final copy has neither it nor `PREVIEW`), `issue_compile_final()` and
  `issue_send()` refuse a .tex that carries it. The two-up print of an issued document uses the issued revision.
  **Timing** (XeLaTeX in the sandbox, 2 passes each, 2026-11-07): A4 `odigies-kyfoplastiki` 13.5 s → 12.3 s,
  A5 `syntagi-a5` 13.2 s → 12.3 s. The QR was *not* the main cost: the kit's preamble alone takes ~5.3 s per pass
  (bare XeLaTeX 1.4 s, `fontawesome5` +1.7 s, Roboto fonts +1.6 s; the body is negligible; fontconfig cache is fine).
  XeTeX cannot dump native fonts into a format, so the remaining lever is fewer passes: reusing the previous
  preview's `.aux` (1 pass instead of 2, ~6 s saved). Not built: still undecided (§6.7).
- **Phases 1–4 done (2026-11-07)**, files: `web/templates/content/type_edit.zetem` (the old page is
  `type_edit_classic.zetem`, at `?ui=classic`), `web/css/type-editor.css` (library `type-editor`, scoped `.te`),
  `web/js/type_editor.js` (shell, Save dialog, unsaved marker, `beforeunload`, Structure tab), `web/js/type_chips.js`
  (§4.3), `web/js/type_content.js`, `web/js/type_fields.js`; `type_tree.js` exports its parser, token rule and renames
  as `window.MSTypeTree` (one copy of each). Tests: `tests/js/type_chips_test.js` (every block text of every type file:
  split/join byte-identical; the §4.3 cases; the naming rule) and `bin/check_chips_dom.js` (Chromium headless shell:
  every block text round-trips through a real contenteditable; typing, a chip, Enter, bold; a foreign element turns the
  row plain and writes nothing; an unsafe text opens plain with the note), both in the static suite. Checked in
  Chromium on the server: tabs and counts, Structure, Content (chips, hover token, choice-group radios, reveal from
  the preview), Fields, rename dry run (`bedrest_days` → 1 token, 0 operations), Save dialog; an edit in Content
  changes exactly one value of the definition.
  **Deviations, decided while building:**
  - Section name (§3): `label` first, then `heading` (the composer's rule, so the editor never names a section
    differently from the form), then the plan's fallbacks.
  - The JSON tab keeps today's Tree/JSON sub-switch; invalid JSON blocks leaving the tab.
  - "History" toggles the versions table (with Release, diff) in the page until phase 9's review page exists;
    "Review & release" and "Test PDF" are not shown yet (phases 9 and 5).
  - Markup (`{Ο|Η}`, `**bold**`, `{v:…}`) is shown as typed, without colour highlighting inside the editable text:
    highlighting means re-rendering spans while typing (caret risk) and is left for phase 10.
  - Unsafe characters (U+00A0, CR, zero-width and other control characters) always open as plain text: a
    contenteditable cannot be trusted to keep them. No real block text has one today.
  - A stored version is 4-space JSON (the server's `json_encode`); the editor writes 2 spaces, so the text box differs
    in whitespace after the first edit, as with the tree. The server re-encodes on save, so versions differ only by
    the edit.
  - Medication-table default rows and shared-component content are still edited in the JSON tab (phases 7, 8).
- **Phase 9 done (2026-11-07):** `web/type_diff.php` (`types_struct_diff()`, `types_word_diff()`, `types_section_name()` —
  the editor's naming rule in PHP; tests `tests/static/type_diff_test.php` on `odigies-kyfoplastiki` and `shared/purpose`),
  the page `GET /types/{kind}/{name}/review?v=N` (`page_type_review`, `type_review.zetem`): changes from the released
  version new documents use (for a released version or none released: the version before it; `&against=M` to choose)
  grouped by section, word-level highlight of changed texts, the raw JSON diff link, the checks (`types_check()` and the
  M/F sample's errors), the pinned shared versions, the impact text, the required checkbox. `action=release` now needs
  `confirm=1` (else back to the review page); the one-click Release of the history table is a "Review & release" link.
  `check_web.php` covers: refused without the confirmation, released with it, the secretary is refused the page.
  Addition: a removed and an added field with the same definition are reported as one **renamed** field.
  Lints and Go to source came with phase 5.
- **Phase 5 done (2026-11-07):**
  - §6.1: `render_markup()` records where an issue was found (`$ctx['src']`, set by every loop that renders a block, a
    topic's or section's free text, or a table; `render_issue_at()`), and `render_checks()` gives each check a `src`
    (a missing value: its field). Test in `render_test.php`. The panel also reads the place from the server's
    validation messages (`block <id>: …`).
  - §6.6: `types_lint()` (in `web/type_diff.php`): unused field, pinned-but-unused shared component, a block `datefmt`
    with no date in it (level `note`, folded in the panel: the kit imports put `d-m-Y` on many blocks), case-rule
    operations whose block/section/field is missing. Shown in the preview panel and on the review page. Section without
    heading or label is **not** linted: the naming rule already gives every section a readable name, and 7 of 10
    sections of a postop type would be flagged.
  - §4.8: tabs **Preview · Form · PDF**, Go to source on every check that knows its place, "updated hh:mm:ss", the
    "HTML approximation" note under the paper. The classic page keeps Form/Print.
  - §6.4: `POST /types/typedef/{name}/pdf` (`page_type_pdf_post`): the unsaved text with the sample values through
    `render_document(['preview' => true])` and `compile_job()` in the sandbox, purpose `type-preview`, no document;
    `GET …/pdf?b=ID` serves it to its own user only. **One build per user** is kept (not per type and user: the builds
    table has no type column, and one is enough), its store objects collected like `issue_prune_builds()`; a per-user
    `flock` refuses a second build while one runs (409). Built on request (not after every edit), marked stale when the
    text or gender changes. Measured: ~11–12.6 s, 2 passes. `check_web.php` covers 409, serving, another user refused,
    one kept, invalid text refused before building.
- **Phase 6 done (2026-11-07):** `web/js/type_rules.js` (Case rules tab): rules with chip label and id, their operations
  as sentences ("Turns off «…» (section › topic)", the choice-group effect as in `render_feature_add()`, "Sets the field
  <chip> to [value]", "Adds text to <section>: [text]"), reorder/remove, missing targets in red, a searchable sentence
  picker, field and section pickers. "Preview with this rule" (§6.2): `page_type_preview_post` takes `features[]`,
  renders the sample without and with the rules, and shows the with-rules page in which what they turned off is put back
  (a block of a choice group as the group's choice when the rules cleared it) and marked: struck through red, turned
  on/added/set green. Leaving the tab turns the rule preview off (a view's `hide()`). Checked: `proegkrisi`'s RF rule
  shows 7 sentences; with the rule 2 visible sentences are struck (the 2 others it turns off are not on by default, so
  "strikes 4" in §8 is not reachable with the sample) and 3 are added; `odigies-auxeniki`'s Διαρροή ΕΝΥ shows its 25
  operations. `check_web.php` covers the marks.
- **Phase 7 done (2026-11-07):** `/types/shared/{name}` has tabs **Content · Used by · JSON** (`web/js/type_shared.js`).
  Content: the sentences (alternatives) with "default in N types: …"; renaming or deleting one that a type uses as its
  default choice is refused in the browser with the list of types (the server's check unchanged); or the drug library as a
  grouped, searchable table whose cells show `{br}` as a new line (a cell that already holds a real new line is edited
  as it is). Used by (`types_used_by()`, server-rendered): each type's latest version, the component version it pins
  (resolved from the hash), its default choice, "Pinned, but no section uses it", and **Update pin** when a newer release
  exists: `action=update_pin` on the type makes a new **draft** type version with the component's latest release and the
  note "Update <name> to vN" (to review and release like any change). Checked: `purpose` lists 14 types, `preop-odigies`
  flagged (the plan's "13 types" are the 13 that use it); a no-op edit through the drug cells leaves the library
  unchanged; `check_web.php` covers listing, the offer and the new draft (only the hash differs).
- **Phase 8 done (2026-11-07):** `web/js/type_meds.js`, used by the Structure and Content tabs for a `medtable` section:
  the drug library (a select of the drugs components the type pins; the page passes their entries as `data-libraries`),
  the default rows as an editable table (cells show `{br}` as a new line; ↑ ↓ ✕; "+ Empty row"), and the library,
  grouped with counts and searchable, whose entries are copied as default rows ("a copy: later library changes do not
  change it"). Checked on `odigies-kyfoplastiki` (4 default rows) and `syntagi` (none): touching every cell leaves the
  definition unchanged; a copied entry lands as name/dose/freq/days/notes.
- **Phase 10, in part (2026-11-07):** tab bar by keyboard (← → Home End, roving tabindex, `tabpanel` roles); list rows
  are focusable (Enter selects, Alt+↑/↓ moves in Structure); chips are focusable and show their token on focus; focus
  rings on buttons, tabs, rows and inputs; widths checked at 1600, 1280, 1024 and 700 px (no sideways page scroll; the
  list/editor split stacks under 1100 px); empty states in every tab (a definition with no sections, fields or rules).
  **Not done:** a contrast audit with a tool; removing `?ui=classic` (after the doctor accepts).
- **Markup colours (2026-11-08):** the chip editor colours `**`/`*` (and shows the text between them bold/italic),
  `{Ο|Η}`, `{v:…}`, `{br}`, `\ ` and escapes, by the engine's own tokenizer rule (`markup()` in `type_chips.js`). The
  spans carry `data-mk` and are read back as their text, so the bytes cannot change; after each input the row is
  re-coloured and the caret put back at its offset in the text (not during IME composition, which Greek accents use).
  Chrome wraps typed characters in its own `<span style>` next to coloured text: `read()` accepts such inline wrappers
  (text only) and the re-colouring removes them; block elements (`div`, `p`) still turn the row plain. The editable
  element sets `white-space: pre-wrap` itself (without it a browser types spaces as U+00A0). Tested in
  `check_chips_dom.js`: colours, caret kept while typing in the middle, typed markup coloured at once, round trip.
  Known cost: the browser's own undo (Ctrl+Z) does not reach across a re-colouring.
- **PDF tab = composer, checked (2026-11-08):** `check_web.php` builds the PDF tab's PDF over HTTP, gives a document the
  same (sample) inputs and runs the composer's own `doc_preview()`: same `.tex`, same letterhead files, and the PDF
  tab's manifest rebuilt with the composer build's time stamp gives byte-identical PDFs. The two builds differ only by
  `SOURCE_DATE_EPOCH` (each preview stamps the time it ran). Found on the way: CLI scripts ran in UTC while the app
  uses `tz: Europe/Athens`, so "today" differed after midnight; `bin/lib/cli_boot.php` now sets the app's timezone.
- **Acceptance checklist (§8), run in headless Chromium on the server, 2026-11-07:**
  readable names and counts (kyfoplastiki 10 sections, 8 fields, Γενικές Οδηγίες 13/14) ✓; a non-round-tripping text
  (U+00A0) opens plain with the note, nothing written unless edited (`check_chips_dom.js`) ✓ (a literal `<` round-trips,
  so it opens as chips); an edit with a field changes exactly that one value ✓; chip label, token on hover ✓;
  field picker / bold (`check_chips_dom.js`) ✓; two blocks made alternatives, "none" set, the Form preview shows the
  choice ✓; rename `bedrest_days` → 1 token, 0 operations ✓; `proegkrisi` rule 7 sentences, with the rule 2 struck and
  3 added (4 struck is not reachable: 2 of the blocks it turns off are off by default) ✓; `purpose` Used by 14 types,
  `preop-odigies` flagged ✓; review page and release with the checkbox ✓ (`check_web.php`, `type_diff_test.php`);
  previews draw the QR frame, final copies unchanged (`render_test.php`, `check_m1.php`) ✓.
  PDF tab = composer: see the entry below. **Not checked:** the blocked tab switch with invalid JSON (an `alert`, which the headless run cannot answer), and the QR of a real
  final copy scanned with a phone.

## 8. Acceptance checklist (manual, on the server)

- Load each of the six test sources; every section has a readable name; counts are right
  (kyfoplastiki: 10 sections, 8 fields, Γενικές Οδηγίες 13/14 on).
- A block whose text does not round-trip (test it with a block containing a deliberately odd sequence,
  e.g. a U+00A0 or a literal `<`) opens as plain text with the note, and saving leaves it unchanged.
- Edit a sentence with a field: chip shows the label, hover shows `@bedrest_days`; save; the JSON
  diff shows only the intended change.
- Insert a field via the picker; add bold; add `{Ο|Η}`; preview both genders.
- Make two blocks alternatives, set the default, set "none"; the Form preview shows radio buttons.
- Rename `bedrest_days` → `bed_rest_days`: dialog says 1 token, 0 operations; `\@bedrest_days` in a
  test block untouched.
- `proegkrisi` case rule shows 7 sentences; "With this rule" strikes 4 and adds 3.
- `purpose` Used by lists 13 types with their defaults; preop-odigies flagged as pinned-but-unused.
- Invalid JSON in the JSON tab: switching tabs is blocked, the last good preview stays.
- Review page for a draft with one added block: the change list shows exactly that block; release
  needs the checkbox; after release, new documents use it and old documents keep their version.
- PDF tab produces the same PDF bytes as the composer would for the same inputs (except draft marks).
- Composer preview and PDF tab show the QR rectangle with its note at the header and (A4) at the end;
  the header does not shift compared with the final copy.
- The final review copy of a test document has a real QR that scans to `\QRBaseURL` + its DocArc code.

## 9. Findings in the current data (content is the doctor's to change; the editor may point them out)

- `types/syntagi.json` `note_no_reduce`: «μόνος/μόνη» where kyfoplastiki uses `{μόνος|μόνη}`.
- `types/preop-odigies.json` (draft) pins `purpose` but no section uses it.
- `types/adeia.json` `amka_row` carries `datefmt: d-m-Y` but contains no date.
- `types/syntagi.json` and `syntagi-a5.json`: the field `amka_value` («ΑΜΚΑ») is printed by no text (found by the lint,
  2026-11-07).
- Stored type versions hold the shared component's **hash**, not `latest`; "write latest in the shared
  map" (the current hint on the page) only works when editing JSON. §6.3 replaces it.

## 10. Out of scope

New markup features (underline, alignment, tables, images, links, lists inside a block), AI writing,
a rich-text format, a template gallery, app-wide navigation changes, the Greek interface, changes to
`types/*.json` content, the repo import/export workflow (`bin/import_types.php`, `bin/export_types.php`
stay as they are; mention them on the review page: "saved in the database only; export and commit").
