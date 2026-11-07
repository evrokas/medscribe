# Type editor UI redesign: plan

**Status:** plan, nothing built yet (2026-11-07).
**Scope:** the type editor (`/types/typedef/{name}` and `/types/shared/{name}`) and its release step.
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
   `\DocDraft` set and the placeholder QR, as the composer preview does), purpose `type-preview`, not
   tied to a document. Keep only the latest such build per type and user, and clean its store objects
   like `issue_gc_object()` does. Serialise per type with a short lock so double clicks don't run two
   XeLaTeX jobs. Expect ~12 s; show progress.
5. **Structural diff.** `types_struct_diff(array $a, array $b): array` in a new `web/type_diff.php`:
   match sections/topics/blocks/fields/features by id; report added/removed/moved/changed with paths
   and names (§3 naming rule, in PHP). Pure function, unit-tested in `tests/static/type_diff_test.php`
   on real type pairs (e.g. a type file vs. the same with one block edited, one moved, one field
   renamed).
6. **Lints (warnings only, never block a save):** field used 0 times; shared component pinned but not
   used; `datefmt` on a block without a date token; feature op with a missing target; section with
   neither heading nor label. Computed in PHP (`types_lint()`), shown in the issues bar and on the
   review page.

## 7. Phases

Each phase ends with `bin/run_tests.sh` green (static + tex), `bin/check_web.php` green, `php -l` on
touched files, `node --check` on touched JS, and a manual pass in a browser on the server (Chromium/
Playwright if available) on `odigies-kyfoplastiki`, `proegkrisi`, `adeia`, `syntagi`, `shared/purpose`,
`shared/drug-library`. Commit per phase; update `CLAUDE.md` at the end of each phase with what exists.

| Phase | Content | Exit check |
|---|---|---|
| 0 | Read the code paths in §2; run the full test suite and note the baseline; record timing of a preview and a PDF build. | Baseline written in the phase commit message. |
| 1 | Shell inside the top bar: header, status pill, unsaved marker, Save dialog (§5.1), tab bar, JSON tab = today's editor unchanged. New CSS file `web/css/type-editor.css` (scoped `.te-*`). | Every current action works from the new shell; tree test passes. |
| 2 | Structure tab (§4.1) incl. naming rule and Document settings. | Reorder/add/duplicate/delete equal to the tree; round-trip test. |
| 3 | Chip text editor module (§4.3) with its round-trip test, then the Content tab (§4.2) incl. choice groups and latex blocks. | Byte-identical round trip of every block text; G3 regression unchanged (`bin/check_m1.php`). |
| 4 | Fields tab and rename dialog (§4.4). | Rename tests incl. dry-run counts; `bin/rename_fields.php` behaviour unchanged. |
| 5 | Preview panel (§4.8), issues with sources (§6.1), lints (§6.6), PDF tab (§6.4). | Go-to-source for missing/unknown tokens; PDF tab builds in the sandbox; no leftover builds. |
| 6 | Case rules tab (§4.5) + preview with rules (§6.2). | `proegkrisi` RF rule and `odigies-auxeniki` Διαρροή ΕΝΥ shown as sentences; with/without preview correct. |
| 7 | Shared components: Content, Used by, Update pin (§4.6, §6.3). | Update pin creates a draft type version with the new hash; nothing else changes. |
| 8 | Medication table editor (§4.7). | `syntagi` and `odigies-kyfoplastiki` default rows edit and round-trip. |
| 9 | Review & release page and structural diff (§5.2, §6.5); remove one-click release. | `type_diff_test.php`; release refused without `confirm=1`; secretary gets 403/redirect. |
| 10 | Polish: keyboard access (tabs, menus, Move up/down instead of drag, chips focusable), focus rings, contrast, long Greek labels, 1280/1024/narrow widths, empty states (no sections, no fields, no rules). | Manual checklist below. |

Keep the old editor reachable at `?ui=classic` until Phase 10 is accepted, then remove it.

## 8. Acceptance checklist (manual, on the server)

- Load each of the six test sources; every section has a readable name; counts are right
  (kyfoplastiki: 10 sections, 8 fields, Γενικές Οδηγίες 13/14 on).
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

## 9. Findings in the current data (content is the doctor's to change; the editor may point them out)

- `types/syntagi.json` `note_no_reduce`: «μόνος/μόνη» where kyfoplastiki uses `{μόνος|μόνη}`.
- `types/preop-odigies.json` (draft) pins `purpose` but no section uses it.
- `types/adeia.json` `amka_row` carries `datefmt: d-m-Y` but contains no date.
- Stored type versions hold the shared component's **hash**, not `latest`; "write latest in the shared
  map" (the current hint on the page) only works when editing JSON. §6.3 replaces it.

## 10. Out of scope

New markup features (underline, alignment, tables, images, links, lists inside a block), AI writing,
a rich-text format, a template gallery, app-wide navigation changes, the Greek interface, changes to
`types/*.json` content, the repo import/export workflow (`bin/import_types.php`, `bin/export_types.php`
stay as they are; mention them on the review page: "saved in the database only; export and commit").
