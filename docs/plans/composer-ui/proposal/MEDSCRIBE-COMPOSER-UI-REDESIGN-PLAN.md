# MedScribe Composer UI Modernisation Plan

**Status:** Design and implementation brief for Claude.ai  
**Scope:** Document list, new-document flow, document composer, PDF preview, checks, and issuing workflow.  
**Out of scope:** The document type editor. Treat the type editor as complete and do not redesign it as part of this work.

## 1. Product goal

Modernise the document-writing experience so a doctor or secretary can understand what to do next without having to learn the internal data model. The interface should feel like a calm, focused clinical workspace rather than a long technical form.

The redesign must preserve the existing document model, type-version behaviour, text semantics, PDF/TeX generation, DocArc integration, audit trail, and role permissions. This is a UI/UX redesign, not permission to simplify or change business rules.

### Primary user jobs

1. Find a recent document and understand its status.
2. Start a document for a ZPMS patient or enter a patient manually.
3. Complete patient/document details and type-specific values.
4. Select prepared clinical wording, edit it for this document, or add original text.
5. Apply/remove case features.
6. Add/edit medication rows where relevant.
7. inspect a faithful PDF preview and resolve checks.
8. Hand a document to the doctor for review, or issue it as the doctor.
9. Understand exactly when a document becomes frozen and what has been sent to DocArc.

## 2. Design direction

### Visual language

- Calm, clinical, contemporary; avoid a generic admin-dashboard look.
- Warm-white content surfaces on a very light cool-grey canvas.
- Deep navy navigation; restrained blue for primary actions; teal/green only for successful validation or completed steps; amber for attention/review; red only for blocking errors.
- Use whitespace and grouping instead of dense borders and nested boxes.
- Rounded corners (8–12 px), subtle shadows, thin neutral dividers.
- Typography: a highly legible sans-serif for the application; preserve the document's own typography in the PDF preview.
- Use clear labels and explanatory microcopy. Avoid internal terms such as `slot`, `layout`, `token`, `code_reserved`, `final_compiled` in ordinary user-facing UI unless placed in a technical detail/help area.
- Keep Greek patient-facing content and Greek document output intact. UI labels may be bilingual if that matches the existing product language.

### Interaction principles

- One obvious primary action per screen.
- Make document state and next step visible at all times.
- Progressive disclosure: show essential information first; keep optional/advanced details collapsed.
- Use human labels, meaningful counts, and plain-language status descriptions.
- Preserve typed content when a save/build operation fails.
- Never imply that the PDF preview is current if it is stale.
- Do not hide the difference between **Save**, **Save & preview**, **Ready for review**, and **Issue**.
- Avoid modal dialogs for routine editing. Use inline editing, drawers, or focused confirmation dialogs only when needed.

## 3. Proposed information architecture

### Persistent application shell

- Left navigation:
  - Documents
  - New document
  - Text library
  - Document types
  - DocArc links
- Top bar:
  - Breadcrumb / current page
  - Search (if supported by existing functionality)
  - Account menu
- Main content area:
  - Page title and short description
  - Contextual actions

The type editor remains accessible through Document types but is not part of this redesign.

## 4. Screen-by-screen redesign

### A. Documents dashboard (`/documents`)

Replace the bare table-first page with a practical work queue.

**Top area**
- Title: “Documents”
- Description: “Create, review and manage your medical documents.”
- Primary action: **New document**

**Summary cards**
- All documents
- Drafts in progress
- Awaiting your review
- Issued this month

These are optional if reliable counts are not already available. Do not introduce expensive or inaccurate aggregate queries solely for decoration.

**Recent documents table**
- Document title/type and number
- Patient
- Human-readable status
- Last updated
- Created by
- Row action menu

Add search and filters only where the backend can support them. Keep the table compact, scannable, and sortable if sorting is already supported.

**Status language**
Map internal states to clear display labels while retaining the exact internal values:
- `draft` → Draft
- `review` → Ready for review
- `code_reserved` → DocArc code reserved
- `final_compiled` → Final PDF ready
- `approved` → Approved — sending
- `pdf_sent` → PDF sent — completing archive
- `issued` → Issued
- `withdrawn` → Withdrawn

Use distinct, accessible status colours, but never rely on colour alone.

### B. New document flow

The current page is a single form. Keep it simple, but structure it as a short guided setup.

**Step 1 — Document**
- Document type (only types with a released version)
- Explain that the latest released type version is used when the draft is created.

**Step 2 — Patient**
- Search ZPMS by name or AMKA with autocomplete.
- Make the selected ZPMS patient visually distinct and show a “Linked from ZPMS” indicator.
- Offer a clear **Enter patient manually** alternative.
- For manual entry, show only the fields required by the selected type and document rules.
- Explain that edits made inside this document do not update ZPMS.
- Gender must remain explicit where Greek grammar depends on it.
- For patient-free types, make the patient section clearly optional.

**Step 3 — Document settings**
- Clinic
- Document date (default today)
- Any other genuinely creation-time setting

Primary action: **Create draft**.

Do not change the current patient-data copying rules, age calculation, or type-version snapshot behaviour.

### C. Document composer (`/documents/{id}`)

This is the most important screen. Replace the long, visually uniform vertical form with a two-pane workspace.

**Desktop layout**
- Left pane: editable document content (approximately 52–56%).
- Right pane: PDF preview (approximately 44–48%).
- Resizable divider and the existing three view modes: form only, both, preview only.
- Keep the preview visible by default on sufficiently wide screens.
- On narrow screens, use a clear Form / Preview switch rather than squeezing both panes.

**Composer header**
- Document title and number
- Human-readable status
- Patient name (if present)
- Last saved / unsaved indicator
- Contextual actions: History (when applicable), Issue / Issue page (doctor only), More

**Patient summary banner**
- Patient name, gender, age, AMKA (mask where appropriate)
- Source indicator: “Linked from ZPMS” or “Entered manually”
- Edit patient action
- A concise note: “Changes here apply to this document only.”

**Document details card**
Group the current fields:
- Clinic
- Document date
- Printed title
- Closing
- Letterhead version
- Stamp choice
- Recipient, when applicable

Use human labels and inline hints. Letterhead version should be presented as an advanced setting with a warning that changing it is intentional and allowed only before code reservation, matching current rules.

**Clinical values card**
- Render type-defined values in a responsive grid.
- Required fields have a visible “Required” label, not only an asterisk.
- Keep date input and formatting behaviour as currently defined by the type.
- Explain that values populate all matching references in the document.
- Group related fields if the type metadata supports grouping; otherwise do not invent groupings.

**Case features**
- Present as selectable chips or compact cards.
- Each feature should explain its effect in plain language, e.g. “Selects the sentences and section commonly used for this case.”
- Show active features clearly.
- Removing a feature must continue to restore exactly the prior state it changed.
- Preserve the current save-before-apply/remove behaviour and protect unsaved content.

**Sections**
Convert each section to a clear accordion/card:
- Human-readable section title
- Optional / required indicator
- Sentence count or medication-row count
- Short helper text where the type provides it
- Expand/collapse affordance
- Expand all / Collapse all controls

Default folding rules remain the same: empty optional sections start folded; folding is only a screen preference and never changes document content.

**Sentence selection**
- Checkbox sentence: use a checkbox row with the complete sentence visible.
- Choice group: use radio cards or a compact radio list, with “None” clearly available.
- Keep edited-for-this-document wording visually distinct and explain that it does not alter the type.
- Switched-off sentences retain their edits.
- LaTeX blocks remain read-only and visually labelled as system-managed content; do not expose raw TeX as editable text in the composer.

**Free text**
- Use a comfortable multiline editor with clear paragraph/list guidance.
- “Add your own text” should be the prominent action.
- Present previous-document/library suggestions as optional insertion chips or a suggestion panel. Inserting a suggestion copies it into the current document; it must remain editable.
- Keep field insertion available near the editor. Insert at the last active text cursor as it does now.
- Explain the supported markup through a small “Formatting & fields” help popover, not a large always-visible technical guide.
- Keep `@field`, gender alternatives, bold/italic, spine-level markup, line breaks, and escaping semantics unchanged.

**Medication table**
- Use a proper structured table with readable column widths and row spacing.
- Primary action: **Add medication**. Offer “From drug library” and “Empty row” as clear choices.
- Keep the existing columns: Drug, Dose, Frequency, Days, Notes.
- Make row deletion explicit and easy to target.
- Preserve editable cells, `{br}` behaviour, default rows, and the rule that empty drug-name rows are dropped on save.
- A table with no rows remains absent from the printed document.

**Sticky composer action bar**
- Save
- Save & preview (primary)
- Ready for review (when role/status permits)
- Clear save state: Saved / Unsaved changes / Saving / Save failed
- If preview is stale, show “Preview needs updating” with a direct action.
- Do not add autosave unless separately specified and designed with the current build-locking rules.

### D. PDF preview

- Show the actual generated PDF, not a fabricated HTML approximation.
- Preview header should say whether it is current or stale and show the preview generation time.
- Zoom controls and page navigation should be easy to find.
- Preserve draft watermark and empty preview QR frames.
- A5 printing links remain available only for A5 types and should be grouped under a small “Print options” menu.
- Clearly distinguish preview from final PDF.

### E. Checks and validation

Move checks from an easily missed area at the bottom into a persistent, compact validation summary near the composer action bar and on the issue page.

**States**
- All checks passed
- N items need attention

Each issue should be actionable: clicking it should navigate to and focus the relevant field/section.

Preserve all existing checks:
- Missing required value
- Unknown `@field`
- Missing gender for Greek gender alternatives
- Empty patient name when required
- Empty document date
- Missing clinic

Saving and previewing with errors remains allowed. Issuing remains blocked when checks fail. Do not convert non-blocking warnings into hard errors without an explicit product decision.

### F. Review and issue page

Replace the plain technical issue page with a guided three-stage process:

1. Prepare
2. Review final PDF
3. Approve & issue

Show a compact summary:
- Patient
- MedScribe document type and DocArc link status
- DocArc clinic
- Reserved code, when available
- Validation result

**Stage 1 — Request code and build**
- Explain that DocArc creates the record first so the final PDF can contain the real QR.
- Button: **Request code & build final PDF**
- Show progress and prevent duplicate submissions.

**Stage 2 — Review final PDF**
- Display the exact final PDF that will be archived.
- Make it obvious that no document has been sent yet.
- Actions: **Approve & send to DocArc** and **Back to editing**.
- If returning to editing, preserve the reserved code and rebuild at the next issue step as currently specified.

**Stage 3 — Issued**
- Display success state and issued code.
- Show verification code / QR link instructions.
- Links to exact PDF and `.tex` sent.
- If the send stopped part-way, preserve the current `approved` / `pdf_sent` recovery semantics and show **Continue sending** or **Send again** as appropriate. Never send twice and never rebuild during retry.

**Withdraw**
- Keep withdrawal separate from routine correction.
- Require a reason and a strong confirmation explaining that DocArc deletes the record and the QR link will no longer resolve.
- Keep withdrawn documents doctor-only and preserve audit behaviour.

**History**
- Present each archived revision as a timeline/list with PDF, `.tex`, letterhead files/versions, and TeX environment.
- Keep Rebuild and verify doctor-only.
- Do not imply that revisions of an issued document can be created; they are not currently available.

### G. Text library

The library is not the central focus of this redesign, but align its visual language:
- Group by section in a searchable list.
- Show a short excerpt and source document/date.
- Make Promote / Demote / Hide actions clear.
- Explain that suggestions are inserted for review and are not automatically printed.
- Preserve existing field substitution and withdrawal behaviour.

## 5. Role-aware UI

The same screen should adapt to role permissions without hiding the workflow's meaning.

**Doctor**
- All document authoring actions
- Ready for review / Back to draft
- Issue, approve, send, withdraw
- Delete withdrawn document
- History and rebuild/verify
- Library, type configuration, DocArc links

**Secretary**
- Create, edit, save, preview
- Ready for review / Back to draft
- Delete draft
- No issue, approve, send, withdraw, withdrawn-document access, or doctor-only library/type actions

Hide or disable unavailable actions consistently. If disabled, explain why and who can perform the action.

## 6. Non-negotiable functional invariants

Claude must preserve these behaviours exactly unless a separate change is explicitly approved:

1. A document snapshots the released type version used at creation.
2. ZPMS data is copied into the document; later document edits never update ZPMS.
3. Patient age is calculated at the document date.
4. Greek gender agreement depends on patient gender; missing gender blocks issue where required.
5. A sentence edit is document-local. Returning to the original text drops the edit.
6. Turning a sentence off does not discard its edit.
7. Case features switch on/off their defined elements and removal restores exactly the prior state.
8. Applying/removing a case feature saves first so text is not lost.
9. Free-text suggestions are copied into the document and reviewed/edited by the user.
10. Field insertion uses the last active text cursor.
11. Medication default rows are copies; empty-name rows are discarded on save.
12. No autosave is introduced by this UI project.
13. Save is refused while a PDF build is in progress; typed changes remain in the form and can be saved again.
14. Save & preview stores the form and builds the preview; preview is not silently refreshed.
15. Preview PDF has draft watermark and preview QR frames; it is not the final PDF.
16. A document with checks failing may be saved and previewed, but cannot be issued.
17. DocArc code reservation precedes final PDF generation.
18. Approve & send archives the exact approved PDF and `.tex`; it must not rebuild.
19. Send retries resume safely and never duplicate the DocArc record or rebuild the PDF.
20. Issued documents are frozen. Withdrawal is exceptional and deletes the DocArc record.
21. Doctor/secretary permissions and all audit/history behaviour remain unchanged.

## 7. Responsive behaviour

- Desktop (≥ 1200 px): split form + preview workspace.
- Tablet (768–1199 px): form/preview tabs or a collapsible preview drawer; no cramped 50/50 split.
- Mobile (< 768 px): single-column flow, sticky bottom action bar with safe-area spacing, compact patient summary, full-width controls.
- Tables may scroll horizontally on small screens, but medication editing should remain usable.
- Preserve keyboard navigation and focus when sections expand/collapse.

## 8. Accessibility and usability requirements

- Meet WCAG 2.2 AA contrast for text and controls.
- All controls have visible focus states and accessible labels.
- Keyboard support for accordion, choice groups, dialogs, and menus.
- Do not communicate status by colour alone.
- Minimum comfortable touch target around 40–44 px.
- Avoid tiny text for metadata; do not reduce clinical sentence text to fit.
- Support browser zoom and long Greek patient names/clinical text without clipping.
- Confirm destructive actions and explain their consequences.

## 9. Suggested implementation sequence for Claude

### Phase 0 — Audit and mapping
- Read the existing composer documentation and inspect the current implementation.
- Map every current form control, endpoint, state transition, and permission to the redesign.
- Identify reusable components and existing PDF/DocArc mechanisms.
- Do not change backend logic or schema during the initial UI pass.

### Phase 1 — Design tokens and shared shell
- Add consistent spacing, typography, colour, border, radius, shadow, and focus tokens.
- Implement the left navigation, top bar, page heading, buttons, status pills, cards, form fields, and responsive layout primitives.
- Keep the existing framework and coding conventions; avoid introducing a new dependency unless justified.

### Phase 2 — Documents list and new-document flow
- Implement dashboard table, status mapping, search/filter only if supported.
- Redesign new-document form and ZPMS/manual patient selection.
- Verify patient-free document types and all current validation rules.

### Phase 3 — Composer structure
- Implement split-pane workspace, patient banner, document details, clinical values, case-feature controls, and section accordions.
- Keep all existing data names and form submission payloads stable.
- Verify expand/collapse and resizable pane behaviour.

### Phase 4 — Sentence and free-text editors
- Improve checkbox/radio choice presentation, document-local edit indication, free-text editor, field insertion, and markup help.
- Verify cursor insertion, text preservation, Greek text, and all markup edge cases.

### Phase 5 — Medication editor and preview
- Redesign medication rows and drug-library insertion.
- Integrate the existing PDF renderer unchanged.
- Add clear preview freshness state, zoom/page controls, and A5 print options.

### Phase 6 — Checks and issue workflow
- Add actionable validation summary.
- Implement guided issue stages using existing state transitions.
- Preserve locking, code reservation, final PDF approval, retry, withdrawal, and history semantics.

### Phase 7 — Responsive/accessibility QA
- Test desktop, tablet, and mobile.
- Test keyboard-only operation and browser zoom.
- Test long names, long sentences, empty optional sections, large medication tables, Greek characters, and slow PDF generation.

## 10. Required regression test matrix

| Area | Test | Expected result |
|---|---|---|
| New document | ZPMS search and select | Correct patient data copied; source link shown |
| New document | Manual patient | Manual fields work when ZPMS is unavailable |
| Patient | Edit copied patient details | Only this document changes |
| Values | Required field empty | Check appears; preview remains possible; issue blocked |
| Sentences | Change prepared wording | Edit marked document-local; type unchanged |
| Sentences | Restore original wording | Edit marker disappears |
| Choice group | Select None | No sentence in group prints |
| Sections | Collapse/expand | No document content changes |
| Free text | Insert field at cursor | Field inserted at last active cursor |
| Library | Insert prior text | Copied into current document and remains editable |
| Case feature | Add/remove | Correct elements switch; prior state restored |
| Medication | Add library item | Row is copied and editable |
| Medication | Save empty drug-name row | Empty row discarded |
| Save | Build in progress | Save refused; typed content retained |
| Preview | Edit after preview | Preview marked stale until Save & preview |
| Issue | Missing DocArc type link | Issuing blocked with actionable explanation |
| Issue | Request code | Code reserved before final PDF build |
| Issue | Approve & send | Exact reviewed PDF and `.tex` sent; no rebuild |
| Retry | DocArc unavailable mid-send | Safe continuation; no duplicate record |
| Issued | Attempt edit | Document remains frozen |
| Withdraw | Provide reason | DocArc record deleted; audit retained |
| Permissions | Secretary opens issue route | Issue actions unavailable |
| History | Rebuild and verify | Byte-identical verification uses archived build inputs |

## 11. Definition of done

- All screens in scope use the new visual system and responsive layout.
- The type editor is untouched.
- No existing document content, payload, workflow state, permissions, PDF output, or DocArc semantics are changed.
- All regression cases above pass.
- No user-entered content is lost on failed save/build or navigation within the supported flow.
- The interface communicates saved/unsaved/stale-preview/validation/issue states clearly.
- The implementation is delivered in small, reviewable phases rather than a single risky rewrite.

## 12. Reference mockups

The accompanying PNGs are conceptual UI mockups, not screenshots of the running application:

1. `01-documents-dashboard.png` — document work queue and status overview.
2. `02-document-composer.png` — split-pane editing workspace with patient summary and PDF preview.
3. `03-review-issue.png` — guided final review and issuing process.

Use them as layout and visual-direction references. Adapt details to the existing framework and actual capabilities rather than copying static mockup data literally.
