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
