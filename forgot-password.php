<?php
/**
 * forgot-password.php — SMS OTP password reset for registered users.
 *
 * Flow (single page, two steps kept in $_SESSION['fp_phone']):
 *   Step 1  Enter mobile number  -> 6-digit OTP sent through iProg SMS
 *   Step 2  Enter OTP + new password -> password updated -> login.php?reset=1
 *
 * Place at the project root, next to login.php and register.php.
 * Uses includes/auth-common.php (session, CSRF, PDO via db.php, login throttle).
 * Resets passwords for rows in the `guests` table only.
 */

require_once __DIR__ . '/includes/auth-common.php';

bienmarko_start_session();

if (bienmarko_is_logged_in()) {
    header('Location: ' . bienmarko_dashboard_path($_SESSION['auth_role']));
    exit;
}

/* =====================================================================
   CONFIG
   ===================================================================== */

// Put the token in an environment variable (or paste it here for local dev only).
// Do NOT commit a real token to Git — rotate any token that was ever shared.
define('FP_IPROG_TOKEN',   getenv('IPROG_API_TOKEN') ?: 'cc54e13d90c58456917e81bcac4c6c2a0c648e74');
define('FP_IPROG_URL',     'https://sms.iprogtech.com/api/v1/sms_messages');
define('FP_VERIFY_SSL',    true);   // set false ONLY on local XAMPP if cURL has no CA bundle

// Registered guest accounts only. Staff (admins table) are intentionally excluded.
define('FP_USERS_TABLE',   'guests');
define('FP_PHONE_COLUMN',  'phone');          // change if your guests table names it differently
define('FP_ID_COLUMN',     'id');
define('FP_EMAIL_COLUMN',  'email');
define('FP_PASSWORD_COLUMN', 'password_hash');

define('FP_OTP_TTL_MIN',   10);   // OTP validity (minutes)
define('FP_RESEND_SEC',    60);   // cooldown between SMS sends
define('FP_MAX_SENDS_HR',  5);    // max SMS per number per hour
define('FP_MAX_ATTEMPTS',  5);    // wrong-code attempts before a new code is required
define('FP_MIN_PASSWORD',  8);    // same rule as register.php

$siteName    = 'BienMarko';
$siteTagline = "Bien Marko's Pension House";

/* =====================================================================
   HELPERS
   ===================================================================== */

function fp_db(): PDO
{
    static $ready = false;
    $pdo = bienmarko_db();

    if (!$ready) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id      BIGINT UNSIGNED NOT NULL,
                phone        VARCHAR(20)     NOT NULL,
                otp_hash     CHAR(64)        NOT NULL,
                attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at   DATETIME        NOT NULL,
                created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pr_phone (phone),
                INDEX idx_pr_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Housekeeping: drop anything long expired.
        $pdo->exec("DELETE FROM password_resets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $ready = true;
    }
    return $pdo;
}

function fp_csrf_ok(): bool
{
    return bienmarko_csrf_valid($_POST['csrf_token'] ?? null);
}

/** Returns ['e164' => '+639171234567', 'digits' => [...match variants]] or null. */
function fp_parse_phone(string $input): ?array
{
    $d = preg_replace('/\D/', '', $input);

    if (preg_match('/^63(9\d{9})$/', $d, $m))      { $core = $m[1]; }
    elseif (preg_match('/^0(9\d{9})$/', $d, $m))   { $core = $m[1]; }
    elseif (preg_match('/^(9\d{9})$/', $d, $m))    { $core = $m[1]; }
    else { return null; }

    return [
        'e164'   => '+63' . $core,
        'digits' => ['0' . $core, '63' . $core, $core],
    ];
}

function fp_mask_phone(string $e164): string
{
    return substr($e164, 0, 5) . str_repeat('•', 4) . substr($e164, -3);
}

function fp_find_user(PDO $pdo, array $variants): ?array
{
    $in    = implode(',', array_fill(0, count($variants), '?'));
    $id    = '`' . FP_ID_COLUMN . '`';
    $email = '`' . FP_EMAIL_COLUMN . '`';
    $pass  = '`' . FP_PASSWORD_COLUMN . '`';
    $phone = '`' . FP_PHONE_COLUMN . '`';
    $table = '`' . FP_USERS_TABLE . '`';

    // Strip spaces, dashes, plus signs and parentheses so stored formats still match.
    $cleanPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";

    // Only real accounts: a guest row created by a no-account booking has no
    // password, and must never be claimable through this form.
    $sql = "SELECT $id AS id, $email AS email
            FROM $table
            WHERE $pass IS NOT NULL AND $pass <> ''
              AND $cleanPhone IN ($in)
            LIMIT 1";

    $st = $pdo->prepare($sql);
    $st->execute($variants);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fp_hash_otp(string $e164, string $otp): string
{
    return hash_hmac('sha256', $e164 . '|' . $otp, FP_IPROG_TOKEN . '|bienmarko-reset');
}

/** Sends an SMS through iProg. Returns true on success. */
function fp_send_sms(string $e164, string $message): bool
{
    $ch = curl_init(FP_IPROG_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'api_token'    => FP_IPROG_TOKEN,
            'phone_number' => $e164,
            'message'      => $message,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => FP_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => FP_VERIFY_SSL ? 2 : 0,
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log without the message body so OTP codes never reach the log.
    error_log('[forgot-password] iProg HTTP ' . $http . ' | ' . substr((string) $response, 0, 300) . ($err ? ' | cURL: ' . $err : ''));

    if ($response === false || $http < 200 || $http >= 300) {
        return false;
    }
    $data = json_decode((string) $response, true);
    if (is_array($data) && isset($data['status']) && !in_array($data['status'], ['success', 200, '200'], true)) {
        return false;
    }
    return true;
}

/**
 * Creates and texts a fresh OTP. Silent no-op (still "true") when the DB-side
 * rate limits are hit, so the response never reveals whether a number exists.
 */
function fp_issue_otp(PDO $pdo, int $userId, string $e164): bool
{
    // Hourly cap
    $st = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $st->execute([$e164]);
    if ((int) $st->fetchColumn() >= FP_MAX_SENDS_HR) {
        return true;
    }

    // Cooldown
    $st = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) FROM password_resets WHERE phone = ?');
    $st->execute([$e164]);
    $since = $st->fetchColumn();
    if ($since !== null && $since !== false && (int) $since < FP_RESEND_SEC) {
        return true;
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $ins = $pdo->prepare(
        'INSERT INTO password_resets (user_id, phone, otp_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . (int) FP_OTP_TTL_MIN . ' MINUTE))'
    );
    $ins->execute([$userId, $e164, fp_hash_otp($e164, $otp)]);
    $rowId = (int) $pdo->lastInsertId();

    $ok = fp_send_sms(
        $e164,
        "Your Bien Marko's Pension House password reset code is {$otp}. Valid for " . FP_OTP_TTL_MIN . " minutes. Do not share this code."
    );

    if (!$ok) {
        $pdo->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$rowId]);
    }
    return $ok;
}

/* =====================================================================
   REQUEST HANDLING
   ===================================================================== */

$csrfToken = bienmarko_csrf_token();
$error     = '';
$notice    = '';

if (isset($_GET['restart'])) {
    unset($_SESSION['fp_phone'], $_SESSION['fp_last_sent']);
    header('Location: forgot-password.php');
    exit;
}

$step      = isset($_SESSION['fp_phone']) ? 2 : 1;
$maskedTo  = $step === 2 ? fp_mask_phone($_SESSION['fp_phone']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!empty($_POST['website'])) {           // honeypot
        header('Location: login.php');
        exit;
    }

    if (!fp_csrf_ok()) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        /* ---------- Step 1: request code ---------- */
        if ($action === 'send') {
            $parsed = fp_parse_phone((string) ($_POST['phone'] ?? ''));

            if ($parsed === null) {
                $error = 'Enter a valid Philippine mobile number, e.g. 09171234567.';
            } else {
                $last = (int) ($_SESSION['fp_last_sent'] ?? 0);
                if (time() - $last < FP_RESEND_SEC && ($_SESSION['fp_phone'] ?? '') === $parsed['e164']) {
                    $error = 'Please wait a moment before requesting another code.';
                } else {
                    try {
                        $pdo  = fp_db();
                        $user = fp_find_user($pdo, $parsed['digits']);
                        $sent = true;
                        if ($user) {
                            $sent = fp_issue_otp($pdo, (int) $user['id'], $parsed['e164']);
                        }
                        if (!$sent) {
                            $error = 'We could not send the SMS right now. Please try again shortly.';
                        } else {
                            // Same response whether or not the number is registered.
                            $_SESSION['fp_phone']     = $parsed['e164'];
                            $_SESSION['fp_last_sent'] = time();
                            header('Location: forgot-password.php?sent=1');
                            exit;
                        }
                    } catch (Throwable $e) {
                        error_log('[forgot-password] ' . $e->getMessage());
                        $error = 'Something went wrong. Please try again.';
                    }
                }
            }
        }

        /* ---------- Resend ---------- */
        elseif ($action === 'resend' && $step === 2) {
            $e164 = $_SESSION['fp_phone'];
            if (time() - (int) ($_SESSION['fp_last_sent'] ?? 0) < FP_RESEND_SEC) {
                $error = 'Please wait before requesting another code.';
            } else {
                try {
                    $pdo    = fp_db();
                    $parsed = fp_parse_phone($e164);
                    $user   = $parsed ? fp_find_user($pdo, $parsed['digits']) : null;
                    $sent   = true;
                    if ($user) {
                        $sent = fp_issue_otp($pdo, (int) $user['id'], $e164);
                    }
                    if ($sent) {
                        $_SESSION['fp_last_sent'] = time();
                        $notice = 'A new code has been sent.';
                    } else {
                        $error = 'We could not send the SMS right now. Please try again shortly.';
                    }
                } catch (Throwable $e) {
                    error_log('[forgot-password] ' . $e->getMessage());
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }

        /* ---------- Step 2: verify + set new password ---------- */
        elseif ($action === 'reset' && $step === 2) {
            $e164     = $_SESSION['fp_phone'];
            $otp      = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirm  = (string) ($_POST['confirm_password'] ?? '');

            if (strlen($otp) !== 6) {
                $error = 'Enter the 6-digit code we sent you.';
            } elseif (strlen($password) < FP_MIN_PASSWORD) {
                $error = 'Password must be at least ' . FP_MIN_PASSWORD . ' characters.';
            } elseif (strlen($password) > 200) {
                $error = 'Password is too long.';
            } elseif (!hash_equals($password, $confirm)) {
                $error = 'Passwords do not match.';
            } else {
                try {
                    $pdo = fp_db();
                    $st  = $pdo->prepare(
                        'SELECT id, user_id, otp_hash, attempts, (expires_at > NOW()) AS live
                         FROM password_resets WHERE phone = ? ORDER BY id DESC LIMIT 1'
                    );
                    $st->execute([$e164]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);

                    if (!$row || !(int) $row['live']) {
                        $error = 'That code is invalid or has expired. Request a new one.';
                    } elseif ((int) $row['attempts'] >= FP_MAX_ATTEMPTS) {
                        $error = 'Too many incorrect attempts. Please request a new code.';
                    } elseif (!hash_equals($row['otp_hash'], fp_hash_otp($e164, $otp))) {
                        $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
                        $error = 'Incorrect code. Please check the SMS and try again.';
                    } else {
                        $pdo->beginTransaction();
                        $upd = $pdo->prepare(
                            'UPDATE `' . FP_USERS_TABLE . '` SET `' . FP_PASSWORD_COLUMN . '` = ? WHERE `' . FP_ID_COLUMN . '` = ?'
                        );
                        $upd->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['user_id']]);
                        $pdo->prepare('DELETE FROM password_resets WHERE phone = ?')->execute([$e164]);
                        $pdo->commit();

                        // Lift any login lockout so they can sign in right away.
                        $em = $pdo->prepare('SELECT `' . FP_EMAIL_COLUMN . '` FROM `' . FP_USERS_TABLE . '` WHERE `' . FP_ID_COLUMN . '` = ?');
                        $em->execute([(int) $row['user_id']]);
                        $email = $em->fetchColumn();
                        if ($email) {
                            bienmarko_clear_login_throttle(bienmarko_normalize_email((string) $email));
                        }

                        unset($_SESSION['fp_phone'], $_SESSION['fp_last_sent']);
                        session_regenerate_id(true);
                        header('Location: login.php?reset=1');
                        exit;
                    }
                } catch (Throwable $e) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[forgot-password] ' . $e->getMessage());
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }

    $step     = isset($_SESSION['fp_phone']) ? 2 : 1;
    $maskedTo = $step === 2 ? fp_mask_phone($_SESSION['fp_phone']) : '';
}

if ($step === 2 && $notice === '' && $error === '' && isset($_GET['sent'])) {
    $notice = 'If that number is registered, a 6-digit code is on its way.';
}

$cooldownLeft = $step === 2
    ? max(0, FP_RESEND_SEC - (time() - (int) ($_SESSION['fp_last_sent'] ?? 0)))
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Forgot Password · <?php echo htmlspecialchars($siteName); ?></title>
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/login.css">
<style>
    .fp-ok { color: #34d399 !important; }
    .fp-otp { letter-spacing: .4em; text-align: center; font-weight: 600; font-size: 1.15rem; }
    .fp-sent-to { margin: 0 0 4px; font-size: .9rem; opacity: .8; }
    .fp-links { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px; font-size: .88rem; }
    .fp-linkbtn { background: none; border: 0; padding: 0; color: inherit; font: inherit; text-decoration: underline; cursor: pointer; }
    .fp-linkbtn:disabled { opacity: .5; cursor: not-allowed; text-decoration: none; }
</style>
</head>
<body>
<div class="lobby">
    <div class="lobby__glow" aria-hidden="true"></div>

    <div class="lobby__content">
        <div class="lobby__brand">
            <span class="lobby__mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 11l9-8 9 8"></path>
                    <path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"></path>
                </svg>
            </span>
            <div>
                <div class="lobby__brand-name"><?php echo htmlspecialchars($siteName); ?></div>
                <div class="lobby__brand-tagline"><?php echo htmlspecialchars($siteTagline); ?></div>
            </div>
        </div>

        <div class="lobby__card">
        <?php if ($step === 1): ?>

            <div class="lobby__heading">
                <h1>Forgot password?</h1>
                <p>Enter the mobile number on your account and we'll text you a verification code.</p>
            </div>

            <form class="login-form" method="POST" action="forgot-password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="send">

                <div class="hp-field" aria-hidden="true">
                    <label for="website">Leave this field blank</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="field">
                    <label for="phone">Mobile Number</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7A2 2 0 0 1 22 16.9z"></path>
                        </svg>
                        <input type="tel" id="phone" name="phone" placeholder="09171234567" required autocomplete="tel" inputmode="numeric" maxlength="20" autofocus>
                    </div>
                </div>

                <p class="login-form__status<?php echo $error !== '' ? ' is-visible' : ''; ?>" role="alert" aria-live="assertive"><?php echo htmlspecialchars($error); ?></p>

                <button type="submit" class="btn" id="fpSubmit">
                    <span class="btn__spinner" aria-hidden="true"></span>
                    <span class="btn__label">Send Code</span>
                </button>
            </form>

        <?php else: ?>

            <div class="lobby__heading">
                <h1>Reset password</h1>
                <p class="fp-sent-to">Code sent to <strong><?php echo htmlspecialchars($maskedTo); ?></strong></p>
                <p>Enter the 6-digit code and choose a new password.</p>
            </div>

            <form class="login-form" method="POST" action="forgot-password.php" id="resetForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="reset">

                <div class="hp-field" aria-hidden="true">
                    <label for="website">Leave this field blank</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="field">
                    <label for="otp">Verification Code</label>
                    <div class="field__control">
                        <input type="text" id="otp" name="otp" class="fp-otp" placeholder="••••••" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="password">New Password</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input class="has-trailing-icon" type="password" id="password" name="password" placeholder="At least 8 characters" required autocomplete="new-password" maxlength="200">
                        <button type="button" class="field__toggle-visibility" id="togglePassword" aria-pressed="false" aria-label="Show password">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 3l18 18"></path>
                                <path d="M10.6 10.6a3 3 0 0 0 4.24 4.24"></path>
                                <path d="M9.4 5.3A9.6 9.6 0 0 1 12 5c6.5 0 10 7 10 7a13.5 13.5 0 0 1-3.1 3.9M6.2 6.2A13.7 13.7 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 4.2-.9"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" required autocomplete="new-password" maxlength="200">
                    </div>
                </div>

                <p class="login-form__status<?php echo ($error !== '' || $notice !== '') ? ' is-visible' : ''; ?><?php echo ($error === '' && $notice !== '') ? ' fp-ok' : ''; ?>" id="fpStatus" role="alert" aria-live="assertive"><?php echo htmlspecialchars($error !== '' ? $error : $notice); ?></p>

                <button type="submit" class="btn" id="fpSubmit">
                    <span class="btn__spinner" aria-hidden="true"></span>
                    <span class="btn__label">Reset Password</span>
                </button>
            </form>

            <div class="fp-links">
                <form method="POST" action="forgot-password.php" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="fp-linkbtn" id="resendBtn" data-left="<?php echo (int) $cooldownLeft; ?>" <?php echo $cooldownLeft > 0 ? 'disabled' : ''; ?>>Resend code</button>
                </form>
                <a href="forgot-password.php?restart=1">Use a different number</a>
            </div>

        <?php endif; ?>
        </div>

        <p class="lobby__footnote">Remembered it? <a href="login.php">Back to log in</a></p>
    </div>
</div>

<script>
(function () {
    'use strict';

    var toggle = document.getElementById('togglePassword');
    var pw     = document.getElementById('password');
    if (toggle && pw) {
        toggle.addEventListener('click', function () {
            var show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    }

    var otp = document.getElementById('otp');
    if (otp) {
        otp.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    }

    var resend = document.getElementById('resendBtn');
    if (resend) {
        var left = parseInt(resend.getAttribute('data-left'), 10) || 0;
        if (left > 0) {
            var label = 'Resend code';
            var t = setInterval(function () {
                left--;
                if (left <= 0) {
                    clearInterval(t);
                    resend.disabled = false;
                    resend.textContent = label;
                } else {
                    resend.textContent = label + ' (' + left + 's)';
                }
            }, 1000);
            resend.textContent = label + ' (' + left + 's)';
        }
    }

    var forms = document.querySelectorAll('form.login-form');
    for (var i = 0; i < forms.length; i++) {
        forms[i].addEventListener('submit', function () {
            var b = this.querySelector('.btn');
            if (b) { b.disabled = true; b.classList.add('is-loading'); }
        });
    }
})();
</script>
</body>
</html>