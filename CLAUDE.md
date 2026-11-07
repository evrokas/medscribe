# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

MedScribe is in design, with a **pre-M0 shell** running (login, roles, placeholder pages; see "Pre-M0 shell"
below). The repo has `README.md`, `LICENSE` (Apache-2.0) and **`PLAN.md`**, the design
plan (goals, decisions D1–D19, versioning, content reuse model, milestones, open questions, and the
directory layout in §14). The template store, compile service and test runner of M0 exist (see below). Read `PLAN.md` before building anything. Update this file as soon as real structure and commands exist; don't invent
conventions that aren't in the code or the plan.

## What it is

A template-driven generator for medical documents (reports, certificates, consent forms, clinical
letters). It is built in PHP on the in-house **ZeusFw** framework and typesets output with TeX/LaTeX. The
intended architecture keeps four layers separate: generation engine, framework, templates and content.
It is meant mainly for one person's medical practice, but should stay adaptable for other practitioners.

## Environment

- Served from `/var/www/shtml/apps/medscribe` alongside sibling ZeusFw apps (`erweb`, `mweb`, `zpms`,
  `zweb`, …) under the Apache `DocumentRoot /var/www/shtml`.
- **ZeusFw** is a separate checkout at `/var/www/shtml/apps/zeusfw` with its own `CLAUDE.md`. Read it
  before using framework features (YAML routes, `.zetem` templates, `maker.php` entities, `core/lib/`
  utilities). Sibling apps don't copy the framework. They symlink to it, for example `erweb/fw ->
  /var/www/shtml/apps/zeusfw`, and keep the symlink gitignored. Anything under `zeusfw/core/` is shared
  by every app, so framework changes must stay backward-compatible.
- TeX Live 2026 is installed at `/usr/local/texlive/2026/bin/x86_64-linux/`. `pdflatex`, `xelatex`,
  `lualatex` and `latexmk` are all available.
- Check PHP syntax with `php -l <file>`.
- Sibling apps make new files `evrokas:www-data` so Apache can read them. Check an existing app's
  `CLAUDE.md` (for example `../inv/CLAUDE.md`) for the exact permission conventions before creating
  web-served files.

## LaTeX templates (source: `~/devel/docs`, GitHub `evrokas/evrdocs`)

The documents MedScribe has to produce already exist as a hand-edited LaTeX kit used on Overleaf. Read
`~/devel/docs/template-kit/README.md` and `VARIABLES.md` (the full variable reference) before touching
the generation layer. MedScribe should drive this kit, not rewrite it.

- **Layout:** the shared files sit in the kit root, and every path inside them is relative to that
  root, so TeX must run *from the root*:
  - `evrtemplate.tex`: the letterhead (header, footer, QR, date, title, signature/stamp).
  - `evr-gr.tex` / `evr-en.tex`: doctor details, `\QRBaseURL`, labels, and one
    `\SetClinic{key}{name}{address}{phone}{logo}` line per clinic. A new clinic must be added to
    both files.
  - `images/`: logos and the stamp image.
  - `latexmkrc`.
- **Templates:** `templates/tpl-*.tex` holds one complete, compilable letter per document type (the
  README table maps type → file). `letter-gr.tex` / `letter-en.tex` are blank letters. The text is
  Greek apart from the `-en` and `recommendation-en` files. `overleaf/` is the old archive; don't use
  it.
- **How a letter is set up:**
  - **Variables:** `\providecommand\Name{value}` lines come *before* `\input{evrtemplate}`. Because
    `\providecommand` doesn't overwrite, MedScribe can inject values by prepending its own
    definitions before the template's settings block.
  - **Main variables:**
    - `\DocLang` (`gr`/`en`, matched with documentclass `greek`/`british`), `\DocPaper` (`a4`/`a5`)
      and `\Clinic` (`Mandra`, `Peristeri`, `Bioclinic`, `Psychikou`, `Metropolitan`, `Dunant`,
      `Euroclinic`).
    - `\DocTitle`, `\DocDate`, `\DocClosing` (empty = no doctor block) and `\DocStamp` (`yes`/`no`).
    - `\PatientPrefix` / `\PatientName` / `\PatientAge` (empty = hidden) / `\PatientAMKA`.
  - **Template-specific variables:** `\LeaveDays`, `\Drug`, `\DrugCount`, `\Recipient`, `\InputPDF`.
  - **Body helpers:**
    - `PatientInfo` with `\Info{label}{value}` rows;
    - `MedTable` with `\DrugEntry{name}{dose}{freq}{days}{notes}`;
    - `UrgentBox`;
    - `\V{L4-5}` for spine levels.
  - **Body text:** placeholders are written `[text]`, and alternative sentences are `%`-commented
    lines. Turning those into structured input is MedScribe's main templating job.
  - **Drug library:** the postop templates (`tpl-odigies-*`) and `tpl-syntagi.tex` share a 32-line
    commented drug library that must stay identical across all six files.
- **QR ↔ DocArc:** the QR code encodes `\QRBaseURL` + `\DocCode`, where `\QRBaseURL` is
  `https://doc.erns.eu/qr/` (DocArc's public `qr.php`). `\DocCode` is the DocArc record token, and
  empty means no QR. This is why the DocArc record must exist before typesetting (see DocArc below).
- **Build:**
  - **Engine:** XeLaTeX only (fontspec + the system font **Roboto Slab**, which is installed here).
  - **Passes:** at least two runs are needed (TikZ overlays, `LastPage` totals, QR position).
  - **Reference script:** `~/devel/docs/scripts/build.sh <file.tex>` (`-o outdir`, `-k` keep temp
    files). It copies the kit into a temp dir, runs `xelatex -interaction=nonstopmode -halt-on-error`
    2–4 times until no "Rerun" message appears, and copies the PDF out. MedScribe's compile step
    should behave the same way: an isolated temp dir per job and never writing into the kit. Two
    traps found in review (`PLAN.md` §5.4, §7.2): TeX Live's `openin_any=p` does **not** stop `\input` of
    server files here (checked 2026-11-06: absolute and `../` paths are still read), so compile jobs run
    in `bin/tex-sandbox.sh` (private root with only `/usr`, fonts and the job folder, no network); and
    `evrtemplate.tex` defaults `\DocDate` to `\today`, which a fixed `SOURCE_DATE_EPOCH` overrides, so
    MedScribe must always set `\DocDate` explicitly.
  - **Two A5 pages per A4 sheet:** `scripts/a5-2up.sh [--dup] [-c] <a5.pdf|.tex>` (pdfpages), or
    `latexmk -xelatex templates/tpl-a5-2up.tex`.
- **Content rules from the kit:** templates contain placeholders only, never real patient data.
  Clinical wording is the doctor's own; don't rephrase it. `template-kit/PROGRESS.md` lists open
  clinical review items.

## Collaborating apps

MedScribe sits between two existing apps. It reads patient data from **ZPMS**, the main collaborator,
and stores finished patient documents in **DocArc**. Neither side has a MedScribe endpoint yet. Any
change to either integration needs the other repo's `CLAUDE.md` as well.

### The family's integration pattern (copy it)

Every app pair in this family already works the same way: ZPMS↔DocArc and ZPMS↔APYweb (`../apy`, see
its `CLAUDE.md`, "Link to ZPMS"):

- **Server to server only.** The browser calls a session-gated endpoint in its own app, and that app's
  PHP calls the other app with cURL. The shared key must never reach client-side JS.
- **Auth:** one static key per app pair, sent in a custom header (`X-DocArc-Api-Key`,
  `X-ZPMS-Api-Key`, …). The serving side checks it with `hash_equals()`. The serving endpoint is
  *not* session-gated: in ZeusFw it is a route with no `access:` key, so the handler is the only guard.
- **Config:** the key lives in a gitignored `config/<peer>_api.php` file holding `define(...)`
  constants. ZPMS commits it as `*.php.in`; APYweb commits it as `*.php.example`. Generate keys with
  `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. If the file is absent or the key is blank, the
  integration is off:
  - **Serving side:** returns HTTP 503 `{"error":"unavailable"}`.
  - **Calling side:** quietly shows nothing.
- **Serving-side status codes:** 401 `{"error":"unauthorized"}` (logged with `REMOTE_ADDR`) and 400
  for bad input.
- **Calling side fails closed:** 3-second timeouts (`CURLOPT_TIMEOUT` and `CURLOPT_CONNECTTIMEOUT`).
  Any network error, non-200 status or malformed JSON returns `null` and goes to `error_log()`, never
  an exception. Check the response field by field and keep only well-formed rows. Reference clients:
  `../zpms/web/apyweb_client.php` and DocArc's `includes/zpms_client.php`.
- **Minimal disclosure:** return only the fields the caller needs. Write an audit entry that records
  who asked and how many results matched, never search terms or patient names (ZPMS:
  `zpms_audit('…', ['actor' => '<app>', 'actor_type' => 'api', 'detail' => 'matches=N'])`).
- **No shared patient ID between apps.** DocArc and APYweb match on the patient's name only. APYweb
  normalises names with `upper_gr_php()` (accents removed, uppercased). ZPMS patients do have a stable
  `guid`, which MedScribe should store and reuse. A patient may also be typed by hand (no `guid`), for any
  type, and printed details can be changed per document without writing back (`PLAN.md` D23).

### ZPMS (`../zpms`): patient data source

- **Existing endpoint:** `GET /api/v1/patients/search/{term}`, handled by
  `docarc_patients_search_api` in `web/api_patients.php` and checked against the
  `X-DocArc-Api-Key` header.
  - **Response:** `{"matches":[{"guid","name","amka"}]}`, at most 10 matches, soft-deleted patients
    left out.
  - **Search:** `patientsClassEx::search($term, ['pname','pamka'], true, 10)`, with a minimum of
    2 characters.
- **What MedScribe needs from ZPMS:**
  - **Its own key:** add `config/medscribe_api.php.in` and an `X-MedScribe-Api-Key` header, rather
    than reusing DocArc's key.
  - **Probably a new endpoint:** fetching one patient by `guid` with the fields documents need. The
    available patient fields (`web/classes/yaml/patients.yaml`) are `pname`, `pdob`,
    `pgender` (M/F/NULL), `pamka`, `ptel`, `paddr`, `pemail`, `pnote` and `deleted`.
  - **Possibly** appointment or operation data from ZPMS as well.
- **How to add it:** register the route in ZPMS's `config/settings.info.yaml` with no `access:` key,
  and `require_once` the handler file from `web/index.php`. Add tests under `tests/functional/`.

### DocArc (GitHub `evrokas/docarc`): document store

DocArc is plain PHP 8.1+ with no framework and SQLite (`data/docarc.sqlite`), in `public/*.php` and
`includes/*.php`. It deliberately avoids frameworks and libraries. Its only checks are `php -l` and
`bin/check_requirements.sh`.

- **Records:** each archive record has a random token (`TYPE-YEAR-XXXXXX`, from `generate_token()`
  using the document type's code), a document type, dates, `patient_name` as plain text (no ZPMS
  guid), an optional clinic and `requesting_user`.
- **Files:**
  - **Main document:** one per record, content-sniffed against `DOCARC_ALLOWED_UPLOAD_TYPES` and
    stored at `data/documents/{token}.{ext}`.
  - **Supplementary files:** any number per record, stored in `data/supplementary/`. They are meant
    for things like LaTeX sources (`.tex`/`.bib`/`.cls`/figures), which suits MedScribe's
    generated PDF plus its `.tex` source.
  - **Access:** files are always streamed through PHP. The public `qr.php` (QR code on the paper,
    optional verification code) serves only the main document.
- **No inbound API exists.** Records and uploads are created only through staff pages behind a
  session and CSRF check (`public/index.php`, `upload.php`, `supplementary_upload.php`). Today
  DocArc only *calls* ZPMS. Settings → ZPMS Integration holds `zpms_enabled`, `zpms_base_url` and
  `zpms_api_key` in its `settings` table.
- **What storing MedScribe documents needs:** a new API-key-gated endpoint in DocArc, using the
  pattern above, that:
  - lists DocArc's document types (`id`, `code`, `label`). DocArc owns them: MedScribe links each of
    its types to exactly one DocArc type (many-to-one) and never creates them; each document gets
    one DocArc code for life
    (`PLAN.md` D20);
  - creates a record for a DocArc document type, rejecting unknown ones, and stores the PDF as its
    main document, with the `.tex` source as a supplementary file;
  - returns the token and retrieval link. If the QR code must be printed *inside* the PDF, the token
    has to exist before typesetting, so the flow is: request a code (DocArc creates the record with type,
    description, clinic, patient name, date and requesting user) → compile LaTeX with the code and
    QR → the doctor approves the final PDF → upload the PDF and the `.tex` to that record
    (`PLAN.md` §7.4). An abandoned draft's empty record is deleted;
  - **revised documents** follow DocArc's agreed versioning design (DocArc `CLAUDE.md`, "Document
    versioning", not implemented yet): a new version of the same record under the same code, with a
    required correction note, never DocArc's current destructive replace;
  - **withdrawn documents** (mistakes such as the wrong patient): DocArc deletes the whole record;
    MedScribe keeps the document marked *withdrawn* and deletes it only if the doctor chooses to;
  - reuses `includes/documents.php` and `includes/token.php` rather than duplicating them;
  - writes DocArc's GDPR `audit_log`.

## ZeusFw app layout to follow (reference: `../zpms`)

`../zpms` is the most complete ZeusFw app on this machine. Its `README.md` is a long dated changelog of
design decisions, and `tests/README.md` describes its test suite. When scaffolding MedScribe, copy its
structure rather than inventing a new one:

- **Framework link:** `fw -> /var/www/shtml/apps/zeusfw` and `web/core -> ../fw/core/`, both symlinks
  and both gitignored. `config/db.php` defines `__APPDIR__`, `__FWDIR__` (`web/core`) and the DB
  constants.
- **Config:** secrets and per-site files are committed only as `*.in` templates (`config/db.php.in`,
  `config/site.info.yaml.in`, …). The real files are gitignored and created by copying the template.
  `fw/bin/init.sh` fills them in interactively.
- **`config/site.info.yaml`** holds per-deployment settings: error display, template cache,
  `libpath`, `disabled_packages`.
- **`config/settings.info.yaml`** describes the app. It sets:
  - **`routes:`**: `url`, `handler` (a plain PHP function), `method`, an optional `access:` role, and a
    `title` map per language (`en:`/`gr:`), which is also used for the breadcrumb.
  - **Page layout:** `regions:` and `structure:` place modules into page regions. `modules:` lists the
    modules to load and the folders to load them from (`@core/modules/`, `@app/web/modules/`).
  - **Other keys:** `templates:` search paths (`@core/templates/`, then `@app/web/templates/`, so the
    app can override core templates), `css:`, `libraries:`, `menu:`, `roles:`, `languages:` and
    session policy.
  - **Path prefixes:** `@core/`, `@app/` and `@mod/`.
- **`web/index.php`** is the only entry point. `web/.htaccess` rewrites every request that isn't a
  real file to `index.php?<path>`. The file loads `config/db.php` and `__FWDIR__/bootstrap.php`, then
  `require_once`s the app's feature files. Next it builds `new Kernel($_SERVER, "../config")` and calls
  `$kernel->boot([...])` with opt-in flags (`csrf_login`, `csrf_webforms`, `login_lockout`,
  `login_redirect`, `default_language`). Route handlers are global functions after that, returning
  `Renderer::render("x.zetem", [...])`. An optional `zeusfw_app_boot()` function runs app-specific
  code during boot.
- **Templates:** `.zetem` files go under `web/templates/` (`content/`, `blocks/`, `email/`, …):
  - `{% ... %}` holds control flow and `attach_library()`.
  - `{{ }}` and `{{{ }}}` print values. Each `{{ }}` must fit on one line, and a `|` inside it is the
    filter pipe.
  - `{# #}` is a comment.
  - Visible text goes through `t('English term')`. Translations live in a dictionary file loaded into
    the DB (zpms uses `config/dictionary.gr.php` and `bin/import_dictionary.php`).
- **Entities:** only `web/classes/yaml/<table>.yaml` is committed. It declares the table name, the
  class name and the fields, with `@guid`, `@cdate` and `@cuser` as built-in field types. The generated
  `web/classes/*.php` and `web/classes/sql/*` files are gitignored. To add custom behaviour, extend the
  generated class (`<name>ClassEx`) in a separate file.
- **Schema updates: `fw/bin/update.sh`, run from the app root.** Don't write a separate migration tool.
  The script:
  - regenerates classes and SQL from the YAML (`spill:class:all`, `spill:sql:all`);
  - imports tables that are missing;
  - diffs the YAML against the live DB (`diff:sql:all`) and applies the difference;
  - loads feeders and webforms;
  - finally runs the app's own executable **`bin/post-update.sh`**, if present.

  It applies SQL through the app's `sql/msql.sh`. Every question has an option, e.g. `--dry-run`,
  `--non-interactive`, `--web-diff=yes` (see `--help`). **Keep `bin/post-update.sh` to a minimum:**
  express changes through the entity YAML, feeders (`web/content/*.feeder.yaml`), one-off scripts in
  `bin/` run by hand, or code that tolerates old rows. Use `post-update.sh` (idempotent steps only)
  only when none of these can do it, and tell the user each time before adding a step. The individual
  maker commands, run from `web/classes`, are still useful during development:
  ```sh
  php ../core/maker/maker.php spill:class:all
  php ../core/maker/maker.php update:bootstrap
  php ../core/maker/maker.php spill:sql:all
  php ../core/maker/maker.php form:load yaml/<entity>.yaml   # DB-defined webforms
  ```
- **Modules:** each module lives in `web/modules/<name>/` as `<name>.info.yaml` plus `<name>.php`.
- **Runtime folders:** `var/` (sessions, logs), `logs/`, `web/cache`, `web/files` and `backup/` are
  gitignored.
- **CLI and tests:** maintenance scripts go in `bin/`. The test runner is `bin/run_tests.sh`
  (`--static-only` / `--functional-only`), which calls `php tests/run_all.php`:
  - **Static checks:** `php -l`, `node --check`, compiling every `.zetem` file, and dictionary terms.
  - **Functional tests:** real HTTP requests through `php -S` against a throwaway DB whose name must
    contain "test" (`config/db.test.php`).
  - **Test code:** helpers are in `tests/lib/`, and the runner picks up every file under
    `tests/{static,functional}/`.

## Pre-M0 shell (2026-11-06)

A ZeusFw app scaffold copied from ZPMS: `fw`/`web/core` symlinks, `config/*.in` templates, `web/index.php`,
`web/pages.php` (route handlers, all placeholders), `web/ClassesEx.php` (`usersClassEx`), `.zetem` templates,
`css/styles.css`. Roles `administrator` (superuser), `doctor`, `secretary` come from `bin/seed_users.php`.

- **Set up a checkout:** `sql/admin.sql` + `config/db.php` from the `.in` files (database and user `medscribe`);
  create them with the MySQL root account (`sudo mysql < sql/admin.sql`); then
  `fw/bin/update.sh --core-tables=all --web-tables=all --core-diff=yes --web-diff=yes --content=no --forms=no --generate-feeders=no --non-interactive`,
  then `php bin/seed_users.php --user=NAME --role=doctor` (asks for the password).
- **Try it without Apache:** `php -S 127.0.0.1:8099 -t web router.php` (`router.php` is dev-only and emulates
  `.htaccess`). Production needs its own vhost with `DocumentRoot .../medscribe/web`, like `pms.erns.eu`
  (see `deploy/apache-vhost.conf.sample`).
- Strings are English only for now; there is no `config/dictionary.gr.php` yet (the Greek interface is postponed, 2026-11-06).

## M0 foundations (2026-11-06)

- **Store** (`web/store.php`): sources with linear immutable versions, bytes in `web/files/store/objects/`
  keyed by SHA-256 (verified on read), base releases B1, B2, … Tables: `sources`, `source_versions`,
  `base_releases`, `base_release_files`, `builds` (entities in `web/classes/yaml/`).
- **Compile** (`web/compile.php` + `bin/tex-sandbox.sh`): job folder filled from hashes, XeLaTeX in a
  namespace sandbox (user/mount/net/pid, chroot with `/usr` read-only), 2–4 passes, fixed
  `SOURCE_DATE_EPOCH`, manifest recorded in `builds`, `compile_from_manifest()` for rebuilds. Needs
  unprivileged user namespaces (works for `www-data` on this server; tested with `setpriv`).
- **Import:** `php bin/import_kit.php` loads `~/devel/docs/template-kit` (base + assets → a new base release
  if changed; `templates/*.tex` → draft type versions). Idempotent.
- **Tests:** `bin/run_tests.sh` (`--static-only`, `--tex-only`) runs lint/YAML/route checks and
  `bin/check_m0.php`, the M0 acceptance check: store build is byte-identical to `build.sh`, manifest
  rebuild, old-build rebuild, and the `\input` isolation test (plain-text secret, not PHP).
- **Kit change (committed in `~/devel/docs` as 8a6e291, not pushed):** `\DocDraft` and `\DocRevision` added to
  `evrtemplate.tex` (Q6); with both empty the PDF is unchanged.
- CLI scripts boot through `bin/lib/cli_boot.php`, which must call `dbConnection::init()` itself.

## M1 engine and first type (2026-11-06)

- **Type definitions** are JSON in `types/*.json`, imported as released `typedef/<name>` sources by
  `php bin/import_types.php --release` (validated first). The format, the block markup (`{Ο|Η}` gender,
  `@field`, `{v:L4-5}`, `**bold**`) and the document inputs are described in the header comment of
  `web/render.php`. Everything else a user types is escaped, so text cannot break the build.
- **`web/render.php`**: pure functions (no DB): `render_document()` → `[tex, issues]`,
  `render_checks()`, `render_validate_type()`. Unit tests: `tests/static/render_test.php`.
- **`web/documents.php`**: list, new (patient typed by hand), composer (form + PDF preview in an
  iframe), draft delete. Table `documents` (inputs as JSON). Preview = compile with `\DocDraft` and a
  placeholder QR (takes ~12 s: the pure-TeX QR codes dominate).
- **G3 regression:** `bin/check_m1.php` compiles the generated document and the hand-filled kit
  template (`tests/fixtures/mri-handfilled-{M,F}.tex`) and requires byte-identical PDFs.
- Wording of `types/vevaiosi-mri.json` is copied verbatim from the kit; only the doctor edits it.
- `store_mkdir()` sets 02770 on every folder it creates: the CLI user and Apache's user both write
  under `web/files`.

## M2 ZPMS link (2026-11-06)

- **MedScribe side:** `web/zpms_client.php` (fail-closed cURL client: 3 s timeouts, field-by-field
  validation through `web/patient.php`, off when `config/zpms_api.php` is absent), the patient search box
  and `?patient=<guid>` prefill on `/documents/new`, `/documents/patient-search` (session-gated proxy:
  the key never reaches the browser). A document keeps a **snapshot** (`inputs.patient`: name, gender,
  age at the document date, AMKA, plus `guid` and `dob`); it is editable on the document and never
  written back. Without ZPMS the patient is typed by hand (D23).
- **Tests:** `tests/static/patient_test.php` (age at a date, row cleaning) and `bin/check_m2.php`
  (the client against `tests/lib/mock_zpms.php`: 401/404/503, malformed JSON, 3 s timeout, wrong types).
- **ZPMS side (separate repo, NOT live yet):** branch `medscribe-api` in the git worktree
  `~/devel/zpms-medscribe` (based on the live checkout's commit 1fab362): `config/medscribe_api.php.in`,
  `GET /api/v1/patients/{guid}` (MedScribe key only; name, dob, gender, AMKA; 404 for deleted), the
  search endpoint also accepting `X-MedScribe-Api-Key`, a "New document" button on the patient page,
  audit rows (`patient_get_api`), and `tests/functional/medscribe_api.php` (7/7 pass). To go live:
  merge that branch into ZPMS, create `config/medscribe_api.php` there (key and `MEDSCRIBE_BASE_URL`),
  put the same key into MedScribe's `config/zpms_api.php`. Four unrelated ZPMS tests fail in the
  worktree (mail template path and `sudo`; environment, not this change).

## M3 DocArc and the document lifecycle (2026-11-06)

- **DocArc side (separate repo, NOT live yet):** a clone at `~/devel/docarc`, branch `medscribe-api` (cloned from
  `/media/repos/docarc.git`; DocArc is not deployed on this machine): `public/api.php` +
  `includes/api_medscribe.php`, key in `config/medscribe_api.php`, `records.api_idempotency_key`, tests
  `php tests/api_medscribe.php`. Its `CLAUDE.md` has a "MedScribe API" section. To go live: merge, create the
  key file there, put the same key in MedScribe's `config/docarc_api.php`, and make sure DocArc's QR base URL
  setting equals the kit's `\QRBaseURL` (MedScribe refuses a code whose URL differs from what would be printed).
- **MedScribe:** `web/docarc_client.php` (never throws; 3 s lookups, `DOCARC_UPLOAD_TIMEOUT` for uploads),
  `web/issue.php` (the state machine: draft/review → code_reserved → final_compiled → approved → pdf_sent →
  issued → withdrawn; every transition is a compare-and-swap on `status`, steps take `busy_until` first, a
  failed step resumes with the same call, DocArc calls are idempotent). **Every TeX run on a document (preview,
  final PDF, rebuild) holds the `busy_until` lock, and the composer's save takes it too**: a save during a build is
  refused and the typed form is handed back once (`$_SESSION['doc_unsaved']`). The lock lasts
  `issue_busy_seconds()`, longer than the slowest possible build; the two-up print takes it too (409 while busy), `web/issue_pages.php` (issue page,
  history, revision files, `/types/links` for D20), `web/audit.php` (basic audit: who/when/what/document/revision,
  never patient data or reasons). Only the doctor issues, withdraws, deletes and rebuilds (D21).
- **Verification code (D22):** the AMKA when valid, else a 6-digit code made **once** and kept in `documents.verify_code`
  *before* DocArc is called, so a retry (DocArc answering the existing record by idempotency key) never shows a code DocArc
  does not have. The send's PATCH carries `verification` too, so a corrected AMKA reaches DocArc.
- **What MedScribe may delete in DocArc:** only records it created through the API (DocArc answers 404 for every other
  record, staff ones included), and `mode=empty` only when nothing was ever uploaded (no main document, no supplementary
  file; else 409). Deleting a draft (doctor or secretary) only ever asks for `mode=empty`; withdraw (`mode=all`) is the doctor's.
- **Abandoned drafts:** the empty DocArc record is deleted; if DocArc is down the job is queued in
  `docarc_cleanup` (`issue_cleanup_run()`, to be run by `bin/retry_cleanups.php`), and a job never deletes a
  record that has a document.
- **Previews:** only the latest preview build of a document is kept (`builds.document_id`); deleting a document
  removes its builds and the store objects nothing else references (`issue_gc_object()`).
- **Not built:** Revise (waits for DocArc's versioning, PLAN.md Q15); the secretary's "ready for review" shows on
  the list only as a status badge, not yet on the doctor's Home.
- **Tests:** `bin/check_m3.php` runs the whole lifecycle against a real DocArc started with `php -S` on throw-away
  folders (`DOCARC_DIR`, default `~/devel/docarc`; skipped if absent), including G5 (rebuild after a new base
  release gives the same PDF hash). It leaves one extra base release (B-next with a `% m3-test` comment) in the dev DB.

## M4 types, type editor and content pool (2026-11-06)

- **Types:** `vevaiosi-mri`, `vevaiosi-ylikon`, `adeia`, `vevaiosi-amoivis`, `gnomateysi-exetaseon` in
  `types/*.json`, wording copied verbatim from the kit. The seven "purpose" sentences are a **shared component**
  (`types/shared/purpose.json`, source `shared/purpose`); a type lists `"shared": {"purpose": "latest"}` and
  `bin/import_types.php` pins "latest" to the component's SHA-256, so a type version always means the same text.
  New engine features, all in `web/render.php`'s header comment: `inline` blocks (continue the previous paragraph),
  `heading_level`, list `intro`/`list_options`, one item per line for list free text, field `placeholder`,
  `titles`/`closings`, `\ ` (control space), a single newline is a space.
- **G3 regression for all of them:** `tests/fixtures/cases.php` + the hand-filled kit templates in
  `tests/fixtures/*.tex`; `bin/check_m1.php` requires byte-identical PDFs (male and female, defaults and variants).
- **Type editor** (`web/types.php`, `/types/{typedef|shared}/{name}`, doctor): JSON editor that validates before
  saving, change note required, new versions are drafts until released, diff between versions (`web/diff.php`).
  A document uses the latest *released* version at creation and keeps it.
- **Tree view of the type editor** (`web/js/type_tree.js`, plain JS; Tree/JSON tabs, the JSON text box stays the form field):
  every key is a row to edit, rename, reorder (drag or arrows), duplicate (new ids) or delete; key order is kept by its own JSON
  parser. Renames carry: a field → every `@field` (whole, unescaped tokens) and feature `field`; a block/section id → feature
  operations; a choice group → all its blocks; a shared key → `use`/`library`. A section's name on the composer form is
  `label`, else `heading`, else `slot`. Test: `tests/js/type_tree_test.js` (all type files round-trip unchanged), run by the
  static suite.
- **Preview ⇄ source:** `render_document(..., ['trace' => true])` (preview only, never a PDF) wraps fields, blocks, extra text,
  section and topic headings in `\MSsrc{kind:id}{...}` (`render_trace()`; raw LaTeX with lists/environments stays unwrapped);
  `render_html.php` turns them into `<span data-src>`. Hovering printed text shows a ⌖ that selects its source in the tree (or
  the JSON text: the tree's parser records each node's offsets); the ⌖ of a tree row highlights its text in the preview.
- **Date format:** a date field may carry `datefmt` (how it prints everywhere, also in blocks with their own `datefmt`); a
  block's `datefmt` serves the dates without one (the kit imports set `d-m-Y` on most blocks); else `d/m/Y`. The type editor's
  previews put a real date (default or today) in date fields so the format shows. Formats are PHP `date()` patterns or `long` (20 Φεβρουαρίου 2026); `datefmt` is valid only on date fields. The
  tree offers a list of formats and a "+ date format" button on date fields.
- **Draft versions can be saved in place** (`source_update_draft()`, action `save_here`): only the latest version, while still a
  draft and used by no document; a released version never changes (a new version is the only way), and the old bytes stay in
  the store.
- **Folding sections in the input form** (`web/js/sections.js`, composer and Form preview): a click on a section's legend folds
  it; "Expand all"/"Collapse all". A section starts folded when it has items (blocks or a medication table) and none is picked,
  no free text and no rows, or when it is an optional section switched off (`collapsed` in `doc_view_model()`); a
  free-text-only section starts open.
- **Preview panel of the type editor** (typedef only; `web/js/type_preview.js`, `POST /types/typedef/{name}/preview`): the
  unsaved text, refreshed after each edit, as **Form** (the composer's `composer_type_part.zetem`, the same template the composer
  uses, inside a disabled fieldset) or **Print** (`web/render_html.php`: runs `render_document()` and turns the body LaTeX into
  HTML, so it cannot drift from the engine; labels and doctor details come from the base release's `evr-<lang>.tex`; no
  letterhead, QR or page layout: the PDF stays the reference). Sample inputs: `types_sample_inputs()` (field default, else
  placeholder, else label; Male/Female patient). The splitter of both pages is `web/js/splitter.js` (`.splitpane`).
- **Letterhead version per document (D10):** a select in the composer (any released base release).
- **Content pool** (`web/pool.php`, D8/D16): at issue, text the doctor wrote or edited (local edits and free text,
  not unchanged type blocks) is stored per slot with the patient's name, AMKA, field values and dates turned back
  into `@tokens` (swap on insertion); withdrawing removes it. The composer offers suggestions per section (same
  slot first, then same family) and a token palette; `/library` lists the pool (promote, demote, hide).
  Only exact copies of the values are tokenized; a surname alone or another grammatical form stays as written. That is
  accepted for issued documents because the doctor reviews every letter, suggestions included, before issuing (decided
  2026-11-06). The archive seeder, whose letters nobody reviews one by one, still drops any text where part of the name
  is found (`pool_name_left()`: accent/case-free stem match, errs on the safe side).
  **Not built from M4:** merge in the library, ranking by case features (M6), a composer warning when the document's own
  text names a *different* patient (§7.3).
- **Bug found by `bin/check_web.php`:** a new document used to start with every standalone block switched off;
  `check_web.php` (real HTTP, temporary doctor + secretary accounts) now guards that, role access and CSRF.

## M5 prescriptions (2026-11-06)

- `medtable` layout: rows in `inputs.meds[section]`, chosen from the shared **drug library**
  (`types/shared/drug-library.json`, the kit's 32 entries; a section says `"library": "drug-library"`), editable in
  the composer; cells use the block markup, `{br}` = line break inside a cell. Types `syntagi` (A4) and
  `syntagi-a5`; `page_numbers` is a type default and a document setting.
- **Two A5 per A4 sheet** (`web/print.php`, `/documents/{id}/print.pdf?mode=seq|dup`): imposition with pdfpages from the
  document's latest PDF, same sandbox; a printing aid, not archived.
- **Not built:** medication rows proposed by case modules (M6) and the "confirm each proposed row" rule.
- **M5 verified (2026-11-06):** `bin/check_m1.php` passes for `syntagi` (A4) and `syntagi-a5` (byte-identical to the
  hand-filled kit templates; fixture row order = library order) and for two-up printing (a 64-row prescription →
  8 A5 pages → 4 sheets in order, 8 with each page twice, landscape A4).

## M6–M8 (2026-11-06, partly)

- **Importer** `tools/kit_import.py <name>...` (Python, one-off, run from the app root): proposes `types/<name>.json`
  from a kit template (`tpl-<name>.tex`) and writes its regression fixture (`tests/fixtures/auto/<name>.tex`, the kit file
  with every `[placeholder]` filled) plus `tests/fixtures/auto/cases.json`. Uncommented lines become blocks that are on,
  commented ones blocks that are off; `[text]` becomes a field (dates by shape); gender is made agreeing; the seven purpose
  sentences become the shared component; anything it cannot express as markup becomes a trusted `format: latex` block
  and is listed in the type's `needs_review`. Imported: `proegkrisi`, `enimerotiko`, `gnomateysi-farmakon`,
  `iatrodikastiki`, `synainesi`, `praktiko`, `epistoli` (it also stands for the kit's blank `letter-gr`/`letter-en`; no
  separate types, decided 2026-11-06), `recommendation-en` (English), and the postop types
  `odigies-osfyiki|kranio|kyfoplastiki`.
- **Type review (2026-11-06, in a Claude session):** all of them are reviewed (`"reviewed": "<date>"` in the type). The kit's
  `gnomateysi-farmakon` was split into `gnomateysi-baclofen`, `gnomateysi-fyk` and `gnomateysi-farmakon` (chronic medication);
  the two new types need a DocArc link on `/types/links` before issuing. A reviewed type is no longer compared with its kit
  file in `bin/check_m1.php` (it must build with no issues instead): the type is the reference now. The postop types were
  done with structure-only defaults (the doctor's instruction); their emergency boxes are off until the doctor enables them.
  Workflow: edit `types/<name>.json`, then `php bin/import_types.php --release --note="..." <name>` (one new version, the
  doctor's note). **Edits made in the web type editor** go to the store only: bring them back with
  `php bin/export_types.php [--check] [--latest] [--force] [name…]` (latest released version → `types/*.json` in the repo
  style, shared pins back to `"latest"`; a file holding edits the store does not have is held unless `--force`), then review
  `git diff types/` and commit. `bin/check_types_sync.php` (in the test runner) tests the export and warns when files and
  store differ; the mapping is in `bin/lib/types_sync.php`.
- **Field renames in bulk:** `php bin/rename_fields.php <renames.json> [--dry-run] [type…]` (the tree editor's rename rules: whole
  unescaped `@tokens`, feature operations; two old ids with one new id are merged; every type is checked before anything is
  written). The generic `f1`, `f2`, … ids of the imported types were renamed with `tools/field_renames.json` (2026-11-07; the
  kranio discharge dates merged), released as one new version per type.
- **Engine additions from the review:** `has_patient: "optional"` (name not required), a section `placeholder` (grey hint in
  its free-text box; a section with no blocks is a free-text box) and `label` (composer name of a heading-less section),
  `intro` on `medtable`, `datefmt: "long"` (20 Φεβρουαρίου 2026), `item: true` blocks in a paragraph topic (a bullet list under
  the paragraph), and urgentbox blocks with a `label` (a heading line, no bullet). `proegkrisi` ships the first case feature (chip
  "Επανάληψη RF γασσερίου"; kit wording).
- **Engine additions:** `topics` layout (enumerated topics with bullets, one slot per topic), `medtable` `default_rows` and
  `title_page`, list `bullet`/`list_options`/`list_preamble`, numbered headings, `recipient` setting,
  `has_patient: false` (practice documents), raw-LaTeX blocks read-only in the composer (changed in the type editor),
  **case features** (`features` in a type; chips whose operations
  enable/disable/set/add and are undone exactly on removal; unit-tested; composer chips; `proegkrisi`'s RF chip and `odigies-auxeniki`'s Διαρροή ΕΝΥ chip, both in kit wording:
  modules hold clinical wording, which is the doctor's to write).
- **Regression of imported types** (`bin/check_m1.php`, "auto"): PDF text equal to the filled kit template (blank lines
  ignored; a few differ in sub-pixel spacing only). `php bin/check_auto.php <name>` shows the differences for one type.
- **M8 types** (`ikanotita-work|driving|travel`, `preop-odigies`, `gdpr-enimerosi`, `timokatalogos`, `fylladio`) are
  **drafts** (`"draft": true` is kept out of release by `import_types.php`): skeletons whose wording the doctor writes;
  the three practice documents have no patient. Release them from the type editor after review.
- **Archive seeding (D15):** `bin/seed_archive.php <folder> [--dry-run]` (unit-tested on synthetic letters only). Texts go
  to `pool_archive_slot(<heading>)` and are suggested (third tier, after the slot and its family) in sections with the same
  heading; each letter is one pseudo-document (negative id from its content), so a second run changes nothing;
  `--dry-run` prints every text it would store. It has
  **not** been run on the real archive: those letters hold real patients' details, so the doctor runs it.
- **Not done:** modules for the postop types, the rest of the §12.3 operations (SWAP, PROPOSE MEDROW with confirmation),
  M9 (scales, deferred by the plan).
- **`odigies-auxeniki` and `odigies-tridymo`** (fixed 2026-11-06): before release they were rendered with the kit fixture's
  values and compared with the filled kit file (equal text), then reviewed like the others. `auxeniki` carries the
  **"Διαρροή ΕΝΥ" chip** (M6's acceptance case): it switches on the kit's three DURAL TEAR parts (critical instructions,
  headache/drain-site topics, CSF-leak signs in the emergency box) and the alternative intro line.

## Type editor UI redesign (in progress, 2026-11-07)

The plan is `docs/plans/type-editor-ui.md` (its §7 "Progress" says what is built) (decisions, invariants, tab-by-tab spec, backend additions,
phases, acceptance checklist), mockup sources in `docs/plans/type-editor-ui/mockups/`. Decided by the doctor: keep the
app's top bar; fields show as chips with the stored `@token` on hover; the toolbar offers only what the block markup
supports. Read the plan before touching `web/types.php`, `type_edit.zetem` or `web/js/type_*.js`.

- **QR frame in previews (phase 0b):** a preview's .tex sets the qrcode package's draft mode (`RENDER_QR_PLACEHOLDER`), so
  the QR is an empty frame of the same size; a final copy keeps the real QR (unchanged bytes). The issue flow refuses a
  .tex carrying the line. Measured: it saves ~1 s of a ~12–13 s preview; the kit's preamble (fonts) is ~5.3 s of every
  pass, so the next lever is fewer passes (reusing the previous `.aux`), not decided.
- **The new editor page (phases 1–4):** `type_edit.zetem` + `web/css/type-editor.css` (scoped `.te`) with tabs Structure ·
  Content · Fields · JSON (shared components: JSON only, for now); the old page stays at `?ui=classic` until phase 10.
  Scripts: `type_tree.js` (parser, token rule, renames: `window.MSTypeTree`; the JSON tab's tree), `type_chips.js` (block
  text with fields as chips; a text that does not survive text → DOM → text byte for byte opens as plain text),
  `type_editor.js` (shell, Save dialog, unsaved marker, Structure tab, the naming rule `sectionName`),
  `type_content.js`, `type_fields.js` (rename dialog with a dry run on a copy). Every view parses the form's textarea
  when shown and writes it back (`commit()`): the textarea stays the one source of truth. Tests:
  `tests/js/type_chips_test.js`, `bin/check_chips_dom.js` (needs a Chromium; the Playwright headless shell in
  `~/.cache/ms-playwright` is found by itself, or `CHROME=`).
- **Review & release (phase 9):** `/types/{kind}/{name}/review?v=N` shows what changes (`web/type_diff.php`, matched by id,
  grouped by section, word diff, a field renamed = one item), the checks and the impact; releasing needs its checkbox
  (`confirm=1`, enforced by `page_type_edit_post`). There is no one-click release any more.
- **Preview panel (phase 5):** checks carry their source (`render_issue_at()`, `src` in `render_checks()`) for "Go to
  source"; lints in `types_lint()` (`web/type_diff.php`); a **PDF** tab: `POST /types/typedef/{name}/pdf` compiles the
  unsaved text with the sample values (purpose `type-preview`, one build kept per user, a per-user lock, 409 while one
  runs), `GET …/pdf?b=ID` serves it to its user.
- **Case rules tab (phase 6):** `web/js/type_rules.js`; the preview takes `features[]` and returns `marks` (del/ins/set
  sources) so the panel strikes or greens what a rule changes.
- **Shared components (phase 7):** tabs Content · Used by · JSON (`web/js/type_shared.js`, `types_used_by()`); "Update pin"
  (`action=update_pin` on the type) makes a new draft type version pinned to the component's latest release.
- **Medication tables (phase 8):** `web/js/type_meds.js` edits a `medtable` section's library and `default_rows` (copied
  from the pinned library, passed to the page as `data-libraries`).
