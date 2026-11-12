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
 * SWIMMING POOL PRICING  (single source of truth, used by the booking
 * API, the bookings page and the payments page)
 * -----------------------------------------------------------------------
 *   1 … 10 guests  -> flat BK_POOL_MIN_CHARGE (₱1,000) for the day
 *   11 or more     -> pool price per person x guests (e.g. 11 x ₱150 = ₱1,650)
 *
 * Multiplied by the number of days booked. To change the rule, edit the two
 * constants below (and the matching JS in bookings.php / payments.php).
 */
const BK_POOL_MIN_GUESTS = 10;       // group size covered by the flat charge
const BK_POOL_MIN_CHARGE = 1000.00;  // flat charge (₱) per day for that group

function bk_pool_fee(float $pricePerPerson, int $guests, int $days = 1): float
{
    $guests = max(1, $guests);
    $days   = max(1, $days);
    $perDay = $guests <= BK_POOL_MIN_GUESTS
        ? BK_POOL_MIN_CHARGE
        : $pricePerPerson * $guests;
    return round($perDay * $days, 2);
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
 *   billed  = room fee + extra bed fee + other charges - discount
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

/* =======================================================================
   DASHBOARD / BOOKINGS-FILTER HELPERS  (SQL versions of the payment rule)
   -----------------------------------------------------------------------
   bk_payment_state() above answers "is THIS booking fully paid?" one
   booking at a time. The dashboard and the Bookings filters need the same
   answer for many bookings at once, so these helpers express the SAME rule
   in SQL. Use them with:

       FROM bookings b
       LEFT JOIN payments p ON p.booking_id = b.id

   (payments has a UNIQUE key on booking_id, so the join never duplicates
   a booking row.)

   The rule (identical to bk_payment_state):
     billed  = room_fee + extra_guest_fee + other_charges - discount
               (no payments row yet -> bookings.total_amount)
     paid    = payments.total_paid   (no row -> 0)
     balance = billed - paid, never negative
     fully paid = a payments row exists AND paid >= billed
   ======================================================================= */

/** Makes sure `payments` exists so the LEFT JOINs below can't fatal on a
 *  fresh install. Same definition as admin/payments.php (CREATE IF NOT EXISTS
 *  is a no-op once the real table is there). */
function bk_ensure_payments_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `payments` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `booking_id`      INT UNSIGNED NOT NULL,
                `room_fee`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `extra_guest_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `other_charges`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `discount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `discount_reason` VARCHAR(255)  NULL,
                `total_paid`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `payment_method`  ENUM('cash','gcash') NOT NULL DEFAULT 'cash',
                `payment_status`  ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
                `notes`           TEXT NULL,
                `paid_at`         DATETIME NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_booking` (`booking_id`),
                CONSTRAINT `fk_pay_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (\Throwable $e) {
        error_log('[booking-stay] could not ensure payments table: ' . $e->getMessage());
    }
}

/** SQL: amount billed for the booking. */
function bk_billed_sql(string $b = 'b', string $p = 'p'): string
{
    return "(CASE WHEN {$p}.id IS NULL THEN {$b}.total_amount"
         . " ELSE GREATEST(0, {$p}.room_fee + {$p}.extra_guest_fee + {$p}.other_charges - {$p}.discount) END)";
}

/** SQL: amount collected so far for the booking. */
function bk_paid_sql(string $p = 'p'): string
{
    return "COALESCE({$p}.total_paid, 0)";
}

/** SQL boolean: the booking is fully paid. */
function bk_fully_paid_sql(string $b = 'b', string $p = 'p'): string
{
    return "({$p}.id IS NOT NULL AND ROUND(" . bk_paid_sql($p) . " * 100) >= ROUND(" . bk_billed_sql($b, $p) . " * 100))";
}

/** SQL: unpaid balance (never negative). */
function bk_balance_sql(string $b = 'b', string $p = 'p'): string
{
    return "GREATEST(0, " . bk_billed_sql($b, $p) . " - " . bk_paid_sql($p) . ")";
}

/** SQL: 'paid' | 'partial' | 'unpaid' for the payment badge. */
function bk_pay_state_sql(string $b = 'b', string $p = 'p'): string
{
    return "(CASE WHEN " . bk_fully_paid_sql($b, $p) . " THEN 'paid'"
         . " WHEN " . bk_paid_sql($p) . " > 0 THEN 'partial'"
         . " ELSE 'unpaid' END)";
}

/** Philippine "today" — the dashboard and its Bookings links must agree on
 *  the date no matter what timezone the web server is set to. */
function bk_ph_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
}