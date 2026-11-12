<?php
/**
 * admin/includes/backup-data.php
 * -----------------------------------------------------------------------
 * Shared helpers for the Backup & Restore feature, used by both
 * admin/backup.php (the page), admin/api/backup.php (create/delete/
 * restore), and admin/backup-download.php (streaming a stored file).
 * Same split as admin/includes/accommodations-data.php.
 *
 * Backups are plain .sql dump files, generated in pure PHP via PDO
 * (SHOW CREATE TABLE + SELECT * -> INSERT) rather than shelling out to
 * mysqldump — this project has no Composer dependencies and doesn't
 * assume shell_exec()/exec() are available on the host, so a
 * PHP-only dumper is the more portable choice.
 *
 * Dump files are stored OUTSIDE any web-servable "uploads" style path
 * expectations: admin/storage/backups/ is locked down with a
 * "Require all denied" .htaccess (stricter than the script-execution-only
 * lockdown used for accommodation photos/avatars/tours, because a backup
 * file contains full guest and booking records, not a public photo).
 * The only way to fetch one is through admin/backup-download.php, which
 * is itself gated behind bienmarko_require_role('admin').
 * -----------------------------------------------------------------------
 */

const BACKUP_STORAGE_DIR = __DIR__ . '/../storage/backups';

/** Makes sure the `backups` table exists — additive, self-guarding, safe to run on every request. */
function backup_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `backups` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `filename`    VARCHAR(150) NOT NULL,
            `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_by`  INT UNSIGNED NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Makes sure the backup storage folder exists and is fully locked down
 * against direct web access (not just script execution — these dumps
 * contain guest PII, so unlike admin/assets/uploads/* the whole folder
 * is denied, not just .php files).
 */
function backup_ensure_storage_dir(): void
{
    if (!is_dir(BACKUP_STORAGE_DIR)) {
        mkdir(BACKUP_STORAGE_DIR, 0755, true);
    }

    $htaccess = BACKUP_STORAGE_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents(
            $htaccess,
            "# Database backups — never served directly. Only\n"
            . "# admin/backup-download.php (which checks the admin role first)\n"
            . "# may read files from this folder.\n"
            . "Require all denied\n"
            . "\n"
            . "# Apache 2.2 fallback, in case this host doesn't support\n"
            . "# the 2.4 \"Require\" syntax above.\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "  Order allow,deny\n"
            . "  Deny from all\n"
            . "</IfModule>\n"
        );
    }

    $indexGuard = BACKUP_STORAGE_DIR . '/index.html';
    if (!file_exists($indexGuard)) {
        file_put_contents($indexGuard, '');
    }
}

/** Formats a byte count as a short human-readable size string. */
function backup_format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Builds a full logical SQL dump of every table in the current database:
 * DROP TABLE IF EXISTS + the exact CREATE TABLE MySQL reports, followed
 * by one INSERT per row. Every value is passed through PDO::quote() so
 * strings containing quotes, semicolons, or newlines round-trip safely.
 */
function backup_generate_dump(PDO $pdo): string
{
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $sql  = "-- Bien Marko's Pension House — Database Backup\n";
    $sql .= "-- Database: {$dbName}\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $createRow = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        $createSql = $createRow[1] ?? '';

        $sql .= "-- ----------------------------\n";
        $sql .= "-- Table structure for `{$table}`\n";
        $sql .= "-- ----------------------------\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $createSql . ";\n\n";

        $rowStmt = $pdo->query('SELECT * FROM `' . $table . '`');
        $rowCount = 0;
        while ($row = $rowStmt->fetch(PDO::FETCH_ASSOC)) {
            $rowCount++;
            $columnList = '`' . implode('`, `', array_keys($row)) . '`';
            $valueList = implode(', ', array_map(
                static fn($value) => $value === null ? 'NULL' : $pdo->quote((string) $value),
                array_values($row)
            ));
            $sql .= "INSERT INTO `{$table}` ({$columnList}) VALUES ({$valueList});\n";
        }
        if ($rowCount > 0) {
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    return $sql;
}

/**
 * Splits a multi-statement SQL dump into individual statements, the way
 * a real SQL parser would — tracking whether we're inside a quoted
 * string or identifier so a semicolon inside a guest's note, address,
 * or a column comment never causes a false split. Comment lines
 * (`-- ...`) ride along with whichever statement follows them, which
 * is harmless: MySQL ignores them when the statement executes.
 */
function backup_split_sql_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $inString = false;
    $stringChar = '';
    $inLineComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $current .= $char;
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inString) {
            $current .= $char;
            if ($char === '\\' && $next !== '') {
                // Escaped character (\', \\, etc.) — consume the next
                // char verbatim too, so an escaped quote can't end the
                // string early.
                $current .= $next;
                $i++;
                continue;
            }
            if ($char === $stringChar) {
                $inString = false;
            }
            continue;
        }

        if ($char === '-' && $next === '-') {
            $inLineComment = true;
            $current .= $char;
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $inString = true;
            $stringChar = $char;
            $current .= $char;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * Runs every statement in a dump against the database. Not wrapped in a
 * single PDO transaction — a dump's DROP/CREATE TABLE statements each
 * trigger an implicit commit in MySQL anyway, so transactional rollback
 * across the whole restore isn't actually available regardless. Stops
 * and throws on the first statement that fails, with enough context for
 * the caller to report which one.
 *
 * Returns the number of statements executed.
 */
function backup_restore_from_sql(PDO $pdo, string $sql): int
{
    $statements = backup_split_sql_statements($sql);
    $executed = 0;

    foreach ($statements as $index => $statement) {
        try {
            $pdo->exec($statement);
            $executed++;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Failed on statement ' . ($index + 1) . ' of ' . count($statements) . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    return $executed;
}


/* ======================================================================
   SCHEDULED (AUTOMATIC) BACKUPS
   ----------------------------------------------------------------------
   Runs a full backup on a fixed interval without anyone clicking
   "Create Backup". Two triggers share the same function:
     1. admin/cron/scheduled-backup.php  - real cron / Windows Task
        Scheduler job (recommended once the system is hosted).
     2. backup_lazy_trigger()            - fallback called from
        admin/includes/head.php, so backups still happen on hosts with
        no cron access as long as an admin opens the panel.
   Scheduled files are named bienmarko-auto-backup-*.sql and are the
   only ones ever pruned; manual backups and uploaded restore files
   are never touched. created_by is NULL, which the Backup page already
   labels "System".
   ====================================================================== */

const BACKUP_SCHEDULE_INTERVAL_HOURS = 24; // one automatic backup per day
const BACKUP_SCHEDULE_KEEP           = 14; // newest automatic backups to retain
const BACKUP_AUTO_PREFIX             = 'bienmarko-auto-backup-';

/** Writes a full dump to storage and logs it in `backups`. Returns [id, filename, file_size]. */
function backup_create_file(PDO $pdo, string $prefix, ?int $createdBy): array
{
    backup_ensure_table($pdo);
    backup_ensure_storage_dir();

    $sql      = backup_generate_dump($pdo);
    $stamp    = date('Y-m-d_His');
    $filename = $prefix . $stamp . '.sql';
    for ($n = 2; is_file(BACKUP_STORAGE_DIR . '/' . $filename); $n++) {
        $filename = $prefix . $stamp . '-' . $n . '.sql'; // never overwrite a file from the same second
    }
    $path     = BACKUP_STORAGE_DIR . '/' . $filename;

    if (@file_put_contents($path, $sql) === false) {
        throw new \RuntimeException('Could not write backup file to admin/storage/backups.');
    }

    $size = (int) filesize($path);

    try {
        $ins = $pdo->prepare(
            'INSERT INTO backups (filename, file_size, created_by) VALUES (:filename, :file_size, :created_by)'
        );
        $ins->execute(['filename' => $filename, 'file_size' => $size, 'created_by' => $createdBy]);
    } catch (\Throwable $e) {
        @unlink($path);
        throw $e;
    }

    return ['id' => (int) $pdo->lastInsertId(), 'filename' => $filename, 'file_size' => $size];
}

/** DATETIME (DB clock) of the newest automatic backup, or null if none yet. */
function backup_last_auto_at(PDO $pdo): ?string
{
    backup_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT MAX(created_at) FROM backups WHERE filename LIKE :p');
    $stmt->execute(['p' => BACKUP_AUTO_PREFIX . '%']);
    $v = $stmt->fetchColumn();
    return $v ? (string) $v : null;
}

/** Human-readable time of the next automatic backup ("Due now" if overdue / none yet). */
function backup_next_auto_label(PDO $pdo): string
{
    $last = backup_last_auto_at($pdo);
    if ($last === null) {
        return 'Due now';
    }
    $next = strtotime($last) + BACKUP_SCHEDULE_INTERVAL_HOURS * 3600;
    $now  = (int) strtotime((string) $pdo->query('SELECT NOW()')->fetchColumn());
    return $next <= $now ? 'Due now' : date('M j, Y g:i A', $next);
}

/** Deletes automatic backups beyond the newest BACKUP_SCHEDULE_KEEP. */
function backup_prune_auto(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id, filename FROM backups WHERE filename LIKE :p ORDER BY created_at DESC, id DESC');
    $stmt->execute(['p' => BACKUP_AUTO_PREFIX . '%']);
    $rows = $stmt->fetchAll();

    $removed = 0;
    foreach (array_slice($rows, BACKUP_SCHEDULE_KEEP) as $row) {
        $path = BACKUP_STORAGE_DIR . '/' . basename($row['filename']);
        if (is_file($path)) {
            @unlink($path);
        }
        $del = $pdo->prepare('DELETE FROM backups WHERE id = :id');
        $del->execute(['id' => (int) $row['id']]);
        $removed++;
    }
    return $removed;
}

/**
 * Creates an automatic backup if the newest one is older than the
 * interval (or $force). Returns the new backup's info, or null when
 * nothing was due / another process is already running one.
 */
function backup_run_scheduled(PDO $pdo, bool $force = false): ?array
{
    backup_ensure_table($pdo);

    $lock = $pdo->query("SELECT GET_LOCK('bienmarko_scheduled_backup', 0)")->fetchColumn();
    if ((int) $lock !== 1) {
        return null; // someone else is already backing up
    }

    try {
        if (!$force) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM backups
                 WHERE filename LIKE :p AND created_at > (NOW() - INTERVAL ' . (int) BACKUP_SCHEDULE_INTERVAL_HOURS . ' HOUR)'
            );
            $stmt->execute(['p' => BACKUP_AUTO_PREFIX . '%']);
            if ((int) $stmt->fetchColumn() > 0) {
                return null; // not due yet
            }
        }

        $result = backup_create_file($pdo, BACKUP_AUTO_PREFIX, null);
        backup_prune_auto($pdo);
        return $result;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bienmarko_scheduled_backup')");
    }
}

/**
 * Fallback scheduler for hosts without cron: called on admin page loads,
 * checks at most once per 30 minutes per session, and runs the backup
 * AFTER the page has been sent so the admin never waits on it.
 */
function backup_lazy_trigger(): void
{
    if (PHP_SAPI === 'cli' || ($_SESSION['auth_role'] ?? null) !== 'admin') {
        return;
    }
    $now = time();
    if (($_SESSION['backup_checked_at'] ?? 0) > $now - 1800) {
        return;
    }
    $_SESSION['backup_checked_at'] = $now;

    register_shutdown_function(static function (): void {
        ignore_user_abort(true);
        @set_time_limit(120);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // don't hold the session lock during the dump
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            backup_run_scheduled(bienmarko_db());
        } catch (\Throwable $e) {
            error_log('[scheduled backup] ' . $e->getMessage());
        }
    });
}
