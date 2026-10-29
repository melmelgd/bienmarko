<?php
/**
 * admin/includes/reports-data.php
 * -----------------------------------------------------------------------
 * Shared data layer for the Reports & Analytics section (report.php +
 * report-financial.php, report-performance.php, report-guest-insights.php,
 * report-inventory.php) AND reports-export.php.
 *
 * Centralizing the queries here (rather than duplicating them per page,
 * the way dashboard.php/payments.php duplicate small display helpers)
 * matters specifically for Reports because the PDF/XLSX export has to
 * produce numbers that match what the on-screen report shows — keeping
 * one copy of each query is what guarantees that.
 *
 * Every reports_*_data() function is read-only and takes the already
 * -resolved PDO connection plus a start/end date range (both inclusive,
 * 'Y-m-d'). Table-creation is additive/self-guarding, same pattern as
 * admin/payments.php and admin/inventory.php.
 * -----------------------------------------------------------------------
 */

const RPT_REVENUE_STATUSES = ['confirmed', 'completed'];
const RPT_TYPES            = ['Room', 'Event Hall', 'Cottage', 'Swimming Pool'];

/* ------------------------------------------------------------------
   Table guards (additive-only, mirrors dashboard.php's own guards)
------------------------------------------------------------------ */

/**
 * Guest Insights reads ratings from the same `feedback` table that
 * admin/feedback.php and admin/api/feedback.php already create and
 * maintain (guest_id, rating, category, message, is_read, is_published,
 * created_at — there is no `status`/`comment`/`booking_id` column).
 * This guard mirrors that exact CREATE TABLE / ADD COLUMN pair so Guest
 * Insights works even on a session that has never opened the Feedback
 * page, and so both places stay byte-for-byte in sync on schema.
 */
function reports_ensure_feedback_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `feedback` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `guest_id`     INT UNSIGNED NOT NULL,
            `rating`       TINYINT UNSIGNED NOT NULL,
            `category`     ENUM('general','room','service','cleanliness','other') NOT NULL DEFAULT 'general',
            `message`      TEXT NOT NULL,
            `is_read`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_guest`     (`guest_id`),
            KEY `idx_created`   (`created_at`),
            KEY `idx_read`      (`is_read`),
            KEY `idx_published` (`is_published`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_read`");
    $done = true;
}

/**
 * Guests currently have no country field to display in Top Returning
 * Guests. Adding it here (nullable, additive) so the report can show it
 * once the registration form is updated to collect it — until then rows
 * fall back to 'Philippines' at display time. Flagged as a design
 * decision: the actual registration-form UI change is out of scope for
 * this feature and would need its own follow-up.
 */
function reports_ensure_guest_country_column(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `country` VARCHAR(80) NULL DEFAULT NULL AFTER `email`");
    $done = true;
}

/**
 * Every report reads from tables that other admin pages normally create
 * on first visit (bookings, accommodations, payments, expenses,
 * inventory). Reports should work even if this admin session has never
 * opened those pages, so this guard mirrors the exact CREATE/ALTER
 * statements those pages already run — same additive, idempotent
 * pattern, just centralized here so the four report pages don't each
 * need their own copy.
 */
function reports_ensure_core_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    if (!function_exists('acc_ensure_table')) {
        require_once __DIR__ . '/accommodations-data.php';
    }
    acc_ensure_table();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `bookings` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference`        VARCHAR(20)  NOT NULL UNIQUE,
            `guest_id`         INT UNSIGNED NULL,
            `accommodation_id` INT UNSIGNED NOT NULL,
            `check_in`         DATE         NOT NULL,
            `check_out`        DATE         NOT NULL,
            `guests_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `notes`            TEXT         NULL,
            `status`           ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed',
            `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_guest`         (`guest_id`),
            KEY `idx_accommodation` (`accommodation_id`),
            KEY `idx_dates`         (`check_in`, `check_out`),
            KEY `idx_status`        (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Older installs may have created this column NOT NULL (pre-guest-checkout);
    // this MODIFY is the same one admin/payments.php already runs on every load.
    $pdo->exec("ALTER TABLE `bookings` MODIFY COLUMN `guest_id` INT UNSIGNED NULL DEFAULT NULL");
    $pdo->exec("
        ALTER TABLE `bookings`
            ADD COLUMN IF NOT EXISTS `guest_name`     VARCHAR(200) NULL DEFAULT NULL AFTER `total_amount`,
            ADD COLUMN IF NOT EXISTS `email`          VARCHAR(200) NULL DEFAULT NULL AFTER `guest_name`,
            ADD COLUMN IF NOT EXISTS `phone`          VARCHAR(30)  NULL DEFAULT NULL AFTER `email`,
            ADD COLUMN IF NOT EXISTS `address`        VARCHAR(255) NULL DEFAULT NULL AFTER `phone`,
            ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','gcash') NULL DEFAULT 'cash' AFTER `address`,
            ADD COLUMN IF NOT EXISTS `booking_type`   ENUM('registered','guest') NOT NULL DEFAULT 'registered' AFTER `payment_method`
    ");

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `expenses` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `expense_date`  DATE NOT NULL,
            `category`      VARCHAR(40) NOT NULL DEFAULT 'Others',
            `amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `notes`         VARCHAR(255) NOT NULL DEFAULT '',
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inventory_items` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name`            VARCHAR(150) NOT NULL,
            `category`        VARCHAR(60)  NOT NULL DEFAULT 'Others',
            `quantity`        INT UNSIGNED NOT NULL DEFAULT 0,
            `min_stock_level` INT UNSIGNED NOT NULL DEFAULT 0,
            `cost_per_unit`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `expiration_date` DATE NULL DEFAULT NULL,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inventory_logs` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `item_id`    INT UNSIGNED NOT NULL,
            `change_qty` INT NOT NULL,
            `reason`     ENUM('initial','restock','usage','adjustment') NOT NULL DEFAULT 'adjustment',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_inv_log_item` (`item_id`),
            CONSTRAINT `fk_inv_log_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    reports_ensure_feedback_table($pdo);
    reports_ensure_guest_country_column($pdo);

    $done = true;
}

/* ------------------------------------------------------------------
   Period resolution — shared by every report page + the export
   endpoint, so "Month" always means the same date range everywhere.
------------------------------------------------------------------ */
function reports_resolve_period(array $get): array
{
    $period  = $get['period'] ?? 'month';
    $allowed = ['week', 'month', 'year', 'custom'];
    if (!in_array($period, $allowed, true)) {
        $period = 'month';
    }

    $today = new DateTime('today');

    if ($period === 'week') {
        $start = (clone $today)->modify('monday this week');
        $end   = (clone $start)->modify('+6 days');
    } elseif ($period === 'year') {
        $start = new DateTime($today->format('Y') . '-01-01');
        $end   = new DateTime($today->format('Y') . '-12-31');
    } elseif ($period === 'custom') {
        $rawStart = trim((string) ($get['start'] ?? ''));
        $rawEnd   = trim((string) ($get['end'] ?? ''));
        try {
            $start = $rawStart !== '' ? new DateTime($rawStart) : new DateTime('first day of this month');
            $end   = $rawEnd   !== '' ? new DateTime($rawEnd)   : new DateTime('today');
        } catch (\Exception $e) {
            $start = new DateTime('first day of this month');
            $end   = new DateTime('today');
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
    } else { // month
        $start = new DateTime('first day of this month');
        $end   = new DateTime('last day of this month');
    }

    return [
        'period' => $period,
        'start'  => $start->format('Y-m-d'),
        'end'    => $end->format('Y-m-d'),
        'label'  => reports_period_label($period, $start, $end),
    ];
}

function reports_period_label(string $period, DateTime $start, DateTime $end): string
{
    if ($period === 'week')   return $start->format('M j') . ' – ' . $end->format('M j, Y');
    if ($period === 'year')   return $start->format('Y');
    if ($period === 'custom') return $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
    return $start->format('F Y');
}

/** 'Room' -> 'Rooms', 'Swimming Pool' -> 'Pool', etc. — display-only plural/short labels. */
function rpt_type_label(string $type): string
{
    $map = [
        'Room'          => 'Rooms',
        'Event Hall'    => 'Event Halls',
        'Cottage'       => 'Cottages',
        'Swimming Pool' => 'Pool',
    ];
    return $map[$type] ?? $type;
}

function rpt_type_short(string $type): string
{
    return $type === 'Swimming Pool' ? 'Pool' : $type;
}

/* ------------------------------------------------------------------
   1) FINANCIAL REPORTS
------------------------------------------------------------------ */
function reports_financial_data(PDO $pdo, string $start, string $end): array
{
    $inStatuses = "'" . implode("','", RPT_REVENUE_STATUSES) . "'";

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount),0) FROM bookings
         WHERE status IN ($inStatuses) AND check_in BETWEEN :s AND :e"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $revenue = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN :s AND :e"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $expenses = (float) $stmt->fetchColumn();

    $profit = $revenue - $expenses;

    /* Revenue grouped by accommodation TYPE (with the number of bookings) */
    $stmt = $pdo->prepare(
        "SELECT a.type AS label, COUNT(*) AS bookings, COALESCE(SUM(b.total_amount),0) AS amount
         FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.status IN ($inStatuses) AND b.check_in BETWEEN :s AND :e
         GROUP BY a.type ORDER BY amount DESC"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $byType = $stmt->fetchAll();

    /* Revenue grouped by individual accommodation (name + its type + bookings) */
    $stmt = $pdo->prepare(
        "SELECT a.name AS label, a.type AS type, COUNT(*) AS bookings,
                COALESCE(SUM(b.total_amount),0) AS amount
         FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.status IN ($inStatuses) AND b.check_in BETWEEN :s AND :e
         GROUP BY a.id, a.name, a.type ORDER BY amount DESC"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $byAccommodation = $stmt->fetchAll();

    /* Payment collection — measured over the SAME bookings that make up
       Revenue above (Confirmed + Completed, check-in inside the period),
       so Revenue, Collected and Outstanding always describe one set of
       bookings.
         collected   = money actually received so far (payments.total_paid,
                       which already accumulates partial payments)
         outstanding = what is still owed: billed amount minus what was
                       received, never below zero — the same formula the
                       Payments page uses for its "Outstanding Balance".
       A booking with no payments row yet is simply unpaid. */
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS bookings,
                COALESCE(SUM(COALESCE(p.total_paid, 0)), 0) AS collected,
                COALESCE(SUM(GREATEST(b.total_amount - COALESCE(p.total_paid, 0), 0)), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN COALESCE(p.total_paid, 0) > 0 THEN 1 ELSE 0 END), 0) AS received_cnt,
                COALESCE(SUM(CASE WHEN b.total_amount - COALESCE(p.total_paid, 0) > 0.004 THEN 1 ELSE 0 END), 0) AS balance_cnt,
                COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS fully_paid_cnt,
                COALESCE(SUM(CASE WHEN p.payment_status = 'partial' THEN 1 ELSE 0 END), 0) AS partial_cnt,
                COALESCE(SUM(CASE WHEN p.id IS NULL OR p.payment_status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_cnt
         FROM bookings b LEFT JOIN payments p ON p.booking_id = b.id
         WHERE b.status IN ($inStatuses) AND b.check_in BETWEEN :s AND :e"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $pay = $stmt->fetch();

    /* Expenses grouped by category (so the printed report can show WHERE
       the money went, not just the total) */
    $stmt = $pdo->prepare(
        "SELECT category AS label, COUNT(*) AS entries, COALESCE(SUM(amount),0) AS amount
         FROM expenses WHERE expense_date BETWEEN :s AND :e
         GROUP BY category ORDER BY amount DESC"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $expensesByCategory = $stmt->fetchAll();

    return [
        'revenue'            => $revenue,
        'expenses'           => $expenses,
        'profit'             => $profit,
        'byType'             => $byType,
        'byAccommodation'    => $byAccommodation,
        'expensesByCategory' => $expensesByCategory,

        'bookingCount'       => (int) $pay['bookings'],
        'collected'          => (float) $pay['collected'],
        'outstanding'        => (float) $pay['outstanding'],
        'fullyPaidCount'     => (int) $pay['fully_paid_cnt'],
        'partialCount'       => (int) $pay['partial_cnt'],
        'unpaidCount'        => (int) $pay['unpaid_cnt'],

        /* Existing keys used by report-financial.php and reports-export.php
           ("Paid" / "Pending"). Same names, now backed by the corrected
           figures: Paid = money received, Pending = balance still owed. */
        'paidTotal'          => (float) $pay['collected'],
        'paidCount'          => (int) $pay['received_cnt'],
        'pendingTotal'       => (float) $pay['outstanding'],
        'pendingCount'       => (int) $pay['balance_cnt'],
    ];
}

/* ------------------------------------------------------------------
   2) PERFORMANCE REPORTS
------------------------------------------------------------------ */
/**
 * Seasonal trends and peak-season labels are always computed over the
 * full calendar year that the selected range ends in (matching the
 * mockup, where the trend chart always spans Jan–Dec regardless of the
 * Week/Month/Year toggle). Only the Performance Rankings block below is
 * scoped to the selected period.
 *
 * "Peak season" = the longest run of consecutive months whose occupancy
 * is at or above that type's own yearly average — a simple, explainable
 * definition rather than a statistically rigorous one, appropriate for
 * a resort of this scale.
 */
function reports_performance_data(PDO $pdo, string $start, string $end): array
{
    $year  = (int) date('Y', strtotime($end));
    $types = RPT_TYPES;

    $stmt = $pdo->query("SELECT type, COUNT(*) AS cnt FROM accommodations GROUP BY type");
    $typeCounts = [];
    foreach ($stmt->fetchAll() as $r) {
        $typeCounts[$r['type']] = (int) $r['cnt'];
    }

    /* Nights booked per type per month, for the whole year */
    $nights = [];
    foreach ($types as $t) {
        for ($m = 1; $m <= 12; $m++) {
            $nights[$t][$m] = 0;
        }
    }

    $inStatuses = "'" . implode("','", RPT_REVENUE_STATUSES) . "'";
    $stmt = $pdo->prepare(
        "SELECT a.type, b.check_in, b.check_out
         FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.status IN ($inStatuses)
           AND b.check_in <= :yend AND b.check_out >= :ystart"
    );
    $stmt->execute(['ystart' => "$year-01-01", 'yend' => "$year-12-31"]);

    $yearStartTs = strtotime("$year-01-01");
    $yearEndTs   = strtotime(($year + 1) . "-01-01");
    foreach ($stmt->fetchAll() as $row) {
        $t = $row['type'];
        if (!isset($nights[$t])) continue;
        $ciTs = max(strtotime($row['check_in']), $yearStartTs);
        $coTs = min(strtotime($row['check_out']), $yearEndTs);
        for ($ts = $ciTs; $ts < $coTs; $ts += 86400) {
            $m = (int) date('n', $ts);
            $nights[$t][$m]++;
        }
    }

    /* Occupancy % per type per month */
    $occupancy = [];
    foreach ($types as $t) {
        $roomCount = $typeCounts[$t] ?? 0;
        foreach (range(1, 12) as $m) {
            $daysInMonth = (int) date('t', mktime(0, 0, 0, $m, 1, $year));
            $maxNights   = $roomCount * $daysInMonth;
            $occupancy[$t][$m] = $maxNights > 0 ? (int) round(($nights[$t][$m] / $maxNights) * 100) : 0;
        }
    }

    /* Peak season per type: longest contiguous run of above-average months */
    $peak = [];
    foreach ($types as $t) {
        $vals = $occupancy[$t];
        $avg  = array_sum($vals) / 12;

        /* A run beats the current best if it is longer, or equally long
           but busier overall — so two one-month "peaks" resolve to the
           busier month instead of simply the earlier one. */
        $runTotal = fn(array $run) => array_sum(array_map(fn($m) => $vals[$m], $run));
        $beats    = fn(array $run, array $best) => count($run) > count($best)
            || (count($run) === count($best) && count($run) > 0 && $runTotal($run) > $runTotal($best));

        $bestRun = [];
        $curRun  = [];
        for ($m = 1; $m <= 12; $m++) {
            if ($avg > 0 && $vals[$m] >= $avg) {
                $curRun[] = $m;
            } else {
                if ($beats($curRun, $bestRun)) $bestRun = $curRun;
                $curRun = [];
            }
        }
        if ($beats($curRun, $bestRun)) $bestRun = $curRun;

        if (empty($bestRun)) {
            $peak[$t] = ['label' => 'No data', 'pct' => 0];
        } else {
            $names = array_map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)), $bestRun);
            $label = count($bestRun) > 1 ? ($names[0] . '-' . end($names)) : $names[0];
            $pct   = (int) round(array_sum(array_map(fn($m) => $vals[$m], $bestRun)) / count($bestRun));
            $peak[$t] = ['label' => $label, 'pct' => $pct];
        }
    }

    /* Performance rankings — scoped to the selected period */
    $stmt = $pdo->prepare(
        "SELECT a.type AS label, COUNT(*) AS bookings, COALESCE(SUM(b.total_amount),0) AS revenue
         FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.status IN ($inStatuses) AND b.check_in BETWEEN :s AND :e
         GROUP BY a.type ORDER BY bookings DESC, revenue DESC, a.type ASC"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $rankings = $stmt->fetchAll();

    return [
        'types'      => $types,
        'unitCounts' => $typeCounts,   // how many accommodation units exist per type
        'occupancy'  => $occupancy,
        'peak'       => $peak,
        'rankings'   => $rankings,
        'year'       => $year,
    ];
}

/* ------------------------------------------------------------------
   3) GUEST INSIGHTS
------------------------------------------------------------------ */
function reports_guest_insights_data(PDO $pdo, string $start, string $end): array
{
    reports_ensure_feedback_table($pdo);
    reports_ensure_guest_country_column($pdo);

    $activeStatuses = "'confirmed','completed','pending'";

    /* Total guests active within the period (registered + walk-in, deduped) */
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT COALESCE(CAST(b.guest_id AS CHAR), CONCAT('g:', COALESCE(b.guest_name, ''), ':', COALESCE(b.email, ''))))
         FROM bookings b
         WHERE b.status IN ($activeStatuses) AND b.check_in BETWEEN :s AND :e"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $totalGuests = (int) $stmt->fetchColumn();

    /* Repeat guests: registered guests active this period whose all-time
       booking count is more than one (walk-in guests can't be reliably
       deduped across visits without an account, so they're excluded here). */
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT b1.guest_id)
         FROM bookings b1
         WHERE b1.guest_id IS NOT NULL
           AND b1.status IN ($activeStatuses)
           AND b1.check_in BETWEEN :s AND :e
           AND (SELECT COUNT(*) FROM bookings b2
                WHERE b2.guest_id = b1.guest_id AND b2.status IN ($activeStatuses)) > 1"
    );
    $stmt->execute(['s' => $start, 'e' => $end]);
    $repeatGuests = (int) $stmt->fetchColumn();

    /* Average guest rating (all-time, published feedback only) + how many
       published reviews it is based on */
    $ratingRow    = $pdo->query("SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM feedback WHERE is_published = 1")->fetch();
    $avgRatingRaw = $ratingRow['avg_rating'] ?? null;
    $avgRating    = $avgRatingRaw !== null ? round((float) $avgRatingRaw, 1) : null;
    $ratingCount  = (int) ($ratingRow['cnt'] ?? 0);

    /* Top returning guests (registered, all-time visit count).
       A guest only counts as "returning" once they have booked at least
       $minVisits times, so first-time guests (1 booking) are not listed.
       Change $minVisits to 3 to require two returns before listing. */
    $minVisits = 2;
    $stmt = $pdo->prepare(
        "SELECT g.id, CONCAT(g.first_name, ' ', g.last_name) AS name, g.country,
                COUNT(b.id) AS visits
         FROM guests g
         JOIN bookings b ON b.guest_id = g.id AND b.status IN ($activeStatuses)
         GROUP BY g.id, g.first_name, g.last_name, g.country
         HAVING COUNT(b.id) >= :minv
         ORDER BY visits DESC, name ASC
         LIMIT 5"
    );
    $stmt->bindValue('minv', $minVisits, PDO::PARAM_INT);
    $stmt->execute();
    $topGuests = $stmt->fetchAll();

    $mostBookedStmt = $pdo->prepare(
        "SELECT a.name, COUNT(*) c FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.guest_id = :gid AND b.status IN ($activeStatuses)
         GROUP BY a.id, a.name ORDER BY c DESC LIMIT 1"
    );
    $ratingStmt = $pdo->prepare("SELECT AVG(rating) FROM feedback WHERE guest_id = :gid AND is_published = 1");

    foreach ($topGuests as &$g) {
        $mostBookedStmt->execute(['gid' => $g['id']]);
        $g['most_booked'] = $mostBookedStmt->fetchColumn() ?: '—';

        $ratingStmt->execute(['gid' => $g['id']]);
        $r = $ratingStmt->fetchColumn();
        $g['rating']  = $r !== null ? round((float) $r, 1) : null;
        $g['country'] = $g['country'] ?: 'Philippines';
    }
    unset($g);

    return [
        'totalGuests'  => $totalGuests,
        'repeatGuests' => $repeatGuests,
        'avgRating'    => $avgRating,
        'ratingCount'  => $ratingCount,
        'topGuests'    => $topGuests,
    ];
}

/* ------------------------------------------------------------------
   4) INVENTORY REPORTS
   Reuses the inventory_items / inventory_logs tables that
   admin/inventory.php already creates — no new tables needed here.
------------------------------------------------------------------ */
function reports_inv_status(array $item): string
{
    $qty = (int) $item['quantity'];
    $min = (int) $item['min_stock_level'];
    if ($qty <= 0) return 'out_of_stock';
    if ($qty <= $min) return 'low_stock';
    return 'in_stock';
}

function reports_inventory_data(PDO $pdo, string $start, string $end): array
{
    $items = $pdo->query("SELECT * FROM inventory_items ORDER BY name ASC")->fetchAll();

    $totalItems = count($items);
    $lowStock   = 0;
    $totalValue = 0.0;
    $totalUnits = 0;
    foreach ($items as $it) {
        if (reports_inv_status($it) === 'low_stock' || reports_inv_status($it) === 'out_of_stock') {
            $lowStock++;
        }
        $totalValue += ((float) $it['quantity']) * ((float) $it['cost_per_unit']);
        $totalUnits += (int) $it['quantity'];
    }

    /* Most used items within the period, by usage-log quantity.
       "Usage rate" = usage this period / (usage this period + current
       stock on hand) — a simple turnover indicator, not a share of a
       fixed total (so the bars don't need to sum to 100%). */
    $stmt = $pdo->prepare(
        "SELECT i.id, i.name, i.quantity, COALESCE(SUM(ABS(l.change_qty)), 0) AS used
         FROM inventory_items i
         LEFT JOIN inventory_logs l ON l.item_id = i.id
             AND l.reason = 'usage'
             AND l.created_at BETWEEN :s AND :e
         GROUP BY i.id, i.name, i.quantity
         HAVING used > 0
         ORDER BY used DESC
         LIMIT 6"
    );
    $stmt->execute(['s' => $start . ' 00:00:00', 'e' => $end . ' 23:59:59']);

    $mostUsed = [];
    foreach ($stmt->fetchAll() as $u) {
        $used  = (int) $u['used'];
        $denom = $used + (int) $u['quantity'];
        $rate  = $denom > 0 ? (int) round(($used / $denom) * 100) : 0;
        $mostUsed[] = ['name' => $u['name'], 'used' => $used, 'stock' => (int) $u['quantity'], 'pct' => $rate];
    }

    return [
        'items'      => $items,
        'totalItems' => $totalItems,
        'lowStock'   => $lowStock,
        'totalValue' => $totalValue,
        'totalUnits' => $totalUnits,
        'mostUsed'   => $mostUsed,
    ];
}