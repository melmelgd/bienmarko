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
$allowedStatuses = ['', 'confirmed', 'pending', 'cancelled', 'completed'];
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = '';

/* ------------------------------------------------------------------
   Stat cards
------------------------------------------------------------------ */
$statTotal = (int) $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();

$statConfirmed = (int) $pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'"
)->fetchColumn();

$statPending = (int) $pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status = 'pending'"
)->fetchColumn();

$statCancelled = (int) $pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status = 'cancelled'"
)->fetchColumn();

$statCompleted = (int) $pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status = 'completed'"
)->fetchColumn();

/* ------------------------------------------------------------------
   Build main query with optional filters
------------------------------------------------------------------ */
$whereClauses = [];
$params       = [];

if ($filterStatus !== '') {
    $whereClauses[] = 'b.status = :status';
    $params['status'] = $filterStatus;
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

$sql = "SELECT
            b.id, b.reference, b.check_in, b.check_out,
            b.guests_count, b.status, b.total_amount,
            b.booking_type, b.payment_method, b.notes,
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
        ORDER BY b.created_at DESC";

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

function bk_badge(string $status): string
{
    $map = [
        'confirmed' => ['ab--green', 'Confirmed'],
        'pending'   => ['ab--amber', 'Pending'],
        'cancelled' => ['ab--red',   'Cancelled'],
        'completed' => ['ab--blue',  'Completed'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['ab--grey', ucfirst($status)];
    return '<span class="ab ' . $cls . '">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</span>';
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

/* Responsive */
@media (max-width: 1100px) { .stat-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px)  { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 540px)  { .detail-grid { grid-template-columns: 1fr; } .form-row { grid-template-columns: 1fr; } }
</style>

<?php
/* Pass bookings to JS as JSON for the detail modal */
$bookingsJson = json_encode(array_map(function ($bk) {
    return [
        'id'           => (int) $bk['id'],
        'reference'    => $bk['reference'],
        'booking_type' => $bk['booking_type'],
        'status'       => $bk['status'],
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
        <div class="stat-card <?php echo $filterStatus === 'confirmed' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('confirmed')">
            <div class="stat-card-top">
                <span class="stat-label">Confirmed</span>
                <span class="stat-icon stat-icon--emerald"><?php echo icon('calendar-check'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statConfirmed; ?></span>
        </div>
        <div class="stat-card <?php echo $filterStatus === 'pending' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('pending')">
            <div class="stat-card-top">
                <span class="stat-label">Pending</span>
                <span class="stat-icon stat-icon--amber"><?php echo icon('clock'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statPending; ?></span>
        </div>
        <div class="stat-card <?php echo $filterStatus === 'cancelled' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('cancelled')">
            <div class="stat-card-top">
                <span class="stat-label">Cancelled</span>
                <span class="stat-icon stat-icon--red"><?php echo icon('close'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statCancelled; ?></span>
        </div>
        <div class="stat-card <?php echo $filterStatus === 'completed' ? 'is-active' : ''; ?>" onclick="applyStatusFilter('completed')">
            <div class="stat-card-top">
                <span class="stat-label">Completed</span>
                <span class="stat-icon stat-icon--blue"><?php echo icon('calendar-check'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statCompleted; ?></span>
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
            <option value="confirmed"  <?php echo $filterStatus === 'confirmed'  ? 'selected' : ''; ?>>Confirmed</option>
            <option value="pending"    <?php echo $filterStatus === 'pending'    ? 'selected' : ''; ?>>Pending</option>
            <option value="cancelled"  <?php echo $filterStatus === 'cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
            <option value="completed"  <?php echo $filterStatus === 'completed'  ? 'selected' : ''; ?>>Completed</option>
        </select>
    </div>

    <!-- ===== Bookings Table ===== -->
    <div class="panel table-wrap">
        <div class="table-head">
            <h2 class="panel-title">All Bookings</h2>
            <span class="results-count" id="resultsCount">
                <?php echo count($bookings); ?> booking<?php echo count($bookings) === 1 ? '' : 's'; ?>
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
                        <th>Guests</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="bookingsTbody">
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="9">
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
                                <td><?php echo (int) $bk['guests_count']; ?></td>
                                <td>
                                    <span style="text-transform:capitalize;">
                                        <?php echo htmlspecialchars($bk['payment_method'] ?? 'cash', ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td><?php echo bk_badge($bk['status']); ?></td>
                                <td class="td-amount">₱<?php echo number_format((float) $bk['total_amount'], 2); ?></td>
                                <td onclick="event.stopPropagation()">
                                    <div class="td-actions">
                                        <button class="btn-sm btn-sm--gold" onclick="openDetail(<?php echo (int) $bk['id']; ?>)" title="View Details">
                                            <?php echo icon('calendar', 'icon-sm'); ?>
                                        </button>
                                        <?php if (in_array($bk['status'], ['confirmed','pending'])): ?>
                                            <button class="btn-sm btn-sm--success" onclick="quickStatus(<?php echo (int) $bk['id']; ?>, 'completed')" title="Mark Completed">
                                                <?php echo icon('calendar-check', 'icon-sm'); ?>
                                            </button>
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
                            <input class="form-input" type="date" name="check_in" min="<?php echo $today; ?>" required id="nbCheckIn">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-out <span style="color:var(--red)">*</span></label>
                            <input class="form-input" type="date" name="check_out" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required id="nbCheckOut">
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
    document.getElementById('modalBadge').innerHTML    = badgeHtml(b.status);
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
        btns  = '<button class="btn btn-blue" onclick="changeStatus(' + b.id + ',\'confirmed\')">Confirm</button>';
        btns += '<button class="btn btn-success" onclick="changeStatus(' + b.id + ',\'completed\')">Mark Completed</button>';
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
            toast('Booking status updated to ' + cap(newStatus) + '.', 'success');
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

function submitNewBooking(e) {
    e.preventDefault();
    var form  = document.getElementById('newBookingForm');
    var data  = Object.fromEntries(new FormData(form).entries());
    var btn   = document.getElementById('nbSubmitBtn');
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
        }
    })
    .catch(function () {
        toast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.textContent = 'Confirm Booking';
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
        confirmed: ['ab--green', 'Confirmed'],
        pending:   ['ab--amber', 'Pending'],
        cancelled: ['ab--red',   'Cancelled'],
        completed: ['ab--blue',  'Completed'],
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