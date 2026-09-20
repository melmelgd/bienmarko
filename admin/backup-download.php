<?php
/**
 * admin/backup-download.php
 * -----------------------------------------------------------------------
 * Streams one stored backup .sql file to the browser. Plain GET (not an
 * api/*.php JSON endpoint) because a real navigation/link is what
 * triggers a browser's file-save prompt — same reasoning as
 * admin/reports-export.php for report downloads. Guarded by session +
 * admin role, same as every other admin page; the storage folder itself
 * is also denied to direct web access (see includes/backup-data.php),
 * so this script is the only way to read a backup file at all.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/backup-data.php';

$pdo = bienmarko_db();
backup_ensure_table($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid backup.';
    exit;
}

$stmt = $pdo->prepare('SELECT filename FROM backups WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Backup not found.';
    exit;
}

$filename = basename($row['filename']);
$path = BACKUP_STORAGE_DIR . '/' . $filename;

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Backup file is missing from the server.';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($path);
exit;
