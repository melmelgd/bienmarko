<?php
/**
 * admin/api/create-admin.php
 * -----------------------------------------------------------------------
 * Handles the POST from ../create-admin.php. Guarded the same way as the
 * page itself — reachable only by an already-logged-in admin, redirecting
 * anyone else to the shared login page before looking at anything they
 * submitted.
 *
 * Classic form POST in, redirect back out (not JSON) — see the note in
 * create-admin.php for why this page doesn't use a login.js-style fetch
 * layer.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';

function redirect_with_error(string $code): void
{
    header('Location: ../create-admin.php?error=' . urlencode($code));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method not allowed.';
    exit;
}

if (!bienmarko_csrf_valid($_POST['csrf_token'] ?? null)) {
    redirect_with_error('invalid');
}

$name            = trim((string) ($_POST['name'] ?? ''));
$email           = bienmarko_normalize_email((string) ($_POST['email'] ?? ''));
$password        = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    redirect_with_error('invalid');
}

if (strlen($password) < 8 || strlen($password) > BIENMARKO_MAX_FIELD_LENGTH) {
    redirect_with_error('weak');
}

if ($password !== $confirmPassword) {
    redirect_with_error('mismatch');
}

$pdo = bienmarko_db();

$existing = $pdo->prepare('SELECT id FROM admins WHERE email = :email LIMIT 1');
$existing->execute(['email' => $email]);
if ($existing->fetch() !== false) {
    redirect_with_error('duplicate');
}

$insert = $pdo->prepare(
    'INSERT INTO admins (name, email, password_hash, created_by) VALUES (:name, :email, :hash, :created_by)'
);
$insert->execute([
    'name'       => $name,
    'email'      => $email,
    'hash'       => password_hash($password, PASSWORD_DEFAULT),
    'created_by' => $_SESSION['auth_user_id'],
]);

header('Location: ../create-admin.php?created=1');
exit;
