<?php

require_once __DIR__ . '/../../includes/auth-common.php';

// ---- Only POST is accepted -------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$isJson = bienmarko_request_is_json();
$input  = bienmarko_read_input();

// ---- Response helpers ------------------------------------------------------

function reg_json_error(string $message, int $status = 422): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message, 'redirect' => null]);
    exit;
}

function reg_redirect_error(string $code): void
{
    header('Location: ../../register.php?error=' . urlencode($code));
    exit;
}

function reg_fail(bool $isJson, string $jsonMessage, string $redirectCode): void
{
    if ($isJson) {
        reg_json_error($jsonMessage);
    } else {
        reg_redirect_error($redirectCode);
    }
}

// ---- CSRF ------------------------------------------------------------------
bienmarko_start_session();

if (!bienmarko_csrf_valid($input['csrf_token'] ?? null)) {
    reg_fail($isJson, 'Invalid request. Please refresh and try again.', 'csrf');
}

// Rotate the CSRF token immediately so it cannot be replayed.
unset($_SESSION['csrf_token']);

// ---- Honeypot --------------------------------------------------------------
if (bienmarko_honeypot_triggered($input)) {
    // Return a convincing success response to the bot without writing anything.
    if ($isJson) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => '', 'redirect' => null]);
        exit;
    }
    header('Location: ../../login.php?registered=1');
    exit;
}

// ---- Parse & sanitise input ------------------------------------------------
$fullName = trim((string) ($input['full_name'] ?? ''));
$email    = bienmarko_normalize_email((string) ($input['email']    ?? ''));
$phone    = trim((string) ($input['phone']    ?? ''));
$password = (string) ($input['password'] ?? '');

// ---- Validate --------------------------------------------------------------

// Required fields
if ($fullName === '' || $email === '' || $phone === '' || $password === '') {
    reg_fail($isJson, 'All fields are required.', 'invalid');
}

// Field length caps
if (
    strlen($fullName) > BIENMARKO_MAX_FIELD_LENGTH
    || strlen($email) > BIENMARKO_MAX_FIELD_LENGTH
    || strlen($phone) > 20
    || strlen($password) > BIENMARKO_MAX_FIELD_LENGTH
) {
    reg_fail($isJson, 'One or more fields exceed the maximum allowed length.', 'invalid');
}

// Email format
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    reg_fail($isJson, 'Please enter a valid email address.', 'invalid');
}

// Phone: 7–15 digits (allows leading +, spaces, dashes, parentheses)
$phoneDigits = preg_replace('/\D/', '', $phone);
if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
    reg_fail($isJson, 'Phone number must be 7–15 digits.', 'phone');
}

// Password — minimum 8 characters
if (strlen($password) < 8) {
    reg_fail($isJson, 'Password must be at least 8 characters.', 'weak_pass');
}

// ---- Split full name → first / last ----------------------------------------
// Everything before the first whitespace run = first_name; remainder = last_name.
$nameParts = preg_split('/\s+/', $fullName, 2);
$firstName = $nameParts[0] ?? $fullName;
$lastName  = $nameParts[1] ?? '';

// ---- Database insert -------------------------------------------------------
try {
    $pdo = bienmarko_db();

    // Duplicate email check
    $checkStmt = $pdo->prepare('SELECT id FROM guests WHERE email = :email LIMIT 1');
    $checkStmt->execute(['email' => $email]);
    if ($checkStmt->fetch() !== false) {
        reg_fail($isJson, 'An account with that email already exists.', 'exists');
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $insertStmt = $pdo->prepare(
        'INSERT INTO guests (first_name, last_name, email, phone, password_hash)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash)'
    );
    $insertStmt->execute([
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'email'         => $email,
        'phone'         => $phone,
        'password_hash' => $passwordHash,
    ]);

} catch (PDOException $e) {
    error_log('Register PDO error: ' . $e->getMessage());
    reg_fail($isJson, 'An error occurred. Please try again later.', 'db');
}

// ---- Respond — NO session written -----------------------------------------
// The guest must sign in through the normal login flow after registration.

if ($isJson) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'  => true,
        'message'  => '',
        'redirect' => null,   // JS handles redirect to login.php via the overlay
    ]);
    exit;
}

// Classic no-JS fallback: go straight to login page
header('Location: ../../login.php?registered=1');
exit;
