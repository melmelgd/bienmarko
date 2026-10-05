<?php
/**
 * gmail_callback.php
 * -----------------------------------------------------------------------
 * Step 2 of the one-time "Connect Gmail" flow. Google redirects here after
 * the admin clicks Allow. This verifies the anti-CSRF state, swaps the
 * one-time code for tokens, stores them encrypted (gmail_oauth table), and
 * sends the admin back to Settings.
 *
 * The URL of this file is the "Authorized redirect URI" registered on the
 * OAuth client in Google Cloud.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-common.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/gmail-mailer.php';

bienmarko_start_session();

$finish = static function (string $key, string $value): never {
    header('Cache-Control: no-store');
    header('Location: admin/settings.php?' . $key . '=' . rawurlencode(mb_substr($value, 0, 300)));
    exit;
};

if (($_SESSION['auth_role'] ?? '') !== 'admin' || empty($_SESSION['auth_user_id'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Admin session not found</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:480px;margin:12vh auto;padding:0 20px;line-height:1.5">'
        . '<h2>Admin session not found</h2>'
        . '<p>Your admin sign-in was not detected on this page. Sign in as an administrator, then open '
        . '<strong>Settings → Email (Gmail)</strong> and click <strong>Connect Gmail</strong> again.</p></body>';
    exit;
}

// The state is single-use: read it, then drop it whatever happens next.
$expected = (string) ($_SESSION['gmail_oauth_state'] ?? '');
$startedAt = (int) ($_SESSION['gmail_oauth_state_at'] ?? 0);
unset($_SESSION['gmail_oauth_state'], $_SESSION['gmail_oauth_state_at']);

$state = (string) ($_GET['state'] ?? '');
if ($expected === '' || $state === '' || !hash_equals($expected, $state) || (time() - $startedAt) > 600) {
    $finish('gmail_error', 'The sign-in link expired or was not valid. Click Connect Gmail to start again.');
}

$googleError = (string) ($_GET['error'] ?? '');
if ($googleError !== '') {
    $finish('gmail_error', bienmarko_gmail_explain_error($googleError, (string) ($_GET['error_description'] ?? '')));
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    $finish('gmail_error', 'Google did not send back an authorization code. Click Connect Gmail to try again.');
}

if (!bienmarko_gmail_is_configured()) {
    $finish('gmail_error', 'Gmail is not configured: add the Client ID and Client Secret to includes/gmail-config.php.');
}

$res = bienmarko_gmail_token_request([
    'grant_type'   => 'authorization_code',
    'code'         => $code,
    'redirect_uri' => bienmarko_gmail_redirect_uri(),
]);
if (!$res['ok']) {
    $finish('gmail_error', $res['error']);
}

try {
    $saved = bienmarko_gmail_store_connection(bienmarko_db(), $res['data']);
} catch (Throwable $e) {
    error_log('[bienmarko] gmail store_connection failed: ' . $e->getMessage());
    $finish('gmail_error', 'Connected to Google, but saving the connection failed: ' . $e->getMessage());
}

if (!$saved['ok']) {
    $finish('gmail_error', $saved['error']);
}

$finish('gmail', 'connected');
