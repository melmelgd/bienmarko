<?php
/**
 * user/cron/send-feedback-reminders.php
 *
 * Command-line only. Sends the dashboard notification + Gmail reminder for
 * every guest who has a completed booking without feedback, even if they
 * never log in. Safe to run as often as you like: each booking is reminded once.
 *
 * Run every 15-60 minutes:
 *   Linux cron:        *\/30 * * * * php /path/to/user/cron/send-feedback-reminders.php
 *   Windows (XAMPP):   Task Scheduler -> php.exe  C:\xampp\htdocs\...\user\cron\send-feedback-reminders.php
 *
 * IMPORTANT: set BM_FB_SITE_URL in user/includes/feedback-reminders.php so the
 * email button has a full link.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/feedback-reminders.php';

$pdo = bienmarko_db();
$res = bm_fb_sync_reminders($pdo, null, true, false);

echo date('Y-m-d H:i:s') . " - notifications created: {$res['notified']}, emails sent: {$res['emailed']}\n";