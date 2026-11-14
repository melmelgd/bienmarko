<?php
/**
 * admin/dashboard.php
 * -----------------------------------------------------------------------
 * Dashboard Overview — four stat cards, a "Today" panel (Arriving /
 * Departing) and a "Revenue by accommodation type" panel.
 *
 * RULES THIS PAGE FOLLOWS
 *   • Cancelled bookings are excluded everywhere here. They remain visible
 *     on the Bookings page.
 *   • "Today" is Philippine time (Asia/Manila), whatever the server's own
 *     timezone is.
 *   • Every card links to the matching filtered view on bookings.php.
 *   • Stage names (Upcoming / Checked-in / Completed) and the "fully paid"
 *     rule come from includes/booking-stay.php, so this page, the Bookings
 *     page and the Payments page can never disagree.
 *
 * TOP CARDS
 *   Upcoming Stays    stage Upcoming AND check-in today or later
 *   Active Now        stage Checked-in (an overstay stays here until the
 *                     admin checks the guest out)
 *   Pending Payments  non-cancelled bookings that are not fully paid
 *                     (unpaid or partial), in ANY stage — a completed stay
 *                     that was never fully paid still counts
 *   Booked This Month non-cancelled bookings whose check-in is this month
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

// "Today" must be Philippine time. Set it before anything calls date().
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/booking-stay.php';

$active_page        = 'dashboard';
$page_title         = 'Dashboard Overview';
$breadcrumb         = 'Dashboard';
$page_action_label  = null;        // No action button on this page
$show_period_toggle = false;

$pdo  = bienmarko_db();
$csrf = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Dates (Philippine time)
------------------------------------------------------------------ */
$phNow      = bk_ph_now();
$today      = $phNow->format('Y-m-d');
$monthStart = $phNow->format('Y-m-01');
$monthEnd   = $phNow->format('Y-m-t');
$monthKey   = $phNow->format('Y-m');
$monthLabel = $phNow->format('M Y');
$todayLabel = $phNow->format('M j, Y');

/* ------------------------------------------------------------------
   Make sure the tables / columns this page reads exist (additive,
   idempotent — same guards used by bookings.php and payments.php).
------------------------------------------------------------------ */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `bookings` (
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `reference`        VARCHAR(20)  NOT NULL UNIQUE,
        `guest_id`         INT UNSIGNED NOT NULL,
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
// Walk-in guests have guest_id = NULL and store their name on the booking row.
$pdo->exec("ALTER TABLE `bookings` MODIFY COLUMN `guest_id` INT UNSIGNED NULL DEFAULT NULL");
$pdo->exec("
    ALTER TABLE `bookings`
        ADD COLUMN IF NOT EXISTS `guest_name` VARCHAR(200) NULL DEFAULT NULL AFTER `total_amount`,
        ADD COLUMN IF NOT EXISTS `email`      VARCHAR(200) NULL DEFAULT NULL AFTER `guest_name`
");
bk_stay_migrate($pdo);          // checked_in_at / checked_out_at
bk_ensure_payments_table($pdo); // payments (used for Paid / Unpaid / Partial)

/* Shared SQL pieces */
$notCancelled = "b.status <> 'cancelled'";
$fullyPaid    = bk_fully_paid_sql('b', 'p');
$balanceSql   = bk_balance_sql('b', 'p');
$payStateSql  = bk_pay_state_sql('b', 'p');

/* ------------------------------------------------------------------
   TOP CARD 1 — Upcoming Stays
   Stage Upcoming (not checked in, not cancelled) with check-in today or
   later. A never-arrived booking from a past date is NOT counted here.
------------------------------------------------------------------ */
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings b
     WHERE " . bk_stage_sql('upcoming', 'b') . "
       AND b.check_in >= :today"
);
$st->execute(['today' => $today]);
$statUpcoming = (int) $st->fetchColumn();

/* ------------------------------------------------------------------
   TOP CARD 2 — Active Now
   Everyone currently checked in, regardless of their check-out date, so
   an overstay stays here until the admin checks them out.
------------------------------------------------------------------ */
$st = $pdo->prepare(
    "SELECT COUNT(*) AS active,
            COALESCE(SUM(b.check_out < :today), 0) AS overstay
     FROM bookings b
     WHERE " . bk_stage_sql('checked_in', 'b')
);
$st->execute(['today' => $today]);
$row          = $st->fetch(\PDO::FETCH_ASSOC);
$statActive   = (int) $row['active'];
$statOverstay = (int) $row['overstay'];

/* ------------------------------------------------------------------
   TOP CARD 3 — Pending Payments
   Not cancelled and not fully paid (unpaid or partial), in any stage.
------------------------------------------------------------------ */
$statPending = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM bookings b
     LEFT JOIN payments p ON p.booking_id = b.id
     WHERE {$notCancelled} AND NOT {$fullyPaid}"
)->fetchColumn();

/* ------------------------------------------------------------------
   TOP CARD 4 — Booked This Month
   Not cancelled, check-in date inside the current month.
------------------------------------------------------------------ */
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings b
     WHERE {$notCancelled}
       AND b.check_in BETWEEN :ms AND :me"
);
$st->execute(['ms' => $monthStart, 'me' => $monthEnd]);
$statMonth = (int) $st->fetchColumn();

/* ------------------------------------------------------------------
   TODAY — Arriving
   Non-cancelled bookings whose check-in date is today, whatever their
   stage. Not-yet-arrived guests first, checked-in guests last.
------------------------------------------------------------------ */
$todayCols = "b.id, b.reference, b.status, b.check_in, b.check_out,
              b.checked_in_at, b.checked_out_at,
              a.name AS room_name, a.type AS room_type,
              g.first_name, g.last_name, b.guest_name,
              {$payStateSql} AS pay_state,
              {$balanceSql}  AS balance";
$todayFrom = "FROM bookings b
              JOIN accommodations a ON a.id = b.accommodation_id
              LEFT JOIN guests   g ON g.id = b.guest_id
              LEFT JOIN payments p ON p.booking_id = b.id";

$st = $pdo->prepare(
    "SELECT {$todayCols}
     {$todayFrom}
     WHERE {$notCancelled}
       AND b.check_in = :today
     ORDER BY CASE WHEN b.checked_in_at  IS NULL THEN 0
                   WHEN b.checked_out_at IS NULL THEN 1
                   ELSE 2 END ASC,
              b.created_at ASC, b.id ASC"
);
$st->execute(['today' => $today]);
$arriving = $st->fetchAll(\PDO::FETCH_ASSOC);

/* ------------------------------------------------------------------
   TODAY — Departing
   Check-out date is today and the stage is Checked-in or Completed
   (checked out). Upcoming bookings are excluded so a no-show never
   appears as departing. Checked-in first, already checked-out last.
------------------------------------------------------------------ */
$st = $pdo->prepare(
    "SELECT {$todayCols}
     {$todayFrom}
     WHERE {$notCancelled}
       AND b.check_out = :today
       AND (" . bk_stage_sql('checked_in', 'b') . " OR " . bk_stage_sql('completed', 'b') . ")
     ORDER BY CASE WHEN b.checked_out_at IS NULL THEN 0 ELSE 1 END ASC,
              b.created_at ASC, b.id ASC"
);
$st->execute(['today' => $today]);
$departing = $st->fetchAll(\PDO::FETCH_ASSOC);

const DASH_ROW_LIMIT = 5;

/* ------------------------------------------------------------------
   REVENUE BY ACCOMMODATION TYPE
   Period : current month, by CHECK-IN date (same as Booked This Month).
   Scope  : non-cancelled bookings only.
   Collected = money received (payments.total_paid).
   Pending   = unpaid balance (billed - paid, never negative).
------------------------------------------------------------------ */
$st = $pdo->prepare(
    "SELECT a.type AS type,
            COALESCE(SUM(p.total_paid), 0) AS collected,
            COALESCE(SUM({$balanceSql}), 0) AS pending
     FROM bookings b
     JOIN accommodations a ON a.id = b.accommodation_id
     LEFT JOIN payments p ON p.booking_id = b.id
     WHERE {$notCancelled}
       AND b.check_in BETWEEN :ms AND :me
     GROUP BY a.type"
);
$st->execute(['ms' => $monthStart, 'me' => $monthEnd]);

// The four categories are always shown, even at ₱0.
$revCats = [
    'room'    => ['label' => 'Room',       'color' => 'var(--gold-400)', 'amount' => 0.0],
    'event'   => ['label' => 'Event hall', 'color' => 'var(--blue)',     'amount' => 0.0],
    'cottage' => ['label' => 'Cottage',    'color' => 'var(--emerald)',  'amount' => 0.0],
    'pool'    => ['label' => 'Pool',       'color' => 'var(--fuchsia)',  'amount' => 0.0],
];
$revOther      = 0.0;   // custom accommodation types ("Other") — shown only if > 0
$totalPending  = 0.0;
$totalCollected = 0.0;

foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $key = dash_rev_bucket((string) $r['type']);
    $collected = (float) $r['collected'];
    if ($key === 'other') {
        $revOther += $collected;
    } else {
        $revCats[$key]['amount'] += $collected;
    }
    $totalCollected += $collected;
    $totalPending   += (float) $r['pending'];
}
$totalCollected = round($totalCollected, 2);
$totalPending   = round($totalPending, 2);

if ($revOther > 0) {
    $revCats['other'] = ['label' => 'Other', 'color' => 'var(--red)', 'amount' => $revOther];
}
foreach ($revCats as $k => $c) {
    // Zero total => 0% and an empty bar (never divide by zero).
    $width = $totalCollected > 0 ? min(100, max(0, ($c['amount'] / $totalCollected) * 100)) : 0;
    $revCats[$k]['width'] = $width;
    $revCats[$k]['pct']   = (int) round($width);
}

/* ------------------------------------------------------------------
   Helpers
------------------------------------------------------------------ */
function dash_e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function dash_peso(float $n): string
{
    return '₱' . number_format($n, 2);
}

/** Which of the four revenue rows an accommodation type belongs to ('other' = custom type). */
function dash_rev_bucket(string $type): string
{
    $t = strtolower(trim($type));
    if ($t === 'room')                            return 'room';
    if ($t === 'event hall')                      return 'event';
    if ($t === 'cottage')                         return 'cottage';
    if ($t === 'swimming pool' || $t === 'pool')  return 'pool';
    return 'other';
}

/** Guest name — registered guests come from `guests`, walk-ins from the booking row. */
function dash_guest_name(array $bk): string
{
    if (!empty($bk['first_name'])) {
        return trim($bk['first_name'] . ' ' . $bk['last_name']);
    }
    return $bk['guest_name'] ?? '—';
}

/** Paid / Unpaid / Partial badge. */
function dash_pay_badge(string $state): string
{
    $map = [
        'paid'    => ['ab--green', 'Paid'],
        'partial' => ['ab--blue',  'Partial'],
        'unpaid'  => ['ab--amber', 'Unpaid'],
    ];
    [$cls, $lbl] = $map[$state] ?? $map['unpaid'];
    return '<span class="ab ' . $cls . '">' . $lbl . '</span>';
}

/** URL on the Bookings page. Always scope=all so the list isn't limited to one week. */
function dash_url(array $params): string
{
    return dash_e('bookings.php?' . http_build_query(['scope' => 'all'] + $params));
}

/** data-* attributes the confirmation dialog reads (see the script at the bottom). */
function dash_stay_attrs(array $bk, string $action): string
{
    $room = $bk['room_name'] !== '' ? $bk['room_name'] : $bk['room_type'];
    $attrs = [
        'data-stay-action' => $action,
        'data-id'          => (int) $bk['id'],
        'data-name'        => dash_guest_name($bk),
        'data-ref'         => $bk['reference'] ?? '',
        'data-room'        => $room,
        'data-in'          => date('M j, Y', strtotime($bk['check_in'])),
        'data-out'         => date('M j, Y', strtotime($bk['check_out'])),
        'data-pay'         => (string) $bk['pay_state'],
        'data-balance'     => dash_peso((float) $bk['balance']),
    ];
    $out = '';
    foreach ($attrs as $k => $v) {
        $out .= ' ' . $k . '="' . dash_e($v) . '"';
    }
    return $out;
}

/**
 * One row of the Today panel.
 *   $mode 'arrive' : Check-in button on Upcoming rows, Checked-in badge once arrived.
 *   $mode 'depart' : Check-out button on Checked-in rows, Checked-out badge when done.
 */
function dash_render_row(array $bk, string $mode): void
{
    $stage   = bk_stage($bk);
    $payment = (string) $bk['pay_state'];
    $balance = (float) $bk['balance'];

    echo '<li class="t-row">';
    echo   '<div class="t-main">';
    echo     '<div class="t-name">' . dash_e(dash_guest_name($bk)) . '</div>';
    echo     '<div class="t-sub">'  . dash_e($bk['room_name'] !== '' ? $bk['room_name'] : $bk['room_type']) . '</div>';
    echo   '</div>';
    echo   '<div class="t-side">';
    echo     dash_pay_badge($payment);

    if ($mode === 'arrive') {
        if ($stage !== 'upcoming') {
            echo '<span class="ab ab--green">Checked-in</span>';
        }
        if ($stage === 'upcoming') {
            echo '<button type="button" class="t-btn"' . dash_stay_attrs($bk, 'check_in') . '>Check-in</button>';
        }
    } else {
        if ($stage === 'completed') {
            echo '<span class="ab ab--grey">Checked-out</span>';
        }
        if ($stage === 'checked_in') {
            if ($payment === 'paid') {
                echo '<button type="button" class="t-btn"' . dash_stay_attrs($bk, 'check_out') . '>Check-out</button>';
            } else {
                // The API refuses check-out until fully paid; mirror that here.
                echo '<button type="button" class="t-btn" disabled'
                   . ' title="' . dash_e('Collect ' . dash_peso($balance) . ' in Payments before checking out') . '">Check-out</button>';
            }
        }
    }

    echo   '</div>';
    echo '</li>';
}

/** A whole Arriving / Departing column. */
function dash_render_column(string $title, string $tone, array $rows, string $mode, string $emptyText, string $viewAllUrl): void
{
    $total = count($rows);
    echo '<section class="t-col t-col--' . $tone . '">';
    echo   '<div class="t-col-head"><h3>' . dash_e($title) . '</h3><span class="t-count">' . $total . '</span></div>';

    if ($total === 0) {
        echo '<p class="t-empty">' . dash_e($emptyText) . '</p>';
    } else {
        echo '<ul class="t-list">';
        foreach (array_slice($rows, 0, DASH_ROW_LIMIT) as $bk) {
            dash_render_row($bk, $mode);
        }
        echo '</ul>';
        if ($total > DASH_ROW_LIMIT) {
            echo '<a class="t-viewall" href="' . $viewAllUrl . '">View all ' . $total . ' &rarr;</a>';
        }
    }
    echo '</section>';
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
?>

<style>
/* ============================================================
   admin/dashboard.php — page-scoped styles only.
   Uses CSS variables from assets/css/style.css.
   ============================================================ */

.stack { display: flex; flex-direction: column; gap: 24px; }

/* ---------- Top stat cards (each one is a link) ---------- */
.stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; }
.stat-card {
    display: flex; flex-direction: column; justify-content: space-between; gap: 14px;
    min-height: 108px; padding: 22px 26px;
    background: var(--panel); border: 1px solid var(--border); border-radius: 20px;
    color: inherit; text-decoration: none;
    transition: border-color var(--transition), transform var(--transition), background var(--transition);
}
.stat-card:hover { border-color: var(--gold-500); background: var(--panel-alt); transform: translateY(-2px); }
.stat-card:focus-visible { outline: 2px solid var(--gold-400); outline-offset: 2px; }
.stat-label { font-size: 15px; font-weight: 500; color: var(--text-secondary); }
.stat-value { font-family: var(--font-display); font-size: 38px; font-weight: 700; line-height: 1; color: var(--text); }
.stat-hint  { font-size: 12px; color: var(--text-tertiary); margin-top: -6px; }
.stat-hint--warn { color: var(--red); font-weight: 600; }

/* ---------- Panels ---------- */
.dash-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 20px; align-items: start; }
.panel     { background: var(--panel); border: 1px solid var(--border); border-radius: 20px; padding: 26px 28px; }
.panel-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
.panel-title  { margin: 0; font-family: var(--font-display); font-size: 28px; font-weight: 700; color: var(--text); }
.pill {
    font-size: 12.5px; font-weight: 500; white-space: nowrap;
    padding: 6px 14px; border-radius: var(--r-full);
    background: var(--panel-alt); border: 1px solid var(--border); color: var(--text-secondary);
}

/* ---------- Today: Arriving | Departing ---------- */
.t-cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
.t-col  { padding: 0 22px; min-width: 0; }
.t-col:first-child { padding-left: 0; border-right: 1px solid var(--border); }
.t-col:last-child  { padding-right: 0; }
.t-col-head {
    display: flex; align-items: baseline; justify-content: space-between;
    padding-bottom: 10px; margin-bottom: 4px; border-bottom: 1px solid var(--border);
}
.t-col-head h3 { margin: 0; font-size: 17px; font-weight: 600; }
.t-col--arrive .t-col-head h3 { color: var(--emerald); }
.t-col--depart .t-col-head h3 { color: var(--gold-400); }
.t-count { font-size: 13px; color: var(--text-secondary); }
.t-list  { list-style: none; margin: 0; padding: 0; }
.t-row   { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 0; border-bottom: 1px solid var(--border); }
.t-row:last-child { border-bottom: none; }
.t-main  { min-width: 0; }
.t-name  { font-size: 16px; font-weight: 500; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.t-sub   { margin-top: 2px; font-size: 13px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.t-side  { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0; }
.t-empty { margin: 0; padding: 16px 0; font-size: 15px; color: var(--text-secondary); }
.t-viewall { display: inline-block; margin-top: 10px; font-size: 13px; font-weight: 600; color: var(--gold-400); text-decoration: none; }
.t-viewall:hover { color: var(--gold-300); }
.t-btn {
    padding: 5px 14px; border-radius: var(--r-full); border: 1px solid var(--gold-500);
    background: transparent; color: var(--gold-400);
    font-family: inherit; font-size: 12px; font-weight: 700; cursor: pointer;
    transition: background var(--transition), color var(--transition);
}
.t-btn:hover:not(:disabled) { background: var(--gold-500); color: var(--on-accent); }
.t-btn:disabled { opacity: .45; cursor: not-allowed; border-color: var(--border-strong); color: var(--text-tertiary); }

/* ---------- Status badges ---------- */
.ab {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: var(--r-full);
    font-size: 12px; font-weight: 600; letter-spacing: .02em;
}
.ab--green { background: var(--emerald-bg); color: var(--emerald); }
.ab--amber { background: var(--amber-bg);   color: var(--gold-400); }
.ab--red   { background: var(--red-bg);     color: var(--red); }
.ab--blue  { background: var(--blue-bg);    color: var(--blue); }
.ab--grey  { background: var(--panel-alt);  color: var(--text-secondary); }

/* ---------- Revenue by accommodation type ---------- */
.rev-figure { margin: 4px 0 2px; font-family: var(--font-display); font-size: 52px; font-weight: 700; line-height: 1.1; color: var(--gold-400); }
.rev-sub    { margin: 0 0 20px; font-size: 16px; color: var(--text-secondary); }
.rev-sub .sep { margin: 0 8px; color: var(--text-muted); }
.rev-list   { list-style: none; margin: 0; padding: 0; }
.rev-row    { padding: 14px 0; border-top: 1px solid var(--border); }
.rev-top    { display: flex; align-items: center; gap: 12px; }
.rev-dot    { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.rev-name   { flex: 1; font-size: 17px; color: var(--text); }
.rev-amt    { font-weight: 700; font-size: 16px; color: var(--text); }
.rev-pct    { width: 54px; text-align: right; font-size: 15px; color: var(--text-secondary); }
.rev-bar    { height: 6px; margin-top: 10px; border-radius: var(--r-full); background: var(--panel-alt); overflow: hidden; }
.rev-bar > span { display: block; height: 100%; border-radius: var(--r-full); }

/* ---------- Check-in / check-out confirmation dialog (same look as Bookings) ---------- */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.7);
    display: flex; align-items: center; justify-content: center;
    z-index: 500; padding: 20px;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.is-open { opacity: 1; pointer-events: auto; }
.modal {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-lg); width: 100%; max-width: 520px;
    max-height: 90vh; overflow-y: auto;
    transform: translateY(12px); transition: transform .2s;
}
.modal-overlay.is-open .modal { transform: translateY(0); }
.modal-header { padding: 20px 24px 16px; display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 1px solid var(--border); }
.modal-title  { font-family: var(--font-display); font-size: 17px; font-weight: 700; }
.modal-close  { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 4px 8px; font-size: 22px; line-height: 1; border-radius: var(--r-sm); }
.modal-close:hover { color: var(--text); background: var(--panel-alt); }
.modal-body   { padding: 20px 24px; }
.modal-actions { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; flex-wrap: wrap; }
.stay-notice { margin-bottom: 20px; padding: 12px 14px; border-radius: var(--r-md); font-size: 13.5px; line-height: 1.55; border: 1px solid var(--border); background: var(--panel-alt); color: var(--text-secondary); }
.stay-notice strong { color: var(--text); }
.stay-notice--arrive { border-color: var(--gold-600); background: var(--gold-tint-bg); }
.stay-notice--depart { border-color: var(--emerald); background: var(--emerald-bg); }
.stay-notice-warn { display: block; margin-top: 6px; color: var(--gold-400); font-weight: 600; }
.detail-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
.detail-row   { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.detail-label { font-size: 11.5px; color: var(--text-secondary); }
.detail-value { font-size: 14px; color: var(--text); font-weight: 500; overflow-wrap: anywhere; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: var(--r-md); font-family: inherit; font-size: 14px; font-weight: 600; border: 1px solid transparent; cursor: pointer; transition: all var(--transition); }
.btn-secondary { background: var(--panel-alt); color: var(--text); border-color: var(--border); }
.btn-secondary:hover { border-color: var(--border-strong); }
.btn-success   { background: var(--emerald-bg); color: var(--emerald); border-color: var(--emerald); }
.btn-success:hover { background: var(--emerald); color: #fff; }
.btn-success:disabled, .btn-success:disabled:hover { opacity: .45; cursor: not-allowed; background: var(--emerald-bg); color: var(--emerald); }

/* ---------- Toasts ---------- */
.toast-wrap { position: fixed; bottom: 28px; right: 28px; z-index: 900; display: flex; flex-direction: column; gap: 10px; }
.toast {
    padding: 12px 18px; border-radius: var(--r-md);
    font-size: 13.5px; font-weight: 500;
    box-shadow: 0 8px 30px rgba(0,0,0,.5);
    transform: translateX(120%); opacity: 0;
    transition: transform .3s, opacity .3s;
}
.toast.show { transform: translateX(0); opacity: 1; }
.toast--success { background: var(--emerald-bg); border: 1px solid var(--emerald); color: var(--emerald); }
.toast--error   { background: var(--red-bg);     border: 1px solid var(--red);     color: var(--red); }

/* ---------- Responsive ---------- */
@media (max-width: 1200px) {
    .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dash-grid { grid-template-columns: 1fr; }
}
@media (max-width: 700px) {
    .t-cols { grid-template-columns: 1fr; }
    .t-col, .t-col:first-child, .t-col:last-child { padding: 0; border-right: none; }
    .t-col:last-child { margin-top: 22px; }
    .panel { padding: 22px 20px; }
    .panel-title { font-size: 24px; }
    .rev-figure { font-size: 40px; }
}
@media (max-width: 480px) {
    .stat-grid { grid-template-columns: 1fr; }
}
</style>

<div class="stack">

    <!-- ===== Top cards: each opens the matching filtered Bookings view ===== -->
    <div class="stat-grid">
        <a class="stat-card" href="<?php echo dash_url(['status' => 'upcoming', 'from' => $today]); ?>">
            <span class="stat-label">Upcoming stays</span>
            <span class="stat-value"><?php echo $statUpcoming; ?></span>
        </a>

        <a class="stat-card" href="<?php echo dash_url(['status' => 'checked_in']); ?>">
            <span class="stat-label">Active now</span>
            <span class="stat-value"><?php echo $statActive; ?></span>
            <?php if ($statOverstay > 0): ?>
                <span class="stat-hint stat-hint--warn"><?php echo $statOverstay; ?> past check-out date</span>
            <?php endif; ?>
        </a>

        <a class="stat-card" href="<?php echo dash_url(['pay' => 'unpaid']); ?>">
            <span class="stat-label">Pending payments</span>
            <span class="stat-value"><?php echo $statPending; ?></span>
        </a>

        <a class="stat-card" href="<?php echo dash_url(['month' => $monthKey]); ?>">
            <span class="stat-label">Booked this month</span>
            <span class="stat-value"><?php echo $statMonth; ?></span>
        </a>
    </div>

    <div class="dash-grid">

        <!-- ===== Today ===== -->
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title">Today</h2>
                <span class="pill"><?php echo dash_e($todayLabel); ?></span>
            </div>
            <div class="t-cols">
                <?php
                dash_render_column('Arriving', 'arrive', $arriving, 'arrive',
                    'No arrivals today', dash_url(['arriving' => $today]));
                dash_render_column('Departing', 'depart', $departing, 'depart',
                    'No departures today', dash_url(['departing' => $today]));
                ?>
            </div>
        </div>

        <!-- ===== Revenue by accommodation type ===== -->
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title">Revenue by accommodation type</h2>
                <span class="pill"><?php echo dash_e($monthLabel); ?></span>
            </div>

            <p class="rev-figure"><?php echo dash_peso($totalCollected); ?></p>
            <p class="rev-sub">collected <span class="sep">|</span> <?php echo dash_peso($totalPending); ?> pending</p>

            <ul class="rev-list">
                <?php foreach ($revCats as $c): ?>
                    <li class="rev-row">
                        <div class="rev-top">
                            <span class="rev-dot" style="background:<?php echo $c['color']; ?>;"></span>
                            <span class="rev-name"><?php echo dash_e($c['label']); ?></span>
                            <span class="rev-amt"><?php echo dash_peso($c['amount']); ?></span>
                            <span class="rev-pct"><?php echo $c['pct']; ?>%</span>
                        </div>
                        <div class="rev-bar">
                            <span style="width:<?php echo number_format($c['width'], 2, '.', ''); ?>%;background:<?php echo $c['color']; ?>;"></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

    </div>

</div><!-- /.stack -->

<div class="toast-wrap" id="toastWrap"></div>

<!-- ===== Check-in / Check-out confirmation ===== -->
<div class="modal-overlay" id="stayModal" aria-modal="true" role="dialog" aria-labelledby="stayTitle">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="stayTitle">Confirm check-in</div>
            <button type="button" class="modal-close" id="stayClose" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="stay-notice" id="stayNotice"></div>
            <div class="detail-grid">
                <div class="detail-row"><span class="detail-label">Guest</span><span class="detail-value" id="stayName">—</span></div>
                <div class="detail-row"><span class="detail-label">Reference</span><span class="detail-value" id="stayRef">—</span></div>
                <div class="detail-row"><span class="detail-label">Accommodation</span><span class="detail-value" id="stayRoom">—</span></div>
                <div class="detail-row"><span class="detail-label">Payment</span><span class="detail-value" id="stayPay">—</span></div>
                <div class="detail-row"><span class="detail-label">Check-in</span><span class="detail-value" id="stayIn">—</span></div>
                <div class="detail-row"><span class="detail-label">Check-out</span><span class="detail-value" id="stayOut">—</span></div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-success" id="stayConfirm">Confirm Check-in</button>
            <button type="button" class="btn btn-secondary" id="stayCancel">Cancel</button>
        </div>
    </div>
</div>

<script>
(function () {
    var CSRF = <?php echo json_encode($csrf); ?>;

    function toast(msg, type) {
        var wrap = document.getElementById('toastWrap');
        var el   = document.createElement('div');
        el.className = 'toast toast--' + (type || 'success');
        el.textContent = msg;
        wrap.appendChild(el);
        setTimeout(function () { el.classList.add('show'); }, 20);
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
        }, 3500);
    }

    // Check-in / Check-out buttons in the Today panel. Clicking one opens a
    // confirmation dialog (same verify-then-confirm flow as the Bookings page);
    // the booking only changes when the admin presses Confirm. Same endpoint
    // and rules as the Bookings page (api/bookings.php); the page reloads on
    // success so every card and list comes straight from the database.
    var modal      = document.getElementById('stayModal');
    var confirmBtn = document.getElementById('stayConfirm');
    var pending    = null;   // { action, id }
    var PAY_LABEL  = { paid: 'Paid', partial: 'Partial', unpaid: 'Unpaid' };

    function setText(id, v) { document.getElementById(id).textContent = v || '—'; }

    function openStayModal(btn) {
        var action = btn.getAttribute('data-stay-action');
        var isIn   = action === 'check_in';
        var pay    = btn.getAttribute('data-pay');
        pending = { action: action, id: parseInt(btn.getAttribute('data-id'), 10) };

        document.getElementById('stayTitle').textContent = isIn ? 'Confirm check-in' : 'Confirm check-out';
        confirmBtn.textContent = isIn ? 'Confirm Check-in' : 'Confirm Check-out';
        confirmBtn.disabled = false;

        setText('stayName', btn.getAttribute('data-name'));
        setText('stayRef',  btn.getAttribute('data-ref'));
        setText('stayRoom', btn.getAttribute('data-room'));
        setText('stayIn',   btn.getAttribute('data-in'));
        setText('stayOut',  btn.getAttribute('data-out'));
        setText('stayPay',  (PAY_LABEL[pay] || pay) +
            (pay !== 'paid' ? ' · ' + btn.getAttribute('data-balance') + ' due' : ''));

        var notice = document.getElementById('stayNotice');
        notice.className = 'stay-notice ' + (isIn ? 'stay-notice--arrive' : 'stay-notice--depart');
        notice.innerHTML = isIn
            ? '<strong>Guest arriving at the front desk?</strong> Verify the guest\'s name, contact details, ' +
              'accommodation and dates below match the reservation, then confirm their arrival.'
            : '<strong>Guest leaving?</strong> Confirm check-out only once the guest has settled up and left. ' +
              'The booking will move to Completed.';

        modal.classList.add('is-open');
        confirmBtn.focus();
    }

    function closeStayModal() {
        modal.classList.remove('is-open');
        pending = null;
    }

    document.querySelectorAll('[data-stay-action]').forEach(function (btn) {
        btn.addEventListener('click', function () { openStayModal(btn); });
    });
    document.getElementById('stayClose').addEventListener('click', closeStayModal);
    document.getElementById('stayCancel').addEventListener('click', closeStayModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeStayModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeStayModal();
    });

    confirmBtn.addEventListener('click', function () {
        if (!pending) return;
        var action = pending.action;
        var id     = pending.id;
        var label  = confirmBtn.textContent;
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Saving…';

        fetch('api/bookings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, id: id, csrf_token: CSRF })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                toast(action === 'check_in'
                    ? 'Guest checked in.'
                    : 'Guest checked out — booking completed.', 'success');
                closeStayModal();
                setTimeout(function () { window.location.reload(); }, 700);
            } else {
                toast(data.error || 'Could not update the booking.', 'error');
                if (data.refresh) {
                    closeStayModal();
                    setTimeout(function () { window.location.reload(); }, 1800);
                } else {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = label;
                }
            }
        })
        .catch(function () {
            toast('Network error. Please try again.', 'error');
            confirmBtn.disabled = false;
            confirmBtn.textContent = label;
        });
    });
})();



// cron/send-reminders.php is called every 15 minutes, but only once per browser session. The cron script itself checks the last run time in the database, so this is just a client-side throttle to avoid hammering the server with requests from multiple open tabs.

  (function() {
    const lastRun = localStorage.getItem('cron_last_run');
    const now = Date.now();
    const fifteenMinutes = 15 * 60 * 1000;

    // Only trigger if 15 minutes have passed since this browser last ran it
    if (!lastRun || (now - lastRun) > fifteenMinutes) {
      localStorage.setItem('cron_last_run', now);
      fetch('/cron/send-reminders.php?key=test12345')
        .catch(() => {}); // Silently ignore network errors
    }
  })();

</script>

<?php require __DIR__ . '/includes/foot.php'; ?>