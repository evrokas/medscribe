# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

MedScribe is at the very start: the repo contains only `README.md` and `LICENSE` (Apache-2.0). There is
no code, build, lint or test setup yet. Update this file as soon as real structure and commands exist;
don't invent conventions that aren't in the code.

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
  `guid`, which MedScribe should store and reuse.

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
  - creates a record (picking the document type by code) and stores the PDF as its main document,
    plus optional supplementary sources;
  - returns the token and retrieval link. If the QR code must be printed *inside* the PDF, the token
    has to exist before typesetting, so the flow is: create the record → compile LaTeX with the token
    and QR → upload the PDF to that record;
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
  `web/classes/*.php` and `web/classes/sql/*` files are gitignored. After a schema change, run these
  from `web/classes` (there is no migration runner, so apply the SQL by hand):
  ```sh
  php ../core/maker/maker.php spill:class:all
  php ../core/maker/maker.php update:bootstrap
  php ../core/maker/maker.php spill:sql:all
  php ../core/maker/maker.php form:load yaml/<entity>.yaml   # DB-defined webforms
  ```
  To add custom behaviour, extend the generated class (`<name>ClassEx`) in a separate file.
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
