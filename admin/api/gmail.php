<?php
/**
 * admin/api/gmail.php
 * -----------------------------------------------------------------------
 * JSON API behind Settings → Email (Gmail). Guarded exactly like
 * admin/api/settings.php — session + admin role + CSRF, checked before
 * anything submitted is looked at. Every request is POST with a JSON body
 * and an `action` field:
 *
 *   action=status      - connection state, redirect URI in use, recent sends
 *   action=send_test   - send a test email (to `to`, or the admin's own email)
 *   action=disconnect  - revoke at Google and wipe the stored tokens
 *
 * Connecting is not done here: the browser goes to ../../gmail_authorize.php.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/booking-emails.php';

header('Content-Type: application/json; charset=utf-8');

function gm_fail(string $msg, int $code = 200): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function gm_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gm_fail('Method not allowed.', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];

/* CSRF check — before anything submitted is looked at */
if (empty($body['csrf_token']) || !bienmarko_csrf_valid($body['csrf_token'])) {
    gm_fail('Invalid or expired security token.', 419);
}

$pdo    = bienmarko_db();
$action = trim((string) ($body['action'] ?? ''));

try {
    bienmarko_gmail_ensure_tables($pdo);
} catch (Throwable $e) {
    error_log('[bienmarko] gmail tables: ' . $e->getMessage());
    gm_fail('Could not prepare the email tables: ' . $e->getMessage());
}

/* ================================================================
   ACTION: status
   ================================================================ */
if ($action === 'status') {
    $recent = [];
    try {
        $recent = $pdo->query(
            'SELECT `kind`, `recipient`, `status`, `error`, `created_at`
             FROM `email_log` ORDER BY `id` DESC LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[bienmarko] email_log read: ' . $e->getMessage());
    }

    gm_ok(['gmail' => bienmarko_gmail_status($pdo), 'recent' => $recent]);
}

/* ================================================================
   ACTION: send_test
   ================================================================ */
if ($action === 'send_test') {
    $to = trim((string) ($body['to'] ?? ''));
    if ($to === '') {
        $stmt = $pdo->prepare('SELECT `email` FROM `admins` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => (int) ($_SESSION['auth_user_id'] ?? 0)]);
        $to = trim((string) $stmt->fetchColumn());
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || mb_strlen($to) > 190) {
        gm_fail('Enter a valid email address to send the test to.');
    }

    $status = bienmarko_gmail_status($pdo);
    if (!$status['connected']) {
        gm_fail($status['status'] === 'needs_reauth'
            ? 'The Gmail connection needs to be re-authorized. Click Reconnect first.'
            : 'Gmail is not connected yet. Click Connect Gmail first.');
    }

    $site    = bienmarko_email_site_info($pdo);
    $subject = 'Test email from ' . $site['name'];
    $sender  = $status['sender_email'] !== '' ? $status['sender_email'] : 'the connected Gmail account';
    $text    = "This is a test email from the {$site['name']} booking system.\n\n"
        . "If you can read this, booking confirmations, updates, cancellations and reminders will be delivered from {$sender}.";
    $html    = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px;line-height:1.55;color:#2b2620;">'
        . '<h2 style="margin:0 0 12px;color:#8a6a1f;">Test email</h2>'
        . '<p style="margin:0 0 12px;">This is a test email from the <strong>' . bienmarko_email_esc($site['name']) . '</strong> booking system.</p>'
        . '<p style="margin:0;">If you can read this, booking confirmations, updates, cancellations and reminders will be delivered from <strong>'
        . bienmarko_email_esc($sender) . '</strong>.</p></div>';

    $res = bienmarko_gmail_send($pdo, [
        'to'        => $to,
        'subject'   => $subject,
        'html'      => $html,
        'text'      => $text,
        'reply_to'  => $site['email'],
        'from_name' => $site['name'],
    ]);

    bienmarko_email_log($pdo, null, 'test', $to, $subject, $res['ok'] ? 'sent' : 'failed',
        $res['ok'] ? $res['id'] : null, $res['ok'] ? null : $res['error']);

    if (!$res['ok']) {
        gm_fail($res['error']);
    }
    gm_ok(['sent_to' => $to]);
}

/* ================================================================
   ACTION: disconnect
   ================================================================ */
if ($action === 'disconnect') {
    try {
        bienmarko_gmail_disconnect($pdo);
    } catch (Throwable $e) {
        error_log('[bienmarko] gmail disconnect: ' . $e->getMessage());
        gm_fail('Could not disconnect Gmail: ' . $e->getMessage());
    }
    gm_ok(['gmail' => bienmarko_gmail_status($pdo)]);
}

gm_fail('Unknown action.', 400);
