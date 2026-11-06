<?php

/**
 * Compile service (PLAN.md §7.2): XeLaTeX in an isolated job folder filled
 * from a manifest, never from a working copy. Mirrors
 * ~/devel/docs/scripts/build.sh (2-4 passes until no "Rerun") and adds what a
 * server needs: openin_any/openout_any=p, no shell escape, a time limit, a size
 * limit, a fixed SOURCE_DATE_EPOCH, and a manifest of everything used.
 */

if (is_file(__APPDIR__ . '/config/tex.php')) {
    include_once(__APPDIR__ . '/config/tex.php');
}
defined('MEDSCRIBE_XELATEX') || define('MEDSCRIBE_XELATEX', '/usr/local/texlive/2026/bin/x86_64-linux/xelatex');
defined('MEDSCRIBE_TEX_TIMEOUT') || define('MEDSCRIBE_TEX_TIMEOUT', 120);
defined('MEDSCRIBE_TEX_MAX_PASSES') || define('MEDSCRIBE_TEX_MAX_PASSES', 4);
defined('MEDSCRIBE_JOB_MAX_BYTES') || define('MEDSCRIBE_JOB_MAX_BYTES', 64 * 1024 * 1024);

function compile_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function compile_dir_size(string $dir): int {
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $n += $f->getSize();
    }
    return $n;
}

/** A kit path must stay inside the job: relative, no "." or ".." parts, no NUL (dot-files such as .latexmkrc are allowed). */
function compile_safe_path(string $p): bool {
    return $p !== '' && $p[0] !== '/' && !preg_match('#(^|/)\.\.?(/|$)#', $p) && !str_contains($p, "\0");
}

/** Writable cache/HOME for fontconfig and TeX (outside any job). */
function compile_home(): string {
    $h = __APPDIR__ . '/web/files/cache/home';
    store_mkdir($h);
    return $h;
}

/** Build environment recorded in every manifest. */
function compile_environment(): array {
    $ver = '';
    $o = [];
    exec(escapeshellarg(MEDSCRIBE_XELATEX) . ' --version 2>&1', $o);
    $ver = $o[0] ?? 'unknown';
    $fonts = [];
    $fl = [];
    exec('fc-list "Roboto Slab" file 2>/dev/null', $fl);
    foreach ($fl as $line) {
        $f = rtrim(trim(explode(':', $line)[0]), ':');
        if (is_file($f)) {
            $fonts[basename($f)] = hash_file('sha256', $f);
        }
    }
    ksort($fonts);
    return ['xelatex' => $ver, 'medscribe' => MEDSCRIBE_VERSION, 'fonts' => $fonts];
}

/**
 * Compile a job.
 *   $files: list of ['kitpath'=>..., 'sha256'=>..., 'name'=>?, 'version'=>?]  (read from the store, hash-checked)
 *   $extra: kitpath => bytes for files that are not store sources (a generated document .tex)
 *   $main:  kitpath of the file to compile
 * Returns ['ok','pdf'(bytes|null),'pdf_sha256','log_tail','manifest'(array),'passes'].
 */
function compile_job(array $files, array $extra, string $main, int $epoch, string $purpose = 'test', string $user = 'system', ?int $documentId = null): array {
    $result = ['ok' => false, 'pdf' => null, 'pdf_sha256' => null, 'log_tail' => '', 'passes' => 0];
    $job = __APPDIR__ . '/web/files/jobs/' . bin2hex(random_bytes(8));
    store_mkdir($job);
    $manifest = ['files' => [], 'main' => $main, 'source_date_epoch' => $epoch, 'env' => compile_environment()];
    try {
        foreach ($files as $f) {
            if (!compile_safe_path($f['kitpath'])) throw new RuntimeException('unsafe path ' . $f['kitpath']);
            $bytes = store_get($f['sha256']);               // checks the hash
            $dest = "$job/" . $f['kitpath'];
            store_mkdir(dirname($dest));
            file_put_contents($dest, $bytes);
            $manifest['files'][] = ['name' => $f['name'] ?? $f['kitpath'], 'kitpath' => $f['kitpath'], 'version' => $f['version'] ?? null, 'sha256' => $f['sha256']];
        }
        foreach ($extra as $kitpath => $bytes) {
            if (!compile_safe_path($kitpath)) throw new RuntimeException("unsafe path $kitpath");
            store_put($bytes);                              // so a rebuild can fetch it by hash
            $dest = "$job/$kitpath";
            store_mkdir(dirname($dest));
            file_put_contents($dest, $bytes);
            $manifest['files'][] = ['name' => $kitpath, 'kitpath' => $kitpath, 'version' => null, 'sha256' => hash('sha256', $bytes), 'generated' => true];
        }
        if (!is_file("$job/$main")) throw new RuntimeException("main file $main is not in the job");
        $manifest['tex_sha256'] = hash_file('sha256', "$job/$main");

        $jobname = pathinfo($main, PATHINFO_FILENAME);
        // XeLaTeX runs inside bin/tex-sandbox.sh: a private root with only /usr, fonts and this
        // job folder, no network. TeX Live's openin_any=p does not restrict \input here (checked
        // 2026-11-06), so the sandbox is what keeps server files out of a document.
        $texenv = [
            'PATH=' . dirname(MEDSCRIBE_XELATEX) . ':/usr/bin',
            'HOME=/home/tex',
            'SOURCE_DATE_EPOCH=' . $epoch,
            'FORCE_SOURCE_DATE=1',
            'openin_any=p',
            'openout_any=p',
            'TEXMFOUTPUT=/job',
        ];
        $log = '';
        $converged = false;
        for ($pass = 1; $pass <= MEDSCRIBE_TEX_MAX_PASSES; $pass++) {
            $result['passes'] = $pass;
            $cmd = array_merge([__APPDIR__ . '/bin/tex-sandbox.sh', $job, compile_home()], $texenv,
                ['--', MEDSCRIBE_XELATEX, '-interaction=nonstopmode', '-halt-on-error', '-no-shell-escape', "-jobname=$jobname", $main]);
            [$code, $out] = compile_run($cmd, $job, ['PATH' => '/usr/bin:/bin', 'TEX_TIMEOUT' => (string)MEDSCRIBE_TEX_TIMEOUT], MEDSCRIBE_TEX_TIMEOUT + 10);
            $log = is_file("$job/$jobname.log") ? file_get_contents("$job/$jobname.log") : $out;
            if ($code !== 0) {
                $result['log_tail'] = implode("\n", array_slice(explode("\n", $log), -40));
                throw new RuntimeException($code === 124 ? 'XeLaTeX timed out' : 'XeLaTeX failed');
            }
            if (compile_dir_size($job) > MEDSCRIBE_JOB_MAX_BYTES) throw new RuntimeException('job folder too large');
            if ($pass >= 2 && !preg_match('/Rerun to get|Label\(s\) may have changed/', $log)) { $converged = true; break; }
        }
        $result['log_tail'] = implode("\n", array_slice(explode("\n", $log), -15));
        $pdfPath = "$job/$jobname.pdf";
        if (!is_file($pdfPath)) throw new RuntimeException('no PDF was produced');
        $result['pdf'] = file_get_contents($pdfPath);
        $result['pdf_sha256'] = hash('sha256', $result['pdf']);
        $manifest['pdf_sha256'] = $result['pdf_sha256'];
        $manifest['converged'] = $converged;
        $result['ok'] = true;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    } finally {
        compile_rrmdir($job);
    }
    $result['manifest'] = $manifest;
    compile_record($result, $purpose, $user, $documentId);
    return $result;
}

/** Run a command with a deadline; returns [exit code (124 = timeout), combined output]. */
function compile_run(array $cmd, string $cwd, array $env, int $timeout): array {
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($p)) return [127, 'cannot start process'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = time() + $timeout;
    $code = null;
    while (true) {
        $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) { $code = $st['exitcode']; break; }
        if (time() > $deadline) { proc_terminate($p, 9); $code = 124; break; }
        usleep(50000);
    }
    $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($p);
    return [$code === 124 ? 124 : ($code ?? $exit), $out];
}

function compile_record(array &$result, string $purpose, string $user, ?int $documentId = null): void {
    $m = $result['manifest'];
    store_db()->prepare('INSERT INTO builds (guid, cuser, purpose, status, tex_sha256, pdf_sha256, source_date_epoch, manifest, log_tail, document_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([store_uuid(), $user, $purpose, $result['ok'] ? 'ok' : 'failed', $m['tex_sha256'] ?? null, $result['pdf_sha256'],
                   $m['source_date_epoch'], json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $result['log_tail'], $documentId]);
    $result['build_id'] = (int)store_db()->lastInsertId();
}

/** Rebuild from a stored manifest (PLAN.md §5.4): every file is fetched by hash. */
function compile_from_manifest(array $manifest, string $purpose = 'test', string $user = 'system', ?int $documentId = null): array {
    $files = []; $extra = [];
    foreach ($manifest['files'] as $f) {
        if (!empty($f['generated'])) {
            $extra[$f['kitpath']] = store_get($f['sha256']);   // generated .tex must be in the store too
        } else {
            $files[] = $f;
        }
    }
    return compile_job($files, $extra, $manifest['main'], (int)$manifest['source_date_epoch'], $purpose, $user, $documentId);
}
