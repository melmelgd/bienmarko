<?php
/**
 * admin/cron/scheduled-backup.php
 *
 * Command-line only. Creates the automatic daily database backup
 * (bienmarko-auto-backup-*.sql in admin/storage/backups/) and prunes
 * automatic backups beyond the newest 14. Does nothing if one was already
 * made within the last 24 hours, so it is safe to run more often.
 *
 *   Linux cron (daily 2 AM):  0 2 * * * php /path/to/admin/cron/scheduled-backup.php
 *   Windows (XAMPP):          Task Scheduler -> php.exe  C:\xampp\htdocs\Bookingsystem\admin\cron\scheduled-backup.php
 *   Force a run now:          php scheduled-backup.php --force
 *
 * Intervals / retention: see BACKUP_SCHEDULE_* in admin/includes/backup-data.php.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/backup-data.php';

$force = in_array('--force', $argv ?? [], true);

try {
    $res = backup_run_scheduled(bienmarko_db(), $force);
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' - backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo date('Y-m-d H:i:s') . ' - ' . ($res
    ? "backup created: {$res['filename']} (" . backup_format_bytes($res['file_size']) . ')'
    : 'no backup needed (one already exists within the last ' . BACKUP_SCHEDULE_INTERVAL_HOURS . ' hours)') . "\n";
