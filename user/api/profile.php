<?php

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

const PROFILE_AVATAR_DIR         = __DIR__ . '/../../admin/assets/uploads/avatars';
const PROFILE_AVATAR_URL_BASE    = '../admin/assets/uploads/avatars';
const PROFILE_AVATAR_MAX_BYTES   = 5 * 1024 * 1024; // 5 MB
const PROFILE_AVATAR_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];

function prof_fail(int $httpCode, string $message): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function prof_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

/** Ensures the avatar upload folder exists and can't execute scripts — same lockdown used by admin/includes/accommodations-data.php's photo folder. */
function prof_ensure_avatar_dir(): void
{
    if (!is_dir(PROFILE_AVATAR_DIR)) {
        mkdir(PROFILE_AVATAR_DIR, 0755, true);
    }

    $htaccess = PROFILE_AVATAR_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents(
            $htaccess,
            "# Uploaded files only — never execute scripts from this folder.\n"
            . "php_flag engine off\n"
            . "<FilesMatch \"\\.(php|phtml|php\\d|phar)$\">\n"
            . "  Require all denied\n"
            . "</FilesMatch>\n"
        );
    }

    $indexGuard = PROFILE_AVATAR_DIR . '/index.html';
    if (!file_exists($indexGuard)) {
        file_put_contents($indexGuard, '');
    }
}

/** Validates and stores an uploaded avatar. Returns the stored filename, or null when no file was submitted. Pushes any problems onto &$errors. */
function prof_handle_avatar_upload(?array $file, array &$errors): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'Photo is too large for this server\'s upload limit. Please choose a smaller file.';
        } else {
            $errors[] = 'Photo upload failed. Please try a different file.';
        }
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Photo upload failed. Please try again.';
        return null;
    }

    if ($file['size'] > PROFILE_AVATAR_MAX_BYTES) {
        $errors[] = 'Photo is too large (max 5MB).';
        return null;
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, PROFILE_AVATAR_ALLOWED_EXT, true)) {
        $errors[] = 'Photo must be a JPG, PNG, or WEBP file.';
        return null;
    }

    if (@getimagesize($file['tmp_name']) === false) {
        $errors[] = 'That file does not appear to be a valid image.';
        return null;
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], PROFILE_AVATAR_DIR . '/' . $filename)) {
        $errors[] = 'Could not save the uploaded photo. Please try again.';
        return null;
    }

    return $filename;
}

function prof_delete_avatar_file(?string $filename): void
{
    if (!$filename) {
        return;
    }
    $path = PROFILE_AVATAR_DIR . '/' . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    prof_fail(405, 'Method not allowed.');
}

if (!bienmarko_csrf_valid($_POST['csrf_token'] ?? null)) {
    prof_fail(403, 'Your session has expired. Please refresh the page and try again.');
}

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
if ($guestId < 1) {
    prof_fail(401, 'Please sign in again.');
}

$pdo = bienmarko_db();

// Additive migration — safe to run on every request.
$pdo->exec("ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `avatar_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `phone`");

prof_ensure_avatar_dir();

$existingStmt = $pdo->prepare('SELECT avatar_filename FROM guests WHERE id = :id LIMIT 1');
$existingStmt->execute(['id' => $guestId]);
$existing = $existingStmt->fetch();
if ($existing === false) {
    prof_fail(404, 'Account not found.');
}

/* ---- Validate text fields ---- */
$errors = [];

$firstName = trim((string) ($_POST['firstName'] ?? ''));
$lastName  = trim((string) ($_POST['lastName'] ?? ''));
$email     = bienmarko_normalize_email((string) ($_POST['email'] ?? ''));
$phone     = trim((string) ($_POST['phone'] ?? ''));

if ($firstName === '' || mb_strlen($firstName) > 100) {
    $errors[] = 'First name is required (max 100 characters).';
}
if ($lastName === '' || mb_strlen($lastName) > 100) {
    $errors[] = 'Last name is required (max 100 characters).';
}
if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors[] = 'Please enter a valid email address.';
}

$phoneDigits = preg_replace('/\D/', '', $phone);
if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
    $errors[] = 'Phone number must be 7–15 digits.';
}

if (!$errors && $email !== '') {
    $dupStmt = $pdo->prepare('SELECT id FROM guests WHERE email = :email AND id != :id LIMIT 1');
    $dupStmt->execute(['email' => $email, 'id' => $guestId]);
    if ($dupStmt->fetch() !== false) {
        $errors[] = 'That email is already used by another account.';
    }
}

/* ---- Avatar ---- */
$newAvatarFilename = prof_handle_avatar_upload($_FILES['avatar'] ?? null, $errors);
$removeAvatar       = !empty($_POST['remove_avatar']) && $_POST['remove_avatar'] !== '0';

if ($errors) {
    prof_delete_avatar_file($newAvatarFilename);
    prof_fail(422, implode(' ', $errors));
}

$finalAvatarFilename = $existing['avatar_filename'];
if ($newAvatarFilename !== null) {
    prof_delete_avatar_file($existing['avatar_filename']);
    $finalAvatarFilename = $newAvatarFilename;
} elseif ($removeAvatar) {
    prof_delete_avatar_file($existing['avatar_filename']);
    $finalAvatarFilename = null;
}

$stmt = $pdo->prepare(
    'UPDATE guests SET
        first_name = :first_name, last_name = :last_name,
        email = :email, phone = :phone, avatar_filename = :avatar_filename
     WHERE id = :id'
);
$stmt->execute([
    'first_name'      => $firstName,
    'last_name'       => $lastName,
    'email'           => $email,
    'phone'           => $phone,
    'avatar_filename' => $finalAvatarFilename,
    'id'              => $guestId,
]);

// Keep the session-stored greeting name in sync so the dashboard's
// "Welcome back" line and anywhere else reading $_SESSION['auth_name']
// stay correct without requiring the guest to sign in again.
$fullName = trim($firstName . ' ' . $lastName);
$_SESSION['auth_name'] = $fullName;

$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));

prof_ok([
    'user' => [
        'name'     => $fullName,
        'email'    => $email,
        'phone'    => $phone,
        'initials' => $initials,
        'avatar'   => $finalAvatarFilename ? PROFILE_AVATAR_URL_BASE . '/' . rawurlencode($finalAvatarFilename) : null,
    ],
]);
