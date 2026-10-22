<?php
/**
 * admin/bookings.php
 * -----------------------------------------------------------------------
 * Bookings management, organised around the front-desk workflow:
 *
 *   ?view=upcoming  Upcoming Arrivals  — active, guest has NOT checked in
 *   ?view=staying   Currently Staying  — admin recorded the check-in, guest
 *                                        has not been checked out yet
 *   ?view=all       All Bookings       — every record (Upcoming, Checked-in,
 *                                        Completed, Cancelled) with the
 *                                        search box, status filter and paging
 *
 * Statuses are NEVER changed by a date or a clock. A guest moves
 * Upcoming → Checked-in → Completed only when the admin confirms it in the
 * booking's detail dialog (api/bookings.php: check_in / check_out). The
 * stage rules live in includes/booking-stay.php.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/booking-stay.php';

$active_page       = 'bookings';
$page_title        = 'Bookings';
$breadcrumb        = 'Bookings';
$page_action_label = 'New Booking';
$show_period_toggle = false;

$pdo   = bienmarko_db();
$today = date('Y-m-d');
$csrf  = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Ensure bookings table has all columns (migration guard)
------------------------------------------------------------------ */
$pdo->exec("ALTER TABLE `bookings` MODIFY COLUMN `guest_id` INT UNSIGNED NULL DEFAULT NULL");
$pdo->exec("
    ALTER TABLE `bookings`
        ADD COLUMN IF NOT EXISTS `guest_name`     VARCHAR(200) NULL DEFAULT NULL     AFTER `total_amount`,
        ADD COLUMN IF NOT EXISTS `email`          VARCHAR(200) NULL DEFAULT NULL     AFTER `guest_name`,
        ADD COLUMN IF NOT EXISTS `phone`          VARCHAR(30)  NULL DEFAULT NULL     AFTER `email`,
        ADD COLUMN IF NOT EXISTS `address`        VARCHAR(255) NULL DEFAULT NULL     AFTER `phone`,
        ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','gcash') NULL DEFAULT 'cash' AFTER `address`,
        ADD COLUMN IF NOT EXISTS `booking_type`   ENUM('registered','guest') NOT NULL DEFAULT 'registered' AFTER `payment_method`
");

// Companion / guest-mate names — JSON array, populated by the guest,
// registered-user, or admin walk-in booking flows for Swimming Pool
// bookings so front desk can see who's in the group.
$pdo->exec("
    ALTER TABLE `bookings`
        ADD COLUMN IF NOT EXISTS `companion_names` TEXT NULL DEFAULT NULL AFTER `booking_type`
");

// Manual front-desk check-in / check-out timestamps (see booking-stay.php).
bk_stay_migrate($pdo);

// Optional: if the bookings table ever gets a `check_in_time` column, the
// Upcoming / All sorts use it as the tie-breaker between guests arriving on
// the same date (earliest first) and it is shown next to the date. Today the
// table only stores a check-in DATE, so this stays off and ties fall back to
// the booking's creation order.
$hasCheckInTime = (bool) $pdo->query("SHOW COLUMNS FROM `bookings` LIKE 'check_in_time'")->fetch();

/* ------------------------------------------------------------------
   Request: view, search, status filter, page
------------------------------------------------------------------ */
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');
$allowedStatuses = ['', 'upcoming', 'checked_in', 'completed', 'cancelled'];
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = '';

/* No ?view= → Upcoming Arrivals (the front desk's working list). A link that
   carries a search or status filter but no view (e.g. the notification bell's
   "bookings.php?search=BM-XXXX") must still find ANY booking, so it opens
   All Bookings. */
$view = $_GET['view'] ?? '';
if (!in_array($view, ['upcoming', 'staying', 'all'], true)) {
    $view = ($filterSearch !== '' || $filterStatus !== '') ? 'all' : 'upcoming';
}
if ($view !== 'all') {
    $filterStatus = '';   // the status filter belongs to All Bookings only
}

/* Pagination — 10 bookings per page, like the Payments and Dashboard tables */
$perPage     = 10;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

/* ------------------------------------------------------------------
   Counts — drive the tab badges and the stat cards. They are computed
   from the same stage conditions as the lists, on every page load, so a
   status change is reflected the moment the page refreshes.
------------------------------------------------------------------ */
$countRow = $pdo->query(
    "SELECT
        COUNT(*)                                          AS total,
        COALESCE(SUM(" . bk_stage_sql('upcoming',   '') . "), 0) AS upcoming,
        COALESCE(SUM(" . bk_stage_sql('checked_in', '') . "), 0) AS checked_in,
        COALESCE(SUM(" . bk_stage_sql('completed',  '') . "), 0) AS completed,
        COALESCE(SUM(" . bk_stage_sql('cancelled',  '') . "), 0) AS cancelled
     FROM bookings"
)->fetch();
$statTotal      = (int) $countRow['total'];
$statUpcoming   = (int) $countRow['upcoming'];
$statCheckedIn  = (int) $countRow['checked_in'];
$statCompleted  = (int) $countRow['completed'];
$statCancelled  = (int) $countRow['cancelled'];

/* ------------------------------------------------------------------
   Build main query
------------------------------------------------------------------ */
$whereClauses = [];
$params       = [];

if ($view === 'upcoming') {
    $whereClauses[] = bk_stage_sql('upcoming');
} elseif ($view === 'staying') {
    $whereClauses[] = bk_stage_sql('checked_in');
} elseif ($filterStatus !== '') {
    $whereClauses[] = bk_stage_sql($filterStatus);
}

if ($filterSearch !== '') {
    $whereClauses[] = '(
        b.reference    LIKE :s1
        OR b.guest_name LIKE :s2
        OR b.email      LIKE :s3
        OR g.first_name LIKE :s4
        OR g.last_name  LIKE :s5
        OR g.email      LIKE :s6
        OR a.name       LIKE :s7
        OR CONCAT_WS(\' \', g.first_name, g.last_name) LIKE :s8
    )';
    $like = '%' . $filterSearch . '%';
    foreach (['s1','s2','s3','s4','s5','s6','s7','s8'] as $k) {
        $params[$k] = $like;
    }
}

$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

/* Sorting — every ORDER BY ends in b.id so the order is fully deterministic
   and identical on every page of the results.
     upcoming : today & future arrivals first (nearest date first), then any
                arrivals whose date has already passed (still Upcoming until
                the admin acts — they are never auto-checked-in); ties on a
                date → check-in time if the column exists, then booking order.
     staying  : soonest expected check-out first (overdue ones lead).
     all      : check-in date ascending, ties → check-in time, booking order. */
$timeSort = $hasCheckInTime ? 'b.check_in_time IS NULL, b.check_in_time ASC, ' : '';
if ($view === 'upcoming') {
    $orderBy = '(b.check_in < ' . $pdo->quote($today) . ') ASC, b.check_in ASC, '
             . $timeSort . 'b.created_at ASC, b.id ASC';
} elseif ($view === 'staying') {
    $orderBy = 'b.check_out ASC, b.check_in ASC, b.created_at ASC, b.id ASC';
} else {
    $orderBy = 'b.check_in ASC, ' . $timeSort . 'b.created_at ASC, b.id ASC';
}

/* Total matching rows (all filters applied, no LIMIT) — drives the
   pagination bar and the "N bookings" count in the table header. */
$countSql = "SELECT COUNT(*)
             FROM bookings b
             JOIN accommodations a ON a.id = b.accommodation_id
             LEFT JOIN guests     g ON g.id = b.guest_id
             {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalBookings = (int) $countStmt->fetchColumn();

$totalPages  = max(1, (int) ceil($totalBookings / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

$timeSelect = $hasCheckInTime ? 'b.check_in_time,' : '';
$sql = "SELECT
            b.id, b.reference, b.check_in, b.check_out,
            {$timeSelect}
            b.guests_count, b.status, b.total_amount,
            b.checked_in_at, b.checked_out_at,
            b.booking_type, b.payment_method, b.notes,
            b.accommodation_id,
            b.guest_name, b.email AS guest_book_email,
            b.phone AS guest_phone, b.address AS guest_address,
            b.companion_names,
            b.created_at,
            a.name AS room_name, a.type AS room_type, a.price AS room_price,
            g.first_name, g.last_name, g.email AS reg_email, g.phone AS reg_phone
        FROM bookings b
        JOIN accommodations a ON a.id = b.accommodation_id
        LEFT JOIN guests     g ON g.id = b.guest_id
        {$where}
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

/* ------------------------------------------------------------------
   Helpers
------------------------------------------------------------------ */
function bk_name(array $bk): string
{
    if (!empty($bk['first_name'])) {
        return trim($bk['first_name'] . ' ' . $bk['last_name']);
    }
    return $bk['guest_name'] ?? '—';
}

function bk_email(array $bk): string
{
    return $bk['reg_email'] ?? $bk['guest_book_email'] ?? '';
}

function bk_phone(array $bk): string
{
    return $bk['reg_phone'] ?? $bk['guest_phone'] ?? '';
}

/** Status badge — Upcoming, Checked-in, Completed or Cancelled. */
function bk_stage_badge(array $bk): string
{
    $map = [
        'upcoming'   => ['ab--amber', 'Upcoming'],
        'checked_in' => ['ab--green', 'Checked-in'],
        'completed'  => ['ab--blue',  'Completed'],
        'cancelled'  => ['ab--red',   'Cancelled'],
    ];
    [$cls, $lbl] = $map[bk_stage($bk)];
    return '<span class="ab ' . $cls . '">' . $lbl . '</span>';
}

/** Small "Today / Tomorrow / Overdue" tag under a date — a label only; it never changes a status. */
function bk_date_chip(string $date, string $today, string $overdueLabel): string
{
    if ($date === $today) {
        return '<span class="chip chip--gold">Today</span>';
    }
    if ($date === date('Y-m-d', strtotime($today . ' +1 day'))) {
        return '<span class="chip chip--blue">Tomorrow</span>';
    }
    if ($date < $today) {
        return '<span class="chip chip--red">' . htmlspecialchars($overdueLabel, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return '';
}

/** Check-in time (only when the optional column exists and has a value). */
function bk_time_label(array $bk): string
{
    if (empty($bk['check_in_time'])) {
        return '';
    }
    $t = strtotime($bk['check_in_time']);
    return $t ? date('g:i A', $t) : '';
}

/** Link to another view, keeping the current search term. */
function bk_view_url(string $view, string $search): string
{
    $q = ['view' => $view];
    if ($search !== '') {
        $q['search'] = $search;
    }
    return htmlspecialchars('bookings.php?' . http_build_query($q), ENT_QUOTES, 'UTF-8');
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
?>

<style>
/* ============================================================
   admin/bookings.php — page-scoped styles
   ============================================================ */
.stack       { display: flex; flex-direction: column; gap: 24px; }
.stat-grid   { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; }
.stat-card   { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 16px 18px; cursor: pointer; transition: border-color var(--transition), box-shadow var(--transition); }
.stat-card:hover, .stat-card.is-active { border-color: var(--gold-500); box-shadow: 0 0 0 2px rgba(245,166,35,.15); }
.stat-card-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.stat-label    { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.stat-icon     { width: 34px; height: 34px; border-radius: var(--r-md); display: flex; align-items: center; justify-content: center; }
.stat-icon--emerald { background: var(--emerald-bg); color: var(--emerald); }
.stat-icon--blue    { background: var(--blue-bg);    color: var(--blue); }
.stat-icon--amber   { background: var(--amber-bg);   color: var(--gold-400); }
.stat-icon--red     { background: var(--red-bg);     color: var(--red); }
.stat-icon--grey    { background: var(--panel-alt);  color: var(--text-secondary); }
.stat-value  { font-family: var(--font-display); font-size: 24px; font-weight: 800; color: var(--text); display: block; }

/* Toolbar */
.toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.toolbar-search { position: relative; flex: 1; min-width: 220px; }
.toolbar-search input {
    width: 100%; padding: 9px 14px 9px 38px;
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-md); color: var(--text); font-size: 14px;
    transition: border-color var(--transition);
}
.toolbar-search input:focus { outline: none; border-color: var(--gold-500); }
.toolbar-search input::placeholder { color: var(--text-tertiary); }
.toolbar-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); pointer-events: none; }
.filter-select {
    padding: 9px 14px; background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-md); color: var(--text); font-size: 14px;
    cursor: pointer; transition: border-color var(--transition);
}
.filter-select:focus { outline: none; border-color: var(--gold-500); }

/* Table panel */
.panel       { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); overflow: hidden; }
.table-wrap  { overflow: hidden; }
.table-head  { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 0; margin-bottom: 14px; }
.panel-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: var(--text); }
.results-count { font-size: 12.5px; color: var(--text-secondary); }
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; white-space: nowrap; }
thead th { padding: 10px 14px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-tertiary); border-bottom: 1px solid var(--border); text-align: left; }
tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; cursor: pointer; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--panel-alt); }
tbody td { padding: 12px 14px; font-size: 13.5px; color: var(--text); vertical-align: middle; }
.td-guest  { font-weight: 600; }
.td-sub    { font-size: 11.5px; color: var(--text-secondary); margin-top: 1px; }
.td-amount { font-family: var(--font-display); font-weight: 700; }
.td-actions { display: flex; gap: 6px; }

/* Status badges */
.ab { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: var(--r-full); font-size: 11px; font-weight: 700; letter-spacing: .03em; }
.ab--green { background: var(--emerald-bg); color: var(--emerald); }
.ab--amber { background: var(--amber-bg);   color: var(--gold-400); }
.ab--red   { background: var(--red-bg);     color: var(--red); }
.ab--blue  { background: var(--blue-bg);    color: var(--blue); }
.ab--grey  { background: var(--panel-alt);  color: var(--text-secondary); }

/* Type badge */
.type-badge { display: inline-flex; align-items: center; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--r-full); }
.type-badge--registered { background: var(--blue-bg); color: var(--blue); }
.type-badge--guest      { background: var(--panel-alt); color: var(--text-secondary); border: 1px solid var(--border); }

/* Action buttons */
.btn-sm { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: var(--r-sm); font-size: 12px; font-weight: 600; border: 1px solid var(--border); background: var(--panel-alt); color: var(--text-secondary); cursor: pointer; transition: all var(--transition); }
.btn-sm:hover { color: var(--text); border-color: var(--border-strong); }
.btn-sm--gold { background: var(--gold-tint-bg); border-color: var(--gold-600); color: var(--gold-400); }
.btn-sm--gold:hover { background: var(--gold-600); color: var(--on-accent); }
.btn-sm--danger { background: var(--red-bg); border-color: var(--red); color: var(--red); }
.btn-sm--danger:hover { background: var(--red); color: #fff; }
.btn-sm--success { background: var(--emerald-bg); border-color: var(--emerald); color: var(--emerald); }
.btn-sm--success:hover { background: var(--emerald); color: #fff; }

/* Empty state */
.empty-state { padding: 50px 24px; text-align: center; }
.empty-state-icon    { margin: 0 auto 14px; color: var(--text-muted); }
.empty-state-title   { font-family: var(--font-display); font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.empty-state-message { font-size: 13.5px; color: var(--text-secondary); }

/* Modal */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.7);
    display: flex; align-items: center; justify-content: center;
    z-index: 500; padding: 20px;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.is-open { opacity: 1; pointer-events: auto; }
.modal {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-lg); width: 100%; max-width: 640px;
    max-height: 90vh; overflow-y: auto;
    transform: translateY(12px); transition: transform .2s;
}
.modal-overlay.is-open .modal { transform: translateY(0); }
.modal-header {
    padding: 20px 24px 0;
    display: flex; align-items: flex-start; justify-content: space-between;
    border-bottom: 1px solid var(--border); padding-bottom: 16px;
}
.modal-title { font-family: var(--font-display); font-size: 17px; font-weight: 700; }
.modal-close { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 4px; border-radius: var(--r-sm); }
.modal-close:hover { color: var(--text); background: var(--panel-alt); }
.modal-body { padding: 20px 24px; }
.modal-section { margin-bottom: 20px; }
.modal-section:last-child { margin-bottom: 0; }
.modal-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-tertiary); margin-bottom: 12px; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; }
.detail-row { display: flex; flex-direction: column; gap: 2px; }
.detail-label { font-size: 11.5px; color: var(--text-secondary); }
.detail-value { font-size: 14px; color: var(--text); font-weight: 500; }
.modal-actions { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: var(--r-md); font-size: 14px; font-weight: 600; border: 1px solid transparent; cursor: pointer; transition: all var(--transition); }
.btn-primary   { background: var(--gold-500); color: var(--on-accent); border-color: var(--gold-600); }
.btn-primary:hover { background: var(--gold-400); }
.btn-secondary { background: var(--panel-alt); color: var(--text); border-color: var(--border); }
.btn-secondary:hover { border-color: var(--border-strong); }
.btn-danger    { background: var(--red-bg); color: var(--red); border-color: var(--red); }
.btn-danger:hover { background: var(--red); color: #fff; }
.btn-success   { background: var(--emerald-bg); color: var(--emerald); border-color: var(--emerald); }
.btn-success:hover { background: var(--emerald); color: #fff; }
.btn-blue      { background: var(--blue-bg); color: var(--blue); border-color: var(--blue); }
.btn-blue:hover { background: var(--blue); color: #fff; }

/* Toast */
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

/* New booking modal */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); }
.form-input {
    padding: 9px 12px; background: var(--input-bg);
    border: 1px solid var(--border); border-radius: var(--r-sm);
    color: var(--text); font-size: 14px; width: 100%;
    transition: border-color var(--transition);
}
.form-input:focus { outline: none; border-color: var(--gold-500); }
.form-input::placeholder { color: var(--text-tertiary); }

/* New Booking: availability-aware date picker */
.date-picker { position: relative; }
.date-picker-trigger {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    font-family: inherit; text-align: left; cursor: pointer;
}
.date-picker-trigger .is-placeholder { color: var(--text-tertiary); }
.date-picker-trigger.has-error { border-color: var(--red); }
.date-picker-icon { width: 16px; height: 16px; color: var(--text-tertiary); flex-shrink: 0; }
.date-picker-pop {
    display: none;
    position: absolute; top: calc(100% + 6px); left: 0; z-index: 50;
    width: 266px; padding: 12px;
    background: var(--panel-alt); border: 1px solid var(--border-strong);
    border-radius: var(--r-md); box-shadow: 0 12px 32px rgba(0,0,0,.5);
}
.date-picker.is-open .date-picker-pop { display: block; }
.date-picker-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.date-picker-month { font-family: var(--font-display); font-size: 13px; font-weight: 700; color: var(--text); }
.date-picker-nav {
    width: 26px; height: 26px; border-radius: var(--r-sm); border: 1px solid var(--border);
    background: var(--panel); color: var(--text-secondary); cursor: pointer;
    font-size: 15px; line-height: 1; transition: border-color var(--transition), color var(--transition);
}
.date-picker-nav:hover { border-color: var(--gold-500); color: var(--gold-400); }
.date-picker-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.date-picker-weekdays { margin-bottom: 4px; }
.date-picker-weekdays span { font-size: 10px; font-weight: 700; text-align: center; color: var(--text-tertiary); }
.date-picker-cell {
    aspect-ratio: 1 / 1; border: none; background: none; border-radius: var(--r-sm);
    color: var(--text); font-size: 12.5px; font-family: inherit; cursor: pointer;
    transition: background var(--transition), color var(--transition);
}
.date-picker-cell:hover:not(.is-disabled) { background: var(--gold-tint-bg); color: var(--gold-400); }
.date-picker-cell.is-empty { cursor: default; }
.date-picker-cell.is-disabled { color: var(--text-muted); cursor: not-allowed; }
.date-picker-cell.is-booked { color: var(--red); text-decoration: line-through; }
.date-picker-cell.is-selected { background: var(--gold-500); color: var(--on-accent); font-weight: 700; }
.date-picker-note { margin-top: 8px; font-size: 11.5px; color: var(--text-tertiary); text-align: center; }
.date-picker-legend { margin-top: 8px; display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-tertiary); }
.dp-swatch { width: 9px; height: 9px; border-radius: 3px; display: inline-block; background: var(--red-bg); border: 1px solid var(--red); }

/* View tabs — Upcoming Arrivals / Currently Staying / All Bookings */
.view-tabs { display: flex; gap: 6px; flex-wrap: wrap; padding: 5px; background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); }
.view-tab {
    flex: 1 1 0; min-width: 170px;
    display: flex; align-items: center; justify-content: center; gap: 9px;
    padding: 11px 16px; border-radius: var(--r-md);
    font-size: 14px; font-weight: 600; color: var(--text-secondary);
    text-decoration: none; border: 1px solid transparent;
    transition: background var(--transition), color var(--transition), border-color var(--transition);
}
.view-tab:hover { color: var(--text); background: var(--panel-alt); }
.view-tab.is-active { background: var(--gold-tint-bg); border-color: var(--gold-600); color: var(--gold-400); }
.view-tab-count {
    min-width: 24px; padding: 1px 8px; text-align: center;
    border-radius: var(--r-full); font-size: 12px; font-weight: 700;
    background: var(--panel-alt); color: var(--text-secondary);
}
.view-tab.is-active .view-tab-count { background: var(--gold-500); color: var(--on-accent); }
.view-hint { font-size: 12.5px; color: var(--text-secondary); margin-top: 2px; }

/* Small date tags + reference code */
.chip { display: inline-block; margin-top: 3px; padding: 1px 7px; border-radius: var(--r-full); font-size: 10.5px; font-weight: 700; letter-spacing: .03em; }
.chip--gold { background: var(--gold-tint-bg); color: var(--gold-400); }
.chip--blue { background: var(--blue-bg);       color: var(--blue); }
.chip--red  { background: var(--red-bg);        color: var(--red); }
.ref-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; letter-spacing: .02em; }

/* Check-in / check-out notice inside the detail dialog */
.stay-notice { margin-bottom: 20px; padding: 12px 14px; border-radius: var(--r-md); font-size: 13.5px; line-height: 1.55; border: 1px solid var(--border); background: var(--panel-alt); color: var(--text-secondary); }
.stay-notice strong { color: var(--text); }
.stay-notice--arrive { border-color: var(--gold-600); background: var(--gold-tint-bg); }
.stay-notice--depart { border-color: var(--emerald); background: var(--emerald-bg); }
.stay-notice-warn { display: block; margin-top: 6px; color: var(--gold-400); font-weight: 600; }

/* Responsive */
@media (max-width: 1100px) { .stat-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px)  { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 540px)  { .detail-grid { grid-template-columns: 1fr; } .form-row { grid-template-columns: 1fr; } }
</style>

<?php
/* Pass bookings to JS as JSON for the detail modal */
$bookingsJson = json_encode(array_map(function ($bk) use ($today) {
    $stage = bk_stage($bk);
    // Same rules the old page used: only confirmed/pending bookings can be
    // edited or cancelled. New: once a guest has checked in, the booking can
    // still be edited (e.g. a stay extension) but can no longer be cancelled.
    $live = in_array($bk['status'], ['confirmed', 'pending'], true);
    $fmt  = static function ($ts) {
        return $ts ? date('M j, Y g:i A', strtotime($ts)) : '';
    };
    return [
        'id'           => (int) $bk['id'],
        'reference'    => $bk['reference'],
        'booking_type' => $bk['booking_type'],
        'status'       => $bk['status'],
        'stage'        => $stage,
        'can_check_in'  => $stage === 'upcoming',
        'can_check_out' => $stage === 'checked_in',
        'can_cancel'    => $stage === 'upcoming' && $live,
        'can_edit'      => in_array($stage, ['upcoming', 'checked_in'], true) && $live,
        'can_restore'   => $stage === 'cancelled',
        'checked_in_at'  => $fmt($bk['checked_in_at']),
        'checked_out_at' => $fmt($bk['checked_out_at']),
        'accommodation_id' => (int) $bk['accommodation_id'],
        'check_in'     => $bk['check_in'],
        'check_out'    => $bk['check_out'],
        'check_in_time' => bk_time_label($bk),
        'guests_count' => (int) $bk['guests_count'],
        'total_amount' => number_format((float) $bk['total_amount'], 2),
        'payment_method'=> $bk['payment_method'] ?? 'cash',
        'notes'        => $bk['notes'] ?? '',
        'room_name'    => $bk['room_name'],
        'room_type'    => $bk['room_type'],
        'room_price'   => number_format((float) $bk['room_price'], 2),
        'guest_name'   => bk_name($bk),
        'guest_email'  => bk_email($bk),
        'guest_phone'  => bk_phone($bk),
        'guest_address'=> $bk['guest_address'] ?? '',
        'is_pool'      => strcasecmp(trim((string) $bk['room_type']), 'Swimming Pool') === 0,
        'companion_names' => (function ($raw) {
            if (!$raw) {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? array_values($decoded) : [];
        })($bk['companion_names'] ?? null),
        'created_at'   => date('M j, Y g:i A', strtotime($bk['created_at'])),
    ];
}, $bookings));
?>

<div class="stack">

    <!-- ===== View tabs ===== -->
    <nav class="view-tabs" aria-label="Booking views">
        <a class="view-tab <?php echo $view === 'upcoming' ? 'is-active' : ''; ?>" href="<?php echo bk_view_url('upcoming', $filterSearch); ?>"
           <?php echo $view === 'upcoming' ? 'aria-current="page"' : ''; ?>>
            <?php echo icon('clock', 'icon-sm'); ?>
            <span>Upcoming Arrivals</span>
            <span class="view-tab-count"><?php echo $statUpcoming; ?></span>
        </a>
        <a class="view-tab <?php echo $view === 'staying' ? 'is-active' : ''; ?>" href="<?php echo bk_view_url('staying', $filterSearch); ?>"
           <?php echo $view === 'staying' ? 'aria-current="page"' : ''; ?>>
            <?php echo icon('bed', 'icon-sm'); ?>
            <span>Currently Staying</span>
            <span class="view-tab-count"><?php echo $statCheckedIn; ?></span>
        </a>
        <a class="view-tab <?php echo $view === 'all' ? 'is-active' : ''; ?>" href="<?php echo bk_view_url('all', $filterSearch); ?>"
           <?php echo $view === 'all' ? 'aria-current="page"' : ''; ?>>
            <?php echo icon('calendar', 'icon-sm'); ?>
            <span>All Bookings</span>
            <span class="view-tab-count"><?php echo $statTotal; ?></span>
        </a>
    </nav>

    <!-- ===== Stat cards (click = filter All Bookings by that status) ===== -->
    <div class="stat-grid">
        <?php
        $cards = [
            ['',           'Total Bookings', $statTotal,     'calendar',       'grey'],
            ['upcoming',   'Upcoming',       $statUpcoming,  'clock',          'amber'],
            ['checked_in', 'Checked-in',     $statCheckedIn, 'calendar-check', 'emerald'],
            ['completed',  'Completed',      $statCompleted, 'check',          'blue'],
            ['cancelled',  'Cancelled',      $statCancelled, 'close',          'red'],
        ];
        foreach ($cards as [$key, $label, $val, $ico, $tint]):
            $isActive = ($view === 'all' && $filterStatus === $key);
        ?>
        <div class="stat-card <?php echo $isActive ? 'is-active' : ''; ?>" onclick="applyStatusFilter('<?php echo $key; ?>')">
            <div class="stat-card-top">
                <span class="stat-label"><?php echo $label; ?></span>
                <span class="stat-icon stat-icon--<?php echo $tint; ?>"><?php echo icon($ico); ?></span>
            </div>
            <span class="stat-value"><?php echo $val; ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===== Toolbar ===== -->
    <div class="toolbar">
        <div class="toolbar-search">
            <span class="toolbar-search-icon"><?php echo icon('inbox', 'icon-sm'); ?></span>
            <input type="text" id="searchInput" placeholder="Search by name, email, reference, or accommodation…"
                   value="<?php echo htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <?php if ($view === 'all'): ?>
        <select class="filter-select" id="statusSelect">
            <option value="">All Statuses</option>
            <option value="upcoming"   <?php echo $filterStatus === 'upcoming'   ? 'selected' : ''; ?>>Upcoming</option>
            <option value="checked_in" <?php echo $filterStatus === 'checked_in' ? 'selected' : ''; ?>>Checked-in</option>
            <option value="completed"  <?php echo $filterStatus === 'completed'  ? 'selected' : ''; ?>>Completed</option>
            <option value="cancelled"  <?php echo $filterStatus === 'cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
        </select>
        <?php endif; ?>
    </div>

    <!-- ===== Bookings Table ===== -->
    <?php
    $viewMeta = [
        'upcoming' => ['Upcoming Arrivals', 'Guests who have not checked in yet — nearest arrival first. A guest stays Upcoming until you confirm they have arrived.', 'calendar-check', 'No upcoming arrivals', 'Active reservations for guests who have not checked in will appear here.'],
        'staying'  => ['Currently Staying', 'Guests you have checked in — earliest check-out first. They stay here until you record their check-out.', 'bed', 'No guests currently staying', 'Guests appear here after you confirm their check-in from Upcoming Arrivals.'],
        'all'      => ['All Bookings', 'Every booking, oldest check-in date first.', 'calendar', 'No bookings yet', 'New bookings from guests will appear here automatically.'],
    ];
    [$viewTitle, $viewHint, $emptyIcon, $emptyTitle, $emptyMsg] = $viewMeta[$view];
    if ($totalBookings === 0 && ($filterSearch !== '' || $filterStatus !== '')) {
        $emptyIcon  = 'inbox';
        $emptyTitle = 'No bookings match';
        $emptyMsg   = 'Try a different search term or clear the filter.';
    }
    $colCount = ($view === 'upcoming') ? 8 : 7;
    ?>
    <div class="panel table-wrap">
        <div class="table-head">
            <div>
                <h2 class="panel-title"><?php echo $viewTitle; ?></h2>
                <div class="view-hint"><?php echo htmlspecialchars($viewHint, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <span class="results-count" id="resultsCount">
                <?php echo $totalBookings; ?> booking<?php echo $totalBookings === 1 ? '' : 's'; ?>
            </span>
        </div>
        <div class="table-scroll">
            <table id="bookingsTable">
                <thead>
                    <tr>
                        <?php if ($view === 'upcoming'): ?>
                            <th>Guest</th>
                            <th>Contact</th>
                            <th>Accommodation</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Actions</th>
                        <?php elseif ($view === 'staying'): ?>
                            <th>Guest</th>
                            <th>Accommodation</th>
                            <th>Check-in</th>
                            <th>Expected Check-out</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Actions</th>
                        <?php else: ?>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="bookingsTbody">
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="<?php echo $colCount; ?>">
                                <?php render_empty_state($emptyIcon, $emptyTitle, $emptyMsg); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $bk):
                            $bid      = (int) $bk['id'];
                            $stage    = bk_stage($bk);
                            $canCancel = $stage === 'upcoming' && in_array($bk['status'], ['confirmed', 'pending'], true);
                            $email    = bk_email($bk);
                            $phone    = bk_phone($bk);
                            $timeLbl  = bk_time_label($bk);
                        ?>
                            <tr data-id="<?php echo $bid; ?>" onclick="openDetail(<?php echo $bid; ?>)">
                                <td>
                                    <div class="td-guest"><?php echo htmlspecialchars(bk_name($bk), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if ($view === 'all' && $email): ?>
                                        <div class="td-sub"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>

                                <?php if ($view === 'upcoming'): ?>
                                    <td>
                                        <?php if ($email): ?><div><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                        <?php if ($phone): ?><div class="td-sub"><?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                        <?php if (!$email && !$phone): ?><span class="td-sub">—</span><?php endif; ?>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <div><?php echo htmlspecialchars($bk['room_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="td-sub"><?php echo htmlspecialchars($bk['room_type'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>

                                <td>
                                    <div><?php echo date('M j, Y', strtotime($bk['check_in'])); ?></div>
                                    <?php if ($timeLbl): ?><div class="td-sub"><?php echo $timeLbl; ?></div><?php endif; ?>
                                    <?php if ($view === 'upcoming') echo bk_date_chip($bk['check_in'], $today, 'Overdue'); ?>
                                </td>
                                <td>
                                    <div><?php echo date('M j, Y', strtotime($bk['check_out'])); ?></div>
                                    <?php if ($view === 'staying') echo bk_date_chip($bk['check_out'], $today, 'Overdue'); ?>
                                </td>

                                <td><span class="ref-code"><?php echo htmlspecialchars($bk['reference'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo bk_stage_badge($bk); ?></td>
                                <td onclick="event.stopPropagation()">
                                    <div class="td-actions">
                                        <?php if ($view === 'upcoming'): ?>
                                            <button class="btn-sm btn-sm--success" onclick="openDetail(<?php echo $bid; ?>)" title="Review details and check the guest in">
                                                <?php echo icon('calendar-check', 'icon-sm'); ?> Check-in
                                            </button>
                                        <?php elseif ($view === 'staying'): ?>
                                            <button class="btn-sm btn-sm--success" onclick="openDetail(<?php echo $bid; ?>)" title="Review details and check the guest out">
                                                <?php echo icon('logout', 'icon-sm'); ?> Check-out
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn-sm btn-sm--gold" onclick="openDetail(<?php echo $bid; ?>)" title="View Details">
                                            <?php echo icon('eye', 'icon-sm'); ?>
                                        </button>
                                        <?php if ($canCancel && $view !== 'staying'): ?>
                                            <button class="btn-sm btn-sm--danger" onclick="quickStatus(<?php echo $bid; ?>, 'cancelled')" title="Cancel">
                                                <?php echo icon('close', 'icon-sm'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination('bookings.php', $currentPage, $perPage, $totalBookings, [
            'view'   => $view,
            'status' => $filterStatus,
            'search' => $filterSearch,
        ], 'booking'); ?>
    </div>

</div><!-- /.stack -->

<!-- ===== Booking Detail Modal ===== -->
<div class="modal-overlay" id="detailModal" aria-modal="true" role="dialog">
    <div class="modal">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="modalRef">—</div>
                <div style="margin-top:6px;" id="modalBadge"></div>
            </div>
            <button class="modal-close" onclick="closeDetail()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <!-- Front-desk prompt: filled by JS (verify-then-confirm for check-in / check-out) -->
            <div class="stay-notice" id="dStayNotice" style="display:none;"></div>
            <div class="modal-section">
                <div class="modal-section-title">Guest Information</div>
                <div class="detail-grid">
                    <div class="detail-row">
                        <span class="detail-label">Name</span>
                        <span class="detail-value" id="dGuestName">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Type</span>
                        <span class="detail-value" id="dGuestType">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Email</span>
                        <span class="detail-value" id="dGuestEmail">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Phone</span>
                        <span class="detail-value" id="dGuestPhone">—</span>
                    </div>
                    <div class="detail-row" id="dAddressRow">
                        <span class="detail-label">Address</span>
                        <span class="detail-value" id="dGuestAddress">—</span>
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <div class="modal-section-title">Reservation Details</div>
                <div class="detail-grid">
                    <div class="detail-row">
                        <span class="detail-label">Room</span>
                        <span class="detail-value" id="dRoom">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Type</span>
                        <span class="detail-value" id="dRoomType">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Check-in</span>
                        <span class="detail-value" id="dCheckIn">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Check-out</span>
                        <span class="detail-value" id="dCheckOut">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Nights</span>
                        <span class="detail-value" id="dNights">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">No. of Guests</span>
                        <span class="detail-value" id="dGuests">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label" id="dRoomRateLabel">Room Rate / Night</span>
                        <span class="detail-value" id="dRoomPrice">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Payment Method</span>
                        <span class="detail-value" id="dPayMethod">—</span>
                    </div>
                    <div class="detail-row" id="dCheckedInRow" style="display:none;">
                        <span class="detail-label">Checked in at</span>
                        <span class="detail-value" id="dCheckedInAt">—</span>
                    </div>
                    <div class="detail-row" id="dCheckedOutRow" style="display:none;">
                        <span class="detail-label">Checked out at</span>
                        <span class="detail-value" id="dCheckedOutAt">—</span>
                    </div>
                </div>
            </div>
            <div class="modal-section" id="dCompanionsSection" style="display:none;">
                <div class="modal-section-title">Companion Names</div>
                <p id="dCompanions" style="font-size:13.5px;color:var(--text-secondary);line-height:1.6;"></p>
            </div>
            <div class="modal-section" id="dNotesSection">
                <div class="modal-section-title">Notes</div>
                <p id="dNotes" style="font-size:13.5px;color:var(--text-secondary);line-height:1.6;"></p>
            </div>
            <div class="modal-section">
                <div class="modal-section-title">Billing</div>
                <div class="detail-grid">
                    <div class="detail-row">
                        <span class="detail-label">Total Amount</span>
                        <span class="detail-value" id="dTotal" style="font-size:18px;font-family:var(--font-display);font-weight:800;color:var(--gold-400);">₱0.00</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Booked On</span>
                        <span class="detail-value" id="dCreatedAt">—</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-actions" id="modalActions">
            <!-- Injected by JS based on status -->
        </div>
    </div>
</div>

<!-- ===== New Booking Modal ===== -->
<div class="modal-overlay" id="newBookingModal" aria-modal="true" role="dialog">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">New Booking</div>
            <button class="modal-close" onclick="closeNewBooking()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <form id="newBookingForm" onsubmit="submitNewBooking(event)">
                <div class="modal-section">
                    <div class="modal-section-title">Guest Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Guest Name <span style="color:var(--red)">*</span></label>
                            <input class="form-input" type="text" name="guest_name" placeholder="Full name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input class="form-input" type="tel" name="phone" placeholder="+63 9XX XXX XXXX">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input class="form-input" type="email" name="email" placeholder="guest@email.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">No. of Guests <span style="color:var(--red)">*</span></label>
                            <input class="form-input" type="number" name="guests_count" min="1" max="20" value="1" required id="nbGuestsCount">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Address</label>
                            <input class="form-input" type="text" name="address" placeholder="Home address (optional)">
                        </div>
                    </div>
                </div>
                <div class="modal-section">
                    <div class="modal-section-title">Reservation</div>
                    <div class="form-row">
                        <div class="form-group full">
                            <label class="form-label">Accommodation <span style="color:var(--red)">*</span></label>
                            <select class="form-input" name="accommodation_id" required id="nbAccomSelect">
                                <option value="">— Select a room —</option>
                                <?php
                                $rooms = $pdo->query("SELECT id, name, type, price, capacity FROM accommodations WHERE status = 'available' ORDER BY name")->fetchAll();
                                foreach ($rooms as $r):
                                    $rIsPool = strcasecmp(trim($r['type']), 'Swimming Pool') === 0;
                                ?>
                                    <option value="<?php echo (int) $r['id']; ?>"
                                            data-price="<?php echo (float) $r['price']; ?>"
                                            data-cap="<?php echo (int) $r['capacity']; ?>"
                                            data-type="<?php echo htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-pool="<?php echo $rIsPool ? '1' : '0'; ?>">
                                        <?php echo htmlspecialchars($r['name'] . ' (' . $r['type'] . ') — ₱' . number_format($r['price'], 2) . ($rIsPool ? '/person' : '/night'), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form-label" id="nbPoolHint" style="display:none;color:var(--gold-400);margin-top:4px;">
                                This is a Swimming Pool — priced per person, and shared by other bookings up to its capacity for the same dates.
                            </span>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-in <span style="color:var(--red)">*</span></label>
                            <div class="date-picker" id="nbCheckInPicker">
                                <button type="button" class="form-input date-picker-trigger" id="nbCheckInTrigger" aria-haspopup="dialog">
                                    <span id="nbCheckInText" class="is-placeholder">Select date</span>
                                    <?php echo icon('calendar', 'date-picker-icon'); ?>
                                </button>
                                <div class="date-picker-pop" id="nbCheckInPop"></div>
                            </div>
                            <input type="hidden" name="check_in" required id="nbCheckIn">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-out <span style="color:var(--red)">*</span></label>
                            <div class="date-picker" id="nbCheckOutPicker">
                                <button type="button" class="form-input date-picker-trigger" id="nbCheckOutTrigger" aria-haspopup="dialog">
                                    <span id="nbCheckOutText" class="is-placeholder">Select date</span>
                                    <?php echo icon('calendar', 'date-picker-icon'); ?>
                                </button>
                                <div class="date-picker-pop" id="nbCheckOutPop"></div>
                            </div>
                            <input type="hidden" name="check_out" required id="nbCheckOut">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Method</label>
                            <select class="form-input" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estimated Total</label>
                            <input class="form-input" type="text" id="nbTotal" readonly placeholder="₱0.00" style="color:var(--gold-400);font-weight:700;">
                        </div>
                        <div class="form-group full" id="nbCompanionsGroup" style="display:none;">
                            <label class="form-label">Additional Guest Names <span style="font-weight:400;color:var(--text-tertiary);">(one per line, optional)</span></label>
                            <textarea class="form-input" name="companion_names" id="nbCompanionNames" rows="3" placeholder="e.g.&#10;Juan Dela Cruz&#10;Maria Santos"></textarea>
                            <span class="form-label" id="nbCompanionsHint" style="font-weight:400;color:var(--text-tertiary);margin-top:4px;"></span>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Notes</label>
                            <input class="form-input" type="text" name="notes" placeholder="Special requests, notes…">
                        </div>
                    </div>
                </div>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">
            </form>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="submitNewBooking(event)" id="nbSubmitBtn">
                <?php echo icon('calendar-check', 'icon-sm'); ?>
                Confirm Booking
            </button>
            <button class="btn btn-secondary" onclick="closeNewBooking()">Cancel</button>
        </div>
    </div>
</div>

<!-- ===== Edit Booking Modal ===== -->
<div class="modal-overlay" id="editBookingModal" aria-modal="true" role="dialog">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Edit Booking</div>
            <button class="modal-close" onclick="closeEditBooking()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <form id="editBookingForm" onsubmit="submitEditBooking(event)">
                <div class="modal-section">
                    <div class="modal-section-title">Guest Information</div>
                    <span class="form-label" id="edAccountHint" style="display:none;color:var(--text-tertiary);margin-bottom:8px;">
                        This booking belongs to a registered account, so guest details are linked to their profile and shown here for reference only.
                    </span>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Guest Name <span style="color:var(--red)">*</span></label>
                            <input class="form-input" type="text" name="guest_name" placeholder="Full name" required id="edGuestName">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input class="form-input" type="tel" name="phone" placeholder="+63 9XX XXX XXXX" id="edPhone">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input class="form-input" type="email" name="email" placeholder="guest@email.com" id="edEmail">
                        </div>
                        <div class="form-group">
                            <label class="form-label">No. of Guests <span style="color:var(--red)">*</span></label>
                            <input class="form-input" type="number" name="guests_count" min="1" max="20" value="1" required id="edGuestsCount">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Address</label>
                            <input class="form-input" type="text" name="address" placeholder="Home address (optional)" id="edAddress">
                        </div>
                    </div>
                </div>
                <div class="modal-section">
                    <div class="modal-section-title">Reservation</div>
                    <div class="form-row">
                        <div class="form-group full">
                            <label class="form-label">Accommodation <span style="color:var(--red)">*</span></label>
                            <select class="form-input" name="accommodation_id" required id="edAccomSelect">
                                <option value="">— Select a room —</option>
                                <?php
                                $allRooms = $pdo->query("SELECT id, name, type, price, capacity FROM accommodations ORDER BY name")->fetchAll();
                                foreach ($allRooms as $r):
                                    $rIsPool = strcasecmp(trim($r['type']), 'Swimming Pool') === 0;
                                ?>
                                    <option value="<?php echo (int) $r['id']; ?>"
                                            data-price="<?php echo (float) $r['price']; ?>"
                                            data-cap="<?php echo (int) $r['capacity']; ?>"
                                            data-type="<?php echo htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-pool="<?php echo $rIsPool ? '1' : '0'; ?>">
                                        <?php echo htmlspecialchars($r['name'] . ' (' . $r['type'] . ') — ₱' . number_format($r['price'], 2) . ($rIsPool ? '/person' : '/night'), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form-label" id="edPoolHint" style="display:none;color:var(--gold-400);margin-top:4px;">
                                This is a Swimming Pool — priced per person, and shared by other bookings up to its capacity for the same dates.
                            </span>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-in <span style="color:var(--red)">*</span></label>
                            <input class="form-input" type="date" name="check_in" required id="edCheckIn">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-out <span style="color:var(--red)">*</span></label>
                            <input class="form-input" type="date" name="check_out" required id="edCheckOut">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Method</label>
                            <select class="form-input" name="payment_method" id="edPaymentMethod">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estimated Total</label>
                            <input class="form-input" type="text" id="edTotal" readonly placeholder="₱0.00" style="color:var(--gold-400);font-weight:700;">
                        </div>
                        <div class="form-group full" id="edCompanionsGroup" style="display:none;">
                            <label class="form-label">Additional Guest Names <span style="font-weight:400;color:var(--text-tertiary);">(one per line, optional)</span></label>
                            <textarea class="form-input" name="companion_names" id="edCompanionNames" rows="3" placeholder="e.g.&#10;Juan Dela Cruz&#10;Maria Santos"></textarea>
                            <span class="form-label" id="edCompanionsHint" style="font-weight:400;color:var(--text-tertiary);margin-top:4px;"></span>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Notes</label>
                            <input class="form-input" type="text" name="notes" placeholder="Special requests, notes…" id="edNotes">
                        </div>
                    </div>
                </div>
                <input type="hidden" name="id" id="edBookingId" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update">
            </form>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="submitEditBooking(event)" id="edSubmitBtn">
                <?php echo icon('save', 'icon-sm'); ?>
                Save Changes
            </button>
            <button class="btn btn-secondary" onclick="closeEditBooking()">Cancel</button>
        </div>
    </div>
</div>

<!-- ===== Toast container ===== -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ============================================================
   Bookings page JS
   ============================================================ */
var BOOKINGS   = <?php echo $bookingsJson; ?>;
var CSRF       = <?php echo json_encode($csrf); ?>;
var TODAY      = <?php echo json_encode($today); ?>;        // server date (YYYY-MM-DD), used only for wording hints
var CURRENT_VIEW = <?php echo json_encode($view); ?>;       // upcoming | staying | all
var bookingMap = {};
BOOKINGS.forEach(function (b) { bookingMap[b.id] = b; });

/* ---------- Filter helpers ---------- */
// Stat cards and the status dropdown filter All Bookings.
function applyStatusFilter(status) {
    var url = new URL(window.location.href);
    url.searchParams.set('view', 'all');
    if (status) url.searchParams.set('status', status);
    else url.searchParams.delete('status');
    var search = document.getElementById('searchInput').value.trim();
    if (search) url.searchParams.set('search', search);
    else url.searchParams.delete('search');
    url.searchParams.delete('page');
    window.location.href = url.toString();
}

var statusSelect = document.getElementById('statusSelect');   // only rendered in All Bookings
if (statusSelect) {
    statusSelect.addEventListener('change', function () {
        applyStatusFilter(this.value);
    });
}

// Search keeps the current view (and, in All Bookings, the status filter).
var searchTimer;
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    var val = this.value;
    searchTimer = setTimeout(function () {
        var url = new URL(window.location.href);
        url.searchParams.set('view', CURRENT_VIEW);
        if (val.trim()) url.searchParams.set('search', val.trim());
        else url.searchParams.delete('search');
        url.searchParams.delete('page');
        window.location.href = url.toString();
    }, 500);
});

/* ---------- Stat card filter (onclick from PHP) ---------- */
// Already handled by applyStatusFilter above

/* ---------- Detail modal ---------- */
function openDetail(id) {
    var b = bookingMap[id];
    if (!b) return;

    // Compute nights
    var ci = new Date(b.check_in), co = new Date(b.check_out);
    var nights = Math.round((co - ci) / 86400000);

    document.getElementById('modalRef').textContent    = b.reference;
    document.getElementById('modalBadge').innerHTML    = badgeHtml(b.stage);
    document.getElementById('dGuestName').textContent  = b.guest_name || '—';
    document.getElementById('dGuestType').innerHTML    = typeBadge(b.booking_type);
    document.getElementById('dGuestEmail').textContent = b.guest_email || '—';
    document.getElementById('dGuestPhone').textContent = b.guest_phone || '—';
    document.getElementById('dGuestAddress').textContent = b.guest_address || '—';
    document.getElementById('dAddressRow').style.display = b.guest_address ? '' : 'none';
    document.getElementById('dRoom').textContent      = b.room_name;
    document.getElementById('dRoomType').textContent  = b.room_type;
    document.getElementById('dCheckIn').textContent   = fmtDate(b.check_in) + (b.check_in_time ? ' · ' + b.check_in_time : '');
    document.getElementById('dCheckOut').textContent  = fmtDate(b.check_out);
    document.getElementById('dNights').textContent    = nights + ' night' + (nights === 1 ? '' : 's');
    document.getElementById('dGuests').textContent    = b.guests_count;
    document.getElementById('dRoomRateLabel').textContent = b.is_pool ? 'Rate / Person' : 'Room Rate / Night';
    document.getElementById('dRoomPrice').textContent = '₱' + b.room_price + (b.is_pool ? ' / person' : ' / night');
    document.getElementById('dPayMethod').textContent = cap(b.payment_method);
    document.getElementById('dTotal').textContent     = '₱' + b.total_amount;
    document.getElementById('dCreatedAt').textContent = b.created_at;

    // When the admin recorded the arrival / departure (blank until they do)
    document.getElementById('dCheckedInAt').textContent  = b.checked_in_at  || '—';
    document.getElementById('dCheckedOutAt').textContent = b.checked_out_at || '—';
    document.getElementById('dCheckedInRow').style.display  = b.checked_in_at  ? '' : 'none';
    document.getElementById('dCheckedOutRow').style.display = b.checked_out_at ? '' : 'none';

    renderStayNotice(b);

    var notesSection = document.getElementById('dNotesSection');
    if (b.notes && b.notes.trim()) {
        document.getElementById('dNotes').textContent = b.notes;
        notesSection.style.display = '';
    } else {
        notesSection.style.display = 'none';
    }

    var companionsSection = document.getElementById('dCompanionsSection');
    if (b.companion_names && b.companion_names.length) {
        document.getElementById('dCompanions').textContent = b.companion_names.join(', ');
        companionsSection.style.display = '';
    } else {
        companionsSection.style.display = 'none';
    }

    // Render action buttons — driven by the booking's stage and the rules
    // computed on the server (can_* flags), so the UI never offers a
    // transition the API would refuse.
    var actions = document.getElementById('modalActions');
    var btns = '';
    if (b.can_check_in) {
        btns += '<button class="btn btn-success" id="stayBtn" onclick="stayAction(' + b.id + ',\'check_in\')">Confirm Check-in</button>';
    }
    if (b.can_check_out) {
        btns += '<button class="btn btn-success" id="stayBtn" onclick="stayAction(' + b.id + ',\'check_out\')">Confirm Check-out</button>';
    }
    if (b.can_edit) {
        btns += '<button class="btn btn-blue" onclick="openEdit(' + b.id + ')">Edit Booking</button>';
    }
    if (b.can_cancel) {
        btns += '<button class="btn btn-danger" onclick="changeStatus(' + b.id + ',\'cancelled\')">Cancel Booking</button>';
    }
    if (b.can_restore) {
        btns += '<button class="btn btn-blue" onclick="changeStatus(' + b.id + ',\'confirmed\')">Restore Booking</button>';
    }
    btns += '<button class="btn btn-secondary" onclick="closeDetail()">Close</button>';
    actions.innerHTML = btns;

    document.getElementById('detailModal').classList.add('is-open');
}

function closeDetail() {
    document.getElementById('detailModal').classList.remove('is-open');
}

document.getElementById('detailModal').addEventListener('click', function (e) {
    if (e.target === this) closeDetail();
});

/* ---------- Check-in / check-out (manual, admin-confirmed) ----------
   The detail dialog above shows the full reservation first; the status only
   changes when the admin presses "Confirm Check-in" / "Confirm Check-out".
   Nothing here (or anywhere on this page) reacts to the date or the time. */
function renderStayNotice(b) {
    var el = document.getElementById('dStayNotice');
    el.className = 'stay-notice';
    el.style.display = 'none';
    el.innerHTML = '';

    if (b.can_check_in) {
        var html = '<strong>Guest arriving at the front desk?</strong> Verify the guest\'s name, contact details, ' +
                   'accommodation and dates below match the reservation, then confirm their arrival.';
        if (b.check_in > TODAY) {
            html += '<span class="stay-notice-warn">Scheduled for ' + fmtDate(b.check_in) + ' — this would be an early check-in.</span>';
        } else if (b.check_in < TODAY) {
            html += '<span class="stay-notice-warn">Scheduled for ' + fmtDate(b.check_in) + ' — this is a late arrival.</span>';
        }
        el.className += ' stay-notice--arrive';
        el.innerHTML = html;
        el.style.display = '';
    } else if (b.can_check_out) {
        var out = '<strong>Checked in' + (b.checked_in_at ? ' on ' + b.checked_in_at : '') + '.</strong> ' +
                  'Confirm check-out only once the guest has settled up and left. The booking will move to Completed.';
        if (b.check_out < TODAY) {
            out += '<span class="stay-notice-warn">Expected check-out was ' + fmtDate(b.check_out) + ' — this guest is past their scheduled departure.</span>';
        }
        el.className += ' stay-notice--depart';
        el.innerHTML = out;
        el.style.display = '';
    }
}

function stayAction(id, action) {
    var btn = document.getElementById('stayBtn');
    var label = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    fetch('api/bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: action, id: id, csrf_token: CSRF }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            toast(action === 'check_in'
                ? 'Guest checked in — moved to Currently Staying.'
                : 'Guest checked out — booking completed.', 'success');
            closeDetail();
            // Reload so every list and count comes straight from the database.
            setTimeout(function () { window.location.reload(); }, 700);
        } else {
            toast(data.error || 'Could not update the booking.', 'error');
            if (data.refresh) {
                // Someone else already changed it — show the up-to-date lists.
                setTimeout(function () { window.location.reload(); }, 1800);
            } else if (btn) {
                btn.disabled = false; btn.textContent = label;
            }
        }
    })
    .catch(function () {
        toast('Network error. Please try again.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = label; }
    });
}

/* ---------- Status change (cancel / restore) ---------- */
function changeStatus(id, newStatus) {
    fetch('api/bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: id, status: newStatus, csrf_token: CSRF }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            toast(newStatus === 'cancelled' ? 'Booking cancelled.' : 'Booking restored.', 'success');
            closeDetail();
            setTimeout(function () { window.location.reload(); }, 900);
        } else {
            toast(data.error || 'Failed to update status.', 'error');
            if (data.refresh) setTimeout(function () { window.location.reload(); }, 1800);
        }
    })
    .catch(function () { toast('Network error. Please try again.', 'error'); });
}

function quickStatus(id, status) {
    if (!confirm('Mark this booking as ' + status + '?')) return;
    changeStatus(id, status);
}

/* ---------- New booking modal ---------- */
document.querySelector('.btn-action') && document.querySelector('.btn-action').addEventListener('click', function () {
    document.getElementById('newBookingModal').classList.add('is-open');
});

function closeNewBooking() {
    document.getElementById('newBookingModal').classList.remove('is-open');
}
document.getElementById('newBookingModal').addEventListener('click', function (e) {
    if (e.target === this) closeNewBooking();
});

// Auto-calc total — Swimming Pool accommodations are priced per person
// (guest count), every other type per night, same rule as the guest-
// facing booking flows. Additional guest names can be logged for any
// accommodation whose capacity allows more than one guest — not just
// Swimming Pool — mirroring the guest-facing booking flows.
function calcTotal() {
    var sel      = document.getElementById('nbAccomSelect');
    var opt      = sel.options[sel.selectedIndex];
    var price    = parseFloat(opt ? (opt.dataset.price || 0) : 0);
    var cap      = parseInt(opt ? (opt.dataset.cap || 0) : 0, 10);
    var isPool   = !!(opt && opt.dataset.pool === '1');
    var ci       = document.getElementById('nbCheckIn').value;
    var co       = document.getElementById('nbCheckOut').value;
    var guestsEl = document.getElementById('nbGuestsCount');
    var poolHint = document.getElementById('nbPoolHint');
    var companionsGroup = document.getElementById('nbCompanionsGroup');
    var companionsHint  = document.getElementById('nbCompanionsHint');
    var companionsInput = document.getElementById('nbCompanionNames');

    poolHint.style.display = isPool ? '' : 'none';

    if (opt && opt.value && cap > 1) {
        companionsGroup.style.display = '';
        companionsHint.textContent = 'This room fits up to ' + cap + ' guest' +
            (cap === 1 ? '' : 's') + ' — list up to ' + (cap - 1) + ' additional name' +
            (cap - 1 === 1 ? '' : 's') + ' above.';
    } else {
        companionsGroup.style.display = 'none';
        companionsInput.value = '';
    }

    if (cap > 0) {
        guestsEl.max = cap;
        if (parseInt(guestsEl.value, 10) > cap) guestsEl.value = cap;
    }

    if (!ci || !co || !price) { document.getElementById('nbTotal').value = ''; return; }
    var nights = Math.round((new Date(co) - new Date(ci)) / 86400000);
    if (nights < 1) { document.getElementById('nbTotal').value = ''; return; }

    var guests = Math.max(1, parseInt(guestsEl.value, 10) || 1);
    var total  = isPool ? (guests * price) : (nights * price);

    document.getElementById('nbTotal').value = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
document.getElementById('nbAccomSelect').addEventListener('change', calcTotal);
document.getElementById('nbGuestsCount').addEventListener('input', calcTotal);
document.getElementById('nbCheckIn').addEventListener('change', function () {
    var co = document.getElementById('nbCheckOut');
    if (co.value && co.value <= this.value) co.value = '';
    co.min = this.value ? new Date(new Date(this.value).getTime() + 86400000).toISOString().slice(0,10) : '';
    calcTotal();
});
document.getElementById('nbCheckOut').addEventListener('change', calcTotal);

/* ---------- New booking modal: availability-aware date pickers ----------
   Blocks dates already reserved for the selected accommodation so the
   admin can only pick genuinely open dates, then relies on the
   existing transactional check in api/bookings.php (action=create) as
   the authoritative final check when Confirm Booking is clicked.
------------------------------------------------------------------- */
var nbAvailability = null;                        // { capacity, isPool, bookings: [...] } for the selected room, or null
var nbOpenPicker    = null;                        // 'checkin' | 'checkout' | null
var nbViewMonth      = { checkin: null, checkout: null }; // {y,m} currently shown in each calendar

function nbPad(n) { return n < 10 ? '0' + n : '' + n; }
function nbISO(y, m, d) { return y + '-' + nbPad(m + 1) + '-' + nbPad(d); }
function nbParseISO(s) {
    if (!s) return null;
    var p = s.split('-');
    return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
}
function nbFormatDisplay(iso) {
    var d = nbParseISO(iso);
    return d ? d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
}

// Map of ISO date -> guests already booked that day, built from
// nbAvailability.bookings. A date is "covered" by a booking when
// check_in <= date < check_out (checkout day itself is free — the
// same half-open range the server uses for its own conflict check).
function nbBuildBookedMap() {
    var map = {};
    if (!nbAvailability) return map;
    nbAvailability.bookings.forEach(function (b) {
        var cur = nbParseISO(b.check_in);
        var end = nbParseISO(b.check_out);
        while (cur < end) {
            var iso = nbISO(cur.getFullYear(), cur.getMonth(), cur.getDate());
            map[iso] = (map[iso] || 0) + (nbAvailability.isPool ? b.guests_count : 1);
            cur.setDate(cur.getDate() + 1);
        }
    });
    return map;
}

// Whether `iso` is unavailable as a stay-night for `guestsWanted`. Every
// other accommodation type is exclusive (any existing booking blocks
// it); the Swimming Pool is shared up to capacity, same rule as the
// server-side conflict check.
function nbIsBlocked(iso, bookedMap, guestsWanted) {
    if (!nbAvailability) return false;
    var used = bookedMap[iso] || 0;
    if (nbAvailability.isPool) return (used + Math.max(1, guestsWanted)) > nbAvailability.capacity;
    return used > 0;
}

// Earliest blocked date strictly after `fromIso` — a check-out can
// never be pushed past this, or the stay would span an occupied night.
function nbFirstBlockedAfter(fromIso, bookedMap, guestsWanted) {
    if (!nbAvailability || !fromIso) return null;
    var d = nbParseISO(fromIso);
    d.setDate(d.getDate() + 1);
    for (var i = 0; i < 730; i++) {
        var iso = nbISO(d.getFullYear(), d.getMonth(), d.getDate());
        if (nbIsBlocked(iso, bookedMap, guestsWanted)) return iso;
        d.setDate(d.getDate() + 1);
    }
    return null;
}

function nbRenderCalendar(which) {
    var pop         = document.getElementById(which === 'checkin' ? 'nbCheckInPop' : 'nbCheckOutPop');
    var selectedIso = document.getElementById(which === 'checkin' ? 'nbCheckIn' : 'nbCheckOut').value;
    var otherIso    = document.getElementById(which === 'checkin' ? 'nbCheckOut' : 'nbCheckIn').value;
    var guestsWanted = Math.max(1, parseInt(document.getElementById('nbGuestsCount').value, 10) || 1);
    var bookedMap   = nbBuildBookedMap();

    if (!nbViewMonth[which]) {
        var base = nbParseISO(selectedIso) || nbParseISO(otherIso) || new Date();
        nbViewMonth[which] = { y: base.getFullYear(), m: base.getMonth() };
    }
    var view = nbViewMonth[which];

    var today = new Date();
    today.setHours(0, 0, 0, 0);

    var minDate, maxIso = null;
    if (which === 'checkout') {
        if (otherIso) {
            minDate = nbParseISO(otherIso);
            minDate.setDate(minDate.getDate() + 1);
            maxIso = nbFirstBlockedAfter(otherIso, bookedMap, guestsWanted);
        } else {
            minDate = new Date(today.getTime() + 86400000);
        }
    } else {
        minDate = today;
    }

    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var startWeekday = new Date(view.y, view.m, 1).getDay();
    var daysInMonth  = new Date(view.y, view.m + 1, 0).getDate();

    var html = '';
    html += '<div class="date-picker-head">';
    html += '<button type="button" class="date-picker-nav" data-nb-nav="prev" data-nb-which="' + which + '" aria-label="Previous month">&#8249;</button>';
    html += '<span class="date-picker-month">' + monthNames[view.m] + ' ' + view.y + '</span>';
    html += '<button type="button" class="date-picker-nav" data-nb-nav="next" data-nb-which="' + which + '" aria-label="Next month">&#8250;</button>';
    html += '</div>';
    html += '<div class="date-picker-grid date-picker-weekdays">';
    ['S','M','T','W','T','F','S'].forEach(function (w) { html += '<span>' + w + '</span>'; });
    html += '</div>';
    html += '<div class="date-picker-grid">';
    for (var i = 0; i < startWeekday; i++) html += '<span class="date-picker-cell is-empty"></span>';
    for (var day = 1; day <= daysInMonth; day++) {
        var iso         = nbISO(view.y, view.m, day);
        var cellDate    = new Date(view.y, view.m, day);
        var visualBooked = nbAvailability ? nbIsBlocked(iso, bookedMap, guestsWanted) : false;
        var beforeMin   = cellDate < minDate;
        var afterMax    = maxIso ? (iso > maxIso) : false;

        // Check-in must land on a genuinely open night. Check-out just
        // needs to be after check-in and not stretch past the next
        // occupied night — the checkout day itself is always fine even
        // if another booking starts that same day (same-day turnover).
        var disabled = which === 'checkin'
            ? (!nbAvailability || visualBooked || beforeMin)
            : (!nbAvailability || beforeMin || afterMax);

        var isSelected = (iso === selectedIso);
        var cls = 'date-picker-cell';
        if (disabled)   cls += ' is-disabled';
        if (isSelected) cls += ' is-selected';
        if (visualBooked && !beforeMin) cls += ' is-booked';

        html += '<button type="button" class="' + cls + '"' +
            (disabled ? ' disabled' : (' data-nb-pick="' + iso + '" data-nb-which="' + which + '"')) +
            (visualBooked && !beforeMin ? ' title="Already booked"' : '') +
            '>' + day + '</button>';
    }
    html += '</div>';
    html += !nbAvailability
        ? '<div class="date-picker-note">Select an accommodation first.</div>'
        : '<div class="date-picker-legend"><span class="dp-swatch"></span> Booked / unavailable</div>';

    pop.innerHTML = html;
}

function nbCloseDatePickers() {
    nbOpenPicker = null;
    document.getElementById('nbCheckInPicker').classList.remove('is-open');
    document.getElementById('nbCheckOutPicker').classList.remove('is-open');
}

function nbOpenDatePicker(which) {
    if (which === 'checkout' && !document.getElementById('nbCheckIn').value) {
        toast('Please select a check-in date first.', 'error');
        return;
    }
    nbCloseDatePickers();
    nbOpenPicker = which;
    nbRenderCalendar(which);
    document.getElementById(which === 'checkin' ? 'nbCheckInPicker' : 'nbCheckOutPicker').classList.add('is-open');
}

document.getElementById('nbCheckInTrigger').addEventListener('click', function (e) {
    e.stopPropagation();
    if (nbOpenPicker === 'checkin') { nbCloseDatePickers(); return; }
    nbOpenDatePicker('checkin');
});
document.getElementById('nbCheckOutTrigger').addEventListener('click', function (e) {
    e.stopPropagation();
    if (nbOpenPicker === 'checkout') { nbCloseDatePickers(); return; }
    nbOpenDatePicker('checkout');
});
document.addEventListener('click', function (e) {
    if (nbOpenPicker && !e.target.closest('#nbCheckInPicker') && !e.target.closest('#nbCheckOutPicker')) {
        nbCloseDatePickers();
    }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nbOpenPicker) nbCloseDatePickers();
});

function nbSelectDate(which, iso) {
    var hidden = document.getElementById(which === 'checkin' ? 'nbCheckIn' : 'nbCheckOut');
    var text   = document.getElementById(which === 'checkin' ? 'nbCheckInText' : 'nbCheckOutText');
    hidden.value = iso;
    text.textContent = nbFormatDisplay(iso);
    text.classList.remove('is-placeholder');

    if (which === 'checkin') {
        // If the previously-picked check-out no longer fits (it's now
        // on/before the new check-in, or beyond the next occupied
        // night), clear it so the admin re-picks a valid one.
        var coEl = document.getElementById('nbCheckOut');
        if (coEl.value) {
            var bookedMap = nbBuildBookedMap();
            var guestsWanted = Math.max(1, parseInt(document.getElementById('nbGuestsCount').value, 10) || 1);
            var maxIso = nbFirstBlockedAfter(iso, bookedMap, guestsWanted);
            if (coEl.value <= iso || (maxIso && coEl.value > maxIso)) {
                coEl.value = '';
                var coText = document.getElementById('nbCheckOutText');
                coText.textContent = 'Select date';
                coText.classList.add('is-placeholder');
            }
        }
        nbViewMonth.checkout = null;
    }

    nbCloseDatePickers();
    document.getElementById('nbCheckInTrigger').classList.remove('has-error');
    document.getElementById('nbCheckOutTrigger').classList.remove('has-error');

    // Existing 'change' listeners on these hidden fields (calcTotal,
    // etc.) already handle recomputing the total from here.
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
}

document.getElementById('newBookingModal').addEventListener('click', function (e) {
    var navBtn = e.target.closest('[data-nb-nav]');
    if (navBtn) {
        e.stopPropagation();
        var which = navBtn.getAttribute('data-nb-which');
        var dir   = navBtn.getAttribute('data-nb-nav') === 'next' ? 1 : -1;
        var view  = nbViewMonth[which];
        var m = view.m + dir, y = view.y;
        if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
        nbViewMonth[which] = { y: y, m: m };
        nbRenderCalendar(which);
        return;
    }
    var pickBtn = e.target.closest('[data-nb-pick]');
    if (pickBtn) {
        e.stopPropagation();
        nbSelectDate(pickBtn.getAttribute('data-nb-which'), pickBtn.getAttribute('data-nb-pick'));
    }
});

// Fetch fresh availability whenever the accommodation changes, and
// reset any previously-picked dates — they belonged to the old room.
function nbLoadAvailability(accommodationId) {
    nbAvailability = null;
    nbViewMonth = { checkin: null, checkout: null };
    nbCloseDatePickers();

    document.getElementById('nbCheckIn').value  = '';
    document.getElementById('nbCheckOut').value = '';
    var ciText = document.getElementById('nbCheckInText');
    var coText = document.getElementById('nbCheckOutText');
    ciText.textContent = 'Select date'; ciText.classList.add('is-placeholder');
    coText.textContent = 'Select date'; coText.classList.add('is-placeholder');
    calcTotal();

    if (!accommodationId) return;

    fetch('api/bookings.php?action=availability&accommodation_id=' + encodeURIComponent(accommodationId))
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                nbAvailability = {
                    capacity: res.accommodation.capacity,
                    isPool:   res.accommodation.is_pool,
                    bookings: res.bookings,
                };
            } else {
                toast(res.error || 'Could not load availability for this accommodation.', 'error');
            }
        })
        .catch(function () {
            toast('Network error while checking availability.', 'error');
        });
}
document.getElementById('nbAccomSelect').addEventListener('change', function () {
    nbLoadAvailability(this.value);
});

// Pool bookings are shared by guest count, so changing the guest count
// can invalidate an already-picked date range — re-check it.
function nbRevalidateSelection() {
    if (!nbAvailability) return;
    var ci = document.getElementById('nbCheckIn').value;
    var co = document.getElementById('nbCheckOut').value;
    if (!ci || !co) return;

    var bookedMap = nbBuildBookedMap();
    var guestsWanted = Math.max(1, parseInt(document.getElementById('nbGuestsCount').value, 10) || 1);
    var cur = nbParseISO(ci), end = nbParseISO(co), stillOk = true;
    while (cur < end) {
        var iso = nbISO(cur.getFullYear(), cur.getMonth(), cur.getDate());
        if (nbIsBlocked(iso, bookedMap, guestsWanted)) { stillOk = false; break; }
        cur.setDate(cur.getDate() + 1);
    }

    if (!stillOk) {
        document.getElementById('nbCheckIn').value  = '';
        document.getElementById('nbCheckOut').value = '';
        var ciText = document.getElementById('nbCheckInText');
        var coText = document.getElementById('nbCheckOutText');
        ciText.textContent = 'Select date'; ciText.classList.add('is-placeholder');
        coText.textContent = 'Select date'; coText.classList.add('is-placeholder');
        toast('Selected dates no longer fit that many guests — please pick again.', 'error');
        calcTotal();
    }
}
document.getElementById('nbGuestsCount').addEventListener('input', nbRevalidateSelection);

// If the modal is reopened with an accommodation already selected
// (e.g. it was left mid-fill), make sure availability data is current.
function nbOnModalOpen() {
    var sel = document.getElementById('nbAccomSelect');
    if (sel.value) nbLoadAvailability(sel.value);
}
var nbActionBtn = document.querySelector('.btn-action');
if (nbActionBtn) nbActionBtn.addEventListener('click', nbOnModalOpen);

function submitNewBooking(e) {
    e.preventDefault();
    var form  = document.getElementById('newBookingForm');
    var data  = Object.fromEntries(new FormData(form).entries());
    var btn   = document.getElementById('nbSubmitBtn');

    if (!data.accommodation_id) {
        toast('Please select an accommodation.', 'error');
        return;
    }
    if (!data.check_in || !data.check_out) {
        document.getElementById('nbCheckInTrigger').classList.toggle('has-error', !data.check_in);
        document.getElementById('nbCheckOutTrigger').classList.toggle('has-error', !data.check_out);
        toast('Please select both check-in and check-out dates.', 'error');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Saving…';

    // Strip ₱ from total if present (not sent, computed server-side)
    fetch('api/bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.success) {
            toast('Booking ' + (res.reference || '') + ' created successfully!', 'success');
            closeNewBooking();
            setTimeout(function () { window.location.reload(); }, 1000);
        } else {
            toast(res.error || 'Failed to create booking.', 'error');
            btn.disabled = false;
            btn.textContent = 'Confirm Booking';
            // A conflict here means another booking claimed these dates
            // between the calendar loading and Confirm being clicked —
            // this is the server's final availability check (see
            // api/bookings.php, action=create). Refresh availability so
            // the picker reflects reality immediately.
            var sel = document.getElementById('nbAccomSelect');
            if (sel.value) nbLoadAvailability(sel.value);
        }
    })
    .catch(function () {
        toast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.textContent = 'Confirm Booking';
    });
}

/* ---------- Edit booking modal ---------- */
function openEdit(id) {
    var b = bookingMap[id];
    if (!b) return;

    // Registered accounts own their profile details — the admin can
    // still see them here for reference, but only guest (no-account)
    // bookings allow editing the name/contact fields directly.
    var isGuestBooking = b.booking_type === 'guest';
    ['edGuestName', 'edPhone', 'edEmail', 'edAddress'].forEach(function (id) {
        document.getElementById(id).readOnly = !isGuestBooking;
    });
    document.getElementById('edAccountHint').style.display = isGuestBooking ? 'none' : '';

    document.getElementById('edBookingId').value    = b.id;
    document.getElementById('edGuestName').value     = b.guest_name || '';
    document.getElementById('edPhone').value         = b.guest_phone || '';
    document.getElementById('edEmail').value         = b.guest_email || '';
    document.getElementById('edAddress').value       = b.guest_address || '';
    document.getElementById('edGuestsCount').value   = b.guests_count;
    document.getElementById('edCheckIn').value       = b.check_in;
    document.getElementById('edCheckOut').value      = b.check_out;
    document.getElementById('edPaymentMethod').value = b.payment_method || 'cash';
    document.getElementById('edNotes').value         = b.notes || '';
    document.getElementById('edCompanionNames').value = (b.companion_names || []).join('\n');
    document.getElementById('edAccomSelect').value   = b.accommodation_id;

    edCalcTotal();
    closeDetail();
    document.getElementById('editBookingModal').classList.add('is-open');
}

function closeEditBooking() {
    document.getElementById('editBookingModal').classList.remove('is-open');
}
document.getElementById('editBookingModal').addEventListener('click', function (e) {
    if (e.target === this) closeEditBooking();
});

function edCalcTotal() {
    var sel      = document.getElementById('edAccomSelect');
    var opt      = sel.options[sel.selectedIndex];
    var price    = parseFloat(opt ? (opt.dataset.price || 0) : 0);
    var cap      = parseInt(opt ? (opt.dataset.cap || 0) : 0, 10);
    var isPool   = !!(opt && opt.dataset.pool === '1');
    var ci       = document.getElementById('edCheckIn').value;
    var co       = document.getElementById('edCheckOut').value;
    var guestsEl = document.getElementById('edGuestsCount');
    var poolHint = document.getElementById('edPoolHint');
    var companionsGroup = document.getElementById('edCompanionsGroup');
    var companionsHint  = document.getElementById('edCompanionsHint');
    var companionsInput = document.getElementById('edCompanionNames');

    poolHint.style.display = isPool ? '' : 'none';

    if (opt && opt.value && cap > 1) {
        companionsGroup.style.display = '';
        companionsHint.textContent = 'This room fits up to ' + cap + ' guest' +
            (cap === 1 ? '' : 's') + ' — list up to ' + (cap - 1) + ' additional name' +
            (cap - 1 === 1 ? '' : 's') + ' above.';
    } else {
        companionsGroup.style.display = 'none';
    }

    if (cap > 0) {
        guestsEl.max = cap;
        if (parseInt(guestsEl.value, 10) > cap) guestsEl.value = cap;
    }

    if (!ci || !co || !price) { document.getElementById('edTotal').value = ''; return; }
    var nights = Math.round((new Date(co) - new Date(ci)) / 86400000);
    if (nights < 1) { document.getElementById('edTotal').value = ''; return; }

    var guests = Math.max(1, parseInt(guestsEl.value, 10) || 1);
    var total  = isPool ? (guests * price) : (nights * price);

    document.getElementById('edTotal').value = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
document.getElementById('edAccomSelect').addEventListener('change', edCalcTotal);
document.getElementById('edGuestsCount').addEventListener('input', edCalcTotal);
document.getElementById('edCheckIn').addEventListener('change', function () {
    var co = document.getElementById('edCheckOut');
    if (co.value && co.value <= this.value) co.value = '';
    co.min = this.value ? new Date(new Date(this.value).getTime() + 86400000).toISOString().slice(0,10) : '';
    edCalcTotal();
});
document.getElementById('edCheckOut').addEventListener('change', edCalcTotal);

function submitEditBooking(e) {
    e.preventDefault();
    var form = document.getElementById('editBookingForm');
    var data = Object.fromEntries(new FormData(form).entries());
    var btn  = document.getElementById('edSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('api/bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.success) {
            toast('Booking updated successfully!', 'success');
            closeEditBooking();
            setTimeout(function () { window.location.reload(); }, 900);
        } else {
            toast(res.error || 'Failed to update booking.', 'error');
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        }
    })
    .catch(function () {
        toast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.textContent = 'Save Changes';
    });
}

/* ---------- Utility helpers ---------- */
function fmtDate(d) {
    if (!d) return '—';
    var dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

function badgeHtml(status) {
    var map = {
        upcoming:   ['ab--amber', 'Upcoming'],
        checked_in: ['ab--green', 'Checked-in'],
        completed:  ['ab--blue',  'Completed'],
        cancelled:  ['ab--red',   'Cancelled'],
    };
    var b = map[status] || ['ab--grey', cap(status)];
    return '<span class="ab ' + b[0] + '">' + b[1] + '</span>';
}

function typeBadge(type) {
    if (type === 'guest')
        return '<span class="type-badge type-badge--guest">Walk-in Guest</span>';
    return '<span class="type-badge type-badge--registered">Registered User</span>';
}

function toast(msg, type) {
    var wrap = document.getElementById('toastWrap');
    var el   = document.createElement('div');
    el.className = 'toast toast--' + (type || 'success');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 20);
    setTimeout(function () {
        el.classList.remove('show');
        setTimeout(function () { wrap.removeChild(el); }, 400);
    }, 3500);
}

/* Wire "New Booking" topbar button */
var actionBtn = document.querySelector('.btn-action');
if (actionBtn) {
    actionBtn.addEventListener('click', function () {
        document.getElementById('newBookingModal').classList.add('is-open');
    });
}
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>