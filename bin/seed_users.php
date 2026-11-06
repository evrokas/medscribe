#!/usr/bin/env php
<?php
/**
 * Create the MedScribe roles (administrator = superuser, doctor, secretary)
 * and, with --user, a login account. Idempotent: existing roles/users are
 * left alone. Run from the app root after fw/bin/update.sh created the tables:
 *
 *   php bin/seed_users.php                              # roles only
 *   php bin/seed_users.php --user=NAME --role=doctor    # + account (asks for the password)
 *
 * Plain CLI script, no web request involved. Passwords are read from the
 * terminal (or MEDSCRIBE_PASSWORD) and hashed with password_hash().
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(__DIR__ . '/../web');
include_once(__DIR__ . '/../config/db.php');
require_once(__FWDIR__ . '/bootstrap.php');
if (file_exists('ClassesEx.php')) include_once('ClassesEx.php');

$opts = getopt('', ['user:', 'role:', 'name:', 'email:']);

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

$roles = [
    'administrator' => ['Administrator', 1],
    'doctor'        => ['Doctor', 0],
    'secretary'     => ['Secretary', 0],
];
$roleIds = [];
foreach ($roles as $name => [$label, $super]) {
    $st = $db->prepare('SELECT id FROM roles WHERE name = ?');
    $st->bind_param('s', $name); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row) { $roleIds[$name] = (int)$row['id']; echo "role $name exists\n"; continue; }
    $guid = bin2hex(random_bytes(16));
    $st = $db->prepare('INSERT INTO roles (guid, cdate, cuser, name, label, is_superuser) VALUES (?, NOW(), ?, ?, ?, ?)');
    $by = 'seed'; $st->bind_param('ssssi', $guid, $by, $name, $label, $super); $st->execute();
    $roleIds[$name] = $db->insert_id; echo "role $name created\n";
}

if (!isset($opts['user'])) exit(0);
$uname = $opts['user']; $role = $opts['role'] ?? 'doctor';
if (!isset($roleIds[$role])) { fwrite(STDERR, "unknown role $role\n"); exit(1); }

$st = $db->prepare('SELECT id FROM users WHERE uname = ?');
$st->bind_param('s', $uname); $st->execute();
if ($st->get_result()->fetch_assoc()) { echo "user $uname exists, nothing changed\n"; exit(0); }

$pass = getenv('MEDSCRIBE_PASSWORD');
if ($pass === false || $pass === '') {
    fwrite(STDERR, "Password for $uname: ");
    system('stty -echo'); $pass = trim(fgets(STDIN)); system('stty echo'); fwrite(STDERR, "\n");
}
if (strlen($pass) < 8) { fwrite(STDERR, "password must have at least 8 characters\n"); exit(1); }

$hash = password_hash($pass, PASSWORD_DEFAULT);
$name = $opts['name'] ?? $uname; $email = $opts['email'] ?? "$uname@localhost";
$roleStr = (string)$roleIds[$role];
$st = $db->prepare('INSERT INTO users (name, email, uname, upass, cdate, active, expired, wrongpasscount, roles) VALUES (?, ?, ?, ?, NOW(), 1, 0, 0, ?)');
$st->bind_param('sssss', $name, $email, $uname, $hash, $roleStr); $st->execute();
$uid = $db->insert_id;
$guid = bin2hex(random_bytes(16)); $rid = $roleIds[$role];
$st = $db->prepare('INSERT INTO user_roles (guid, cdate, cuser, user_id, role_id) VALUES (?, NOW(), ?, ?, ?)');
$by = 'seed'; $st->bind_param('ssii', $guid, $by, $uid, $rid); $st->execute();
echo "user $uname created with role $role\n";
