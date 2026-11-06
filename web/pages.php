<?php

/**
 * Route handlers for the pre-M0 shell: home, placeholder pages for the
 * areas the plan builds later (PLAN.md §14), profile and system status.
 * Real features replace these placeholders milestone by milestone.
 */

function medscribe_user_roles(): array {
    global $kernel;
    $roles = method_exists($kernel, 'getUserRoles') ? $kernel->getUserRoles() : null;
    return is_array($roles) ? $roles : array_filter(preg_split('/[\s,]+/', (string)$roles));
}

function medscribe_placeholder(string $title, string $note, string $milestone): string {
    return Renderer::render("placeholder.zetem", [
        'heading'   => $title,
        'note'      => $note,
        'milestone' => $milestone,
    ]);
}

function homepage($params) {
    global $kernel;

    if (!SecurityClass::userLoggedIn()) {
        header('location: ' . rel_url('/login'));
        exit();
    }

    $uname = $kernel->getUserName();
    $account = UsersClassEx::getUserAccount($uname);

    $cards = [
        ['url' => '/documents', 'icon' => 'bx-file',     'title' => 'Documents',      'desc' => 'Drafts, ready for review and issued documents', 'roles' => ['doctor', 'secretary', 'administrator']],
        ['url' => '/types',     'icon' => 'bx-layer',    'title' => 'Document types', 'desc' => 'Types, sections and template versions',        'roles' => ['doctor', 'administrator']],
        ['url' => '/library',   'icon' => 'bx-book-content', 'title' => 'Library',    'desc' => 'Phrases, drugs and shared blocks',             'roles' => ['doctor']],
        ['url' => '/status',    'icon' => 'bx-pulse',    'title' => 'System status',  'desc' => 'PHP, database and XeLaTeX checks',             'roles' => ['doctor', 'administrator']],
        ['url' => '/admin/users', 'icon' => 'bx-user-circle', 'title' => 'Users',     'desc' => 'Accounts, roles and permissions',              'roles' => ['administrator']],
        ['url' => '/profile',   'icon' => 'bx-id-card',  'title' => 'My profile',     'desc' => 'Your account details',                         'roles' => []],
    ];
    $mine = medscribe_user_roles();
    $visible = array_values(array_filter($cards, function ($c) use ($mine) {
        return !$c['roles'] || array_intersect($c['roles'], $mine);
    }));

    return Renderer::render("homepage.zetem", [
        'display_name' => $account ? $account->getname() : $uname,
        'cards'        => $visible,
    ]);
}

function page_types($params) {
    $db = store_db();
    $rel = base_release_current();
    $types = $db->query(
        "SELECT s.name, s.kitpath, v.version, v.status, LEFT(v.sha256, 12) AS sha, v.cdate
           FROM sources s JOIN source_versions v ON v.source_id = s.id
          WHERE s.kind = 'type' AND v.version = (SELECT MAX(version) FROM source_versions WHERE source_id = s.id)
          ORDER BY s.name")->fetchAll(PDO::FETCH_ASSOC);
    $builds = $db->query("SELECT id, purpose, status, LEFT(pdf_sha256, 12) AS sha, cdate FROM builds ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $defs = $db->query("SELECT s.name, s.kind, v.version, v.status, LEFT(v.sha256, 12) AS sha FROM sources s JOIN source_versions v ON v.source_id = s.id
                         WHERE s.kind IN ('typedef', 'shared') AND v.version = (SELECT MAX(version) FROM source_versions WHERE source_id = s.id) ORDER BY s.kind, s.name")->fetchAll(PDO::FETCH_ASSOC);
    return Renderer::render("types.zetem", [
        'defs' => $defs, 'release' => $rel,
        'base'    => $rel ? base_release_files((int)$rel['id']) : [],
        'types'   => $types,
        'builds'  => $builds,
    ]);
}

function page_library($params) {
    $slot = trim((string)($_GET['slot'] ?? ''));
    $args = []; $where = '1=1';
    if ($slot !== '') { $where = 'slot = ?'; $args[] = $slot; }
    $st = store_db()->prepare("SELECT id, slot, text, tier, hidden, uses, last_used, first_type FROM pool_blocks WHERE $where ORDER BY slot, (tier = 'library') DESC, uses DESC, last_used DESC LIMIT 500");
    $st->execute($args);
    $slots = store_db()->query('SELECT slot, COUNT(*) AS n FROM pool_blocks GROUP BY slot ORDER BY slot')->fetchAll(PDO::FETCH_ASSOC);
    return Renderer::render("library.zetem", ['blocks' => $st->fetchAll(PDO::FETCH_ASSOC), 'slots' => $slots, 'slot' => $slot]);
}

function page_library_post($params) {
    global $kernel;
    if (!csrfClass::verifyRequest()) { $kernel->addStatus('error', 'Invalid security token.'); header('location: ' . rel_url('/library')); exit(); }
    $id = (int)($_POST['id'] ?? 0);
    $map = ['promote' => ["UPDATE pool_blocks SET tier = 'library' WHERE id = ?", 'Promoted to the library.'],
            'demote'  => ["UPDATE pool_blocks SET tier = 'history' WHERE id = ?", 'Moved back to history.'],
            'hide'    => ['UPDATE pool_blocks SET hidden = 1 WHERE id = ?', 'Hidden: it will not be suggested.'],
            'show'    => ['UPDATE pool_blocks SET hidden = 0 WHERE id = ?', 'Visible again.']];
    $a = (string)($_POST['action'] ?? '');
    if ($id && isset($map[$a])) { store_db()->prepare($map[$a][0])->execute([$id]); $kernel->addStatus('status', $map[$a][1]); }
    header('location: ' . rel_url('/library' . (!empty($_POST['slot']) ? '?slot=' . rawurlencode((string)$_POST['slot']) : '')));
    exit();
}

function page_profile($params) {
    global $kernel;
    $uname = $kernel->getUserName();
    $account = UsersClassEx::getUserAccount($uname);
    return Renderer::render("profile.zetem", [
        'username' => $uname,
        'name'     => $account ? $account->getname() : '',
        'email'    => $account ? $account->getemail() : '',
        'roles'    => implode(', ', medscribe_user_roles()),
    ]);
}

function page_status($params) {
    $xelatex = '/usr/local/texlive/2026/bin/x86_64-linux/xelatex';
    $checks = [];
    $checks[] = ['label' => 'PHP', 'ok' => true, 'detail' => PHP_VERSION];
    $db = false;
    try {
        UsersClassEx::getUserAccount('__probe__');
        $db = true;
    } catch (Throwable $e) {
        $db = false;
    }
    $checks[] = ['label' => 'Database', 'ok' => $db, 'detail' => defined('DB_NAME') ? DB_NAME : '-'];
    $rel = null;
    try { $rel = base_release_current(); } catch (Throwable $e) {}
    $checks[] = ['label' => 'Template store (base release)', 'ok' => (bool)$rel, 'detail' => $rel ? 'B' . $rel['number'] : 'none: run bin/import_kit.php'];
    $checks[] = ['label' => 'TeX sandbox (unshare)', 'ok' => is_executable(__APPDIR__ . '/bin/tex-sandbox.sh') && is_executable('/usr/bin/unshare'), 'detail' => 'bin/tex-sandbox.sh'];
    $checks[] = ['label' => 'XeLaTeX', 'ok' => is_executable($xelatex), 'detail' => $xelatex];
    $files = __APPDIR__ . '/web/files';
    $checks[] = ['label' => 'Files folder writable', 'ok' => is_dir($files) && is_writable($files), 'detail' => $files];
    $checks[] = ['label' => 'evrdocs kit (reference)', 'ok' => is_dir('/home/evrokas/devel/docs/template-kit'), 'detail' => '~/devel/docs/template-kit'];
    return Renderer::render("status.zetem", ['checks' => $checks]);
}
