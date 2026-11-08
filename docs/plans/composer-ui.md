# Document composer UI redesign: plan

**Status:** built (2026-11-08), awaiting the doctor's acceptance; see §12. `?ui=classic` stays until then.
**Scope:** documents list, new document, the composer (`/documents/{id}`), its preview, checks, and the
issue/history pages. **Not** the type editor: it has its own plan, `docs/plans/type-editor-ui.md`, and the
two share some building blocks (§9).
**Inputs:**
- `docs/plans/composer-ui/proposal/`: the design brief and three concept images supplied on 2026-11-08
  (`MEDSCRIBE-COMPOSER-UI-REDESIGN-PLAN.md`, called "the proposal" below). Its 21 invariants are adopted
  (§3) with the corrections noted; its layout is adopted in part (§2).
- `docs/plans/composer-ui/before/`: screenshots of the current pages, taken 2026-11-08 on a local copy
  with `odigies-kyfoplastiki` and `proegkrisi` drafts (no TeX there, so the preview pane is empty).

The doctor's goal in one line: **writing a document should feel like writing the letter, not like filling
in JSON or database fields.** Everything below serves that.

---

## 1. What makes the current composer feel like a database form

Seen in `before/03`, `04`, `05`:

1. **Values come first and stand alone.** `proegkrisi` opens with 15 inputs ("Χρήζει (επέμβαση)",
   "Μήνες υποτροπής", …) before a single sentence. Nothing shows where a value will appear.
2. **Every sentence is an open text box**, so the page reads as a form, ~6,400 px tall for kyfoplastiki.
3. **Raw markup is shown:** `@bedrest_days`, `**πάντα**`, `(π.χ.\ Voltaren…`.
4. **Technical names are shown:** section legends print the slot and layout
   (`import.odigies-kyfoplastiki.s3_t1 · itemize 4/4 on`); untitled sections are called by their slot.
5. **Gender text is unresolved:** `{Ο|Η}` is shown as typed even when the patient's gender is known.
6. **Status words are internal** (`draft`, `code_reserved`, `final_compiled`); the list shows type ids
   (`odigies-kyfoplastiki`) instead of labels.
7. **Feedback is slow and far away:** the only preview is a PDF (~12 s, button-triggered); checks sit at the
   bottom; there is no "unsaved" or "preview out of date" signal.
8. Page chrome on the local copy shows a stray "Announcements" line and a `::` breadcrumb bullet
   (`before/01`). Check whether the server shows the same; if so fix the region setup in
   `config/settings.info.yaml` (not part of this redesign otherwise).

## 2. Decisions

Taken already (carried over from the type editor work, 2026-11-07):
- **Keep the app's top bar.** The proposal's dark left sidebar is not adopted (same decision as for the
  type editor; one shell for the whole app). The look inside the page (white cards, blue primary, clear
  status pills, generous spacing) is adopted.
- **Field values appear as chips; hover shows the stored text** (type editor decision 2, applied here:
  a chip shows the value, hover shows `@field`).
- **Draft previews show a rectangle with a note instead of the QR; only the final copy has the real QR**
  (type editor plan §6.7). This matches the proposal's "preview QR frames".

Decided by the doctor on 2026-11-08:
- **A · No instant HTML preview.** The preview is the real PDF only, built by "Save & preview", with a clear
  "Out of date" marker when the form changed since (the proposal's position). The QR rectangle (above) makes
  each preview build cheaper.
- **B · The Write view is the default** (§4.2), with the current-style **Form view** one click away for people
  who prefer fields (§4.1).
- **C · No summary cards on the documents page.** The one real need behind them is that the doctor notices
  documents the secretary handed over ("Ready for review"; today that shows only as a badge, see M3 "Not
  built"). Proposed: the "Ready for review" filter chip carries its number and is the doctor's default view
  when the number is above zero (§5.2). Awaiting the doctor's confirmation; if declined, plain filter chips.

Mockups: https://claude.ai/artifact/58x9bjCfhJ71dwRXdju6Fy (private to the owner); sources in
`docs/plans/composer-ui/mockups/*.dc.html` (plain HTML/CSS inside an `<x-dc>` wrapper; read for layout and
wording, don't serve). They use real type content (kyfoplastiki, proegkrisi, syntagi, vevaiosi-mri) and made-up
patients, dates, numbers and codes. The edited sentence on `Edit.dc.html` is an invented example of a doctor's
edit, not proposed wording.

## 3. Invariants

The proposal's list (§6 there) is adopted as written, with these corrections and additions:
- #15 "preview QR frames": per §2, a labelled rectangle.
- #14 "preview is not silently refreshed": kept (decision A). Only the Write view's own sentences refresh
  as values or the gender change (§6.1); that is the editor, not a preview, and it saves nothing.
- **The form payload stays the same.** Field names posted to `page_document_post` are unchanged:
  `patient[...]`, `settings[...]`, `fields[id]`, `blocks_on[id]`, `groups[group]`, `btext[id]`,
  `sections_on[id]`, `extra[section]`, `meds[section][i][name|dose|freq|days|notes]`, `base_release_id`,
  `action` (`save|preview|ready|draft|feature_add|feature_remove`), `feature`. New UI only changes how they
  are produced. A test enforces this (§8).
- `btext` keeps its rule: the server stores an edit only when the trimmed text differs from the type's text
  (`doc_inputs_from_post`). The new editor must post exactly the text, so it uses the type editor's chip
  module with its **self-check** (type editor plan §4.3): a block that does not round-trip opens as a plain
  text box.
- `format: latex` blocks stay read-only in the composer.
- No second renderer: anything that shows formatted wording (bold, gender resolved, values filled in) is
  produced by PHP from the engine (§6.1), not re-implemented in JS.
- Role rules unchanged (secretary: no issue/approve/send/withdraw/library/types).
- No autosave.

## 4. The composer (`before/03–05`; concept `proposal/02-document-composer.png`)

Two panes as today (existing `splitpane` + `splitter.js`, three view modes kept): **document** on the left,
**preview** on the right. Narrow screens: tabs Document / Preview.

### 4.1 Header, patient, details

- **Header:** `#id`, type **label**, status pill in plain words (§5.1), "Unsaved changes" marker, buttons
  History (when it exists) and Issue… (doctor). `beforeunload` warning while unsaved.
- **Patient banner** (proposal's design): name, gender, age, AMKA, "From ZPMS" or "Typed by hand",
  **Edit**. Editing opens the four fields in place; note "changes apply to this document only". Missing gender
  on a Greek type shows an amber "Gender needed for the Greek text" right in the banner. Types with
  `has_patient: false` have no banner; `"optional"` shows "No patient (optional) · Add".
- **Document details** collapsed to one line: "Mandra · 08/11/2026 · Μετεγχειρητικές Οδηγίες · Ο ιατρός ·
  Doctor details only" with **Edit**. Expanded: clinic, date, printed title (with the type's alternatives),
  closing, stamp, recipient (when the type has one). **Letterhead version** sits under "Advanced" with its
  existing rule (not after the code is reserved).
- **View switch:** **Write** (default, §4.2) · **Form** (today's layout, cleaned up: no slots, human section
  names, values grouped as today).

### 4.2 Write view: the document as it will read

The left pane shows the document in order, section by section, the way it will print:

- **Section headings** as printed. A section without a heading gets the shared naming rule (type editor
  plan §3: `label`, else the first sentence in «»). Never slot or layout names. Topics show as numbered
  sub-headings, as in print.
- **Each sentence is shown as text, not a text box:** bold rendered, `{Ο|Η}` resolved for the patient's
  gender (both forms highlighted if the gender is missing), `{v:L4-5}` printed, values filled in.
  - In the margin: a checkbox (independent sentence) or a radio (alternatives of a choice group, drawn as
    one box with "None of these" as the last option).
  - Switched-off sentences are dimmed; each section shows on ones first and folds the rest behind
    "Show 3 more optional sentences".
  - **Click a sentence to edit it** for this document: it turns into the chip editor (type editor plan
    §4.3; field chips, hover = `@field`, self-check). Done/Esc returns to text. An edited sentence carries
    "Edited for this document · Restore original" (restore = post the type's text, which drops the edit, as
    today).
- **Values are filled in where they are used** ("fill in the blanks"):
  - a value appears in the sentence as a chip; empty, it shows the field's label as a grey pill
    («Ημέρες κλινήρης»); an empty required one is amber.
  - click the chip → small popover with the input (date picker for dates); the same value updates every
    place it appears.
  - A compact **Values** list (collapsed by default, count shown, e.g. "Values · 6 of 8 filled") lists every
    field, including those used only in sentences that are off, so nothing is hidden. This is today's Values
    block, demoted from first place.
- **Case features** as chips under the header: "+ Επανάληψη RF γασσερίου". Each shows what it does in one
  line ("Turns on 3 sentences, turns off 4"; reuse the type editor's operation sentences, plan §4.5). Adding
  or removing still saves first (`feature_add` / `feature_remove`).
- **Your own text:** "+ Add your own text" at the end of each section, a plain multi-line box (lists: one item
  per line, as today). Suggestions from earlier documents open in a side drawer per section ("Suggestions
  (3)") and are copied in for editing, as today. Field insertion: the chip editor's "@ Field" button replaces
  the global `@…` select.
- **Optional sections:** a switch in the heading ("Include this section"); off sections fold to one line.
- **Medication table** (§4.4).

### 4.3 Preview pane

- The real PDF from the last "Save & preview", with its build time. When the form has changed since, an
  "Out of date" pill and a "Save & preview" button sit above it (decision A; no HTML preview).
- The PDF keeps the draft mark and the QR rectangle; A5 print options move into a small "Print" menu.
- Narrow screens: Document / Preview tabs.

### 4.4 Medication table

- A real table: Drug, Dose, Frequency, Days, Notes, remove (×, large target). Cells grow for multi-line
  values; `{br}` is shown and edited as a real line break and posted as `{br}`.
- **Add medication** button with two choices: "From the drug library" (searchable, grouped, as today's
  select but as a list) and "Empty row". Default rows are copies; empty-name rows are dropped on save
  (unchanged).

### 4.5 Checks and the action bar

- **Sticky action bar** at the bottom of the document pane: Save · **Save & preview** (primary) · Ready for
  review / Back to draft; save state (Saved · Unsaved changes · Saving… · Not saved: a PDF is being built);
  checks summary ("All checks pass" or "2 need attention"), opening a list where each item jumps to the field
  or sentence (needs issues with sources, type editor plan §6.1).
- Checks keep their meaning: saving and previewing are allowed with errors; issuing is blocked.

## 5. Other pages

### 5.1 Status words (everywhere)
draft → Draft · review → Ready for review · code_reserved → Code reserved · final_compiled → Final PDF
ready to approve · approved → Approved, sending · pdf_sent → Sent, finishing · issued → Issued ·
withdrawn → Withdrawn. Pill colour plus the word; the internal value stays in a tooltip.

### 5.2 Documents list (`before/01`; concept `proposal/01`)
- Columns: document (#id · type **label**), patient, status pill, updated (relative: "Today 11:18"), by.
- Filter chips by status (All · Drafts · Ready for review · Issued · Withdrawn) and a search box on patient
  name/code (simple `LIKE` on `documents.patient_name` and `doc_code`; both are columns already).
- No summary cards (decision C). Proposed: the "Ready for review" chip shows its number, and the doctor's list
  opens on it when the number is above zero; the secretary's opens on "Drafts". (Awaiting confirmation; mockup
  `List.dc.html`.)

### 5.3 New document (`before/02`)
- One page, three clear blocks: **Document type** (searchable list of type labels), **Patient** (ZPMS search
  first with "or type the patient by hand"; hidden for patient-free types; gender explicit), **Clinic and
  date**. Button **Create draft**. Copying rules and age-at-date unchanged.

### 5.4 Issue page (`before/06`; concept `proposal/03`)
- A three-step strip: **1 Prepare** (summary: patient, DocArc type link, clinic, checks; button "Reserve code
  and build final PDF", disabled with the reason when checks fail or the type is not linked) → **2 Review the
  final PDF** (the exact file; "nothing has been sent yet"; Approve and send / Edit again, code kept) →
  **3 Issued** (code, verification code, PDF and .tex links, Copy as new). `approved`/`pdf_sent` show
  "Sending stopped: Send again" with the existing retry semantics. Withdraw stays separate, with reason and a
  clear consequence text.
- History: a list per revision with its files and "Rebuild and verify" (doctor).

### 5.5 Library
Restyle only (cards with excerpt, source, date; Promote/Demote/Hide).

## 6. Backend additions

1. **Rendered sentences for the Write view:** a PHP function giving, per block, display HTML for given inputs
   (markup applied, gender resolved, values as chip spans with `data-field`), built from the engine's own
   functions (`render_block_text`, the markup converter behind `render_html_document`). Sent with the page, and
   by `POST /documents/{id}/sentences` (same access as the composer) for the unsaved form after a value or the
   gender changes, so the Write view refreshes without a JS copy of the engine. That endpoint saves nothing,
   takes no lock and builds no PDF; it returns only the sentences, not a document preview (decision A).
2. **Checks for the unsaved form:** the same endpoint returns `render_checks()` for the unsaved form, so the
   action bar's checks summary is current before saving.
3. **Checks with sources:** shared with the type editor (its §6.1).
4. **Documents list filters/search:** query parameters on `page_documents`, prepared statements, indexed
   columns only.
5. **QR rectangle in previews:** type editor plan §6.7 (do it once).

## 7. Phases

Each phase ends with `bin/run_tests.sh`, `bin/check_web.php`, `bin/check_m1.php` and `bin/check_m3.php`
green, `php -l`/`node --check` on touched files, and a manual browser pass with `odigies-kyfoplastiki`,
`proegkrisi` (choice groups, case rule), `syntagi` (medication table), an English type
(`recommendation-en`) and a patient-free type (`timokatalogos`), as doctor and as secretary. Commit per
phase; update `CLAUDE.md` with what exists.

| Phase | Content | Exit check |
|---|---|---|
| 0 | Read the composer code (`web/documents.php`, `composer_type_part.zetem`, `document_edit.zetem`, `web/issue*.php`); run all tests, note the baseline; write the payload test (§8) against the current form. | Baseline + payload test green on the old UI. |
| 1 | Shared pieces (with the type editor if not done yet): CSS tokens/components, status words, section naming rule, QR rectangle (§6.5). | Pages unchanged in function; status words everywhere. |
| 2 | Documents list and new document (§5.2, §5.3). | Filters/search correct; patient-free and English types create drafts as before. |
| 3 | Composer header, patient banner, details line, sticky action bar, unsaved/beforeunload (§4.1, §4.5 without checks list). Form view = today's form cleaned (no slots). | Payload test identical; save-while-building refusal still hands the form back. |
| 4 | Rendered sentences and the `sentences` endpoint (§6.1, §6.2); PDF pane with build time and "Out of date" (§4.3). | Rendered sentence text equals the PDF text for the test documents; the endpoint saves nothing and takes no lock. |
| 5 | Write view (§4.2): sentences as text, checkboxes/radios, fold of off sentences, inline edit with the chip editor + self-check, restore original, values as chips with popovers, Values list. | Payload test identical for unchanged forms; edit → restore leaves no `blocks[id].text`; every type's blocks pass the self-check or fall back. |
| 6 | Case feature chips with effect lines; own-text boxes; suggestions drawer; medication table (§4.4). | Feature add/remove restores state; empty med rows dropped; `{br}` round-trips. |
| 7 | Checks list with go-to-source (§6.2, §6.3); issue page steps; history list (§5.4). | Issue blocked with reason when checks fail or type unlinked; retry path unchanged (`check_m3.php`). |
| 8 | Polish and accessibility (keyboard, focus, contrast, zoom, long Greek names, 1280/1024/narrow), library restyle. | Manual checklist below. |

Keep the old composer at `?ui=classic` until phase 8 is accepted.

## 8. Tests to add

- **Payload test** (`tests/functional/composer_payload.php` or in `bin/check_web.php`): for each test type,
  load the composer, submit it unchanged in the old UI and in the new one, and require identical stored
  `inputs` JSON. Repeat with: one sentence edited, one choice changed, one value filled, a medication row
  added, a feature added and removed.
- **Rendered sentence test** (`tests/static/`): display HTML for every block of every type with a male and a
  female sample contains no `@`, `**`, `{Ο|Η}` left over, and its text equals the corresponding text in
  `render_html_document()`.
- **`sentences` endpoint:** saves nothing (document `udate` unchanged), works while a PDF build holds the lock.
- Status words: every value in `DOC_STATUSES` and the issue statuses has a label.

## 9. Shared with the type editor plan

Build once, use in both: CSS tokens and components; the section naming rule; the chip editor with its
self-check; operation sentences for case features; issues with sources; the QR rectangle; the HTML print
view path. If the type editor work is not done first, phase 1 here builds these and the type editor reuses
them.

## 10. Acceptance checklist (manual, on the server)

- kyfoplastiki: the page opens with the patient banner and the document as text; no slot names, no `@` or
  `**` visible; "Παραμείνετε κλινήρης για «Ημέρες κλινήρης» ημέρες…" shows the label pill until filled; filling
  it in the popover updates the sentence and the Values list.
- Male → female in the banner changes «Ο ασθενής» to «Η ασθενής» in the Write view (and in the PDF after Save & preview).
- proegkrisi: the "Ένδειξη" alternatives are radios with "None"; the RF chip says what it changes; adding and
  removing it restores the previous choices.
- Edit a sentence, save, see "Edited for this document"; Restore original, save, the edit is gone.
- A block that fails the self-check opens as a plain text box and is saved unchanged.
- syntagi: add Medrol taper from the library, the frequency shows four lines; save; reopen identical.
- After any change the PDF pane says "Out of date" until Save & preview; the PDF shows the QR rectangle.
- Save during a running PDF build: refused, typed text still there.
- Secretary: no Issue button; "Ready for review" works; the doctor's list shows the document under
  "Ready for review".
- Issue: steps 1→2→3 with the real QR in the final PDF; Edit again keeps the code; history lists the revision.

## 11. Out of scope

Autosave; new markup; changes to the type format, the engine output, DocArc/ZPMS integrations, permissions
or statuses; a template gallery; the dark sidebar; document revisions after issue (waits for DocArc
versioning); the Greek interface.

## 12. Progress

- **Phase 0 (2026-11-08):** baseline 63/63 (`bin/run_tests.sh`, 11 min 44 s). Payload test `bin/check_payload.php` on
  kyfoplastiki, proegkrisi, syntagi, recommendation-en and epistoli (no released type has `has_patient: false`; the
  patient-free M8 types are drafts). A case feature's undo can leave `extra` absent where it was `[]`: same meaning, the test
  treats them as equal.
- **Phase 1 (2026-11-08):** `web/ui.php` + `web/css/ui.css` (status words and pills on the list, composer, issue and history
  pages; section naming rule; shared tokens, used by the type editor's CSS), breadcrumbs styled and "Announcements" removed
  (§1.8). The QR rectangle was already built (type editor phase 0b: qrcode's draft mode, an empty frame of the same size,
  no text label). Unit test `tests/static/ui_test.php`.
- **Phase 2 (2026-11-08):** documents list (`page_documents`: filters All · Ready for review (counted) · Drafts · Issued ·
  Withdrawn, "Drafts" also holding documents half-way through issuing; search on patient name, code or `#id`, prepared
  `LIKE` with `%`/`_` escaped; the doctor opens on "Ready for review" when it is not empty, the secretary on Drafts, decided
  2026-11-08; type labels, clinic, relative times). The `documents` table has no index besides the key (the entity YAML has no
  index syntax); fine at this size. New document (`doc_new_types()`): a filterable type list, the patient block hidden for
  `has_patient: false`, marked optional for `"optional"`, gender as two explicit choices, `?type=` preselects. Checks added
  to `bin/check_web.php`.
- **Phase 3 (2026-11-08):** `document_edit.zetem` rebuilt: header (back link, type label, status pill, "Unsaved changes",
  History/Issue), patient banner (initials, gender · age · AMKA · source, amber "Gender needed for the Greek text", Edit opens
  the fields in place; "No patient (optional)" for optional types), details line (Edit; letterhead under Advanced), sticky
  action bar (checks summary with its list, save state, Delete draft through `form="delete-form"`, Ready/Back, Save, Save
  and preview). `web/js/composer.js` keeps the summaries in step, marks unsaved changes and warns before leaving;
  `web/css/composer.css` (`.cx`). Section legends use `ui_section_name()` (no slot or layout). The old page is
  `document_edit_classic.zetem` at `?ui=classic` (its form posts to `?ui=classic` and comes back there).
- **Phase 4 (2026-11-08):** `web/sentences.php` (`sentences_for()`: per block, display HTML from `render_markup()` in trace
  mode + `render_html_tex()`; values as `.cx-v` chips with `data-field`, empty ones as their label (`.cx-v-empty`,
  `.cx-v-req`), a missing gender as both forms in `.cx-g` through a `mark_gender` context flag that only this file sets).
  `POST /documents/{id}/sentences` (`page_document_sentences()`): the unsaved form in, `{ok, blocks, checks}` out; CSRF,
  same access as the composer, withdrawn documents the doctor's only; saves nothing, takes no lock. PDF pane: build time,
  "Out of date" (saved after the build, or the form changed), its own Save and preview, A5 print options in a Print menu.
  Exit check: `tests/static/sentences_test.php` compares every block of every type (male, female, no gender; values filled
  and empty) with the engine's HTML print view, which comes from the same LaTeX as the PDF; no PDF text extraction.
  `bin/check_web.php`: the endpoint answers while the lock is held and changes nothing. `ui_section_name()` names built-in
  tokens (`«[AMKA]»`, not `«@amka»`).
- **Phase 5 (2026-11-08):** the Write view (`web/js/composer_write.js`, default; Write · Form switch in the header,
  remembered per browser). It is the same form shown differently, so it adds no form control: each block carries its
  rendered sentence (`.cx-say`, from `sentences_for()`) next to its textarea (which holds the text and `data-original`);
  checkbox/radio in the margin, choice groups as one bordered column with "None of these"; off sentences dimmed and folded
  behind "+ N more optional sentences"; all sections open except optional ones switched off. A click (or Enter) on a
  sentence opens the chip editor (`type_chips.js`, self-check and plain-text fallback; Bold, "@ Field", Done/Ctrl+Enter,
  Cancel/Esc); Done writes the textarea. "edited for this document · Restore original" follows the textarea. A value chip
  opens a small box bound to the real `fields[id]` input (dates through the date picker). Values list collapsed with
  "N of M filled". After a value, choice, gender or text change the sentences and the checks are fetched from the
  `sentences` endpoint (debounced). Checked in a real browser (`tools/shots.php` with `SHOT_JS`): edit → Done → save →
  reload shows the edit; Restore → save → gone; a value through its chip reaches the field and the sentence.
  `bin/check_payload.php` adds "restore original leaves no stored edit". Medication tables, own text, suggestions and case
  features keep their Form-view controls until phase 6.
- **Phase 6 (2026-11-08):** case feature chips with their effect line (`doc_feature_effect()`: "Turns on 3 sentences,
  turns off 4 sentences"; add/remove still save first); own text as "+ Add your own text" / "+ Add your own items (one per
  line)"; suggestions in a drawer per section ("Suggestions (N)", "Use" runs the existing insert into the section's own-text
  box); medication tables as a table with borderless cells that grow, a large ×, and "+ Add medication" (Empty row, or the
  drug library searchable and grouped; the library select stays in the HTML, hidden, for no-JS). `{br}` is shown as a line
  break in the cell textareas and turned back into `{br}` on submit; an unchanged cell posts exactly what the server sent.
  Checked in a real browser: Medrol taper from the library shows four lines, posts and stores `{br}`.
- **Phase 7 (2026-11-08):** checks carry their source to the page (`data-src`); a click (or Enter) on a check opens its
  place: a value chip in a sentence that is on (and its value box), else the field in the Values list; a sentence; an
  own-text box; a section; checks with no source go by subject to the patient banner or the details line (fields opened,
  folded sentences unfolded, a short highlight). Issue page as three steps (Prepare → Review the final PDF → Issued) with a
  summary card (patient, DocArc type link, clinic, code, verification code, checks); step 1 says why it cannot run yet
  ("Not yet:" DocArc off, type not linked, N checks fail) and its button is disabled; "Sending stopped: Send again" for
  `approved`/`pdf_sent`; Withdraw and Delete stay in their own red sections. History: one card per revision with PDF,
  .tex, "Rebuild and verify" and the files it was built with. Forms and actions unchanged (`check_m3.php` drives the
  functions; it passed after this phase).
- **Phase 8 (2026-11-08):** widths: below 1180 px the action bar is compact (save state hidden), below 900 px
  **Document / Preview** tabs (sticky) replace the two panes and the bar takes two rows; "Delete this draft" moved out of
  the bar to a quiet link above it. Dimmed sentences darker for contrast (#66777D). Library as cards (tier pill, slot,
  text, uses, last used, Promote/Demote/Hide; same forms). `docs/composer.md` rewritten for the new pages with new
  screenshots (invented patients only; the list shot is filtered to them). `tools/shots.php` gained `--names`,
  `--genders` and a rendered first PDF page in place of the iframe (`pdftoppm`), since headless Chromium has no PDF viewer.

### Acceptance run (2026-11-08, headless Chromium on the server, made-up drafts)

- kyfoplastiki, Write view: 42 sentences, no `@token`, no `**`, no `{x|y}` left, no slot or layout name in a heading;
  «Ημέρες κλινήρης» shows as an empty-value pill; filled through its box it updates the sentence and the Values count. ✓
- proegkrisi: gender F → M changes «Η ασθενής» to «Ο ασθενής» in the Write view without saving. ✓ The «Ένδειξη»
  alternatives are radios with "None of these"; the RF chip says "Turns on 3 sentences, turns off 4 sentences"; add and
  remove restore the choices (`bin/check_payload.php`). ✓
- Edit a sentence → Done → save → reload shows the edit; Restore original → save → gone (no `blocks[id].text`). ✓
- A text that does not round-trip opens as plain text: the chip editor's own check (`bin/check_chips_dom.js`, every
  type's text). ✓
- syntagi: Medrol taper from the library shows four lines, posts and stores `{br}`. ✓
- "Out of date" after a save following a build; hidden when there is no PDF. ✓ The PDF has the QR frame (preview). ✓
- Save during a running build: refused, typed text handed back (`bin/check_web.php`). ✓
- Secretary: no Issue button (checked in the browser); Ready for review works and the doctor's list opens on it
  (`bin/check_web.php`). ✓
- Issue steps: step 1 with its "Not yet" reasons checked in the browser; steps 2–3 and the real QR are covered by
  `bin/check_m3.php` against a throw-away DocArc, **not clicked through against the live DocArc** (that would create real
  records). To do by the doctor on a real document.
- Narrow (420 px), 1024 and 1440–1600 px checked by screenshot. Keyboard: sentences, checks, view switch and tabs are
  reachable and work with Enter; Esc closes the editor, value box, drawer and drug menu. Value chips inside sentences are
  mouse targets; by keyboard the same values are in the Values list.
