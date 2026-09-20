<?php
/**
 * admin/api/settings.php
 * -----------------------------------------------------------------------
 * JSON API behind admin/settings.php. Guarded the same way as
 * admin/api/expenses.php — session + admin role + CSRF, checked before
 * anything submitted is looked at. Every request is POST with a JSON
 * body and an `action` field selecting what happens:
 *
 *   action=save_general        - property name, contact email/number, address
 *   action=save_notifications  - the four notification toggles
 *   action=update_account      - the logged-in admin's own name, email,
 *                                  and (optionally) password
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo  = bienmarko_db();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

/* CSRF check — before anything submitted is looked at */
if (empty($body['csrf_token']) || !bienmarko_csrf_valid($body['csrf_token'])) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token.']);
    exit;
}

/* ------------------------------------------------------------------
   Ensure the settings table exists — mirrors the guard in
   admin/settings.php so this endpoint also works standalone. Single
   fixed row (id = 1): one pension house, one settings record.
------------------------------------------------------------------ */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `system_settings` (
        `id`                     TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
        `property_name`          VARCHAR(150) NOT NULL DEFAULT '',
        `contact_email`          VARCHAR(190) NOT NULL DEFAULT '',
        `contact_number`         VARCHAR(20)  NOT NULL DEFAULT '',
        `address`                VARCHAR(255) NOT NULL DEFAULT '',
        `notify_new_booking`     TINYINT(1) NOT NULL DEFAULT 1,
        `notify_payment`         TINYINT(1) NOT NULL DEFAULT 1,
        `notify_low_inventory`   TINYINT(1) NOT NULL DEFAULT 0,
        `notify_guest_feedback`  TINYINT(1) NOT NULL DEFAULT 0,
        `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
$pdo->exec('INSERT IGNORE INTO `system_settings` (`id`) VALUES (1)');

function set_fail(string $msg): never
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function set_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

$action = trim((string) ($body['action'] ?? ''));

/* ================================================================
   ACTION: save_general
   ================================================================ */
if ($action === 'save_general') {
    $propertyName = trim((string) ($body['property_name'] ?? ''));
    if ($propertyName === '' || mb_strlen($propertyName) > 150) {
        set_fail('Property name is required (max 150 characters).');
    }

    $contactEmail = bienmarko_normalize_email((string) ($body['contact_email'] ?? ''));
    if ($contactEmail === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || strlen($contactEmail) > 190) {
        set_fail('Please enter a valid contact email.');
    }

    $contactNumber = trim((string) ($body['contact_number'] ?? ''));
    $contactDigits = preg_replace('/\D/', '', $contactNumber);
    if (strlen($contactDigits) < 7 || strlen($contactDigits) > 15) {
        set_fail('Contact number must be 7–15 digits.');
    }
    if (strlen($contactNumber) > 20) {
        set_fail('Contact number is too long.');
    }

    $address = trim((string) ($body['address'] ?? ''));
    if ($address === '' || mb_strlen($address) > 255) {
        set_fail('Address is required (max 255 characters).');
    }

    try {
        $upd = $pdo->prepare(
            'UPDATE system_settings SET
                property_name  = :property_name,
                contact_email  = :contact_email,
                contact_number = :contact_number,
                address        = :address
             WHERE id = 1'
        );
        $upd->execute([
            'property_name'  => $propertyName,
            'contact_email'  => $contactEmail,
            'contact_number' => $contactNumber,
            'address'        => $address,
        ]);
    } catch (\Throwable $e) {
        error_log('[settings api save_general] ' . $e->getMessage());
        set_fail('A database error occurred. Please try again.');
    }

    set_ok([
        'settings' => [
            'property_name'  => $propertyName,
            'contact_email'  => $contactEmail,
            'contact_number' => $contactNumber,
            'address'        => $address,
        ],
    ]);
}

/* ================================================================
   ACTION: save_notifications
   ================================================================ */
if ($action === 'save_notifications') {
    $toBool = static fn($v): int => !empty($v) ? 1 : 0;

    try {
        $upd = $pdo->prepare(
            'UPDATE system_settings SET
                notify_new_booking    = :new_booking,
                notify_payment        = :payment,
                notify_low_inventory  = :low_inventory,
                notify_guest_feedback = :guest_feedback
             WHERE id = 1'
        );
        $upd->execute([
            'new_booking'    => $toBool($body['notify_new_booking'] ?? false),
            'payment'        => $toBool($body['notify_payment'] ?? false),
            'low_inventory'  => $toBool($body['notify_low_inventory'] ?? false),
            'guest_feedback' => $toBool($body['notify_guest_feedback'] ?? false),
        ]);
    } catch (\Throwable $e) {
        error_log('[settings api save_notifications] ' . $e->getMessage());
        set_fail('A database error occurred. Please try again.');
    }

    set_ok();
}

/* ================================================================
   ACTION: update_account
   ================================================================ */
if ($action === 'update_account') {
    $adminId = (int) ($_SESSION['auth_user_id'] ?? 0);
    if ($adminId < 1) {
        set_fail('Please sign in again.');
    }

    $name  = trim((string) ($body['name'] ?? ''));
    $email = bienmarko_normalize_email((string) ($body['email'] ?? ''));

    if ($name === '' || mb_strlen($name) > 150) {
        set_fail('Full name is required (max 150 characters).');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        set_fail('Please enter a valid email address.');
    }

    $currentPassword = (string) ($body['current_password'] ?? '');
    $newPassword     = (string) ($body['new_password'] ?? '');
    $confirmPassword = (string) ($body['confirm_password'] ?? '');
    $wantsPasswordChange = $newPassword !== '' || $confirmPassword !== '';

    $existingStmt = $pdo->prepare('SELECT id, email, password_hash FROM admins WHERE id = :id LIMIT 1');
    $existingStmt->execute(['id' => $adminId]);
    $existing = $existingStmt->fetch();
    if (!$existing) {
        set_fail('Your admin account could not be found.');
    }

    $dupStmt = $pdo->prepare('SELECT id FROM admins WHERE email = :email AND id != :id LIMIT 1');
    $dupStmt->execute(['email' => $email, 'id' => $adminId]);
    if ($dupStmt->fetch() !== false) {
        set_fail('That email is already used by another admin account.');
    }

    $newHash = null;
    if ($wantsPasswordChange) {
        if ($currentPassword === '' || !password_verify($currentPassword, $existing['password_hash'])) {
            set_fail('Your current password is incorrect.');
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > BIENMARKO_MAX_FIELD_LENGTH) {
            set_fail('New password must be at least 8 characters.');
        }
        if ($newPassword !== $confirmPassword) {
            set_fail("New password and confirm password don't match.");
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    try {
        if ($newHash !== null) {
            $upd = $pdo->prepare('UPDATE admins SET name = :name, email = :email, password_hash = :hash WHERE id = :id');
            $upd->execute(['name' => $name, 'email' => $email, 'hash' => $newHash, 'id' => $adminId]);
        } else {
            $upd = $pdo->prepare('UPDATE admins SET name = :name, email = :email WHERE id = :id');
            $upd->execute(['name' => $name, 'email' => $email, 'id' => $adminId]);
        }
    } catch (\Throwable $e) {
        error_log('[settings api update_account] ' . $e->getMessage());
        set_fail('A database error occurred. Please try again.');
    }

    // Keep the session-stored name in sync — same pattern used by
    // user/api/profile.php for the guest-facing dashboard greeting.
    $_SESSION['auth_name'] = $name;

    set_ok(['admin' => ['name' => $name, 'email' => $email, 'password_changed' => $newHash !== null]]);
}

/* Unknown action */
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
