<?php
/**
 * admin/api/backup.php
 * -----------------------------------------------------------------------
 * JSON API behind admin/backup.php. Guarded the same way as
 * admin/api/accommodations.php — session + admin role + CSRF, checked
 * before anything submitted is looked at.
 *
 * FormData-based (like admin/api/accommodations.php), not a JSON body —
 * this module is the only other one besides Accommodations that ever
 * needs to accept an uploaded file (a .sql file for restore-by-upload),
 * so every action here reads from $_POST/$_FILES for consistency within
 * this one file, even the actions that never carry a file.
 *
 *   action=create   - generate a full database dump, store it, log it
 *   action=delete   - remove a stored backup (row + file)
 *   action=restore  - restore the database from either an existing
 *                      backup (backup_id) or an uploaded .sql file
 *                      (restore_file). Requires confirm=RESTORE as a
 *                      server-side backstop for the UI's typed
 *                      confirmation, since this action overwrites
 *                      every table in the database.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/backup-data.php';

header('Content-Type: application/json; charset=utf-8');

function bkp_fail(int $httpCode, string $message): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function bkp_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    bkp_fail(405, 'Method not allowed.');
}

if (!bienmarko_csrf_valid($_POST['csrf_token'] ?? null)) {
    bkp_fail(403, 'Your session has expired. Please refresh the page and try again.');
}

$pdo = bienmarko_db();
backup_ensure_table($pdo);
backup_ensure_storage_dir();

const BKP_MAX_UPLOAD_BYTES = 50 * 1024 * 1024; // 50 MB — generous for a pension house's own data

$action = (string) ($_POST['action'] ?? '');

/* ================================================================
   ACTION: create
   ================================================================ */
if ($action === 'create') {
    try {
        $sql = backup_generate_dump($pdo);
    } catch (\Throwable $e) {
        error_log('[backup api create] dump failed: ' . $e->getMessage());
        bkp_fail(500, 'Could not generate the backup. Please try again.');
    }

    $filename = 'bienmarko-backup-' . date('Y-m-d_His') . '.sql';
    $path = BACKUP_STORAGE_DIR . '/' . $filename;

    if (@file_put_contents($path, $sql) === false) {
        bkp_fail(500, 'Could not save the backup file. Check that the server can write to admin/storage/backups.');
    }

    $fileSize = (int) filesize($path);
    $createdBy = isset($_SESSION['auth_user_id']) ? (int) $_SESSION['auth_user_id'] : null;

    try {
        $ins = $pdo->prepare(
            'INSERT INTO backups (filename, file_size, created_by) VALUES (:filename, :file_size, :created_by)'
        );
        $ins->execute([
            'filename'   => $filename,
            'file_size'  => $fileSize,
            'created_by' => $createdBy,
        ]);
    } catch (\Throwable $e) {
        @unlink($path);
        error_log('[backup api create] insert failed: ' . $e->getMessage());
        bkp_fail(500, 'A database error occurred while logging the backup. Please try again.');
    }

    bkp_ok(['id' => (int) $pdo->lastInsertId(), 'filename' => $filename, 'file_size' => $fileSize]);
}

/* ================================================================
   ACTION: delete
   ================================================================ */
if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        bkp_fail(422, 'Invalid backup record.');
    }

    $stmt = $pdo->prepare('SELECT filename FROM backups WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        bkp_fail(404, 'Backup record not found.');
    }

    $path = BACKUP_STORAGE_DIR . '/' . basename($row['filename']);
    if (is_file($path)) {
        @unlink($path);
    }

    try {
        $del = $pdo->prepare('DELETE FROM backups WHERE id = :id');
        $del->execute(['id' => $id]);
    } catch (\Throwable $e) {
        error_log('[backup api delete] ' . $e->getMessage());
        bkp_fail(500, 'A database error occurred. Please try again.');
    }

    bkp_ok();
}

/* ================================================================
   ACTION: restore
   ================================================================ */
if ($action === 'restore') {
    if (trim((string) ($_POST['confirm'] ?? '')) !== 'RESTORE') {
        bkp_fail(422, 'Restore was not confirmed.');
    }

    $backupId = (int) ($_POST['backup_id'] ?? 0);
    $uploadedFile = $_FILES['restore_file'] ?? null;
    $hasUpload = $uploadedFile && ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $sql = null;
    $loggedFilename = null;

    if ($hasUpload) {
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $tooLarge = in_array($uploadedFile['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            bkp_fail(422, $tooLarge
                ? 'That file is too large for this server\'s upload limit.'
                : 'File upload failed. Please try again.');
        }
        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            bkp_fail(422, 'File upload failed. Please try again.');
        }
        if ($uploadedFile['size'] > BKP_MAX_UPLOAD_BYTES) {
            bkp_fail(422, 'That file is too large (max 50MB).');
        }
        $ext = strtolower(pathinfo((string) $uploadedFile['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            bkp_fail(422, 'Please upload a .sql backup file.');
        }

        $sql = file_get_contents($uploadedFile['tmp_name']);
        if ($sql === false || trim($sql) === '') {
            bkp_fail(422, 'That file appears to be empty or unreadable.');
        }

        // Also save the upload into backup history, so it's available
        // to download or restore again later without re-uploading it.
        $loggedFilename = 'bienmarko-restore-upload-' . date('Y-m-d_His') . '.sql';
        $savedPath = BACKUP_STORAGE_DIR . '/' . $loggedFilename;
        if (@file_put_contents($savedPath, $sql) === false) {
            bkp_fail(500, 'Could not save the uploaded file. Check that the server can write to admin/storage/backups.');
        }

    } elseif ($backupId > 0) {
        $stmt = $pdo->prepare('SELECT filename FROM backups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $backupId]);
        $row = $stmt->fetch();
        if (!$row) {
            bkp_fail(404, 'That backup could not be found.');
        }

        $path = BACKUP_STORAGE_DIR . '/' . basename($row['filename']);
        if (!is_file($path)) {
            bkp_fail(404, 'That backup\'s file is missing from the server.');
        }

        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            bkp_fail(422, 'That backup file appears to be empty or unreadable.');
        }

    } else {
        bkp_fail(422, 'Please choose a backup to restore from, or upload a backup file.');
    }

    try {
        $statementCount = backup_restore_from_sql($pdo, $sql);
    } catch (\Throwable $e) {
        error_log('[backup api restore] ' . $e->getMessage());
        bkp_fail(500, 'Restore failed partway through — the database may now be partially restored. '
            . 'Details: ' . $e->getMessage());
    }

    // Log the upload into backup history only after a successful
    // restore, so a failed/rejected upload never clutters the list.
    if ($loggedFilename !== null) {
        try {
            $createdBy = isset($_SESSION['auth_user_id']) ? (int) $_SESSION['auth_user_id'] : null;
            $ins = $pdo->prepare(
                'INSERT INTO backups (filename, file_size, created_by) VALUES (:filename, :file_size, :created_by)'
            );
            $ins->execute([
                'filename'   => $loggedFilename,
                'file_size'  => (int) strlen($sql),
                'created_by' => $createdBy,
            ]);
        } catch (\Throwable $e) {
            // The restore itself already succeeded; failing to log the
            // uploaded copy into history isn't worth failing the request.
            error_log('[backup api restore] could not log uploaded file: ' . $e->getMessage());
        }
    }

    bkp_ok(['statements_executed' => $statementCount]);
}

/* Unknown action */
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
