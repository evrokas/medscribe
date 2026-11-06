<?php

/**
 * Template store (PLAN.md §5): sources with linear, immutable versions whose
 * bytes live in a content-addressed object store keyed by SHA-256, plus base
 * releases (B1, B2, ...). Plain functions over the app's PDO connection; no
 * web request is needed, so bin/ scripts use them too.
 */

const MEDSCRIBE_VERSION = '0.1.0-m0';

function store_root(): string {
    return __APPDIR__ . '/web/files/store';
}

function store_object_path(string $sha): string {
    return store_root() . '/objects/' . substr($sha, 0, 2) . '/' . substr($sha, 2);
}

function store_mkdir(string $dir): void {
    if (is_dir($dir)) return;
    // Create parents one by one so each gets group write (the CLI user and Apache's user share web/files).
    $parent = dirname($dir);
    if (!is_dir($parent)) store_mkdir($parent);
    if (!mkdir($dir, 02770) && !is_dir($dir)) {
        throw new RuntimeException("cannot create $dir");
    }
    @chmod($dir, 02770);
}

/** Store bytes once, keyed by SHA-256; returns the hash. */
function store_put(string $bytes): string {
    $sha = hash('sha256', $bytes);
    $path = store_object_path($sha);
    if (is_file($path)) {
        return $sha;
    }
    store_mkdir(dirname($path));
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $bytes) === false) {
        throw new RuntimeException("cannot write $tmp");
    }
    chmod($tmp, 0640);
    rename($tmp, $path);
    return $sha;
}

/** Read an object and check it against its hash; throws if missing or corrupt. */
function store_get(string $sha): string {
    if (!preg_match('/^[0-9a-f]{64}$/', $sha)) {
        throw new InvalidArgumentException('bad hash');
    }
    $path = store_object_path($sha);
    $bytes = is_file($path) ? file_get_contents($path) : false;
    if ($bytes === false) {
        throw new RuntimeException("store object $sha is missing");
    }
    if (!hash_equals($sha, hash('sha256', $bytes))) {
        throw new RuntimeException("store object $sha is corrupt (hash mismatch)");
    }
    return $bytes;
}

function store_db(): PDO {
    return dbConnection::getConnection();
}

function store_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/** Find or create a source; returns its row. */
function source_ensure(string $name, string $kind, string $kitpath, string $user = 'system'): array {
    $db = store_db();
    $st = $db->prepare('SELECT * FROM sources WHERE name = ?');
    $st->execute([$name]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        return $row;
    }
    $db->prepare('INSERT INTO sources (guid, cuser, name, kind, kitpath) VALUES (?, ?, ?, ?, ?)')
       ->execute([store_uuid(), $user, $name, $kind, $kitpath]);
    return source_ensure($name, $kind, $kitpath, $user);
}

function source_latest(int $sourceId): ?array {
    $st = store_db()->prepare('SELECT * FROM source_versions WHERE source_id = ? ORDER BY version DESC LIMIT 1');
    $st->execute([$sourceId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Add a version of a source. If the bytes equal the latest version, that
 * version is returned and nothing is created (history only grows on change).
 */
function source_add_version(array $source, string $bytes, string $note, string $status = 'draft', string $user = 'system'): array {
    if (!in_array($status, ['draft', 'released'], true)) {
        throw new InvalidArgumentException('bad status');
    }
    $sha = store_put($bytes);
    $db = store_db();
    $db->beginTransaction();
    try {
        // Lock the source row so two writers cannot take the same number.
        $db->prepare('SELECT id FROM sources WHERE id = ? FOR UPDATE')->execute([$source['id']]);
        $latest = source_latest((int)$source['id']);
        if ($latest && $latest['sha256'] === $sha) {
            $db->commit();
            return $latest;
        }
        $n = $latest ? (int)$latest['version'] + 1 : 1;
        $db->prepare('INSERT INTO source_versions (guid, cuser, source_id, version, sha256, size, status, note, prev_version) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([store_uuid(), $user, $source['id'], $n, $sha, strlen($bytes), $status, $note, $latest ? $latest['version'] : null]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return source_latest((int)$source['id']);
}

/** draft -> released. Released versions never change; releasing twice is a no-op. */
function source_release(int $versionId): void {
    store_db()->prepare("UPDATE source_versions SET status = 'released' WHERE id = ? AND status = 'draft'")
              ->execute([$versionId]);
}

/** Create the next base release from a list of source_version ids. */
function base_release_create(array $versionIds, string $note, string $user = 'system'): array {
    $db = store_db();
    $db->beginTransaction();
    try {
        $n = (int)$db->query('SELECT COALESCE(MAX(number), 0) FROM base_releases FOR UPDATE')->fetchColumn() + 1;
        $db->prepare("INSERT INTO base_releases (guid, cuser, number, status, note) VALUES (?,?,?, 'released', ?)")
           ->execute([store_uuid(), $user, $n, $note]);
        $rid = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO base_release_files (release_id, source_version_id) VALUES (?, ?)');
        $rel = $db->prepare("UPDATE source_versions SET status = 'released' WHERE id = ?");
        foreach ($versionIds as $vid) {
            $ins->execute([$rid, $vid]);
            $rel->execute([$vid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['id' => $rid, 'number' => $n];
}

function base_release_current(): ?array {
    return store_db()->query("SELECT * FROM base_releases WHERE status = 'released' ORDER BY number DESC LIMIT 1")
                     ->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Files of a base release: [['name','kind','kitpath','version','sha256'], ...] */
function base_release_files(int $releaseId): array {
    $st = store_db()->prepare(
        'SELECT s.name, s.kind, s.kitpath, v.version, v.sha256, v.id AS version_id
           FROM base_release_files f
           JOIN source_versions v ON v.id = f.source_version_id
           JOIN sources s ON s.id = v.source_id
          WHERE f.release_id = ? ORDER BY s.name');
    $st->execute([$releaseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** The latest released version of a source by name, or null. */
function source_released_by_name(string $name): ?array {
    $st = store_db()->prepare(
        "SELECT s.name, s.kind, s.kitpath, v.version, v.sha256, v.id AS version_id
           FROM sources s JOIN source_versions v ON v.source_id = s.id
          WHERE s.name = ? AND v.status = 'released' ORDER BY v.version DESC LIMIT 1");
    $st->execute([$name]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
