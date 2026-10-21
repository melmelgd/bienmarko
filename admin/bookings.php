<?php
/**
 * admin/bookings.php
 * -----------------------------------------------------------------------
 * Full live bookings management page. Displays all bookings (registered
 * and guest walk-ins), with stat cards, search/filter, status management,
 * and a detail modal. All data pulled from the database.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';

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

/* ------------------------------------------------------------------
   Filters from GET
------------------------------------------------------------------ */
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');
/* Display statuses only: Upcoming, Checked-in, Cancelled. These are derived
   from the booking dates + cancelled flag (the DB status column is shared
   with Payments / Dashboard / Reports, so its values are left untouched). */
$allowedStatuses = ['', 'upcoming', 'checked_in', 'cancelled'];
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = '';

/* Pagination — 10 bookings per page, like the Payments and Dashboard tables */
$perPage     = 10;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

/* ------------------------------------------------------------------
   Stat cards
------------------------------------------------------------------ */
$statTotal = (int) $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();

$statUpcomingStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings WHERE status <> 'cancelled' AND check_in > :today"
);
$statUpcomingStmt->execute(['today' => $today]);
$statUpcoming = (int) $statUpcomingStmt->fetchColumn();

$statCheckedInStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings WHERE status <> 'cancelled' AND check_in <= :today"
);
$statCheckedInStmt->execute(['today' => $today]);
$statCheckedIn = (int) $statCheckedInStmt->fetchColumn();

$statCancelled = (int) $pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status = 'cancelled'"
)->fetchColumn();

/* ------------------------------------------------------------------
   Build main query with optional filters
------------------------------------------------------------------ */
$whereClauses = [];
$params       = [];

if ($filterStatus === 'cancelled') {
    $whereClauses[] = "b.status = 'cancelled'";
} elseif ($filterStatus === 'upcoming') {
    $whereClauses[] = "b.status <> 'cancelled' AND b.check_in > :today";
    $params['today'] = $today;
} elseif ($filterStatus === 'checked_in') {
    $whereClauses[] = "b.status <> 'cancelled' AND b.check_in <= :today";
    $params['today'] = $today;
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
    )';
    $like = '%' . $filterSearch . '%';
    foreach (['s1','s2','s3','s4','s5','s6','s7'] as $k) {
        $params[$k] = $like;
    }
}

$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

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

$sql = "SELECT
            b.id, b.reference, b.check_in, b.check_out,
            b.guests_count, b.status, b.total_amount,
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
        ORDER BY b.created_at DESC
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

/**
 * Display status for a booking — one of: upcoming, checked_in, cancelled.
 *   cancelled  → the booking was cancelled
 *   upcoming   → not cancelled and the check-in date is still ahead
 *   checked_in → not cancelled and check-in date is today or earlier
 */
function bk_display_status(array $bk, string $today): string
{
    if (($bk['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }
    return ($bk['check_in'] > $today) ? 'upcoming' : 'checked_in';
}

function bk_display_badge(array $bk, string $today): string
{
    $map = [
        'upcoming'   => ['ab--amber', 'Upcoming'],
        'checked_in' => ['ab--green', 'Checked-in'],
        'cancelled'  => ['ab--red',   'Cancelled'],
    ];
    [$cls, $lbl] = $map[bk_display_status($bk, $today)];
    return '<span class="ab ' . $cls . '">' . $lbl . '</span>';
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
.stat-grid   { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
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

/* Responsive */
@media (max-width: 1100px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 700px)  { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 540px)  { .detail-grid { grid-template-columns: 1fr; } .form-row { grid-template-columns: 1fr; } }
</style>

<?php
/* Pass bookings to JS as JSON for the detail modal */
$bookingsJson = json_encode(array_map(function ($bk) use ($today) {
    return [
        'id'           => (int) $bk['id'],
        'reference'    => $bk['reference'],
        'booking_type' => $bk['booking_type'],
        'status'       => $bk['status'],
        'display_status' => bk_display_status($bk, $today),
        'accommodation_id' => (int) $bk['accommodation_id'],
        'check_in'     => $bk['check_in'],
        'check_out'    => $bk['check_out'],
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

    <!-- ===== Stat cards (clickable filters) ===== -->
    <div class="stat-grid">
        <div class="stat-card <?php echo $filterStatus === '' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('')">
            <div class="stat-card-top">
                <span class="stat-label">Total Bookings</span>
                <span class="stat-icon stat-icon--grey"><?php echo icon('calendar'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statTotal; ?></span>
        </div>
        <div class="stat-card <?php echo $filterStatus === 'upcoming' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('upcoming')">
            <div class="stat-card-top">
                <span class="stat-label">Upcoming</span>
                <span class="stat-icon stat-icon--amber"><?php echo icon('clock'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statUpcoming; ?></span>
        </div>
        <div class="stat-card <?php echo $filterStatus === 'checked_in' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('checked_in')">
            <div class="stat-card-top">
                <span class="stat-label">Checked-in</span>
                <span class="stat-icon stat-icon--emerald"><?php echo icon('calendar-check'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statCheckedIn; ?></span>
        </div>
        <div class="stat-card <?php echo $filterStatus === 'cancelled' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('cancelled')">
            <div class="stat-card-top">
                <span class="stat-label">Cancelled</span>
                <span class="stat-icon stat-icon--red"><?php echo icon('close'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statCancelled; ?></span>
        </div>
    </div>

    <!-- ===== Toolbar ===== -->
    <div class="toolbar">
        <div class="toolbar-search">
            <span class="toolbar-search-icon"><?php echo icon('inbox', 'icon-sm'); ?></span>
            <input type="text" id="searchInput" placeholder="Search by name, email, reference, or room…"
                   value="<?php echo htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <select class="filter-select" id="statusSelect">
            <option value="">All Statuses</option>
            <option value="upcoming"   <?php echo $filterStatus === 'upcoming'   ? 'selected' : ''; ?>>Upcoming</option>
            <option value="checked_in" <?php echo $filterStatus === 'checked_in' ? 'selected' : ''; ?>>Checked-in</option>
            <option value="cancelled"  <?php echo $filterStatus === 'cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>

    <!-- ===== Bookings Table ===== -->
    <div class="panel table-wrap">
        <div class="table-head">
            <h2 class="panel-title">All Bookings</h2>
            <span class="results-count" id="resultsCount">
                <?php echo $totalBookings; ?> booking<?php echo $totalBookings === 1 ? '' : 's'; ?>
            </span>
        </div>
        <div class="table-scroll">
            <table id="bookingsTable">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="bookingsTbody">
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="6">
                                <?php render_empty_state(
                                    'calendar',
                                    'No bookings yet',
                                    'New bookings from guests will appear here automatically.'
                                ); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $bk): ?>
                            <tr data-id="<?php echo (int) $bk['id']; ?>" onclick="openDetail(<?php echo (int) $bk['id']; ?>)">
                                <td>
                                    <div class="td-guest"><?php echo htmlspecialchars(bk_name($bk), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php $email = bk_email($bk); if ($email): ?>
                                        <div class="td-sub"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($bk['room_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="td-sub"><?php echo htmlspecialchars($bk['room_type'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($bk['check_in'])); ?></td>
                                <td><?php echo date('M j, Y', strtotime($bk['check_out'])); ?></td>
                                <td><?php echo bk_display_badge($bk, $today); ?></td>
                                <td onclick="event.stopPropagation()">
                                    <div class="td-actions">
                                        <button class="btn-sm btn-sm--gold" onclick="openDetail(<?php echo (int) $bk['id']; ?>)" title="View Details">
                                            <?php echo icon('eye', 'icon-sm'); ?>
                                        </button>
                                        <?php if (in_array($bk['status'], ['confirmed','pending'])): ?>
                                            <button class="btn-sm btn-sm--danger" onclick="quickStatus(<?php echo (int) $bk['id']; ?>, 'cancelled')" title="Cancel">
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
var bookingMap = {};
BOOKINGS.forEach(function (b) { bookingMap[b.id] = b; });

/* ---------- Filter helpers ---------- */
function applyStatusFilter(status) {
    var url = new URL(window.location.href);
    if (status) url.searchParams.set('status', status);
    else url.searchParams.delete('status');
    var search = document.getElementById('searchInput').value.trim();
    if (search) url.searchParams.set('search', search);
    else url.searchParams.delete('search');
    url.searchParams.delete('page');
    window.location.href = url.toString();
}

document.getElementById('statusSelect').addEventListener('change', function () {
    applyStatusFilter(this.value);
});

var searchTimer;
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    var val = this.value;
    searchTimer = setTimeout(function () {
        var url = new URL(window.location.href);
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
    document.getElementById('modalBadge').innerHTML    = badgeHtml(b.display_status);
    document.getElementById('dGuestName').textContent  = b.guest_name || '—';
    document.getElementById('dGuestType').innerHTML    = typeBadge(b.booking_type);
    document.getElementById('dGuestEmail').textContent = b.guest_email || '—';
    document.getElementById('dGuestPhone').textContent = b.guest_phone || '—';
    document.getElementById('dGuestAddress').textContent = b.guest_address || '—';
    document.getElementById('dAddressRow').style.display = b.guest_address ? '' : 'none';
    document.getElementById('dRoom').textContent      = b.room_name;
    document.getElementById('dRoomType').textContent  = b.room_type;
    document.getElementById('dCheckIn').textContent   = fmtDate(b.check_in);
    document.getElementById('dCheckOut').textContent  = fmtDate(b.check_out);
    document.getElementById('dNights').textContent    = nights + ' night' + (nights === 1 ? '' : 's');
    document.getElementById('dGuests').textContent    = b.guests_count;
    document.getElementById('dRoomRateLabel').textContent = b.is_pool ? 'Rate / Person' : 'Room Rate / Night';
    document.getElementById('dRoomPrice').textContent = '₱' + b.room_price + (b.is_pool ? ' / person' : ' / night');
    document.getElementById('dPayMethod').textContent = cap(b.payment_method);
    document.getElementById('dTotal').textContent     = '₱' + b.total_amount;
    document.getElementById('dCreatedAt').textContent = b.created_at;

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

    // Render action buttons
    var actions = document.getElementById('modalActions');
    var btns = '<button class="btn btn-secondary" onclick="closeDetail()">Close</button>';
    if (b.status === 'confirmed' || b.status === 'pending') {
        btns  = '<button class="btn btn-blue" onclick="openEdit(' + b.id + ')">Edit Booking</button>';
        btns += '<button class="btn btn-danger" onclick="changeStatus(' + b.id + ',\'cancelled\')">Cancel Booking</button>';
        btns += '<button class="btn btn-secondary" onclick="closeDetail()">Close</button>';
    } else if (b.status === 'cancelled') {
        btns  = '<button class="btn btn-blue" onclick="changeStatus(' + b.id + ',\'confirmed\')">Restore Booking</button>';
        btns += '<button class="btn btn-secondary" onclick="closeDetail()">Close</button>';
    } else {
        btns = '<button class="btn btn-secondary" onclick="closeDetail()">Close</button>';
    }
    actions.innerHTML = btns;

    document.getElementById('detailModal').classList.add('is-open');
}

function closeDetail() {
    document.getElementById('detailModal').classList.remove('is-open');
}

document.getElementById('detailModal').addEventListener('click', function (e) {
    if (e.target === this) closeDetail();
});

/* ---------- Status change ---------- */
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