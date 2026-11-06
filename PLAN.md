# MedScribe: design plan (v5, for review)

Status: **draft, 2026-11-06.** Decisions marked ✅ were taken in the planning conversation; open
questions are in §11. Nothing is built yet.

Changes since v1:
- **Template storage is decided:** an internal versioned store with a version number and SHA-256
  per template file (§5).
- **Documents can be freely edited away from their template** (D9).
- **Base-template versions can be chosen per document** (D10).
- **New §6:** the template hierarchy found by scanning the kit.
- **v3:** research on the documents a surgical practice needs (§6.5); the scales family; the new
  types chosen (D11–D14); milestones M8–M9; open questions 11–13.
- **v4:** the content reuse model (§12): sections as shared slots, history fed automatically by
  every issued document, archive seeding, reuse across types, @tokens, case features and modules
  (D15–D17); future Bayesian proposals (§13); blocks versioned as sources (§5); milestones updated.
- **v5:** module specification (§12.3); directory layout (§14); DocArc document types and clinics
  owned by DocArc (D20); two-stage issue, revisions under one DocArc code, withdrawal, document
  history (§7.4–7.5); a consistency and failure-case review (§7.4 states and failures, §7.2 safety).

## 1. What MedScribe is for

You already have a working LaTeX letterhead (`evrtemplate.tex` + `evr-gr.tex` / `evr-en.tex` + images)
and 21 document-type templates (`tpl-*.tex`) in the evrdocs kit. Writing a document today means:

1. copy a template and rename it;
2. move `%` signs to choose the clinic, closing, stamp and title;
3. turn sentences and whole blocks on or off: the postop templates have ~85–140 commented
   alternatives each, plus a 32-drug commented library;
4. replace every `[placeholder]` by hand, and fix Greek gender agreement (ο/η ασθενής, του/της);
5. look up the patient in ZPMS and type their details;
6. compile with XeLaTeX, get a code from DocArc, paste it into `\DocCode`, compile again, then upload
   the PDF to DocArc.

Steps 1–6 are structural work. **MedScribe takes all of it over, so that the only thing left for you
is the medical text itself.**

Writing is not static. No two documents are the same: each is **its template, modified**, with text
added, removed or reworded for the patient. Templates change over time too, and so does the
letterhead (a clinic's phone number, a new logo, a layout fix). MedScribe has to support both kinds of
change without ever losing the ability to rebuild an old document exactly.

## 2. Goals and targets

| # | Goal | Target (how we know it's met) |
|---|---|---|
| G1 | No structural LaTeX work for routine documents | Every one of the 21 templates can be produced without seeing or editing LaTeX |
| G2 | Fast | From the ZPMS patient page to an issued `vevaiosi-mri` (PDF in DocArc, QR printed): **under 1 minute**. A postop letter: **under 5 minutes** |
| G3 | Same look as today | For the same inputs, a MedScribe PDF matches the hand-filled kit PDF (text and layout); checked by an automated regression test per type |
| G4 | Correct Greek | Gender agreement comes from the patient's gender automatically; Greek text, accents and hyphenation are correct; user text can never break the LaTeX build |
| G5 | Reproducible and traceable | Every issued document records the exact version and SHA-256 of every template file it was built from. It can be rebuilt from those sources at any time, even after the templates have moved on |
| G6 | Each app keeps its role | ZPMS stays the patient record, DocArc the archive and QR service. MedScribe stores the template sources and what it needs to rebuild a document |
| G7 | The text pool grows from your writing | Every issued document feeds the pool automatically; text written once is offered as a suggestion next time, with no extra action (§12) |

**Out of scope (non-goals):** a clinical record (that's ZPMS), a document archive (that's DocArc),
official electronic channels (e-prescription and e-referral through ΗΔΙΚΑ, e-ΕΦΚΑ sickness
certification, the ΚΕΠΑ electronic file); MedScribe writes the accompanying letters only, a general-purpose LaTeX/WYSIWYG editor, automatic text generation,
a full version-control system (no branches or merges; see §5).

## 3. Decisions so far

| # | Decision | Status |
|---|---|---|
| D1 | **Writing model: blocks + free text.** Each type is shown as sections; the document is a list of block cards per section, pre-filled with the type's defaults. Blocks are added from a suggestions pane (§12), edited in place, or written new; fields are typed values or @tokens. MedScribe generates the LaTeX | ✅ |
| D2 | **Templates live in MedScribe's own versioned store.** Each template file has numbered versions, each identified by its SHA-256. The evrdocs kit is the starting point (imported as version 1) and can still exchange versions with MedScribe (§5.5) | ✅ concept; the details in §5 are for review |
| D3 | **Users: you + secretary.** The secretary prepares drafts; you complete and issue them | ✅ |
| D4 | **Login: ZeusFw's own users/roles** (ZPMS-style RBAC), not ErnsAuth SSO | ✅ |
| D5 | **Lifecycle: issued = locked.** Revisions are new versions of the same DocArc record, under the same code, each with a required reason (DocArc's versioning design, §7.4); mistakes are withdrawn, not revised; "copy as new" reuses the inputs for another patient | ✅ |
| D6 | **Ways to start:** a "New document" button on the ZPMS patient page (patient already filled in), MedScribe's own patient search (through ZPMS), or a patient typed by hand who is not in ZPMS (D23) | ✅ |
| D7 | **All document types are needed;** `vevaiosi-mri` is the first, as the end-to-end pilot | ✅ |
| D8 | **Every issued document feeds the text pool automatically** (no approval step). Suggestions come from blocks of past issued documents; promoting a block to library/default stays manual and is the point where it is cleaned by hand (§12.1). *Replaces the earlier "save to library on request only"* | ✅ 2026-11-05 |
| D9 | **Each document is a modified copy of its template.** Beyond choosing blocks, any block's text can be reworded or removed for that document only, and free text can be inserted anywhere. The template is never changed by writing a document | ✅ |
| D10 | **Base-template versions are selectable.** A new document uses the current released letterhead by default, but an earlier version can be chosen. The choice is recorded with the document | ✅ |
| D11 | **Ten families + scales.** The six kit families (§6.4) sit within the ten care-pathway families of §6.5, plus an eleventh: **clinical scales & questionnaires** (patient questionnaires and doctor-recorded scales/exam charts, scored automatically). *The scales family is deferred (2026-11-06): kept for later, not planned now (§6.5 E, M9, Q11–Q13)* | ✅ families · ⏸ scales deferred |
| D12 | **New types beyond the kit:** pre-operative instructions, fitness certificates (work, driving, travel) and practice documents | ✅ |
| D13 | **Every type has attributes:** family, legal nature (certificate needs an examination date), audience, clinical anchor, signature, has-patient (§6.5 C) | ✅ |
| D14 | **Official electronic channels are out of scope** (ΗΔΙΚΑ e-prescription/e-referral, e-ΕΦΚΑ, ΚΕΠΑ file); MedScribe writes the accompanying documents | ✅ |
| D15 | **Seed the text pool from the existing archive.** The old letters (`overleaf/archive`, `post-surg`, ~95 files) are imported once into history, with each letter's patient details (`\PatientName`, dates, levels) recorded so the swap on insertion (§12.1) works, so MedScribe starts with years of the doctor's own wording (§12) | ✅ 2026-11-05 |
| D16 | **Reuse across types.** Suggestions come from the same slot in the same type, then the same family, then all text. Slots are named consistently across types so text can travel between them (§12) | ✅ 2026-11-05 |
| D17 | **Case features + modules.** A document's case is described with structured feature chips (procedure, area, levels, complications, comorbidities…), not free prose. Each feature has a *module*: blocks spread across slots that it adds, swaps or adjusts (`SET` for value-only changes, §12.3); any feature, value features included, may carry one. Modules stack, conflicts are left to the doctor, every line shows which feature added it, and the doctor reviews; medication rows a module proposes stay unticked until confirmed. The app only selects the doctor's own blocks, never writes clinical wording. Free-text → chips is a later convenience (§12.2) | ✅ 2026-11-05 |
| D18 | **Types are stored as structured data (Q1 → c).** The master of each type (and block) is structured data in MedScribe's store; a kit-style `.tex` is generated from it for reading diffs as a letter and for export to Overleaf. The generated `.tex` is never edited and re-imported as a type | ✅ 2026-11-05 |
| D19 | **Base layer: hybrid master (Q7).** Layout *code* (`evrtemplate.tex`, images) is mastered in git (the evrdocs kit) and imported into MedScribe as a draft version. Layout *data* (doctor details, clinics, labels) is mastered in MedScribe, which generates `evr-gr.tex` / `evr-en.tex` from one form so the two languages cannot drift. Base releases (B1, B2…) are made only in MedScribe | ✅ 2026-11-05 |
| D20 | **DocArc document types are DocArc's.** MedScribe fetches the list from DocArc and links each MedScribe type to **exactly one** of them, so every document gets exactly one DocArc document type and one DocArc code. The link is many-to-one, not 1-1: e.g. all five `odigies` types can share one DocArc type. DocArc has the final word: MedScribe never creates or renames DocArc types, re-checks the link when issuing, and blocks issuing (flagging the type "relink") if DocArc no longer has it. DocArc's clinics are linked to MedScribe's `\Clinic` keys the same way. Issuing is two-stage (§7.4): request the code (DocArc creates the record and returns it), compile with the QR, the doctor approves the final PDF, then send it with the `.tex` source as a supplementary file | ✅ 2026-11-06 |
| D21 | **Only the doctor issues (Q2).** Every type has a "secretary may issue" setting, off for all types; it can be switched on later for specific types that carry no signature or stamp | ✅ 2026-11-06 |
| D22 | **Verification code per type (Q5).** Each type sets whether its DocArc record requires a verification code before the QR link serves the PDF; on by default, as DocArc's own default. The code is the patient's AMKA when known, otherwise a random 6-digit code that MedScribe shows the doctor to give the patient (kept with the document, doctor only, so it can be given again). It can be switched off for types a third party must open freely | ✅ 2026-11-06 |
| D23 | **The patient need not be in ZPMS (Q8), for every type.** The patient can come from ZPMS or be typed by hand, and every printed patient detail can be changed on the document (e.g. the Latin spelling of a name for an English letter). What is printed is the document's own snapshot; changes made there are not written back to ZPMS | ✅ 2026-11-06 |

## 4. Core concepts

```
 Template store (versioned, §5)                       Document (one per patient letter)
 ──────────────────────────────                       ─────────────────────────────────
 base:  evrtemplate.tex  v3 ─┐                        patient snapshot (ZPMS or typed)
        evr-gr.tex       v5  ├─ base release B4 ───┐  settings (clinic, title, stamp…)
        evr-en.tex       v2  │                     ├▶ choices, field values, free text,
        images/*         v1  ┘                     │  local edits, medication rows
 type:  vevaiosi-mri     v2 ─ sections → blocks ───┤  status: draft → review → issued → superseded/withdrawn
 shared: drug library    v7, purpose phrases v1 ───┘  manifest: every source + version + SHA-256
                                                      DocArc token, .tex, PDF
 content pool (§12)                                   case features (chips) → modules
 ──────────────────                                   ───────────────────────────────
 slot (= a named section) ──< block ──< versions      each feature's module ADD/SET/SWAP…
      tiers: default · library · history              across slots (D17)
```

- **Document type** (e.g. "Βεβαίωση συμβατότητας MRI"): the same idea as a `tpl-*.tex` file. It holds:
  - **Defaults:** language, paper size, title (plus alternative titles), closing, stamp, page numbers.
  - **Its DocArc document type** (D20): exactly one, chosen from the list DocArc publishes. Several
    MedScribe types may share one DocArc type.
  - **The patient block:** the `\Info` rows it shows (Διάγνωση, Ημ. Χειρουργείου…).
  - **An ordered list of sections.**
- **Section:** an optional heading plus a *layout*, matching what the kit already uses:
  - `paragraphs`;
  - `itemize`, or `enumerate` with sub-items;
  - `description` (Επανέλεγχοι);
  - `patient-info` rows;
  - `medtable`;
  - `urgentbox`.

  A section can be optional, i.e. switched off as a whole: today that is a commented-out
  `\section*` or `UrgentBox`.

  **A section is a slot.** Each section has a stable dotted name (e.g. `instructions.wound_care`,
  `instructions.activity`, `followup.schedule`) besides its printed heading. The same name in two types means the same kind of
  content, which is what lets text travel between types (D16). There is no separate slot concept.
- **Block (phrase):** one sentence or item. Each has:
  - **Default state:** on or off (today: uncommented or commented).
  - **Choice group:** blocks in the same group are alternatives, choose one (today: "move the `%`").
  - **Fields:** typed placeholders such as `[3] ημέρες` → a number field defaulting to 3, or a date
    or a free-text field. A field's value can come from the patient, the document or you.
  - **Gender variants:** written inline (notation to be fixed in M1, e.g.
    `{ο|η} ασθενής υποβλήθηκε`). They resolve from the patient's gender, and are asked for when ZPMS
    has none.
  - **Optional formatting:** bold, `\V{L4-5}` spine levels.
  - **@tokens:** field references such as `@patient`, `@surgery_date`, `@level`, which take each
    document's values (§12.1).
  - **Its own version history** (§5.1) and a `derived_from` link when it is a variant of another
    block, plus a **tier**: default, library or history (§12).
- **Shared components:** blocks used by more than one type, stored once and versioned once rather
  than copied into each template. The scan (§6) found two that are copied by hand today:
  - **The drug library:** 32 entries, byte-identical in 7 files. Each entry has a name, form,
    default dose, frequency, days and notes. A medication table is built by picking drugs and
    adjusting rows.
  - **The "purpose" sentences:** the 7 "Το παρόν χορηγείται…" alternatives, identical in 9 files.
- **Case features and modules** (§12.2): structured chips describing the case (procedure, area,
  levels, complications, comorbidities). A feature's module is a versioned list of operations on
  slots (ADD, SET, SWAP, DISABLE, ENABLE, PROPOSE MEDROW; §12.3).
- **Document:** one letter for one patient. It holds:
  - **Inputs:** the type version it started from, a snapshot of the patient (from ZPMS, with its
    `guid`, or typed by hand, D23), every choice, value,
    free text and medication row, and its **local edits** (D9): blocks reworded, removed or added
    for this document only.
  - **Its case features**, recorded with every issued document (they rank suggestions now and
    are the data for §13 later).
  - **A manifest** of the exact source versions it was built from (§5.3).
  - **Lifecycle:** draft → *ready for review* (the secretary's hand-over, §8) → issued (locked) →
    superseded by an approved revision (§7.5), or withdrawn if it was a mistake (§7.4). A draft may
    also be issued directly by the doctor. The issue itself passes through the states of §7.4.
  - **Once issued:** the DocArc code (one for the document's whole life), plus, per revision, the
    exact `.tex` and PDF that were sent there.

## 5. Template sources and versioning

### 5.1 What gets versioned

Every file that can end up in a build is a **source** with its own history. A source has a stable
name, e.g. `base/evrtemplate.tex`, `base/evr-gr.tex`, `base/images/logo-stamp.png`,
`type/vevaiosi-mri`, `shared/drug-library`, `block/<id>`, `module/<id>`. There are six kinds:

| Kind | Examples | Today in the kit |
|---|---|---|
| **Base** (letterhead) | `evrtemplate.tex`, `evr-gr.tex`, `evr-en.tex` | root files |
| **Asset** | clinic logos, `logo-01.png`, `logo-stamp.png` | `images/` |
| **Type** (document template) | `vevaiosi-mri`, `odigies-osfyiki`, … | `templates/tpl-*.tex` |
| **Shared component** | drug library, purpose sentences | copied inside several `tpl-*.tex` |
| **Block** | one sentence or item of the content pool (§12) | lines inside `tpl-*.tex` and old letters |
| **Module** | a case feature's list of operations (§12.3) | (none: commented alternatives today) |

A type version lists its sections and the **block versions** that are its defaults, rather than
containing their text, so a block edited once is updated for every type that uses it (in that
type's next version).

### 5.2 Versions: simple, linear, immutable

This works like git but deliberately much simpler:
- **Numbering:** each source has a **linear** history v1, v2, v3… There are no branches and no
  merges.
- **What a version stores:**
  - its **number**, the **SHA-256** of its exact bytes, and the date;
  - **author** and a **change note** ("Psychikou phone corrected");
  - the **previous version**;
  - a **status**: `draft` → `released` → `retired`.
- **Content-addressed storage:** the bytes are stored once, keyed by their SHA-256, the way git
  stores objects. Identical content is stored once, and every read is checked against its hash.
- **Released versions never change.** Editing a template always creates a new version. "Going back"
  means releasing a new version with the old content, so the history is never rewritten.
- **New documents use released versions only.** Draft versions are for trying out a change: you can
  preview a document against a draft template version before releasing it. A block version created
  by issuing a document (new or edited text, D8) counts as released, with tier *history*; it was
  approved by being issued.
- **Diffs:** a text diff between any two versions of a source, to see what changed.

### 5.3 What a document records

Every build, both previews and issues, records a **manifest** in the database:

| For each source used | Plus, for the build as a whole |
|---|---|
| source name, version number, SHA-256 (base files, assets, type, shared components **and every block version used**) | SHA-256 of the generated document `.tex` |
| | SHA-256 of the produced PDF |
| | build environment: XeLaTeX / TeX Live version, MedScribe version, font file hashes |
| | the build's `SOURCE_DATE_EPOCH` |

Local edits (D9) are stored with the document itself, so the manifest plus the document's inputs
rebuild it exactly even after the blocks it used have evolved.

The manifest of an **issued** document is permanent and is shown on the document's page ("built with
evrtemplate v3, evr-gr v5, vevaiosi-mri v2, …").

### 5.4 Rebuilding an old document

1. Read the document's manifest.
2. Fetch each source **by its SHA-256** from the store, and stop if any hash does not match.
3. Lay the files out as the kit layout in a temporary folder, together with the document's stored
   `.tex`.
4. Compile.

The result is the same document even after every template has moved on. For **byte-identical**
PDFs, builds set a fixed creation date (`SOURCE_DATE_EPOCH` with `FORCE_SOURCE_DATE=1`), stored in
the manifest and reused by a rebuild, so a rebuild can be checked against the stored PDF hash. With
`FORCE_SOURCE_DATE`, TeX's `\today` is that epoch, and `evrtemplate.tex` defaults `\DocDate` to
`\today`: **MedScribe therefore always writes `\DocDate` explicitly** (the document date), so the
printed date never depends on the build time. The one thing outside MedScribe's control is the TeX
installation itself; its version is recorded so that a rebuild on a different TeX Live can be flagged.

### 5.5 Choosing base versions (D10)

The base layer is a set of files that must work together: `evrtemplate.tex` expects certain macros in
`evr-gr.tex`, which point to certain images. Picking a version of each file separately is easy to get
wrong, so:
- **Base releases:** the base layer is released as a numbered **base release** (B1, B2, …): a
  manifest of exactly which version of each base file and asset belongs together. Changing one clinic
  phone gives a new `evr-gr.tex` version and a new base release.
- **Per document:** a new document uses the **current base release**. In the document's settings you
  can pick an **earlier base release**, e.g. to reissue a letter with the letterhead it originally
  had.
- **Per type:** each type version records the base release it was made and tested with. MedScribe
  warns, without blocking, when a document combines a type with a base release it was never tested
  with.
- **Advanced: per-file override.** Choosing one file's version outside a release is possible, but
  marked in the manifest as a non-standard combination.

### 5.6 Exchange with the evrdocs kit and Overleaf

- **Import:** the first import makes every kit file v1 of its source and creates base release B1.
  After that (D19): **layout code** (`evrtemplate.tex`, images) is still edited in the kit/git;
  re-importing creates a new *draft* version only for files whose SHA-256 changed, which becomes
  part of a base release in MedScribe. **Layout data** (doctor, clinics, labels) is edited only in
  MedScribe, which generates `evr-gr.tex` / `evr-en.tex`; those two files are not re-imported.
  **Types** are mastered in MedScribe (D18); the kit's `tpl-*.tex` files are imported once.
- **Export:** any base release, with any set of types, can be exported as a kit-shaped folder or zip
  that compiles on its own (`scripts/build.sh`, Overleaf), so you never depend on MedScribe to open
  a source.
- **Format (D18):** a type is stored as structured data and versioned and hashed as such; its
  kit-style `.tex` is generated for diffs and export, never edited and re-imported.

## 6. Template hierarchy (scan of `~/devel/docs/template-kit`, 2026-11-05)

### 6.1 Dependency tree

```
Base layer
  evrtemplate.tex ......................... layout engine: header, footer, QR, signature,
  │                                         helpers PatientInfo / MedTable / UrgentBox / \V
  ├─ \input{evr-\DocLang} ................. picked at build time by \DocLang
  │    ├─ evr-gr.tex ...................... doctor details, labels, \QRBaseURL, 7 × \SetClinic
  │    └─ evr-en.tex ...................... the same keys in English
  ├─ \DrLogo   → images/logo-01.png ....... spine logo (set in evr-*.tex)
  ├─ \DrStamp  → images/logo-stamp.png .... signature/stamp (only when \DocStamp{yes})
  └─ \ClinicLogo → images/logo-*.png|jpg .. picked by \Clinic (Mandra: none)
  (images/logo-01a.svg: vector source of the logo; never used in a build)
  (latexmkrc: build rule, needed only by tpl-a5-2up)

Level 1: document templates (each does \input{evrtemplate}; none inputs another template)
  letter-gr, letter-en, tpl-* (19 files)

Level 2: post-processing (not a letter; consumes the PDF of a level-1 document)
  tpl-a5-2up ── \includepdf{\InputPDF} ──▶ PDF of tpl-syntagi-a5 (or a copy of it)
```

### 6.2 Findings

1. **Only two real layers.** Every letter depends on `evrtemplate.tex` directly. Templates never
   build on other templates. All reuse between templates is **copy-paste**, so a shared change (a
   drug dose, a sentence) has to be repeated by hand in every copy. This is the main thing grouping
   and shared components (§4) should fix.
2. **The language file is chosen at build time** (`\input{evr-\DocLang}`), and the images are chosen
   through data (`\SetClinic`, `\DrLogo`, `\DrStamp`). A document's real dependencies therefore
   depend on its settings. A Greek document at Peristeri without a stamp uses `evr-gr.tex` and
   `logo-iatriko-peristeriou.png` but not `logo-stamp.png`. The manifest (§5.3) records what the
   build actually used.
3. **Copied blocks found:**
   - **The drug library:** 32 lines with the same hash in **7** files: `syntagi`, `syntagi-a5` and
     the 5 `odigies`. The kit README says six; `syntagi-a5` is the seventh.
   - **The purpose sentences:** 7 alternatives, identical in **9** files.
   - **The settings block:** clinic, patient, date, title, closing and stamp, in all **22** letters.
4. **`tpl-a5-2up` is a different kind of thing:** a layout step applied to a finished A5 PDF. In
   MedScribe it becomes an output option of A5 documents ("print 2 per A4 sheet") rather than a
   document type.
5. **All templates default to `\DocStamp{no}`.** The default closing differs per template (empty vs
   `Ο ιατρός`).

### 6.3 What each template uses

| Template | Lang | Paper | Default title | Closing | Patient block | Sections | MedTable | UrgentBox | Own variables |
|---|---|---|---|---|---|---|---|---|---|
| letter-gr | gr | a4 | Ιατρική Βεβαίωση | – | ✓ | – | – | – | |
| letter-en | en | a4 | Medical Certificate | – | ✓ | – | – | – | |
| vevaiosi-mri | gr | a4 | Ιατρική Βεβαίωση | Ο ιατρός | ✓ | – | – | – | |
| vevaiosi-ylikon | gr | a4 | Ιατρική Βεβαίωση για χρήση υλικού | – | ✓ | – | – | – | |
| vevaiosi-amoivis | gr | a4 | Ιατρική Βεβαίωση | Ο ιατρός | ✓ | – | – | – | |
| adeia | gr | a4 | Ιατρική Βεβαίωση | – | ✓ | – | – | – | `\LeaveDays` |
| gnomateysi-exetaseon | gr | a4 | Ιατρική Γνωμάτευση | – | ✓ | – | – | – | |
| gnomateysi-farmakon | gr | a4 | Ιατρική Γνωμάτευση | – | ✓ | – | – | – | `\Drug`, `\DrugCount`, `\PatientAMKA` |
| proegkrisi | gr | a4 | Προέγκριση Χειρουργείου | – | ✓ (Προγρ. επέμβαση) | – | – | – | |
| enimerotiko | gr | a4 | Ενημερωτικό | Ο ιατρός | ✓ (3 dates) | 5 (Ιστορικό → Σχέδιο) | optional | – | |
| iatrodikastiki | gr | a4 | Ιατρική Γνωμάτευση | Ο ιατρός | ✓ (2 dates) | 5 (Ιστορικό → Συμπέρασμα) | – | – | |
| synainesi | gr | a4 | Έντυπο Ενημερωμένης Συγκατάθεσης… | – | ✓ | 2 risk subsections | – | – | |
| praktiko | gr | a4 | Πρακτικό Χειρουργείου | – | ✓ (9 rows) | 5 subsections | – | – | |
| odigies-osfyiki | gr | a4 | Μετεγχειρητικές Οδηγίες | Ο ιατρός | ✓ | Γενικές / Εξειδικευμένες / Επανέλεγχοι | ✓ + library | ✓ (commented) | |
| odigies-auxeniki | gr | a4 | 〃 | Ο ιατρός | ✓ | + Κρίσιμες (dural tear, commented) | ✓ + library | ✓ | |
| odigies-kyfoplastiki | gr | a4 | 〃 | Ο ιατρός | ✓ | Γενικές / Εξειδικευμένες / Επανέλεγχοι | ✓ + library | ✓ (commented) | |
| odigies-kranio | gr | a4 | 〃 | Ο ιατρός | ✓ (+ Ημ. Εξιτηρίου) | Γενικές / Επανέλεγχοι | ✓ + library | ✓ (commented) | |
| odigies-tridymo | gr | a4 | 〃 | Ο ιατρός | ✓ | Κρίσιμες / Γενικές / Εξειδικευμένες / Επανέλεγχοι | ✓ + library | ✓ | |
| syntagi | gr | a4 | Οδηγίες Φαρμακευτικής Αγωγής | Ο ιατρός | ✓ | – | ✓ + library | – | `\DocPageNumbers{all}` |
| syntagi-a5 | gr | **a5** | Συνταγή | Ο ιατρός | ✓ | – | ✓ + library | – | `\DocPageNumbers{all}` |
| epistoli | gr | a4 | (free) | Ο ιατρός | **–** | – | – | – | `\Recipient` |
| recommendation-en | en | a4 | Letter of Recommendation | – | **–** | – | – | – | |
| a5-2up | – | A4 landscape | – | – | – | – | – | – | `\InputPDF`, `\TwoUpMode`, `\TwoUpCutLine` |

### 6.4 Grouping (agreed 2026-11-05)

Six families, grouped by structure:
- **Certificates:** short, patient block plus body plus the purpose sentence: `vevaiosi-*`, `adeia`,
  `letter-gr/en`.
- **Opinions and requests to third parties:** `gnomateysi-*`, `proegkrisi`.
- **Structured clinical reports** with fixed section headings: `enimerotiko`, `iatrodikastiki`,
  `praktiko`, `synainesi`.
- **Postop instructions:** one shared skeleton (Γενικές / Εξειδικευμένες / Επανέλεγχοι / UrgentBox /
  MedTable) with five procedure variants.
- **Prescriptions:** `syntagi`, `syntagi-a5`, plus the 2-per-sheet output option.
- **Letters without a patient:** `epistoli`, `recommendation-en`.

Versioning (§5) also settles the kit's own decision D8 (`TYPES-PLAN.md`). The kit copied the drug
library into each template *because a shared file would silently change old letters on recompile*.
With pinned versions that cannot happen, so the library can safely become one shared component.

### 6.5 What documents a private-practice surgeon needs (research, 2026-11-05)

**Sources.** The main evidence is your own archive: the kit's `TYPES-PLAN.md` classified all ~95
letters in `overleaf/` by purpose. That is complemented by:
- **The Greek framework:** the Code of Medical Ethics (ν. 3418/2005); the Athens Medical
  Association's rules on documents for the courts (after ΣτΕ 2552/2021); e-ΕΦΚΑ / ΚΕΠΑ procedures;
  ΕΟΠΥΥ prior approval (S2); private insurers' claim requirements.
- **Surgical documentation practice** in general.

#### A. Two distinctions that affect the design

1. **Πιστοποιητικό/βεβαίωση vs γνωμάτευση.** Under the Code of Medical Ethics, as the Athens Medical
   Association explains it:
   - **A certificate** attests a fact and **requires a prior examination of the patient**.
   - **An opinion** expresses a medical view; when it is not based on an examination it must be
     drafted with particular care, using all available evidence.

   **Design consequence:** every type carries a *legal nature* attribute. Certificate types require
   an **examination date** field, and documents for court use follow the Athens Medical Association's
   specific rules.
2. **Official electronic channels are out of scope.** Several documents now go through official
   electronic systems, which MedScribe does not replace:
   - e-prescription and e-referral (ΗΔΙΚΑ);
   - e-ΕΦΚΑ sickness certification;
   - the ΚΕΠΑ treating-physician file, which is moving to a single electronic application and
     requires ICD-10 codes per condition.

   MedScribe produces the **accompanying letter or report** that the official form or file needs.
   This extends the non-goals in §2.

#### B. Document families along the care pathway

✅ = a template exists in the kit · ◐ = partly covered · ✗ = gap

| # | Family | Documents | Audience | Kit | Notes |
|---|---|---|---|---|---|
| 1 | **Consultation & referral** | Consultation report / letter to the referring doctor; second opinion; referral or opinion for tests and physiotherapy | Doctor, patient | ◐ `gnomateysi-exetaseon` | **Gap:** the consultation letter to the referring doctor, the most common letter in surgical practice in general |
| 2 | **Pre-operative** | Surgical pre-approval; cost estimate (surgeon fee, materials, clinic); pre-op instructions (tests, stopping anticoagulants, fasting, what to bring); request for cardiology or anaesthesia clearance; procedure information leaflet; informed consent | Insurer/ΕΟΠΥΥ, patient, colleague | ◐ `proegkrisi`, `synainesi` | **Gaps:** the cost estimate (could take fees from APYweb), pre-op instructions, the clearance request. Consent is the one type the **patient signs** |
| 3 | **Peri-operative record** | Operative note; discharge / clinical summary | Clinic, insurer, next doctor | ✅ `praktiko`, `enimerotiko` | Insurers ask for the operative note together with their own treating-physician form |
| 4 | **Post-operative care** | Postop instructions (per procedure); medication instructions; follow-up visit report | Patient, GP | ✅ `odigies-*` ×5, `syntagi`, `syntagi-a5` · ✗ follow-up | **Gap:** the follow-up visit report ("επανεξέταση"), a natural sequel to every operation |
| 5 | **Certificates for insurers & imaging** | Procedure + materials certificate; MRI compatibility of implants; fee-paid certificate | Insurer, imaging centre, tax office | ✅ `vevaiosi-ylikon`, `vevaiosi-mri`, `vevaiosi-amoivis` | Certificates in the legal sense: examination date needed |
| 6 | **Work & social-security certificates** | Sick leave, work restrictions, remote work; fitness to return to work, drive, travel or do sport; disability (supporting report for the ΚΕΠΑ file); military service; school or exam arrangements | Employer, public service, e-ΕΦΚΑ, ΚΕΠΑ, school | ◐ `adeia` | **Gaps:** fitness certificates and the disability report (needs ICD-10 per condition). Public-sector short sick leave (up to 8 days a year) accepts a private doctor's opinion |
| 7 | **Drugs & devices** | High-cost drug (ΦΥΚ) justification; baclofen kit; chronic medication justification; treatment abroad (ΕΟΠΥΥ S2 needs the treating doctor's opinion) | ΕΟΠΥΥ, pharmacy, supplier | ✅ `gnomateysi-farmakon` · ✗ abroad | Treatment abroad is rare |
| 8 | **Medico-legal & insurance claims** | Medico-legal examination; court expert opinion; accident or assault report; claim support (cover report to the insurer's own form) | Court, lawyer, police, insurer | ◐ `iatrodikastiki` | Court documents follow the Athens Medical Association's specific rules (after ΣτΕ 2552/2021) |
| 9 | **Correspondence** | Administrative letters (clinic, laboratory, coding requests, paraffin blocks); recommendation / observership letters; free letters | Institutions, colleagues | ✅ `epistoli`, `recommendation-en`, `letter-*` | No patient, or a patient without a clinical block |
| 10 | **Practice documents (not per patient)** | GDPR privacy notice for patients; consent to use photos or images; practice information and price list; patient education leaflets (e.g. after a lumbar disc herniation) | All patients | ✗ | Static documents that reuse the letterhead and the phrase library. Under GDPR a doctor keeps a record of processing activities (not given to patients); explicit consent is **not** the legal basis for ordinary care. Verify with the medical association's DPO before writing these |
| 11 | **Clinical scales & questionnaires** | (a) **Questionnaires the patient fills in**: pain NRS/VAS, Oswestry (ODI), Neck Disability Index (NDI), Roland–Morris, EQ-5D, SF-36/SF-12. (b) **Scales and exam charts the doctor records**: neurological exam table (MRC muscle power per root, sensation, reflexes, signs), mJOA / Nurick (myelopathy), ASIA, GCS, mRS, Karnofsky, BNI (trigeminal pain and numbness), Barthel | Patient (blank form), doctor/insurer (scored result) | ✗ | **Two outputs per scale:** a blank form to print and fill on paper, and a scored result table. Results can be compared over time (pre-op vs follow-up) and quoted in other documents (follow-up report, pre-approval, medico-legal). Needs **validated Greek versions**; some instruments are licensed (EQ-5D: EuroQol; SF-36/SF-12: licensed), so check each one's terms |

#### C. Attributes every type needs (consequences for §4)

| Attribute | Values | Why |
|---|---|---|
| Family | 1–11 above | Grouping in menus; shared defaults (e.g. the purpose sentence for families 5–6) |
| Legal nature | certificate · opinion · instructions · record · consent · letter | A certificate needs an examination date; consent needs a patient signature area |
| Audience | patient · doctor · insurer/ΕΟΠΥΥ · employer/public body · court · institution | Language register, purpose sentence, how much is disclosed |
| Clinical anchor | surgery · visit · none | A surgery-anchored type asks for, or later pulls from ZPMS, the date, procedure and materials |
| Signature | hand-signed · stamp image · patient signs too · none | Today `\DocClosing` / `\DocStamp`, chosen per type |
| Has patient | yes · no | Families 9–10 often have none |
| Secretary may issue | yes · no (all **no**, D21) | Lets specific unsigned types be issued by the secretary later |
| Verification code | on (AMKA, else random code) · off (D22) | Whether the QR link asks for a code before serving the PDF |

#### D. Gaps: decided 2026-11-05

**Adopted** (D11, D12):
- **Pre-operative instructions** (family 2).
- **Fitness certificates:** work, driving, travel (family 6).
- **Practice documents** (family 10).

**Deferred** (2026-11-06): **clinical scales & questionnaires** (family 11), with everything in
E below, M9 and Q11–Q13.

**Not now:**
- Consultation letter to the referring doctor (family 1).
- Follow-up visit report (family 4).
- Cost estimate (family 2).
- Disability supporting report (family 6).

New types are written fresh, because there is no kit template to import. Each goes through the same
review → release path as the imported ones.

#### E. Scales: what they add to the engine (deferred, 2026-11-06)

*Kept as a record for later; nothing here is planned now, and no earlier milestone has to prepare
for it.*

Scales are the one family that is not just text, so they need three additions:
- **A `scale` section layout.** Each item has its answer options and their scores. MedScribe
  computes the total and any subscores, and shows the interpretation band (e.g. ODI 21–40 % =
  "moderate disability"). The scoring rules are versioned with the scale's definition (§5), so an old
  score can always be explained.
- **Two renderings of the same definition:**
  - a **blank questionnaire** (patient-facing, Greek, printable);
  - a **result table** (items, answers, total, band, date).
- **Structured results.** A completed scale is stored as data (patient guid, scale, version, date,
  answers, score), not only as a PDF. This lets other documents quote it: e.g. "ODI 46 % pre-op →
  18 % at 3 months" in a postop or medico-legal text. Where these results live is open (§11 Q11).

## 7. How a document is produced

### 7.1 Rendering (inputs → `.tex`)

MedScribe never edits a template version at run time. It **writes a complete, standalone `.tex`** for
each document, in the kit's own style:

```latex
\documentclass[10pt,greek]{article}
\usepackage[useregional]{datetime2}
\providecommand\DocLang{gr}
\providecommand\Clinic{Peristeri}
\providecommand\DocCode{PEX-2026-8K3XQ2}
\providecommand\PatientName{…}        % … every setting, from the type defaults + your choices
\input{evrtemplate}
\begin{document}
\begin{PatientInfo} \Info{Ημ. Χειρουργείου}{12-10-2026} \end{PatientInfo}
…body generated from the selected blocks, field values, local edits and free text…
\end{document}
```

Consequences:
- **Same output as today:** the letterhead and helpers (`PatientInfo`, `MedTable`, `UrgentBox`, `\V`)
  are used unchanged.
- **Portable:** the stored `.tex`, together with an export of its base release (§5.6), compiles by
  hand or on Overleaf, which gives you an escape route for any unusual document.
- **Safe text:** your free text and local edits are **escaped** (`& % $ # _ { } ~ ^ \`), and a small
  markup is turned into LaTeX: bold, italic, list items, spine levels.
- **Every block records its format:** `markup` (escaped at every render) or `latex` (trusted). Only
  blocks imported from the kit or the archive, and raw-LaTeX blocks written by the doctor, are
  `latex`. Text that enters the pool from a document (history, D8) keeps the format it was written
  in, so text typed by anyone never becomes trusted by being reused.

### 7.2 Compiling (`.tex` → PDF)

This follows what `~/devel/docs/scripts/build.sh` already does:
- **Isolation:** each job gets its own temporary folder, filled from the template store by the
  manifest's hashes, so a build never reads a mutable working copy.
- **XeLaTeX runs:** `xelatex -interaction=nonstopmode -halt-on-error -no-shell-escape`, 2 to 4 passes
  until there is no "Rerun", with a fixed `SOURCE_DATE_EPOCH`.
- **No reading outside the job:** TeX Live here allows reading any file (`openin_any = a`), so a
  `latex` block could `\input` a server file such as `config/docarc_api.php` into the PDF. Jobs run
  with `openin_any=p` (only the job folder and the TeX tree) and `openout_any=p`; raw-LaTeX blocks
  are a doctor-only feature.
- **Limits:** a time limit and a size limit per job. Fonts and caches go to a folder Apache's user
  can write to.
- **On failure:** the error and the end of the log are shown in the form, never a blank page.
- **A5 and two-up:** A5 documents use `\DocPaper{a5}`, and two A5 pages per A4 sheet is an output
  option using the kit's `a5-2up` imposition.

### 7.3 Preview

**Preview** compiles the draft with a placeholder QR and a "ΠΡΟΣΧΕΔΙΟ" (DRAFT) mark, so a draft can
never be mistaken for an issued document. The mark is the `\DocDraft` switch added to
`evrtemplate.tex` (Q6, resolved), which must exist as a released base version before M1.

**Checks** (shown on the Preview & issue screen; issuing is blocked until all pass):
- all required fields filled, no `[placeholders]` left;
- every module medication row confirmed (§12.3), every module conflict resolved;
- an examination date for certificate types (§6.5 A);
- the type's DocArc type and the clinic's DocArc clinic are linked and still exist (D20);
- no other patient's name or AMKA left in the text (§12.1, swap on insertion).

### 7.4 Issuing (doctor only)

The QR has to be inside the PDF, so the DocArc code (token) must exist before the final typesetting.
Issuing has two stages, **request the code** and **approve and send**:

0. **Final check of the draft.** Preview with the draft mark, and the checks of §7.3.
1. **Request the code.** MedScribe asks DocArc to create a record, sending everything DocArc's
   record needs:

   | DocArc field | From MedScribe |
   |---|---|
   | document type | the one DocArc type linked to the document's type (D20) |
   | description (document name) | the document's title, e.g. "Μετεγχειρητικές Οδηγίες" |
   | clinic (location / practice) | the DocArc clinic linked to the document's `\Clinic` key |
   | patient name | from the patient snapshot, plain text as today |
   | record date | the document date |
   | requesting user | the MedScribe user issuing |
   | verification code | per type (D22): the AMKA if known, otherwise a random 6-digit code; or none |

   DocArc validates the type and clinic, **creates the record** and responds with the **document
   code** (e.g. `POS-2026-7K3QPA`). The record has no file yet. The request carries an
   **idempotency key** (the MedScribe document id): if the response is lost (timeout) and MedScribe
   asks again, DocArc returns the record it already created instead of a second one. MedScribe
   checks that the returned retrieval URL is `\QRBaseURL` + code, so the printed QR resolves.
2. **Compile the final PDF** with `\DocCode{code}`: the real QR, no draft mark. The manifest is
   recorded.
3. **The doctor approves.** The final PDF, exactly as it will be archived, is shown with the code and
   QR. Approving **freezes** the document (no further edits by anyone) and stores the approved bytes;
   only those bytes are sent, never a recompile. Going back to edit instead keeps the code reserved.
   If the record's details changed meanwhile (patient name, clinic, title, date), MedScribe updates
   the DocArc record before sending. If the document's **DocArc type** changed (a different
   MedScribe type), the empty record is deleted and a new code is requested, since the type is part
   of the code.
4. **Send to DocArc:** the PDF as the record's **main document** and the **`.tex` source as a
   supplementary file** (plus any non-kit images). DocArc already accepts `.tex` there: its
   supplementary uploads allow any type except a short extension denylist.
5. **Lock.** MedScribe stores the code, the `.tex`, the PDF and its hash, the inputs and the manifest.

**States and failures.** Each step is a recorded state, so a failure resumes where it stopped rather
than starting over:

```
draft ─▶ code reserved ─▶ final compiled ─▶ approved (frozen) ─▶ PDF sent ─▶ .tex sent ─▶ issued
           │  └─ edit again (code kept) ◀───────┘                   │            │
           └─ draft deleted → DocArc empty record deleted           └── retry ◀──┘ (same bytes)
```

- **Timeouts:** lookups keep the family's 3-second limit; uploads to DocArc get a longer one
  (`config/docarc_api.php`), since a PDF over 3 s is normal.
- **One issue at a time:** a document being issued is locked against a second "Request code" or
  "Approve" (double click, second tab, secretary editing).

**One record per document.** Once a code is reserved, MedScribe keeps it on the draft and reuses it:
going back to edit, a failed compile or a failed upload never creates a second record. If the
draft is abandoned (deleted), MedScribe asks DocArc to **delete the empty record** (decided
2026-11-06). DocArc deletes only a record that has no main document yet; a failed delete is logged
and retried, never ignored.

**Changed documents (revisions)** follow DocArc's agreed versioning design (DocArc `CLAUDE.md`,
"Document versioning", branch `claude/protocol-random-tokens-archive-8xc4p9`, not implemented yet):
**a revision is a new version of the same DocArc record, under the same code.** The QR on paper
already handed out keeps working and always serves the latest version; DocArc keeps every older
version, with its own timestamp proof.

- **"Revise"** makes a new draft from the issued inputs; the issued revision itself never changes.
  It keeps the same patient (the snapshot may be refreshed from ZPMS, e.g. a corrected spelling),
  the same MedScribe type and the same DocArc code and type, even if the type has since been
  relinked to another DocArc type. Changed record details (patient name, clinic, title) are updated
  in DocArc before the new version is sent. By default it keeps the same template
  versions, and you can choose to move it to the current ones.
- **A reason is required.** It is stored with the revision in MedScribe and sent to DocArc as the
  version's **correction note** (staff-only there: never printed, never on the QR page, never in an
  audit log).
- **The amendment is stated inside the document,** because DocArc never alters the file: the
  revised PDF carries a short revision line (e.g. "Αναθεώρηση 2 · 06/11/2026 · αντικαθιστά την
  έκδοση της 03/11/2026"), through a new kit variable `\DocRevision`, empty for the first
  revision (§11 Q6).
- **Issuing a revision** uses the flow of §7.4 without step 1 (the code exists): compile with the
  same code, the doctor approves, MedScribe uploads the PDF as **a new version with the correction
  note** plus the `.tex` as a supplementary file, then locks. The upload states which version it
  expects to replace; if DocArc's current version is not the one MedScribe sent last (staff replaced
  or deleted a version in DocArc), nothing is sent and the doctor is alerted. Only then is it a revision; the
  previous one is marked *superseded*. An abandoned draft of a revision touches nothing in DocArc.
- **Mistakes are not revisions.** A document issued for the wrong patient, or one that must not
  exist, is **withdrawn** (decided 2026-11-06):
  - **DocArc deletes the whole record,** with every version and supplementary file (its
    destructive delete, for data-entry errors). The printed QR then leads nowhere.
  - **MedScribe keeps the document,** marked *withdrawn* with a reason, the date and who withdrew
    it. Only the doctor can open it. **Its text leaves the pool:** the history blocks it fed in at
    issue (D8) are no longer suggested, unless another document also used them, and it no longer
    counts in usage data, module growth or §13's data.
  - **The doctor may delete it from MedScribe as well,** at withdrawal or later, after a
    confirmation. That removes its inputs, `.tex`, PDF and the stored objects that nothing else
    references (the store is shared by hash); the audit log keeps only the event (who, when,
    document id), never patient details or the reason. Copies in backups expire with the backup
    retention.
  - **The right document is made with "copy as new"** for the correct patient: a new document and a
    new code.

  Keeping one patient's document as "history" under another's record is a GDPR problem, not an
  audit trail.
- **Dependency:** revisions need DocArc's versioning built first (its build step 1: schema, storage,
  versioning on replace, required note) and exposed through the API. Until then MedScribe offers no
  "Revise", and never uses DocArc's current destructive replace. Revise is deferred until DocArc implements its
  versioning (§11 Q15); withdraw + copy as new remain available.

### 7.5 Document history

Every document has a **History** view listing its **approved revisions** only, newest first:

Document `POS-2026-2M8XW5` (one DocArc code for all revisions):

| Rev | Status | DocArc version | Approved | By | Reason | Changes |
|---|---|---|---|---|---|---|
| 3 | current | v3 | 06/11/2026 10:52 | Ε. Ρόκας | dose corrected | Αντιθρομβωτική: 1 row changed |
| 2 | superseded | v2 | 04/11/2026 18:10 | Ε. Ρόκας | follow-up date | Επανέλεγχοι: 1 line |
| 1 | superseded | v1 | 03/11/2026 12:30 | Ε. Ρόκας | (original) | |

- **Each row** opens that revision's PDF, `.tex`, inputs and manifest, exactly as approved, and can
  be rebuilt (G5).
- **Changes** compares a revision with the previous one, by section: blocks added, removed or
  reworded, fields and medication rows changed, patient or settings changed. The full diff is one
  click away.
- **Drafts are not revisions.** An open draft of a revision is shown above the list as "draft of
  rev 4, not approved", and disappears if abandoned. Rejected or abandoned drafts leave no revision.
- **Append-only:** revisions are never edited or deleted (the one exception: the doctor deleting a
  withdrawn document, §7.4); the history and the audit log record who approved what and when. The reason text stays out of the audit log (DocArc's rule for correction
  notes, applied here too); the log carries the revision number.
- **Revision numbers follow DocArc's version numbers:** MedScribe stores the version number DocArc
  returns for each upload rather than counting on its own, so both apps name the same thing the
  same way. A withdrawn document shows as *withdrawn* with its reason; its revisions stay listed but only
  the doctor can open them, until the doctor deletes the document.
- **The latest approved revision is the current one** wherever the document is listed (Home,
  patient page); older revisions stay reachable from the history.
- **Only the current revision feeds the pool.** Text a revision removed or reworded (often because
  it was wrong) is no longer suggested from that document, and a document counts once in usage
  data, not once per revision.

## 8. Users and roles

| Role | Can |
|---|---|
| **Secretary** | Pick a patient and a type; create and edit drafts, including drafts of revisions; fill in administrative fields; preview; hand a draft to the doctor by setting it to *ready for review* (an explicit document state, shown on the doctor's Home) |
| **Doctor** | Everything the secretary can, plus request codes, approve and issue, revise, withdraw, delete withdrawn documents and copy documents; write raw-LaTeX blocks; edit, version and release types, shared components and the base layer; edit the phrase and drug libraries |
| **Admin** | Users, roles, settings, integration keys |

These use ZeusFw's built-in users, roles and permissions, the same mechanism ZPMS uses. Each draft
records who created it and who issued it. Each template version records who made it and who released
it. Only the doctor issues (D21); the per-type "secretary may issue" setting stays off for now.

## 9. Integrations

The pattern is the one in `CLAUDE.md`: server to server, an API key in a header checked with
`hash_equals()`, and the integration switches off cleanly when not configured.

**ZPMS** (changes in the ZPMS repo):
- `GET /api/v1/patients/search/{term}`: the existing search, made to accept an
  `X-MedScribe-Api-Key` header as well as DocArc's.
- **New:** `GET /api/v1/patients/{guid}` returns name, date of birth, gender, AMKA, and possibly
  address and phone, only for the types that print them.
- **New:** a "New document" button on the patient page that opens MedScribe at
  `/documents/new?patient=<guid>`.
- Possibly later: surgery and appointment data, to fill in "Ημ. Χειρουργείου" or "Επέμβαση".
- The patient's age is calculated from the date of birth **at the document date**.
- MedScribe stores a **snapshot** of the patient on each document (what was printed), plus the
  `guid`, and never syncs back.
- **Patients not in ZPMS (D23):** for any type, the patient can be typed by hand (name, and as
  the type needs: date of birth or age, gender, AMKA). Such a document has no `guid`, so it does not
  appear on a ZPMS patient page; it is found through MedScribe's own document list. Every printed
  detail of a ZPMS patient can also be changed on the document (e.g. a Latin-script name for an
  English letter, optionally proposed by ΕΛΟΤ 743 transliteration), without touching ZPMS.

**DocArc** (changes in the DocArc repo; it has no inbound API today):
- **New, API-key gated:**
  - **List document types:** `{id, code, label}` from DocArc's `document_types` table. MedScribe
    caches it for the type editor and refreshes it before issuing.
  - **List clinics:** `{id, name}` from DocArc's `clinics` table, linked in MedScribe to its
    `\Clinic` keys (Mandra, Peristeri…). Like types, DocArc owns them. A clinic added in MedScribe's
    Base screen must be linked before documents for it can be issued; DocArc's clinic is optional,
    so a document can also be sent with none.
  - **Create record (request a code):** takes the DocArc document type, description, clinic,
    patient name, record date, requesting user, the verification code setting (D22) and an
    idempotency key (§7.4 step 1), rejects an
    unknown type or clinic, creates the record without a file (or returns the one already created
    for that key) and returns the code and retrieval URL.
  - **Update record details:** patient name, clinic, description, date, while MedScribe's document
    changes before sending or in a revision (§7.4).
  - **Upload main document:** the PDF, after the doctor approves it (§7.4 step 3).
  - **Upload supplementary files:** the `.tex`.
  - **Upload a new version** (after DocArc builds its versioning): the revised PDF with the required
    correction note; the same record and code (§7.4, Revisions).
  - **Withdraw:** DocArc deletes the whole record (all versions, supplementary files), for
    mistakes such as the wrong patient (§7.4).
  - **Delete an empty record:** for a draft abandoned after its code was reserved (§7.4). DocArc
    refuses it once the record has a main document.
- **Reuse DocArc's own code:** `includes/token.php`, `includes/documents.php` and its audit log,
  rather than duplicating them.
- **Type links (D20):** stored in MedScribe as DocArc's type `id`, plus the last code and label
  seen (for display only). The `id` is the key, since DocArc staff can edit codes and labels; what
  DocArc returns always wins. A MedScribe type links to exactly one DocArc type, and a DocArc type
  can serve many MedScribe types. A document has one DocArc code for its whole life, across all
  its revisions. Managed in the type editor, picked from DocArc's
  list; MedScribe never creates DocArc types.
- **Patient name only:** DocArc keeps receiving the patient name as plain text, as today.

**APYweb**: not needed now. `vevaiosi-amoivis` (fee paid) could later pull the payment from APYweb.

## 10. Milestones

Each milestone ends with something you can use or test.

| # | Milestone | Done when |
|---|---|---|
| M0 | **Foundations:** ZeusFw app scaffold (same layout as ZPMS), login + roles, config templates, test runner. The **template store** (sources, versions, SHA-256, base releases), the kit import (all files → v1, base release B1), and the compile service running XeLaTeX as Apache's user from a manifest (`openin_any=p`, §7.2). In the kit (git): `\DocDraft` and `\DocRevision` (Q6) | The unmodified `tpl-vevaiosi-mri.tex` compiles from the store into the same PDF as `scripts/build.sh` gives (byte-identical when both use the same `SOURCE_DATE_EPOCH`; build.sh sets none, so add it there or compare page images). A build's manifest lists every file used, with its version and hash |
| M1 | **Engine + first type**: the type/section/block format, gender variants, field types, local edits, escaping and markup. The form, renderer and preview, with the patient typed in by hand. `vevaiosi-mri` is defined in the new format | A `vevaiosi-mri` is written entirely in the form, and its PDF matches the hand-filled kit version (regression test G3) |
| M2 | **ZPMS**: patient search and fetch-by-guid endpoints in ZPMS, the "New document" button, the patient snapshot | Opening ZPMS → "New document" → `vevaiosi-mri` arrives with the patient already filled in |
| M3 | **DocArc + lifecycle**: the DocArc endpoints (§9), the two-stage issue flow with its states and retries (§7.4), locking, copy as new, withdraw, the document history (§7.5). Revise once DocArc's versioning exists. Rebuilding from a manifest. **Issued documents feed the history** (D8) | **G2 is met for `vevaiosi-mri`:** an issued PDF with a working QR, in DocArc, in under a minute. **G5 is met:** after a new base release, an old document still rebuilds to the same PDF hash |
| M4 | **Template management and short certificates**: an editor for type, section and block versions (draft → released, diffs); base-release selection per document; **the content pool UI: suggestions pane, @tokens, swap on insertion, library screen (promote/merge/hide)**; `vevaiosi-ylikon`, `adeia`, `vevaiosi-amoivis`, `gnomateysi-exetaseon` and the shared purpose sentences | Five types in daily use; changing a clinic phone produces a new base release that new documents use, while old ones keep theirs |
| M5 | **Prescriptions**: the drug library as a shared component, a medication table editor, `syntagi` (A4) and `syntagi-a5`, two A5 pages on one A4 sheet | A multi-page A5 prescription is printed two pages per sheet, straight from MedScribe |
| M6 | **Postop instructions + case modules**: the five `odigies` types (the largest import: ~100+ alternatives each, the dural-tear blocks, emergency boxes); **seeding history from the archive (D15)**; **case features and modules (D17)**, e.g. a "dural tear / CSF leak" module | A postop letter in under 5 minutes (G2); adding the "Διαρροή ΕΝΥ" chip brings in its lines across all sections |
| M7 | **Clinical and other types**: `proegkrisi`, `enimerotiko`, `gnomateysi-farmakon`, `iatrodikastiki`, `synainesi`, `praktiko`, `epistoli`, the English types, and the blank `letter-gr` / `letter-en` | All 21 templates covered (G1) |
| M8 | **New types (D12)**: pre-operative instructions, fitness certificates (work, driving, travel), practice documents (GDPR notice, information and price list, patient leaflets) | Each type released after your review; practice documents work without a patient |
| M9 | ⏸ **Deferred (2026-11-06). Scales (D11)**: the `scale` layout, scoring and interpretation bands, blank questionnaire + result table, structured results, quoting a score in another document. First set: NRS/VAS, ODI, NDI, the neurological exam chart, mJOA, BNI | A patient fills an ODI on paper, the secretary enters it, MedScribe scores it, and the follow-up letter quotes the pre-op and current scores |

**Importing the templates (M1, M4–M7):** a one-off import script reads each `tpl-*.tex` and proposes
the type's structure. Uncommented lines become blocks that are on by default, commented lines become
blocks that are off, `[text]` becomes fields, and copied blocks are linked to their shared component.
You then review each type in the app, one per sitting, as you did with the kit's `TYPES-PLAN`, and
release it as v1.

## 11. Open questions

1. ~~**How is a *type* stored inside its versions?**~~ *Resolved 2026-11-05: (c), structured data
   as the master plus a generated kit-style `.tex` (D18).*
2. ~~**Secretary issuing**~~ *Resolved 2026-11-06: no; only the doctor issues, with a per-type
   "secretary may issue" setting that stays off for now (D21).*
3. ~~**Patient gender missing in ZPMS**~~ *Resolved 2026-11-06: ask.* When ZPMS has no gender,
   and always for a patient typed by hand (D23), MedScribe asks for it on the document. It is not
   written back to ZPMS.
4. ~~**Free-text formatting**~~ *Resolved 2026-11-05: light markup (bold, italic, lists, spine
   levels `L4-5` → `\V{L4-5}`) plus a raw-LaTeX block type as an escape hatch; more formatting only
   if a real document needs it.*
5. **DocArc details:**
   - ~~Verification code~~: per type, on by default, AMKA if known, otherwise a random 6-digit
     code; can be switched off per type (D22, 2026-11-06).
   - ~~Which clinic and requesting-user values should be sent?~~ The DocArc clinic linked to the
     document's `\Clinic` key, and the MedScribe user who issues (§7.4, 2026-11-06).
   - ~~What happens to the empty record of an abandoned draft?~~ DocArc deletes it (2026-11-06).
   - ~~Changed documents~~: aligned with DocArc's versioning design, same record and code, new version
     with a correction note (§7.4, 2026-11-06).
   - ~~Withdrawing~~: DocArc deletes the record; MedScribe keeps the document as *withdrawn* and
     deletes it only if the doctor chooses to (§7.4, 2026-11-06).
6. ~~**Draft mark and revision line**~~ *Resolved 2026-11-06: yes.* A `\DocDraft` switch (the
   preview's "ΠΡΟΣΧΕΔΙΟ" mark) and a `\DocRevision` line (the printed "Αναθεώρηση N · date ·
   replaces…", §7.4) are added to `evrtemplate.tex` in git as a new layout-code version (D19),
   both empty by default, so hand-written letters are unaffected.
7. ~~**Who is the master of the base layer after import?**~~ *Resolved 2026-11-05: hybrid —
   code in git, data in MedScribe, releases only in MedScribe (D19).*
8. ~~**Language**~~ *Resolved 2026-11-06: the English types can take the patient from ZPMS, and
   like every type they accept a patient typed by hand and any change to the printed details (D23).
   `recommendation-en` has no patient block.*
9. **Data kept in MedScribe:** drafts and issued inputs include patient data.
   - ~~How long are never-issued drafts kept?~~ *Resolved 2026-11-06:* until the user deletes them;
     there is no automatic expiry. Deleting a draft that holds a reserved code also deletes its
     empty DocArc record (§7.4).
   - ~~A GDPR audit log?~~ *Resolved 2026-11-06: basic logging only.* MedScribe is a document
     generator, so it does not need ZPMS/DocArc-level auditing. It keeps one simple, append-only
     `audit_log` table (who, when, action, document id, revision) for the events that matter if a
     GDPR request ever concerns an issued document: create, issue, revise, withdraw, delete, and
     opening or downloading an issued document. It records no patient names, text or reasons
     (§7.4–7.5), and can be filtered by document or user and exported. Nothing more elaborate
     (access dashboards, per-field change tracking) is planned.
10. ~~**Version numbers**~~ *Resolved 2026-11-05: plain integers per source (v1, v2…) and per
    base release (B1, B2…); the change note says what changed.*
11. **Where do scale results live?** *(deferred to the future, 2026-11-05.)* In MedScribe, linked
    to the ZPMS guid (simplest), or in ZPMS
    as part of the clinical record, with MedScribe sending them there (fits G6 better, but needs a
    ZPMS write API)?
12. ⏸ *Deferred with the scales family (2026-11-06).* **Which scales, and which Greek versions?** Confirm the first set (M9) and the validated Greek
    translation you use for each. Check the licence terms for licensed instruments (EQ-5D,
    SF-36/SF-12) before including them.
13. ⏸ *Deferred with the scales family (2026-11-06).* **Questionnaire entry:** paper only (the secretary types the answers in), or also a tablet form
    the patient fills in at the office?
14. ~~**Intake of edited and new text on issue**~~ *Resolved 2026-11-05: automatic, no
    approval and no de-identification step (D8, §12.1).*
15. ⏸ *Deferred 2026-11-06 until DocArc implements its versioning.* **Corrections before DocArc
    versioning exists:** "Revise" is built once DocArc's versioning is available; the question of an
    interim correction path is not taken up before then.

## 12. Content reuse model (draft, 2026-11-05)

The goal is to minimise writing time by reusing the doctor's own past text. A document mixes text
common to every patient with text specific to this patient; the doctor reuses paragraphs, modifies
them, or writes new ones.

- **Slot = section** (§4): a section's stable name gives it a meaning (e.g. "Φροντίδα τραύματος",
  "Κινητοποίηση", "Επανέλεγχος") shared across types (D16).
- **Block:** a piece of text in a section, with immutable versions (SHA-256, §5.1), a `derived_from` link
  when it is a modified variant, and usage data (count, last used, types, document context).
- **Tiers:** *default* (pre-selected by the type) → *library* (reviewed alternatives) → *history*
  (blocks of all issued documents and the seeded archive, D15, fed automatically). Promotion is
  manual (§12.1).
- **Context / case features:** per-document structured features (procedure, area, levels,
  complications, comorbidities, surgery date…) that fill block fields, pull in modules and rank
  suggestions (§12.2, D17).
- **Suggestions:** ranked by slot → type → family → all text; same-procedure matches first, then
  frequency and recency (a simple heuristic first; see §13).
- **UI (proposal):** Home (drafts, recently issued, quick start); Composer (outline · document as
  block cards · suggestions pane that follows the cursor, Ctrl+K palette, plain-text editing with
  light markup and field chips, raw-LaTeX block as escape hatch); Preview & issue (real PDF on
  demand, checklist incl. numbers to confirm); Library (variant tree, promote/merge/hide, used-in);
  Type editor; Base screen (doctor, clinics, labels, base releases).

### 12.1 Storage, reuse and @tokens (simple model, 2026-11-05)

1. **Every issued document is stored as it is.** It is the record, and it is the knowledge base.
2. **Suggestions come straight from past issued documents.** No separate cleaned copy, no scanning
   or flagging. Drafts that are never issued add nothing.
3. **@tokens.** In the editor, typing `@` inserts a field token (`@patient`, `@surgery_date`,
   `@level`, `@age`, `@clinic`…), shown as a chip. Tokens stay tokens inside the block, so on
   reuse they take the new document's values automatically.
4. **Swap on insertion for plain text.** When a past block contains the old patient's details as
   plain text, the app replaces them with the current ones: the old document records its own
   name, dates and levels, the new one has its own, so this is a lookup, not text analysis.
   ```
   from an old document:  Ο κ. Ιωάννου, μετά τη μικροδισκεκτομή Ο5-Ι1 …
   inserted now:          Ο κ. Παπαδόπουλος, μετά τη μικροδισκεκτομή Ο4-Ο5 …
   ```
5. **Anything else the doctor sees and fixes**, as when reusing an old letter today. One check
   backs this up, because Greek names inflect (Ιωάννης / Ιωάννου) and an exact lookup can miss a
   form: before issuing, MedScribe looks for the name stems and AMKA of every document a block was
   inserted from, and warns if any is still in the text (§7.3).
6. **Promotion to library/default is manual**, and is where a block is cleaned by hand (e.g.
   plain-text details turned into @tokens).

Privacy is unchanged: the text never leaves MedScribe, and the same documents are already stored
there and in DocArc.

### 12.2 Case features and modules (D17, 2026-11-05)

Two approaches were considered:

- **Free description, the app picks lines.** Fast and natural, but the app has to understand free
  text: keywords miss synonyms and negations ("χωρίς διαρροή ΕΝΥ"), and a language model is a black
  box with GDPR implications. When unsure it fails silently: a missing warning sign doesn't look
  missing.
- **Predefined case sections, the doctor chooses.** Predictable, but a complication is not a
  section. A CSF leak changes several sections at once (wound care: longer dressing, watch for
  leakage; mobilisation: lie flat 24–48 h; when to call: orthostatic headache, clear fluid; review:
  earlier visit), and combinations (procedure × complication × comorbidity) explode.

**Chosen: a hybrid.**

```
Case:  [Μικροδισκεκτομή ×] [Ο4-Ο5 ×] [Διαρροή ΕΝΥ ×] [+ add…]
                                       ↑ typed "ενυ" → autocompleted
```

- **Features as chips** at the top of the composer, chosen from a controlled vocabulary with
  synonyms and autocompletion.
- **Each feature can have a module:** a list of operations across slots (§12.3). Removing the chip
  undoes them. Modules stack, so combinations need no extra writing.
- **Explainable:** every line shows its origin ("added by: Διαρροή ΕΝΥ"). The app proposes; the
  doctor reviews and edits as usual.
- **No silent prescribing:** a module may add text, but **medication rows it proposes stay
  unticked until the doctor confirms each one**. A chip never adds a drug to the document by
  itself.
- **Doctor's own text only:** modules select existing blocks; the app never writes clinical
  wording.
- **Modules grow from history:** on issuing a case with a feature, MedScribe notes which blocks were
  added beyond the type's defaults and offers them for a module (§12.3). Promotion is manual.
- **Groundwork for §13:** each issued document records its features and the blocks chosen, which is
  exactly the training data the probabilistic proposals need. Modules give rule-based selection
  first; statistics refine the ranking and surface lines no module covers yet.
- **Later: free text → chips.** A typed description ("μικροδισκεκτομή με διαρροή ΕΝΥ") is turned
  into chips the doctor confirms, so misunderstandings are visible. Start with a term/synonym
  dictionary; a language model is an optional upgrade, given only the case description, never
  patient identifiers.

### 12.3 Module specification (2026-11-06)

**Placement.** A block inside one document's section is a *placement*: block id + version, on/off,
field values, **origin** (`default` · `module:<id>` · `suggestion` · `manual`) and an optional
local edit. The same block has many placements, one per document. The origin is what makes modules
explainable and reversible.

**Features.** A controlled vocabulary: key, Greek label, category (procedure, level, area,
complication, comorbidity…), synonyms, scope (the families or types where it is offered). Some
features also carry a value (e.g. level `Ο4-Ο5`, which fills `@level`). **Any feature may carry a
module, value features included** (e.g. `Ο5-Ι1` adding level-specific advice); a feature without a
module only fills tokens.

**Modules** are versioned lists of operations on **slots**, never on types, and they hold no wording
of their own (they reference existing blocks):

| Op | Meaning |
|---|---|
| `ADD` | insert a block into a slot, at a position |
| `SET` | change a field value of a block already present (e.g. days 5 → 10) |
| `SWAP` | replace a default block with a variant, **only when the wording differs** |
| `DISABLE` | switch off a default block |
| `ENABLE` | switch on an optional section |
| `PROPOSE MEDROW` | add a medication row, unticked until the doctor confirms it |

- **SET, not variant blocks, for value-only differences** (decided 2026-11-06): "…για 10 ημέρες"
  is the same block with `days = 10`, not a new block.
- **Missing slot or block → op skipped, visibly.** A type without `instructions.wound_care` ignores
  that op, so one module serves every type in which its slots exist (D16). A module may be limited
  to a scope. Skipped ops are never silent: the chip shows "2 of 7 changes don't apply here" and
  lists them, since a missing warning line doesn't look missing.
- **Choice groups:** `ADD` of a block whose choice group already has a block on acts as a `SWAP`
  within the group, so two alternatives are never both on.
- **Modules reference released blocks only** (default or library tier); a history block must be
  promoted before a module can use it.

**Assembly.** Type defaults (origin `default`) → apply each chip's module in the order the chips were
added → resolve tokens, fields, gender → the doctor edits, adds suggestions, ticks medication rows →
issue checks (no unconfirmed rows, no `[placeholders]`).

**Rules.**
- **Removing a chip** undoes only that module's ops: its placements disappear and the fields it set
  return to their defaults, unless the doctor has changed them since. A line the doctor has edited
  is kept and marked "origin removed — keep?".
- **Stacking:** modules combine freely. The same block added by two modules appears once, with both
  origins.
- **Conflicts are the doctor's choice** (decided 2026-11-06): two `SET`s of one field to different
  values, or two `SWAP`s of one block, flag the line and the doctor picks. No module priorities and
  no app-made rule such as "take the larger value".
- **No silent prescribing:** module medication rows are never ticked automatically; the document
  cannot be issued while one is unconfirmed.
- **Versioned:** the manifest records each module version (e.g. `csf_leak v2`); changing a module
  never changes an issued document. A draft keeps the module version it applied; a revision keeps
  them like the other template versions.

**Growth from history.** On issue, MedScribe compares the document with the type defaults and offers
the differences (added blocks, changed fields, medication rows) for a module. With several chips the
app cannot tell which one a difference belongs to, so the doctor assigns each kept difference to a
chip (or to none); promotion is manual.

**Likely tables** (to confirm in M0): `features`, `modules`, `module_ops` (module version, seq, op,
slot, block + version, field, value, position), `document_features` (feature, value),
`document_blocks` (slot, seq, block + version, enabled, field values, origin, local text).

## 13. Future ideas (noted, not planned)

- **Probabilistic (Bayesian) sentence proposals.** Instead of the fixed ranking heuristic of §12,
  estimate for each block the probability that it is chosen given the document's features —
  certificate/document type, body area operated, operation type, duration (e.g. leave or
  recovery days), diagnosis, levels, patient age/gender, clinic, etc. — learned from the history
  of issued documents (e.g. naive Bayes with smoothing, so blocks with little data still appear).
  Could also propose default *values* (typical leave days for a given operation). Needs: the
  context fields to be structured and recorded with every issued document from day one, so the
  data exists when this is built; keep it explainable ("suggested because: microdiscectomy, L4-5").
- **Register tags on blocks** (e.g. short · detailed · patient-friendly · technical). The same
  clinical content can exist in several registers, and suggestions prefer the register that fits
  the type's audience (D13): patient-friendly for a consent or instructions, technical for an
  operative note or a letter to a colleague.
- *(More ideas to be added as the doctor researches further features.)*


## 14. Directory layout (2026-11-05)

Copied from ZPMS (the reference ZeusFw app), with conventions also found in erweb and mweb.
**(new)** marks what is specific to MedScribe.

```
medscribe/
├── CLAUDE.md  PLAN.md  README.md  LICENSE  ChangeLog  TODO.md  BUILD_NUMBER
├── fw -> /var/www/shtml/apps/zeusfw          symlink, gitignored
│
├── config/
│   ├── settings.info.yaml        routes, regions, modules, menu, roles, languages
│   ├── dictionary.gr.php         t() translations
│   ├── db.php.in  db.test.php.in  site.info.yaml.in  update.info.yaml.in
│   ├── zpms_api.php.in           ZPMS URL + X-MedScribe-Api-Key (MedScribe calls ZPMS)
│   ├── docarc_api.php.in         DocArc URL + key (MedScribe calls DocArc)
│   └── tex.php.in          (new) XeLaTeX path, kit path, job limits
│        (the real *.php / *.yaml next to them are gitignored)
│
├── web/
│   ├── index.php  .htaccess  .user.ini
│   ├── core -> ../fw/core/                   symlink, gitignored
│   ├── user_classes.php  ClassesEx.php       extends the generated classes
│   ├── classes/yaml/*.yaml                   entities (generated php/sql gitignored)
│   │
│   │   feature files, flat, as in ZPMS (required from index.php; functions = route handlers):
│   ├── rbac.php  rbac_seed.php  audit.php    RBAC as ZPMS; audit.php basic only (Q9)
│   ├── zpms_client.php  docarc_client.php    cURL clients (3 s lookups, longer uploads; fail closed)
│   ├── documents.php       pages: home, list, new, view, history, revise, withdraw
│   ├── composer.php        composer page + its JSON endpoints (autosave, insert block,
│   │                       suggestions, palette search)
│   ├── library.php         library screen: tiers, promote, hide, used-in
│   ├── types.php           type editor, versions, release
│   ├── base.php            doctor, clinics, labels, base releases
│   ├── store.php     (new) content-addressed version store (SHA-256)
│   ├── render.php    (new) inputs → .tex: escaping, markup, @tokens, gender variants
│   ├── compile.php   (new) XeLaTeX jobs in a temporary folder, manifests
│   ├── suggest.php   (new) ranking, case features → modules
│   ├── issue.php     (new) two-stage issue: request code → compile → approve → send → lock (§7.4)
│   │
│   ├── templates/
│   │   ├── page/  blocks/  menu/  nav/  webforms/      as in ZPMS
│   │   ├── content/        home, documents_list, composer, issue, history, library,
│   │   │                   type_edit, base_edit, settings, audit_log, login
│   │   └── partials/       block_card, suggestion_card, chip, outline   (erweb's convention)
│   ├── modules/            header  topbar  userprofile  buildnumber  githash  copyright
│   ├── js/                 composer.js  suggestions.js  palette.js  preview.js  scripts.js
│   ├── css/                styles.css  composer.css  library.css  …
│   ├── assets/  fonts/
│   ├── cache/                                gitignored
│   └── files/    = libpath, gitignored, writable by www-data
│       ├── .htaccess (new)  "Require all denied": PDFs and sources only through PHP
│       ├── store/objects/ab/cdef…            sources and block versions, by SHA-256
│       ├── documents/2026/<id>/rev<N>/       document.tex  document.pdf (as approved)
│       ├── jobs/                             temporary compile folders, cleaned after each job
│       └── backups/  logs/
│
├── bin/
│   ├── post-update.sh       last resort only, not created until needed (see below)
│   ├── run_tests.sh  import_dictionary.php  update_from_git.sh  restore.sh
│   ├── install_git_hooks.sh
│   ├── import_kit.php       (new) evrdocs kit → v1 sources + base release B1 (D19)
│   ├── seed_archive.php     (new) old letters → history (D15)
│   ├── rebuild_document.php (new) rebuild from a manifest, check the PDF hash (G5)
│   └── lib/
├── deploy/    backup.conf.example  backup-handler.php  health-handler.php
│              medscribe-logrotate  medscribe-update.sudoers   (zops, as ZPMS)
├── sql/       msql.sh  msqldump.sh  admin.sql.in  test/       (sql/backup gitignored)
├── lib/       zops/                            gitignored, as ZPMS
├── tests/     run_all.php  bootstrap.php  README.md
│   ├── lib/          Assert, HttpClient, ServerManager, TestSchema… (from ZPMS)
│   ├── static/       php_lint, js_lint, template_consistency, dictionary_terms
│   ├── functional/   auth_csrf, document_crud, composer_api, issue_flow (DocArc mocked)
│   ├── tex/   (new)  every type compiles; regression PDFs (G3)
│   └── fixtures/ (new) a small copy of the kit + sample type definitions
├── githooks/  pre-commit
├── docs/  plans/
└── var/  logs/                                 gitignored
```

- **Flat feature files in `web/`,** as ZPMS: `index.php` `require_once`s each; their functions are
  the route handlers. No separate engine folder; ZeusFw provides the UI.
- **Schema updates use `fw/bin/update.sh`** (regenerate from YAML, import missing tables, apply
  `diff:sql`, load feeders and webforms). No separate migration tool.
- **Keep `bin/post-update.sh` to a minimum.** Prefer, in this order:
  1. the entity YAML itself (columns, defaults, indexes), applied by `diff:sql`;
  2. feeders (`web/content/*.feeder.yaml`) for reference and seed data, loaded by `update.sh`;
  3. explicit one-off CLI scripts run by hand (`import_kit.php`, `seed_archive.php`), never
     triggered on every update;
  4. code that tolerates old rows (e.g. treats a missing value as the default).

  Only a data change none of these can express goes into `bin/post-update.sh`, as an idempotent
  step, and each such case is raised with the user first.
- **`web/files/` is `libpath`** (ZPMS convention), but **denied to direct web access**. Apache
  serves any real file under `web/` directly, since `.htaccess` only rewrites missing paths, so
  documents are streamed through PHP behind the login.
- **Backups** use `lib/zops` with `deploy/backup-handler.php`, as ZPMS. The version store is plain
  files under `web/files/store/`, so it is backed up with everything else.
- **The evrdocs kit is not copied into the repo:** `bin/import_kit.php` reads it from the path in
  `config/tex.php`. Only a small copy lives in `tests/fixtures/`.
- **Likely entities** (`classes/yaml/`, to confirm in M0): `documents`, `document_revisions`,
  `document_blocks`, `document_features`, `sources`, `source_versions`, `base_releases`, `types`,
  `blocks`, `features`, `modules`, `module_ops`, `docarc_types`, `docarc_clinics` (cached lists and
  links, D20), `audit_log`, plus ZeusFw's users and roles.
