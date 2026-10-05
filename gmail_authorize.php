<?php
/**
 * gmail_authorize.php
 * -----------------------------------------------------------------------
 * Step 1 of the one-time "Connect Gmail" flow. Admin-only. Sends the
 * signed-in admin to Google's consent screen (always with the account
 * picker, so the sending account can be switched at any time). Google
 * then returns to gmail_callback.php.
 *
 * Opened from Settings → Email (Gmail) → Connect Gmail / Reconnect.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-common.php';
require_once __DIR__ . '/includes/gmail-mailer.php';

bienmarko_start_session();

// Root-level script: check the admin session directly (same keys the admin
// portal uses) rather than relying on a role-guard redirect path.
if (($_SESSION['auth_role'] ?? '') !== 'admin' || empty($_SESSION['auth_user_id'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Admin sign-in required</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:480px;margin:12vh auto;padding:0 20px;line-height:1.5">'
        . '<h2>Admin sign-in required</h2>'
        . '<p>Please sign in as an administrator, then open <strong>Settings → Email (Gmail)</strong> and click '
        . '<strong>Connect Gmail</strong>.</p></body>';
    exit;
}

$back = static function (string $msg): never {
    header('Location: admin/settings.php?gmail_error=' . rawurlencode(mb_substr($msg, 0, 300)));
    exit;
};

if (!bienmarko_gmail_is_configured()) {
    $back('Gmail is not configured yet: add the Client ID and Client Secret to includes/gmail-config.php.');
}
if (bienmarko_gmail_redirect_uri() === '') {
    $back('Could not work out the redirect URI. Set "redirect_uri" in includes/gmail-config.php.');
}

// One-time anti-CSRF state, bound to this admin session.
$state = bin2hex(random_bytes(16));
$_SESSION['gmail_oauth_state']    = $state;
$_SESSION['gmail_oauth_state_at'] = time();

header('Cache-Control: no-store');
header('Location: ' . bienmarko_gmail_auth_url($state));
exit;
