<?php
/**
 * admin/includes/booking-stay.php
 * -----------------------------------------------------------------------
 * Front-desk stay lifecycle for bookings — the single source of truth
 * shared by admin/bookings.php (the page) and admin/api/bookings.php
 * (the status-changing endpoints), so the two can never disagree about
 * what "Upcoming", "Checked-in" or "Completed" means.
 *
 * WHY TWO TIMESTAMP COLUMNS INSTEAD OF A NEW `status` VALUE
 * ---------------------------------------------------------
 * bookings.status (pending / confirmed / cancelled / completed) is shared
 * with Payments, Dashboard, Reports and the guest-facing availability
 * checks, which all filter on `status IN ('confirmed','pending')`.
 * Adding a "checked_in" enum value would silently drop checked-in guests
 * out of those queries (e.g. their room could be double-booked and the
 * Payments "outstanding" totals would shrink). So `status` is left
 * exactly as it was, and the physical arrival / departure the admin
 * records at the front desk is stored in two nullable columns:
 *
 *   checked_in_at   set ONLY when the admin clicks "Confirm Check-in"
 *   checked_out_at  set ONLY when the admin clicks "Confirm Check-out"
 *
 * Nothing in this project ever sets them from a date or a timer.
 *
 * STAGES (what the admin sees)
 * ---------------------------------------------------------
 *   cancelled   status = 'cancelled'
 *   completed   not cancelled, checked_out_at IS NOT NULL
 *   checked_in  not cancelled, checked_in_at  IS NOT NULL, checked_out_at IS NULL
 *   upcoming    not cancelled, checked_in_at  IS NULL,     checked_out_at IS NULL
 * -----------------------------------------------------------------------
 */

if (!defined('BK_STAGES')) {
    define('BK_STAGES', ['upcoming', 'checked_in', 'completed', 'cancelled']);
}

/**
 * Additive, idempotent migration — safe to run on every request, same
 * pattern as the other self-guarding migrations in this project.
 *
 * One-time legacy backfill: before this feature, a booking that had been
 * fully paid was flipped to status 'completed' by Payments. Those whose
 * stay is already over are recorded as checked-in + checked-out so they
 * land in History instead of cluttering "Upcoming Arrivals". It runs only
 * in the request that first creates the columns, so it can never turn
 * into a date-based automatic status change later.
 */
function bk_stay_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $hadColumns = (bool) $pdo->query("SHOW COLUMNS FROM `bookings` LIKE 'checked_out_at'")->fetch();
    if ($hadColumns) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE `bookings`
            ADD COLUMN IF NOT EXISTS `checked_in_at`  DATETIME NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `checked_out_at` DATETIME NULL DEFAULT NULL"
    );

    $legacy = $pdo->prepare(
        "UPDATE `bookings`
            SET checked_in_at = check_in, checked_out_at = check_out
          WHERE status = 'completed'
            AND checked_in_at IS NULL
            AND check_out < :today"
    );
    $legacy->execute(['today' => date('Y-m-d')]);
}

/**
 * SQL condition (no parameters) matching one stage.
 *
 * @param string $stage  upcoming | checked_in | completed | cancelled
 * @param string $alias  table alias, e.g. 'b' (pass '' for none)
 */
function bk_stage_sql(string $stage, string $alias = 'b'): string
{
    $p = $alias === '' ? '' : $alias . '.';
    switch ($stage) {
        case 'cancelled':
            return "{$p}status = 'cancelled'";
        case 'completed':
            return "({$p}status <> 'cancelled' AND {$p}checked_out_at IS NOT NULL)";
        case 'checked_in':
            return "({$p}status <> 'cancelled' AND {$p}checked_in_at IS NOT NULL AND {$p}checked_out_at IS NULL)";
        case 'upcoming':
            return "({$p}status <> 'cancelled' AND {$p}checked_in_at IS NULL AND {$p}checked_out_at IS NULL)";
    }
    return '1=1';
}

/** Stage of one booking row (needs status, checked_in_at, checked_out_at). */
function bk_stage(array $row): string
{
    if (($row['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }
    if (!empty($row['checked_out_at'])) {
        return 'completed';
    }
    if (!empty($row['checked_in_at'])) {
        return 'checked_in';
    }
    return 'upcoming';
}
