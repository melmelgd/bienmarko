<?php
/**
 * includes/auth-common.php
 * -----------------------------------------------------------------------
 * Shared helpers for the login page (login.php), both auth endpoints
 * (user/api/auth.php and admin/api/auth.php), and admin/create-admin.php.
 * Kept in one place so a security-relevant change — session cookie
 * hardening, CSRF checking, lockout thresholds, error wording — only ever
 * needs to happen once and everything stays in sync. Same reasoning
 * nav-items.php and icons.php already follow inside each app; this is
 * that pattern applied to the pieces that sit outside either one.
 *
 * As of this update, the login page no longer needs the person to have
 * the "right" tab selected before signing in. Both auth endpoints now
 * delegate to bienmarko_handle_login_request() below, which checks the
 * submitted email/password against BOTH the guests and admins tables via
 * bienmarko_attempt_login() and honors whichever one actually matches.
 * The Guest/Staff toggle on login.php still exists and still decides
 * which of the two endpoint files receives the POST, but that no longer
 * determines who gets logged in as what — only which tab the person is
 * sent back to if the attempt fails. See the docblocks on those two
 * functions for the full reasoning.
 *
 * Rate limiting is backed by the database (see includes/db.php and the
 * login_attempts table) rather than the session, so a lockout survives a
 * cleared cookie or a switch to a different browser/device. It's now
 * keyed on email alone rather than (role, email) — see the comment above
 * bienmarko_seconds_until_unlocked() for why that changed alongside the
 * rest of this update.
 *
 * Deliberately NOT loaded from user/includes/bootstrap.php: bootstrap.php
 * calls a bare session_start() with no cookie hardening, and PHP silently
 * ignores session_set_cookie_params() if it runs after the session is
 * already active (verified while building this — it's a real footgun,
 * not a theoretical one). Pulling bootstrap.php in here would quietly
 * break the "keep me signed in" cookie lifetime below. Going forward,
 * bootstrap.php is the one that should eventually read what this file's
 * endpoints write into the session — not the other way around.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';

// ---- Tunable constants ---------------------------------------------------

define('BIENMARKO_MAX_LOGIN_ATTEMPTS', 5);                // failed attempts allowed...
define('BIENMARKO_LOCKOUT_SECONDS', 60);                  // ...before this cooldown kicks in
define('BIENMARKO_REMEMBER_SECONDS', 60 * 60 * 24 * 30);  // "keep me signed in" = 30 days
define('BIENMARKO_MAX_FIELD_LENGTH', 200);                // sanity cap on raw email/password input

// ---- Session bootstrapping ------------------------------------------------

/**
 * Starts the session with hardened cookie params. Always call this instead
 * of session_start() directly anywhere on these auth surfaces.
 *
 * @param int $lifetimeSeconds 0 = expire when the browser closes (default).
 */
function bienmarko_start_session(int $lifetimeSeconds = 0): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => $lifetimeSeconds,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- CSRF ------------------------------------------------------------------

function bienmarko_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function bienmarko_csrf_valid(?string $submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

// ---- Rate limiting (database-backed, per email) ----------------------------
// Sliding window: locked out once BIENMARKO_MAX_LOGIN_ATTEMPTS failures for
// this email exist within the last BIENMARKO_LOCKOUT_SECONDS. As the oldest
// of those failures ages past that window, the count drops and access is
// restored automatically — no separate "unlock" write ever needed.
//
// Keyed on email ALONE now, not (role, email) as before. Previously, role
// was always something the person had declared by picking a tab, so keying
// on it meant a guest mistyping their password couldn't lock a staff
// member out of the Staff tab on a shared front-desk browser. Now that
// bienmarko_attempt_login() checks both tables regardless of which tab was
// used, there's no "declared role" left to key on — and keying on the
// account's real role would open a worse gap than the one it used to
// close: the same email could be throttled once via user/api/auth.php and
// again, independently, via admin/api/auth.php, doubling the guesses an
// attacker gets against one email before either counter trips. A single
// per-email counter closes that gap outright.

function bienmarko_seconds_until_unlocked(string $email): int
{
    $pdo = bienmarko_db();
    $windowStart = date('Y-m-d H:i:s', time() - BIENMARKO_LOCKOUT_SECONDS);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS attempts, MIN(created_at) AS earliest
         FROM login_attempts
         WHERE email = :email AND created_at > :window_start'
    );
    $stmt->execute(['email' => $email, 'window_start' => $windowStart]);
    $row = $stmt->fetch();

    if ((int) $row['attempts'] < BIENMARKO_MAX_LOGIN_ATTEMPTS) {
        return 0;
    }

    $unlocksAt = strtotime($row['earliest']) + BIENMARKO_LOCKOUT_SECONDS;
    return max(0, $unlocksAt - time());
}

/**
 * $tabRole records which of the two login tabs the attempt came through —
 * purely for the audit trail in login_attempts. It plays no part in the
 * lockout decision above, which is keyed on email alone. Defaults to
 * 'user' only because the column has always stored one of the two literal
 * role strings the rest of the app uses; there's no third value to fall
 * back on for, say, an email that doesn't exist in either table.
 */
function bienmarko_register_failed_attempt(string $email, string $tabRole = 'user'): void
{
    $pdo = bienmarko_db();
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (role, email, ip_address) VALUES (:role, :email, :ip)'
    );
    $stmt->execute([
        'role'  => $tabRole,
        'email' => $email,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/** Called on a successful login so a few earlier typos don't linger against
 *  the next legitimate sign-in attempt. Clears every row for this email —
 *  the throttle is per-email now, so there's no role dimension left to
 *  narrow the DELETE by. */
function bienmarko_clear_login_throttle(string $email): void
{
    $pdo = bienmarko_db();
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
    $stmt->execute(['email' => $email]);
}

// ---- Honeypot ----------------------------------------------------------------

/**
 * "website" is a hidden field real visitors never see or fill (see the
 * .hp-field rule in assets/css/login.css). A bot that fills every field
 * it finds trips it. Treated identically to a wrong password everywhere
 * it's checked, so a bot can't learn to detect and skip it.
 */
function bienmarko_honeypot_triggered(array $input): bool
{
    return !empty($input['website']);
}

// ---- Input parsing -------------------------------------------------------

function bienmarko_request_is_json(): bool
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    return stripos($contentType, 'application/json') !== false;
}

/**
 * Reads POST data whether the client sent JSON (login.js's fetch call) or
 * a classic application/x-www-form-urlencoded submit (no-JS fallback).
 * Both call sites end up with the same flat associative array either way.
 */
function bienmarko_read_input(): array
{
    if (bienmarko_request_is_json()) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function bienmarko_normalize_email(string $raw): string
{
    return strtolower(trim($raw));
}

function bienmarko_valid_login_input(string $email, string $password): bool
{
    if ($email === '' || $password === '') {
        return false;
    }
    if (strlen($email) > BIENMARKO_MAX_FIELD_LENGTH || strlen($password) > BIENMARKO_MAX_FIELD_LENGTH) {
        return false;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// ---- Role / redirect helpers -----------------------------------------------

function bienmarko_dashboard_path(string $role): string
{
    return $role === 'admin' ? 'admin/dashboard.php' : 'user/dashboard.php';
}

function bienmarko_is_logged_in(): bool
{
    return isset($_SESSION['auth_role'], $_SESSION['auth_user_id']);
}

/**
 * Redirects to the shared login page unless the session is logged in with
 * the given role, otherwise returns quietly. Designed for pages one level
 * under the project root (admin/*.php, user/*.php) — the same depth as
 * every existing page in both apps — so '../login.php' is always the
 * right way back up to it from there.
 */
function bienmarko_require_role(string $role): void
{
    if (($_SESSION['auth_role'] ?? null) !== $role) {
        header('Location: ../login.php?role=' . urlencode($role));
        exit;
    }
}

// ---- Credential verification -------------------------------------------------

/**
 * Checks the given email/password against BOTH the guests and admins
 * tables and returns whichever one actually matches. The login form no
 * longer asks the person to declare Guest vs Staff up front, so the
 * account itself — not a tab the person clicked — is what determines the
 * role from here on.
 *
 * Returns, on a match:
 *   ['role' => 'user'|'admin', 'account' => <raw DB row>, 'name' => <string>]
 * Returns null if the credentials don't match any account in either
 * table. Callers must treat every null the same way — one generic
 * "incorrect email or password" — regardless of whether the email
 * matched no one, matched with the wrong password, or matched one table
 * only after also being tried against the other. Never let the
 * difference between these show up in a response; that's exactly the
 * account-enumeration gap the rest of this file is built to avoid.
 *
 * Edge case: if the same email is registered in BOTH tables and the
 * submitted password happens to be valid for both, the admin (staff)
 * match wins. Staff accounts are only ever created deliberately, through
 * admin/create-admin.php — never through self-registration — so a valid
 * staff credential is treated as the more deliberate of the two possible
 * matches. This should be vanishingly rare in practice (it requires the
 * same email AND the same password to exist in both tables at once);
 * flagging the rule explicitly here rather than leaving it as an
 * accident of query order.
 */
function bienmarko_attempt_login(string $email, string $password): ?array
{
    $pdo = bienmarko_db();

    $guestStmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, password_hash FROM guests WHERE email = :email LIMIT 1'
    );
    $guestStmt->execute(['email' => $email]);
    $guest = $guestStmt->fetch();

    $adminStmt = $pdo->prepare(
        'SELECT id, name, email, password_hash FROM admins WHERE email = :email LIMIT 1'
    );
    $adminStmt->execute(['email' => $email]);
    $admin = $adminStmt->fetch();

    $adminMatches = $admin !== false && password_verify($password, $admin['password_hash']);
    if ($adminMatches) {
        return [
            'role'    => 'admin',
            'account' => $admin,
            'name'    => $admin['name'],
        ];
    }

    $guestMatches = $guest !== false && password_verify($password, $guest['password_hash']);
    if ($guestMatches) {
        return [
            'role'    => 'user',
            'account' => $guest,
            'name'    => trim($guest['first_name'] . ' ' . $guest['last_name']),
        ];
    }

    return null;
}

// ---- Response ----------------------------------------------------------------

function bienmarko_auth_message(string $code, int $retryAfterSeconds = 0): string
{
    if ($code === 'locked') {
        return $retryAfterSeconds > 0
            ? "Too many attempts. Try again in {$retryAfterSeconds}s."
            : 'Too many attempts. Please wait a moment and try again.';
    }
    return 'Incorrect email or password.';
}

/**
 * Sends a successful-login response: either a JSON body (login.js's fetch
 * call) or an HTTP redirect (classic form submit) to the dashboard for
 * $role — the role bienmarko_attempt_login() actually matched, which may
 * not be the tab the person had selected on login.php, and that's fine by
 * design now. Called only from bienmarko_handle_login_request(), always
 * as the last thing on the success path — it exits internally so nothing
 * after the call runs.
 */
function bienmarko_send_auth_success(bool $isJson, string $role): void
{
    if ($isJson) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'  => true,
            'message'  => '',
            'redirect' => bienmarko_dashboard_path($role),
        ]);
        exit;
    }

    // Classic form submit: a real redirect (PHP defaults header('Location')
    // to 302). Computed from the DETECTED role rather than a fixed
    // '../dashboard.php', because this can now be reached from either
    // user/api/auth.php or admin/api/auth.php regardless of which table
    // the credentials actually matched — see bienmarko_attempt_login().
    // Both calling files sit two levels under the project root, so
    // '../../' + the root-relative dashboard path is correct from either.
    header('Location: ../../' . bienmarko_dashboard_path($role));
    exit;
}

/**
 * Sends a failed-login response (bad credentials, lockout, CSRF, or
 * honeypot) — either a JSON body or a redirect back to login.php. Called
 * only from bienmarko_handle_login_request(), always as the last thing on
 * a given failure path — it exits internally so nothing after the call
 * runs.
 *
 * $tabRole only decides which tab the person is sent back to on
 * login.php (so a Staff-tab attempt doesn't bounce them back to the
 * Guest tab to retry) — it has no bearing on which accounts were
 * actually checked.
 */
function bienmarko_send_auth_failure(
    bool $isJson,
    string $tabRole,
    string $errorCode = 'invalid',
    int $retryAfterSeconds = 0
): void {
    if ($isJson) {
        http_response_code($errorCode === 'locked' ? 429 : 401);
        if ($retryAfterSeconds > 0) {
            header('Retry-After: ' . $retryAfterSeconds);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'  => false,
            'message'  => bienmarko_auth_message($errorCode, $retryAfterSeconds),
            'redirect' => null,
        ]);
        exit;
    }

    $params = ['role' => $tabRole, 'error' => $errorCode];
    if ($retryAfterSeconds > 0) {
        $params['wait'] = $retryAfterSeconds;
    }
    header('Location: ../../login.php?' . http_build_query($params));
    exit;
}

// ---- Request handling ----------------------------------------------------

/**
 * Handles a POST to user/api/auth.php or admin/api/auth.php — both now
 * reduce to a single call to this function. $tabRole is 'user' or 'admin'
 * depending on which of those two files called it, and is used ONLY to
 * pick which tab the person lands back on if the attempt fails (see
 * bienmarko_send_auth_failure()). It plays no part in deciding who is
 * actually logged in as what: the email/password are checked against
 * BOTH the guests and admins tables via bienmarko_attempt_login(), and
 * whichever one actually matches wins — so submitting valid guest
 * credentials from the Staff tab (or valid staff credentials from the
 * Guest tab) still logs the person in correctly and sends them to their
 * own dashboard.
 *
 * Exits internally on every path — nothing in the calling file runs
 * after this returns, success or failure.
 */
function bienmarko_handle_login_request(string $tabRole): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $isJson   = bienmarko_request_is_json();
    $input    = bienmarko_read_input();
    $remember = !empty($input['remember']);

    // Cookie lifetime must be set before session_start() — see bienmarko_start_session().
    bienmarko_start_session($remember ? BIENMARKO_REMEMBER_SECONDS : 0);

    // Extracted up front (not just validated later) because the rate
    // limiter below needs an identity to key on regardless of which check
    // ends up failing — every failure path registers against this email.
    $email    = bienmarko_normalize_email((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if (!bienmarko_csrf_valid($input['csrf_token'] ?? null)) {
        bienmarko_register_failed_attempt($email, $tabRole);
        bienmarko_send_auth_failure($isJson, $tabRole, 'invalid');
    }

    // Bots that fill every field they find trip this. Treated exactly
    // like a wrong password below so it can't be told apart from one.
    if (bienmarko_honeypot_triggered($input)) {
        bienmarko_register_failed_attempt($email, $tabRole);
        bienmarko_send_auth_failure($isJson, $tabRole, 'invalid');
    }

    // Checked only once the request has passed basic legitimacy checks
    // above, so a forged/replayed request can't be used to probe whether
    // a given email is currently locked out.
    $retryAfter = bienmarko_seconds_until_unlocked($email);
    if ($retryAfter > 0) {
        bienmarko_send_auth_failure($isJson, $tabRole, 'locked', $retryAfter);
    }

    if (!bienmarko_valid_login_input($email, $password)) {
        bienmarko_register_failed_attempt($email, $tabRole);
        bienmarko_send_auth_failure($isJson, $tabRole, 'invalid');
    }

    $match = bienmarko_attempt_login($email, $password);

    // Same generic message whether the email doesn't exist anywhere, the
    // password is wrong, or the email only exists in the other table —
    // never reveal which (avoids confirming an email is registered to
    // someone probing the form).
    if ($match === null) {
        bienmarko_register_failed_attempt($email, $tabRole);
        bienmarko_send_auth_failure($isJson, $tabRole, 'invalid');
    }

    // Success — clear the throttle, then rotate the session ID (session
    // fixation protection) now that the cookie lifetime above already
    // reflects the "remember me" choice.
    bienmarko_clear_login_throttle($email);
    session_regenerate_id(true);

    $_SESSION['auth_role']       = $match['role'];
    $_SESSION['auth_user_id']    = (int) $match['account']['id'];
    $_SESSION['auth_name']       = $match['name'];
    $_SESSION['auth_email']      = $match['account']['email'];
    $_SESSION['auth_login_time'] = time();

    bienmarko_send_auth_success($isJson, $match['role']);
}
