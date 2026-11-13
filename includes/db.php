<?php

/**
 * includes/db.php
 * -----------------------------------------------------------------------
 * Single database entry point for the whole system.
 *
 * TIME ZONE: everything runs on Philippine Standard Time (Asia/Manila,
 * UTC+08:00, no daylight saving) no matter what time zone the web server
 * or the MySQL server is set to:
 *
 *   - PHP : date(), strtotime(), new DateTime('now') ... use Manila time.
 *   - MySQL : NOW(), CURDATE(), CURRENT_TIMESTAMP (created_at / updated_at
 *             defaults) use Manila time for this connection.
 *
 * That is what makes the "day before" and "day of" booking reminders
 * (cron/send-reminders.php), booking timestamps and the admin dashboard
 * all agree on what "today" and "tomorrow" mean.
 * -----------------------------------------------------------------------
 */

if (!defined('BIENMARKO_TIMEZONE')) {
    define('BIENMARKO_TIMEZONE', 'Asia/Manila');   // PHP time zone name
    define('BIENMARKO_DB_UTC_OFFSET', '+08:00');   // same zone as a MySQL offset (works without time-zone tables)
}

date_default_timezone_set(BIENMARKO_TIMEZONE);

function bienmarko_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config/database.php';

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[bienmarko] Database connection failed: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "We're having trouble connecting right now. Please try again shortly.";
        exit;
    }

    // Make MySQL's own clock (NOW(), CURDATE(), CURRENT_TIMESTAMP) Philippine
    // time for this connection. Non-fatal: if a host refuses it, PHP-side
    // dates are still Manila time.
    try {
        $pdo->exec("SET time_zone = '" . BIENMARKO_DB_UTC_OFFSET . "'");
    } catch (Throwable $e) {
        error_log('[bienmarko] Could not set the MySQL session time zone: ' . $e->getMessage());
    }

    return $pdo;
}