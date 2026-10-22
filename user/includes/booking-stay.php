<?php
/**
 * user/includes/booking-stay.php
 * -----------------------------------------------------------------------
 * Guest-side view of the front-desk stay lifecycle.
 *
 * The admin records the physical arrival / departure in two nullable
 * columns on `bookings` (see admin/includes/booking-stay.php):
 *
 *   checked_in_at   set when the admin clicks "Confirm Check-in"
 *   checked_out_at  set when the admin clicks "Confirm Check-out"
 *
 * `bookings.status` is NOT changed by those actions, so the guest pages
 * must read these two columns to show the same stage the admin sees.
 *
 *   cancelled   status = 'cancelled'
 *   completed   not cancelled, checked_out_at IS NOT NULL
 *   checked_in  not cancelled, checked_in_at  IS NOT NULL, checked_out_at IS NULL
 *   upcoming    not cancelled, checked_in_at  IS NULL,     checked_out_at IS NULL
 *
 * Keep these rules identical to admin/includes/booking-stay.php.
 * -----------------------------------------------------------------------
 */

/**
 * TRUE when bookings has the admin's checked_in_at / checked_out_at columns.
 * (They are created by the admin side; until then every booking is Upcoming.)
 */
function bk_has_stay_columns(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) $pdo->query("SHOW COLUMNS FROM `bookings` LIKE 'checked_out_at'")->fetch()
                && (bool) $pdo->query("SHOW COLUMNS FROM `bookings` LIKE 'checked_in_at'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/**
 * SELECT-list fragment: "b.checked_in_at, b.checked_out_at" (or NULL
 * placeholders when the columns don't exist yet, so queries never fail).
 */
function bk_stay_select(PDO $pdo, string $alias = 'b'): string
{
    $p = $alias === '' ? '' : $alias . '.';
    return bk_has_stay_columns($pdo)
        ? "{$p}checked_in_at, {$p}checked_out_at"
        : 'NULL AS checked_in_at, NULL AS checked_out_at';
}

/**
 * SQL condition (no parameters) for "this booking has NOT been checked in
 * or out by the admin". Safe when the columns don't exist (always true).
 */
function bk_not_checked_in_sql(PDO $pdo, string $alias = 'b'): string
{
    if (!bk_has_stay_columns($pdo)) {
        return '1=1';
    }
    $p = $alias === '' ? '' : $alias . '.';
    return "({$p}checked_in_at IS NULL AND {$p}checked_out_at IS NULL)";
}

/**
 * SQL condition (no parameters): the stay is COMPLETED - either the admin
 * confirmed the check-out, or the booking carries the legacy 'completed'
 * status. Used by feedback, which is only open for finished stays.
 */
function bk_completed_sql(PDO $pdo, string $alias = 'b'): string
{
    $p = $alias === '' ? '' : $alias . '.';
    if (!bk_has_stay_columns($pdo)) {
        return "{$p}status = 'completed'";
    }
    return "({$p}status = 'completed' OR ({$p}status <> 'cancelled' AND {$p}checked_out_at IS NOT NULL))";
}

/** Stage of one booking row: upcoming | checked_in | completed | cancelled. */
function bk_stage(array $row): string
{
    if (($row['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }
    if (!empty($row['checked_out_at']) || ($row['status'] ?? '') === 'completed') {
        return 'completed';
    }
    if (!empty($row['checked_in_at'])) {
        return 'checked_in';
    }
    return 'upcoming';
}

/**
 * Label + colour key for a booking row.
 * Upcoming keeps the guest's familiar Confirmed / Pending wording.
 *
 * @return array{0:string,1:string,2:string} [stage, label, colour]
 *         colour is one of: green | amber | red | blue | teal | grey
 */
function bk_stage_badge(array $row): array
{
    $stage = bk_stage($row);
    switch ($stage) {
        case 'cancelled':
            return [$stage, 'Cancelled', 'red'];
        case 'completed':
            return [$stage, 'Completed', 'blue'];
        case 'checked_in':
            return [$stage, 'Checked-in', 'teal'];
    }
    return ($row['status'] ?? '') === 'pending'
        ? [$stage, 'Pending', 'amber']
        : [$stage, 'Confirmed', 'green'];
}
