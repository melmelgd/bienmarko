<?php
/**
 * cron/send-reminders.php
 * -----------------------------------------------------------------------
 * Sends the "your stay is coming up" reminder email for every confirmed
 * booking whose check-in is N days away (N = reminder_days_before in
 * includes/gmail-config.php, default 1). Safe to run as often as you like:
 * a booking is only ever sent ONE reminder (failed attempts retry, max 3).
 *
 * Run it once a day, e.g. every morning:
 *
 *   Command line (XAMPP / most hosts):
 *     php /path/to/Bookingsystem/cron/send-reminders.php
 *     php /path/to/Bookingsystem/cron/send-reminders.php --days=2
 *     php /path/to/Bookingsystem/cron/send-reminders.php --dry-run
 *
 *   By URL (needs "cron_key" set in includes/gmail-config.php):
 *     https://YOUR-DOMAIN/cron/send-reminders.php?key=YOUR_CRON_KEY
 *     (add &dry=1 to preview without sending)
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/../includes/gmail-mailer.php';
$cfg = bienmarko_gmail_config();

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $want = (string) $cfg['cron_key'];
    $got  = (string) ($_GET['key'] ?? '');
    if ($want === '' || $got === '' || !hash_equals($want, $got)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

// The pension house is in the Philippines — "tomorrow" must mean Manila's tomorrow.
date_default_timezone_set('Asia/Manila');

// Command-line runs have no HTTP_HOST; if includes/db.php picks local vs
// live credentials from the host name, give it the live host from site_url.
if ($isCli && empty($_SERVER['HTTP_HOST'])) {
    $siteHost = parse_url((string) $cfg['site_url'], PHP_URL_HOST);
    if (is_string($siteHost) && $siteHost !== '') {
        $_SERVER['HTTP_HOST']   = $siteHost;
        $_SERVER['SERVER_NAME'] = $siteHost;
    }
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking-emails.php';

// Options
$days   = max(0, (int) ($cfg['reminder_days_before'] ?? 1));
$dryRun = false;
if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        } elseif (preg_match('/^--days=(\d{1,2})$/', $arg, $m)) {
            $days = (int) $m[1];
        }
    }
} else {
    $dryRun = !empty($_GET['dry']);
    if (isset($_GET['days']) && preg_match('/^\d{1,2}$/', (string) $_GET['days'])) {
        $days = (int) $_GET['days'];
    }
}

$say = static function (string $line): void {
    echo $line . "\n";
};

try {
    $pdo = bienmarko_db();
} catch (Throwable $e) {
    $say('Database connection failed: ' . $e->getMessage());
    $say('If this only fails from the command line, run the job by URL instead (set "cron_key" in includes/gmail-config.php).');
    exit(1);
}

$target = date('Y-m-d', strtotime("+{$days} day"));
$say('[' . date('Y-m-d H:i:s') . "] Reminder run — check-in date {$target}" . ($dryRun ? ' (dry run)' : ''));

$status = bienmarko_gmail_status($pdo);
if (!$status['connected']) {
    $say('Gmail is not connected (' . $status['status'] . '). Nothing sent. Connect it in Settings → Email (Gmail).');
    exit(0);
}

$ids = bienmarko_email_due_reminders($pdo, $target);
$say(count($ids) . ' reminder(s) due.');

$sent = 0;
$failed = 0;
foreach ($ids as $id) {
    $b = bienmarko_email_load_booking($pdo, $id);
    $label = $b ? ($b['reference'] . ' → ' . $b['recipient']) : "#{$id}";

    if ($dryRun) {
        $say("  would send: {$label}");
        continue;
    }

    $res = bienmarko_email_send_booking($pdo, $id, 'reminder');
    if ($res['ok']) {
        $sent++;
        $say("  sent:   {$label}");
    } else {
        $failed++;
        $say("  FAILED: {$label} — {$res['error']}");
        // A broken connection won't fix itself mid-run; stop hammering Google.
        if (!bienmarko_gmail_status($pdo)['connected']) {
            $say('Gmail connection lost — stopping.');
            break;
        }
    }
}

$say($dryRun ? 'Dry run complete.' : "Done. Sent {$sent}, failed {$failed}.");
exit($failed > 0 ? 2 : 0);
