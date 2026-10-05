<?php
/**
 * includes/gmail-mailer.php
 * -----------------------------------------------------------------------
 * Gmail API transport for Bien Marko's booking system.
 *
 *   - OAuth 2.0 web-server flow (authorize + code exchange + refresh)
 *   - refresh/access tokens stored AES-256-GCM encrypted in `gmail_oauth`
 *   - sends mail with users.messages.send (scope: gmail.send) over cURL —
 *     no Composer / no Google client library
 *   - every attempt is recorded in `email_log`
 *
 * Nothing in here ever throws into the caller's booking flow: send helpers
 * return ['ok' => bool, 'error' => string] instead.
 *
 * Tables are created additively (CREATE TABLE IF NOT EXISTS), the same way
 * the rest of the project self-migrates.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

const BIENMARKO_GMAIL_SCOPE_SEND = 'https://www.googleapis.com/auth/gmail.send';

/* ======================================================================
   Config
   ====================================================================== */

/**
 * Merged settings from includes/gmail-config.php (cached per request).
 *
 * @return array<string,mixed>
 */
function bienmarko_gmail_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $defaults = [
        'client_id'            => '',
        'client_secret'        => '',
        'redirect_uri'         => '',
        'site_url'             => '',
        'reminder_days_before' => 1,
        'cron_key'             => '',
        'ca_bundle'            => '',
        'timeout'              => 15,
        'endpoints'            => [],
    ];

    $loaded = [];
    $file   = __DIR__ . '/gmail-config.php';
    if (is_file($file)) {
        $loaded = (static function (string $f) {
            return require $f;
        })($file);
    }

    $cfg = array_merge($defaults, is_array($loaded) ? $loaded : []);
    return $cfg;
}

/** True once a real Client ID + Secret have been filled in. */
function bienmarko_gmail_is_configured(): bool
{
    $cfg    = bienmarko_gmail_config();
    $id     = trim((string) $cfg['client_id']);
    $secret = trim((string) $cfg['client_secret']);

    return $id !== ''
        && str_contains($id, '.apps.googleusercontent.com')
        && $secret !== ''
        && stripos($secret, 'PASTE_') !== 0;
}

/** Google endpoint URL (defaults overridable via config['endpoints']). */
function bienmarko_gmail_endpoint(string $name): string
{
    static $defaults = [
        'auth'   => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token'  => 'https://oauth2.googleapis.com/token',
        'revoke' => 'https://oauth2.googleapis.com/revoke',
        'send'   => 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
    ];

    $cfg      = bienmarko_gmail_config();
    $override = is_array($cfg['endpoints']) ? ($cfg['endpoints'][$name] ?? '') : '';

    return (is_string($override) && $override !== '') ? $override : $defaults[$name];
}

/**
 * Public base URL of the site with no trailing slash
 * (e.g. "http://localhost/Bookingsystem" or "https://example.com").
 * Returns '' when it cannot be determined (CLI with no site_url set).
 */
function bienmarko_gmail_site_base(): string
{
    $cfg = bienmarko_gmail_config();
    $set = trim((string) $cfg['site_url']);
    if ($set !== '') {
        return rtrim($set, '/');
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^(?:[A-Za-z0-9.\-]+|\[[0-9A-Fa-f:]+\])(?::\d{1,5})?$/', $host)) {
        return '';
    }

    $hostname = strtolower((string) preg_replace('/:\d+$/', '', $host));
    $isLocal  = in_array($hostname, ['localhost', '127.0.0.1', '[::1]'], true);

    if ($isLocal) {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $scheme = $https ? 'https' : 'http';
    } else {
        // Google only accepts https redirect URIs for non-localhost hosts.
        $scheme = 'https';
    }

    // Web path of the project root, derived from the document root so it is
    // the same no matter which script triggered the call.
    $path = '';
    $root = realpath(__DIR__ . '/..');
    $doc  = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($root !== false && $doc !== false) {
        $r = str_replace('\\', '/', $root);
        $d = rtrim(str_replace('\\', '/', $doc), '/');
        if ($d !== '' && stripos($r, $d) === 0) {
            $rest = substr($r, strlen($d));
            if ($rest === '' || $rest[0] === '/') {
                $path = $rest;
            }
        }
    }

    return $scheme . '://' . $host . $path;
}

/** The redirect URI sent to Google (must be registered on the OAuth client). */
function bienmarko_gmail_redirect_uri(): string
{
    $cfg = bienmarko_gmail_config();
    $set = trim((string) $cfg['redirect_uri']);
    if ($set !== '') {
        return $set;
    }
    $base = bienmarko_gmail_site_base();
    return $base === '' ? '' : $base . '/gmail_callback.php';
}

/* ======================================================================
   Tables
   ====================================================================== */

function bienmarko_gmail_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    // Single fixed row (id = 1): one pension house, one sending account.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `gmail_oauth` (
            `id`                TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `sender_email`      VARCHAR(190) NOT NULL DEFAULT '',
            `refresh_token_enc` TEXT NULL,
            `access_token_enc`  TEXT NULL,
            `access_expires_ts` INT UNSIGNED NOT NULL DEFAULT 0,
            `scope`             VARCHAR(500) NOT NULL DEFAULT '',
            `status`            ENUM('disconnected','connected','needs_reauth') NOT NULL DEFAULT 'disconnected',
            `last_error`        VARCHAR(500) NULL DEFAULT NULL,
            `connected_at`      DATETIME NULL DEFAULT NULL,
            `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec('INSERT IGNORE INTO `gmail_oauth` (`id`) VALUES (1)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `email_log` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `booking_id`       INT UNSIGNED NULL DEFAULT NULL,
            `kind`             VARCHAR(30)  NOT NULL DEFAULT 'confirmation',
            `recipient`        VARCHAR(190) NOT NULL DEFAULT '',
            `subject`          VARCHAR(255) NOT NULL DEFAULT '',
            `status`           ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
            `gmail_message_id` VARCHAR(64)  NULL DEFAULT NULL,
            `error`            VARCHAR(500) NULL DEFAULT NULL,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_booking_kind` (`booking_id`, `kind`, `status`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

/** @return array<string,mixed>|null */
function bienmarko_gmail_row(PDO $pdo): ?array
{
    bienmarko_gmail_ensure_tables($pdo);
    $row = $pdo->query('SELECT * FROM `gmail_oauth` WHERE `id` = 1')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/* ======================================================================
   Token encryption (AES-256-GCM, key derived from the client secret)
   ====================================================================== */

function bienmarko_gmail_crypto_available(): bool
{
    return function_exists('openssl_encrypt')
        && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
}

function bienmarko_gmail_crypto_key(): string
{
    $cfg = bienmarko_gmail_config();
    return hash('sha256', 'bienmarko-gmail-token|' . (string) $cfg['client_secret'], true);
}

function bienmarko_gmail_encrypt(string $plain): string
{
    if (!bienmarko_gmail_crypto_available()) {
        throw new RuntimeException('PHP OpenSSL (aes-256-gcm) is not available on this server.');
    }
    $iv  = random_bytes(12);
    $tag = '';
    $enc = openssl_encrypt($plain, 'aes-256-gcm', bienmarko_gmail_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($enc === false) {
        throw new RuntimeException('Could not encrypt the Gmail token.');
    }
    return 'v1:' . base64_encode($iv . $tag . $enc);
}

function bienmarko_gmail_decrypt(?string $blob): ?string
{
    if ($blob === null || $blob === '' || strncmp($blob, 'v1:', 3) !== 0 || !bienmarko_gmail_crypto_available()) {
        return null;
    }
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        bienmarko_gmail_crypto_key(),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    return $plain === false ? null : $plain;
}

/* ======================================================================
   HTTP (cURL)
   ====================================================================== */

/**
 * CA bundle to hand to cURL, or null to use PHP's own default. Only kicks
 * in when php.ini has no curl.cainfo / openssl.cafile — the usual cause of
 * "SSL certificate problem" on a fresh XAMPP.
 */
function bienmarko_gmail_ca_bundle(): ?string
{
    $cfg = bienmarko_gmail_config();
    $set = trim((string) $cfg['ca_bundle']);
    if ($set !== '' && is_file($set)) {
        return $set;
    }

    foreach (['curl.cainfo', 'openssl.cafile'] as $ini) {
        $v = (string) ini_get($ini);
        if ($v !== '' && is_file($v)) {
            return null;
        }
    }

    $bin = (string) PHP_BINARY;
    $candidates = [];
    if ($bin !== '') {
        $candidates[] = dirname($bin) . '/curl-ca-bundle.crt';          // Apache module (XAMPP)
        $candidates[] = dirname($bin) . '/extras/ssl/cacert.pem';       // php.exe / php-cgi (XAMPP)
        $candidates[] = dirname($bin, 2) . '/php/extras/ssl/cacert.pem';
        $candidates[] = dirname($bin, 2) . '/apache/bin/curl-ca-bundle.crt';
    }
    $candidates[] = 'C:/xampp/php/extras/ssl/cacert.pem';
    $candidates[] = 'C:/xampp/apache/bin/curl-ca-bundle.crt';

    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
}

/**
 * Minimal JSON/form HTTP client.
 *
 * @param  string[] $headers
 * @return array{status:int,body:string,json:mixed,error:string}
 */
function bienmarko_gmail_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $out = ['status' => 0, 'body' => '', 'json' => null, 'error' => ''];

    if (!function_exists('curl_init')) {
        $out['error'] = 'The PHP cURL extension is not enabled on this server.';
        return $out;
    }

    $cfg = bienmarko_gmail_config();
    $ch  = curl_init($url);
    $opt = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => max(5, (int) $cfg['timeout']),
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($body !== null) {
        $opt[CURLOPT_POSTFIELDS] = $body;
    }
    $ca = bienmarko_gmail_ca_bundle();
    if ($ca !== null) {
        $opt[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opt);

    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($resp === false) {
        if (in_array($errno, [58, 60, 77, 35], true)) {
            $out['error'] = 'SSL certificate problem talking to Google (' . $err . '). On XAMPP, set curl.cainfo '
                . 'in php.ini or the "ca_bundle" option in includes/gmail-config.php.';
        } elseif ($errno === 6 || $errno === 7 || $errno === 28) {
            $out['error'] = 'Could not reach Google (' . $err . '). Check that the server has internet access.';
        } else {
            $out['error'] = 'Network error talking to Google (' . $err . ').';
        }
        return $out;
    }

    $out['body'] = (string) $resp;
    $json        = json_decode($out['body'], true);
    $out['json'] = (json_last_error() === JSON_ERROR_NONE) ? $json : null;
    return $out;
}

/* ======================================================================
   OAuth
   ====================================================================== */

/** URL to send the admin to for the one-time "Allow" step. */
function bienmarko_gmail_auth_url(string $state): string
{
    $cfg = bienmarko_gmail_config();
    return bienmarko_gmail_endpoint('auth') . '?' . http_build_query([
        'client_id'     => (string) $cfg['client_id'],
        'redirect_uri'  => bienmarko_gmail_redirect_uri(),
        'response_type' => 'code',
        // openid + email lets us record WHICH account was connected.
        'scope'         => 'openid email ' . BIENMARKO_GMAIL_SCOPE_SEND,
        'access_type'   => 'offline',                // we need a refresh token
        'prompt'        => 'select_account consent', // always show the account picker
        'state'         => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Plain-English version of a Google OAuth error code. */
function bienmarko_gmail_explain_error(string $code, string $desc = ''): string
{
    switch ($code) {
        case 'invalid_client':
        case 'unauthorized_client':
            return 'Google rejected the Client ID / Client Secret. Check includes/gmail-config.php against the OAuth client in Google Cloud.';
        case 'invalid_grant':
            return 'Google says this authorization is expired or was revoked. Click Connect Gmail again.';
        case 'redirect_uri_mismatch':
            return 'The redirect URI does not match the OAuth client. Add "' . bienmarko_gmail_redirect_uri()
                . '" under Authorized redirect URIs in Google Cloud.';
        case 'access_denied':
            return 'Google sign-in was cancelled or the account is not an approved test user.';
        default:
            $d = trim($desc);
            return 'Google returned an error (' . ($code !== '' ? $code : 'unknown') . ')' . ($d !== '' ? ': ' . $d : '.');
    }
}

/**
 * POST to the token endpoint.
 *
 * @param  array<string,string> $params
 * @return array{ok:bool,data:array<string,mixed>,error:string,code:string}
 */
function bienmarko_gmail_token_request(array $params): array
{
    $cfg  = bienmarko_gmail_config();
    $body = http_build_query($params + [
        'client_id'     => (string) $cfg['client_id'],
        'client_secret' => (string) $cfg['client_secret'],
    ]);

    $r = bienmarko_gmail_http('POST', bienmarko_gmail_endpoint('token'), [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], $body);

    if ($r['error'] !== '') {
        return ['ok' => false, 'data' => [], 'error' => $r['error'], 'code' => 'network'];
    }

    $j = is_array($r['json']) ? $r['json'] : [];
    if ($r['status'] === 200 && isset($j['access_token'])) {
        return ['ok' => true, 'data' => $j, 'error' => '', 'code' => ''];
    }

    $code = (string) ($j['error'] ?? ('http_' . $r['status']));
    $desc = (string) ($j['error_description'] ?? '');
    return ['ok' => false, 'data' => $j, 'error' => bienmarko_gmail_explain_error($code, $desc), 'code' => $code];
}

/** Email address inside a Google id_token (no signature check needed: it came straight from Google over TLS). */
function bienmarko_gmail_email_from_id_token(string $idToken): string
{
    $parts = explode('.', $idToken);
    if (count($parts) < 2) {
        return '';
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    $claims  = is_string($payload) ? json_decode($payload, true) : null;
    $email   = is_array($claims) ? (string) ($claims['email'] ?? '') : '';
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/**
 * Save the tokens from a successful authorization-code exchange.
 *
 * @param  array<string,mixed> $tok
 * @return array{ok:bool,error:string,email:string}
 */
function bienmarko_gmail_store_connection(PDO $pdo, array $tok): array
{
    $scope = (string) ($tok['scope'] ?? '');
    if ($scope !== '' && !str_contains($scope, 'gmail.send')) {
        return ['ok' => false, 'email' => '', 'error' => 'The "Send email on your behalf" permission was not granted. '
            . 'On the Google screen, leave that box ticked and click Allow.'];
    }

    $refresh = (string) ($tok['refresh_token'] ?? '');
    if ($refresh === '') {
        return ['ok' => false, 'email' => '', 'error' => 'Google did not return a refresh token. Remove "BienMarko\'s Booking System" at '
            . 'myaccount.google.com/permissions, then click Connect Gmail again.'];
    }

    if (!bienmarko_gmail_crypto_available()) {
        return ['ok' => false, 'email' => '', 'error' => 'PHP OpenSSL (aes-256-gcm) is not available, so the token cannot be stored safely.'];
    }

    $email   = bienmarko_gmail_email_from_id_token((string) ($tok['id_token'] ?? ''));
    $expires = time() + max(60, (int) ($tok['expires_in'] ?? 3600));

    bienmarko_gmail_ensure_tables($pdo);
    $stmt = $pdo->prepare(
        "UPDATE `gmail_oauth` SET
            `sender_email`      = :email,
            `refresh_token_enc` = :rt,
            `access_token_enc`  = :at,
            `access_expires_ts` = :exp,
            `scope`             = :scope,
            `status`            = 'connected',
            `last_error`        = NULL,
            `connected_at`      = NOW()
         WHERE `id` = 1"
    );
    $stmt->execute([
        'email' => $email,
        'rt'    => bienmarko_gmail_encrypt($refresh),
        'at'    => bienmarko_gmail_encrypt((string) $tok['access_token']),
        'exp'   => $expires,
        'scope' => mb_substr($scope, 0, 500),
    ]);

    // A fresh connection clears any stale "reconnect needed" alert.
    try {
        $pdo->prepare("DELETE FROM `admin_notifications` WHERE `type` = 'system' AND `message` LIKE 'Gmail connection expired%'")->execute();
    } catch (Throwable $e) {
        // table may not exist yet — irrelevant here
    }

    return ['ok' => true, 'error' => '', 'email' => $email];
}

function bienmarko_gmail_mark_needs_reauth(PDO $pdo, string $reason): void
{
    try {
        bienmarko_gmail_ensure_tables($pdo);
        $pdo->prepare(
            "UPDATE `gmail_oauth` SET `status` = 'needs_reauth', `last_error` = :e,
                    `access_token_enc` = NULL, `access_expires_ts` = 0
             WHERE `id` = 1"
        )->execute(['e' => mb_substr($reason, 0, 500)]);
    } catch (Throwable $e) {
        error_log('[bienmarko] gmail mark_needs_reauth failed: ' . $e->getMessage());
    }
}

/** One unread dashboard alert (not a flood) when the connection breaks. */
function bienmarko_gmail_notify_admin_reauth(PDO $pdo): void
{
    $msg = 'Gmail connection expired — booking emails are paused. Open Settings → Email (Gmail) and click Reconnect.';
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM `admin_notifications` WHERE `type` = 'system' AND `message` = :m AND `is_read` = 0");
        $chk->execute(['m' => $msg]);
        if ((int) $chk->fetchColumn() === 0) {
            // booking_id is NOT NULL in this table, so 0 = "no related booking"
            // (the notification bell already treats that as not clickable).
            $pdo->prepare("INSERT INTO `admin_notifications` (`booking_id`, `type`, `message`, `is_read`) VALUES (0, 'system', :m, 0)")
                ->execute(['m' => $msg]);
        }
    } catch (Throwable $e) {
        error_log('[bienmarko] gmail admin notification failed: ' . $e->getMessage());
    }
}

/**
 * Current connection state for the Settings panel and for send guards.
 *
 * @return array<string,mixed>
 */
function bienmarko_gmail_status(PDO $pdo): array
{
    $out = [
        'configured'   => bienmarko_gmail_is_configured(),
        'status'       => 'disconnected',
        'connected'    => false,
        'sender_email' => '',
        'connected_at' => null,
        'last_error'   => '',
        'redirect_uri' => bienmarko_gmail_redirect_uri(),
        'crypto_ok'    => bienmarko_gmail_crypto_available(),
    ];

    try {
        $row = bienmarko_gmail_row($pdo);
        if ($row) {
            $out['status']       = (string) $row['status'];
            $out['connected']    = $row['status'] === 'connected' && (string) $row['refresh_token_enc'] !== '';
            $out['sender_email'] = (string) $row['sender_email'];
            $out['connected_at'] = $row['connected_at'];
            $out['last_error']   = (string) ($row['last_error'] ?? '');
        }
    } catch (Throwable $e) {
        $out['last_error'] = 'Could not read the Gmail connection: ' . $e->getMessage();
    }

    return $out;
}

/**
 * A usable access token — cached one if still valid, otherwise refreshed.
 *
 * @return array{token:?string,error:string}
 */
function bienmarko_gmail_access_token(PDO $pdo, bool $forceRefresh = false): array
{
    if (!bienmarko_gmail_is_configured()) {
        return ['token' => null, 'error' => 'Gmail is not configured (Client ID / Secret missing in includes/gmail-config.php).'];
    }

    $row = bienmarko_gmail_row($pdo);
    if (!$row || $row['status'] === 'disconnected' || (string) $row['refresh_token_enc'] === '') {
        return ['token' => null, 'error' => 'Gmail is not connected. An admin can connect it in Settings → Email (Gmail).'];
    }
    if ($row['status'] === 'needs_reauth') {
        return ['token' => null, 'error' => 'The Gmail connection needs to be re-authorized (Settings → Email (Gmail) → Reconnect).'];
    }

    if (!$forceRefresh && (int) $row['access_expires_ts'] > time() + 60) {
        $cached = bienmarko_gmail_decrypt((string) $row['access_token_enc']);
        if ($cached !== null && $cached !== '') {
            return ['token' => $cached, 'error' => ''];
        }
    }

    $refresh = bienmarko_gmail_decrypt((string) $row['refresh_token_enc']);
    if ($refresh === null || $refresh === '') {
        $why = 'The stored Gmail token could not be read (the Client Secret was probably changed). Reconnect Gmail.';
        bienmarko_gmail_mark_needs_reauth($pdo, $why);
        bienmarko_gmail_notify_admin_reauth($pdo);
        return ['token' => null, 'error' => $why];
    }

    $res = bienmarko_gmail_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    if (!$res['ok']) {
        if ($res['code'] === 'invalid_grant' || $res['code'] === 'invalid_client') {
            bienmarko_gmail_mark_needs_reauth($pdo, $res['error']);
            bienmarko_gmail_notify_admin_reauth($pdo);
        }
        return ['token' => null, 'error' => $res['error']];
    }

    $access  = (string) $res['data']['access_token'];
    $expires = time() + max(60, (int) ($res['data']['expires_in'] ?? 3600));
    try {
        $pdo->prepare('UPDATE `gmail_oauth` SET `access_token_enc` = :at, `access_expires_ts` = :exp, `last_error` = NULL WHERE `id` = 1')
            ->execute(['at' => bienmarko_gmail_encrypt($access), 'exp' => $expires]);
        if (!empty($res['data']['refresh_token'])) {
            $pdo->prepare('UPDATE `gmail_oauth` SET `refresh_token_enc` = :rt WHERE `id` = 1')
                ->execute(['rt' => bienmarko_gmail_encrypt((string) $res['data']['refresh_token'])]);
        }
    } catch (Throwable $e) {
        error_log('[bienmarko] gmail token cache write failed: ' . $e->getMessage());
    }

    return ['token' => $access, 'error' => ''];
}

/**
 * Disconnect: revoke at Google (best effort) and wipe the stored tokens.
 */
function bienmarko_gmail_disconnect(PDO $pdo): void
{
    $row = bienmarko_gmail_row($pdo);
    if ($row && (string) $row['refresh_token_enc'] !== '') {
        $rt = bienmarko_gmail_decrypt((string) $row['refresh_token_enc']);
        if ($rt !== null && $rt !== '') {
            bienmarko_gmail_http('POST', bienmarko_gmail_endpoint('revoke'), [
                'Content-Type: application/x-www-form-urlencoded',
            ], http_build_query(['token' => $rt]));
        }
    }

    $pdo->exec(
        "UPDATE `gmail_oauth` SET
            `sender_email` = '', `refresh_token_enc` = NULL, `access_token_enc` = NULL,
            `access_expires_ts` = 0, `scope` = '', `status` = 'disconnected',
            `last_error` = NULL, `connected_at` = NULL
         WHERE `id` = 1"
    );
}

/* ======================================================================
   MIME
   ====================================================================== */

/** Strip CR/LF/control characters so user-supplied text can't inject headers. */
function bienmarko_gmail_clean_header(string $v): string
{
    return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v));
}

/** RFC 2047 encode a header value when it contains non-ASCII. */
function bienmarko_gmail_encode_header(string $v): string
{
    $v = bienmarko_gmail_clean_header($v);
    if ($v === '' || !preg_match('/[^\x20-\x7E]/', $v)) {
        return $v;
    }

    $words = [];
    $cur   = '';
    foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (strlen($cur) + strlen($ch) > 42) {
            $words[] = $cur;
            $cur     = '';
        }
        $cur .= $ch;
    }
    if ($cur !== '') {
        $words[] = $cur;
    }

    return implode("\r\n ", array_map(
        static fn (string $w): string => '=?UTF-8?B?' . base64_encode($w) . '?=',
        $words
    ));
}

/** "Name" <email> address header. */
function bienmarko_gmail_addr(string $name, string $email): string
{
    $email = bienmarko_gmail_clean_header($email);
    $name  = bienmarko_gmail_clean_header($name);
    if ($name === '') {
        return $email;
    }
    if (preg_match('/[^\x20-\x7E]/', $name)) {
        return bienmarko_gmail_encode_header($name) . ' <' . $email . '>';
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '" <' . $email . '>';
}

/**
 * Build a multipart/alternative (text + HTML) RFC 5322 message.
 *
 * @param array{to:string,to_name?:string,subject:string,html:string,text:string,reply_to?:string} $m
 */
function bienmarko_gmail_build_mime(array $m, string $fromEmail, string $fromName): string
{
    $crlf = "\r\n";
    $h    = [];
    $h[]  = 'Date: ' . date('r');
    if ($fromEmail !== '') {
        $h[] = 'From: ' . bienmarko_gmail_addr($fromName, $fromEmail);
    }
    $h[] = 'To: ' . bienmarko_gmail_addr((string) ($m['to_name'] ?? ''), (string) $m['to']);

    $reply = trim((string) ($m['reply_to'] ?? ''));
    if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
        $h[] = 'Reply-To: ' . bienmarko_gmail_addr('', $reply);
    }

    $h[] = 'Subject: ' . bienmarko_gmail_encode_header((string) $m['subject']);
    $h[] = 'MIME-Version: 1.0';

    $boundary = 'bm_' . bin2hex(random_bytes(12));
    $h[]      = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $part = static function (string $type, string $content) use ($boundary, $crlf): string {
        return '--' . $boundary . $crlf
            . 'Content-Type: ' . $type . '; charset=UTF-8' . $crlf
            . 'Content-Transfer-Encoding: base64' . $crlf . $crlf
            . chunk_split(base64_encode($content), 76, $crlf);
    };

    return implode($crlf, $h) . $crlf . $crlf
        . $part('text/plain', (string) $m['text'])
        . $part('text/html', (string) $m['html'])
        . '--' . $boundary . '--' . $crlf;
}

/* ======================================================================
   Send + log
   ====================================================================== */

/** Record an email attempt. Never throws. */
function bienmarko_email_log(
    PDO $pdo,
    ?int $bookingId,
    string $kind,
    string $to,
    string $subject,
    string $status,
    ?string $gmailId = null,
    ?string $error = null
): void {
    try {
        bienmarko_gmail_ensure_tables($pdo);
        $pdo->prepare(
            'INSERT INTO `email_log` (`booking_id`, `kind`, `recipient`, `subject`, `status`, `gmail_message_id`, `error`)
             VALUES (:bid, :kind, :to, :subject, :status, :gid, :err)'
        )->execute([
            'bid'     => $bookingId,
            'kind'    => mb_substr($kind, 0, 30),
            'to'      => mb_substr($to, 0, 190),
            'subject' => mb_substr($subject, 0, 255),
            'status'  => in_array($status, ['sent', 'failed', 'skipped'], true) ? $status : 'failed',
            'gid'     => $gmailId !== null ? mb_substr($gmailId, 0, 64) : null,
            'err'     => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[bienmarko] email_log insert failed: ' . $e->getMessage());
    }
}

/**
 * Send one email through the Gmail API.
 *
 * @param  array{to:string,to_name?:string,subject:string,html:string,text:string,reply_to?:string,from_name?:string} $m
 * @return array{ok:bool,id:string,error:string}
 */
function bienmarko_gmail_send(PDO $pdo, array $m): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'id' => '', 'error' => $e];

    $to = trim((string) ($m['to'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return $fail('Invalid recipient email address.');
    }
    $m['to'] = $to;

    try {
        $tok = bienmarko_gmail_access_token($pdo);
        if ($tok['token'] === null) {
            return $fail($tok['error']);
        }

        $row  = bienmarko_gmail_row($pdo);
        $mime = bienmarko_gmail_build_mime(
            $m,
            (string) ($row['sender_email'] ?? ''),
            (string) ($m['from_name'] ?? '')
        );
        $payload = json_encode(['raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '=')]);
        if ($payload === false) {
            return $fail('Could not encode the message.');
        }

        $token = $tok['token'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $r = bienmarko_gmail_http('POST', bienmarko_gmail_endpoint('send'), [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ], $payload);

            if ($r['error'] !== '') {
                return $fail($r['error']);
            }

            if ($r['status'] === 401 && $attempt === 0) {
                // Cached token rejected — refresh once and retry.
                $fresh = bienmarko_gmail_access_token($pdo, true);
                if ($fresh['token'] === null) {
                    return $fail($fresh['error']);
                }
                $token = $fresh['token'];
                continue;
            }

            if ($r['status'] === 200 && is_array($r['json'])) {
                return ['ok' => true, 'id' => (string) ($r['json']['id'] ?? ''), 'error' => ''];
            }

            return $fail(bienmarko_gmail_explain_send_error($pdo, $r['status'], $r['json']));
        }

        return $fail('Gmail rejected the access token.');
    } catch (Throwable $e) {
        error_log('[bienmarko] gmail send exception: ' . $e->getMessage());
        return $fail('Unexpected error while sending: ' . $e->getMessage());
    }
}

/** Turn a failed messages.send response into a message an admin can act on. */
function bienmarko_gmail_explain_send_error(PDO $pdo, int $status, $json): string
{
    $err    = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
    $msg    = (string) ($err['message'] ?? '');
    $reason = (string) ($err['errors'][0]['reason'] ?? ($err['status'] ?? ''));

    if (stripos($msg, 'has not been used in project') !== false || stripos($msg, 'is disabled') !== false || $reason === 'accessNotConfigured') {
        return 'The Gmail API is not enabled for this Google Cloud project. Enable it under APIs & Services → Library → Gmail API.';
    }
    if ($status === 401 || $reason === 'authError' || $reason === 'UNAUTHENTICATED') {
        bienmarko_gmail_mark_needs_reauth($pdo, 'Google rejected the stored credentials.');
        bienmarko_gmail_notify_admin_reauth($pdo);
        return 'Google rejected the stored credentials. Reconnect Gmail in Settings.';
    }
    if ($status === 403 && ($reason === 'insufficientPermissions' || stripos($msg, 'insufficient') !== false || stripos($msg, 'scope') !== false)) {
        bienmarko_gmail_mark_needs_reauth($pdo, 'The connected account has not granted permission to send email.');
        bienmarko_gmail_notify_admin_reauth($pdo);
        return 'The connected account has not granted permission to send email. Reconnect Gmail and keep the permission ticked.';
    }
    if ($status === 429 || stripos($reason, 'rateLimit') !== false || stripos($reason, 'quota') !== false || stripos($msg, 'limit') !== false) {
        return 'Gmail sending limit reached. Try again later.';
    }
    if ($status === 400) {
        return 'Gmail rejected the message' . ($msg !== '' ? ': ' . $msg : '.');
    }

    return 'Gmail returned HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '.');
}
