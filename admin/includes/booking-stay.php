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
 * Swimming Pool sessions — a pool booking is for the DAY session or the
 * NIGHT session. The same date can have one booking set for each session,
 * and each session has its own capacity.
 *
 * Stored in bookings.pool_session ('day' | 'night'). It is NULL for every
 * non-pool booking, and for pool bookings made before this feature
 * existed — those old bookings are treated as occupying BOTH sessions, so
 * nothing already on the books can be double-booked.
 *
 * Additive, idempotent migration — safe to run on every request.
 */
const BK_POOL_SESSIONS = ['day', 'night'];

function bk_pool_session_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!$pdo->query("SHOW COLUMNS FROM `bookings` LIKE 'pool_session'")->fetch()) {
        $pdo->exec("ALTER TABLE `bookings` ADD COLUMN `pool_session` VARCHAR(10) NULL DEFAULT NULL");
    }
}

/** 'day' -> 'Day', 'night' -> 'Night', anything else -> '' */
function bk_pool_session_label(?string $session): string
{
    $s = strtolower(trim((string) $session));
    return $s === 'day' ? 'Day' : ($s === 'night' ? 'Night' : '');
}

/**
 * SQL condition (uses the named parameter :psess) matching every booking
 * that occupies the given pool session: same session, or an old pool
 * booking with no session recorded (it blocks both).
 */
function bk_pool_session_sql(string $alias = ''): string
{
    $p = $alias === '' ? '' : $alias . '.';
    return "({$p}pool_session IS NULL OR {$p}pool_session = :psess)";
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


/**
 * Payment state of one booking, read from the same `payments` row that
 * Payments & Billing saves (so this page, the API and the Payments page can
 * never disagree about what "fully paid" means).
 *
 *   billed  = room fee + extra guest fee + other charges - discount
 *   paid    = payments.total_paid (running total of every payment recorded)
 *   balance = billed - paid (never negative)
 *
 * A booking is fully paid only when a billing record exists AND paid >= billed.
 * No billing record yet  => NOT fully paid (the admin has not billed it), and
 * the balance falls back to bookings.total_amount.
 * A stay that is free after a 100% discount (billed = 0) counts as fully paid
 * once its billing record has been saved.
 *
 * @return array{has_record: bool, billed: float, paid: float, balance: float, fully_paid: bool}
 */
function bk_payment_state(PDO $pdo, int $bookingId): array
{
    $state = ['has_record' => false, 'billed' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'fully_paid' => false];

    try {
        $st = $pdo->prepare(
            "SELECT b.total_amount,
                    p.id AS pay_id, p.room_fee, p.extra_guest_fee, p.other_charges,
                    p.discount, p.total_paid
               FROM bookings b
               LEFT JOIN payments p ON p.booking_id = b.id
              WHERE b.id = :id"
        );
        $st->execute(['id' => $bookingId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // e.g. the payments table doesn't exist yet -> nothing has been paid.
        error_log('[booking-stay] payment lookup failed: ' . $e->getMessage());
        return $state;
    }
    if (!$r) {
        return $state;
    }

    $cents = static function ($v): int {
        return (int) round(round((float) $v, 4) * 100);
    };

    if (empty($r['pay_id'])) {
        $billedC = max(0, $cents($r['total_amount']));
        $state['billed']  = $billedC / 100;
        $state['balance'] = $billedC / 100;
        return $state;
    }

    $billedC = max(0, $cents($r['room_fee']) + $cents($r['extra_guest_fee']) + $cents($r['other_charges']) - $cents($r['discount']));
    $paidC   = $cents($r['total_paid']);

    $state['has_record'] = true;
    $state['billed']     = $billedC / 100;
    $state['paid']       = $paidC / 100;
    $state['balance']    = max(0, $billedC - $paidC) / 100;
    $state['fully_paid'] = $paidC >= $billedC;
    return $state;
}