<?php
/**
 * admin/payments.php
 * -----------------------------------------------------------------------
 * Full live payments & billing management page. Shows all confirmed
 * bookings awaiting or having payment records, allows admins to compute
 * totals, apply discounts/extra charges, record payment method, and
 * generate a receipt summary pop-up. Both registered-user and guest
 * bookings are supported.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';

$active_page        = 'payments';
$page_title         = 'Payments & Billing';
$breadcrumb         = 'Payments';
$page_action_label  = null;
$show_period_toggle = false;

$pdo   = bienmarko_db();
$today = date('Y-m-d');
$csrf  = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Ensure payments table exists
------------------------------------------------------------------ */
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* ------------------------------------------------------------------
   Ensure bookings table has needed columns
------------------------------------------------------------------ */
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

/* ------------------------------------------------------------------
   Filters
------------------------------------------------------------------ */
$filterPay    = $_GET['pay_status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');
$allowedPay   = ['', 'unpaid', 'partial', 'paid'];
if (!in_array($filterPay, $allowedPay, true)) $filterPay = '';

/* ------------------------------------------------------------------
   Stat cards
------------------------------------------------------------------ */
// Total outstanding (confirmed bookings without full payment)
$stmtOutstanding = $pdo->query(
    "SELECT COALESCE(SUM(b.total_amount), 0)
     FROM bookings b
     LEFT JOIN payments p ON p.booking_id = b.id
     WHERE b.status IN ('confirmed','pending')
       AND (p.payment_status IS NULL OR p.payment_status != 'paid')"
);
$statOutstanding = (float) $stmtOutstanding->fetchColumn();

// Total collected this month
$stmtCollected = $pdo->prepare(
    "SELECT COALESCE(SUM(p.total_paid), 0)
     FROM payments p
     WHERE p.payment_status = 'paid'
       AND p.paid_at >= :month_start"
);
$stmtCollected->execute(['month_start' => date('Y-m-01')]);
$statCollected = (float) $stmtCollected->fetchColumn();

// Unpaid count
$stmtUnpaid = $pdo->query(
    "SELECT COUNT(*) FROM bookings b
     LEFT JOIN payments p ON p.booking_id = b.id
     WHERE b.status IN ('confirmed','pending')
       AND (p.payment_status IS NULL OR p.payment_status = 'unpaid')"
);
$statUnpaid = (int) $stmtUnpaid->fetchColumn();

// Paid count this month
$stmtPaidCount = $pdo->prepare(
    "SELECT COUNT(*) FROM payments WHERE payment_status = 'paid' AND paid_at >= :month_start"
);
$stmtPaidCount->execute(['month_start' => date('Y-m-01')]);
$statPaidCount = (int) $stmtPaidCount->fetchColumn();

/* ------------------------------------------------------------------
   Main query — all bookings that are confirmed/pending/completed
   LEFT JOIN payments so unpaid rows still appear
------------------------------------------------------------------ */
$whereClauses = ["b.status IN ('confirmed','pending','completed')"];
$params       = [];

if ($filterPay === 'unpaid') {
    $whereClauses[] = "(p.payment_status IS NULL OR p.payment_status = 'unpaid')";
} elseif ($filterPay === 'partial') {
    $whereClauses[] = "p.payment_status = 'partial'";
} elseif ($filterPay === 'paid') {
    $whereClauses[] = "p.payment_status = 'paid'";
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

$where = 'WHERE ' . implode(' AND ', $whereClauses);

$sql = "SELECT
            b.id            AS booking_id,
            b.reference,
            b.check_in, b.check_out,
            b.guests_count,
            b.status        AS booking_status,
            b.total_amount  AS booking_total,
            b.payment_method AS booking_pm,
            b.booking_type,
            b.guest_name, b.email AS guest_book_email,
            b.phone AS guest_phone,
            a.id   AS room_id,
            a.name AS room_name,
            a.type AS room_type,
            a.price AS room_price,
            g.first_name, g.last_name, g.email AS reg_email, g.phone AS reg_phone,
            p.id              AS pay_id,
            p.room_fee, p.extra_guest_fee, p.other_charges,
            p.discount, p.discount_reason,
            p.total_paid,
            p.payment_method  AS pay_method,
            p.payment_status,
            p.notes           AS pay_notes,
            p.paid_at
        FROM bookings b
        JOIN accommodations a ON a.id = b.accommodation_id
        LEFT JOIN guests     g ON g.id = b.guest_id
        LEFT JOIN payments   p ON p.booking_id = b.id
        {$where}
        ORDER BY b.check_in ASC, b.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* ------------------------------------------------------------------
   Helpers
------------------------------------------------------------------ */
function pm_name(array $row): string
{
    if (!empty($row['first_name'])) {
        return trim($row['first_name'] . ' ' . $row['last_name']);
    }
    return $row['guest_name'] ?? '—';
}

function pm_email(array $row): string
{
    return $row['reg_email'] ?? $row['guest_book_email'] ?? '';
}

function pm_phone(array $row): string
{
    return $row['reg_phone'] ?? $row['guest_phone'] ?? '';
}

function pay_status_badge(string $ps): string
{
    $map = [
        'unpaid'  => ['pb--red',    'Unpaid'],
        'partial' => ['pb--amber',  'Partial'],
        'paid'    => ['pb--green',  'Paid'],
    ];
    [$cls, $lbl] = $map[$ps] ?? ['pb--grey', ucfirst($ps)];
    return '<span class="pb ' . $cls . '">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</span>';
}

function booking_status_badge(string $bs): string
{
    $map = [
        'confirmed'  => ['ab--green', 'Confirmed'],
        'pending'    => ['ab--amber', 'Pending'],
        'cancelled'  => ['ab--red',   'Cancelled'],
        'completed'  => ['ab--blue',  'Completed'],
    ];
    [$cls, $lbl] = $map[$bs] ?? ['ab--grey', ucfirst($bs)];
    return '<span class="ab ' . $cls . '">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</span>';
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';

/* Serialize rows to JSON for JS */
$rowsJson = json_encode(array_map(function ($r) {
    $nights = max(1, (int) round(
        (strtotime($r['check_out']) - strtotime($r['check_in'])) / 86400
    ));
    $payStatus = $r['payment_status'] ?? 'unpaid';
    return [
        'booking_id'      => (int) $r['booking_id'],
        'reference'       => $r['reference'],
        'booking_status'  => $r['booking_status'],
        'check_in'        => $r['check_in'],
        'check_out'       => $r['check_out'],
        'nights'          => $nights,
        'guests_count'    => (int) $r['guests_count'],
        'booking_total'   => (float) $r['booking_total'],
        'room_name'       => $r['room_name'],
        'room_type'       => $r['room_type'],
        'room_price'      => (float) $r['room_price'],
        'guest_name'      => pm_name($r),
        'guest_email'     => pm_email($r),
        'guest_phone'     => pm_phone($r),
        'booking_type'    => $r['booking_type'],
        'pay_id'          => $r['pay_id'] ? (int) $r['pay_id'] : null,
        'room_fee'        => $r['room_fee']        !== null ? (float) $r['room_fee']        : round((float) $r['room_price'] * $nights, 2),
        'extra_guest_fee' => $r['extra_guest_fee'] !== null ? (float) $r['extra_guest_fee'] : 0.0,
        'other_charges'   => $r['other_charges']   !== null ? (float) $r['other_charges']   : 0.0,
        'discount'        => $r['discount']         !== null ? (float) $r['discount']         : 0.0,
        'discount_reason' => $r['discount_reason'] ?? '',
        'total_paid'      => $r['total_paid']       !== null ? (float) $r['total_paid']       : 0.0,
        'pay_method'      => $r['pay_method'] ?? $r['booking_pm'] ?? 'cash',
        'payment_status'  => $payStatus,
        'pay_notes'       => $r['pay_notes'] ?? '',
        'paid_at'         => $r['paid_at'] ? date('M j, Y g:i A', strtotime($r['paid_at'])) : null,
    ];
}, $rows));
?>

<style>
/* ============================================================
   admin/payments.php — page-scoped styles
   ============================================================ */
.stack      { display: flex; flex-direction: column; gap: 24px; }
.stat-grid  { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.stat-card  { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 16px 18px; cursor: pointer; transition: border-color var(--transition), box-shadow var(--transition); }
.stat-card:hover, .stat-card.is-active { border-color: var(--gold-500); box-shadow: 0 0 0 2px rgba(245,166,35,.15); }
.stat-card-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.stat-label  { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.stat-icon   { width: 34px; height: 34px; border-radius: var(--r-md); display: flex; align-items: center; justify-content: center; }
.stat-icon--emerald { background: var(--emerald-bg); color: var(--emerald); }
.stat-icon--blue    { background: var(--blue-bg);    color: var(--blue); }
.stat-icon--amber   { background: var(--amber-bg);   color: var(--gold-400); }
.stat-icon--red     { background: var(--red-bg);     color: var(--red); }
.stat-value { font-family: var(--font-display); font-size: 22px; font-weight: 800; color: var(--text); display: block; }
.stat-sub   { font-size: 11px; color: var(--text-tertiary); margin-top: 3px; }

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

/* Panel / table */
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
.td-ref   { font-family: var(--font-display); font-size: 12px; font-weight: 700; color: var(--gold-400); letter-spacing: .04em; }
.td-guest { font-weight: 600; }
.td-sub   { font-size: 11.5px; color: var(--text-secondary); margin-top: 1px; }
.td-amount { font-family: var(--font-display); font-weight: 700; }
.td-actions { display: flex; gap: 6px; }

/* Status badges */
.ab { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: var(--r-full); font-size: 11px; font-weight: 700; }
.ab--green { background: var(--emerald-bg); color: var(--emerald); }
.ab--amber { background: var(--amber-bg);   color: var(--gold-400); }
.ab--red   { background: var(--red-bg);     color: var(--red); }
.ab--blue  { background: var(--blue-bg);    color: var(--blue); }
.ab--grey  { background: var(--panel-alt);  color: var(--text-secondary); }
/* Payment badges */
.pb { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: var(--r-full); font-size: 11px; font-weight: 700; }
.pb--green { background: var(--emerald-bg); color: var(--emerald); }
.pb--amber { background: var(--amber-bg);   color: var(--gold-400); }
.pb--red   { background: var(--red-bg);     color: var(--red); }
.pb--grey  { background: var(--panel-alt);  color: var(--text-secondary); }

/* Type badge */
.type-badge { display: inline-flex; align-items: center; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--r-full); }
.type-badge--registered { background: var(--blue-bg); color: var(--blue); }
.type-badge--guest      { background: var(--panel-alt); color: var(--text-secondary); border: 1px solid var(--border); }

/* Action buttons */
.btn-sm { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: var(--r-sm); font-size: 12px; font-weight: 600; border: 1px solid var(--border); background: var(--panel-alt); color: var(--text-secondary); cursor: pointer; transition: all var(--transition); }
.btn-sm:hover { color: var(--text); border-color: var(--border-strong); }
.btn-sm--gold { background: var(--gold-tint-bg); border-color: var(--gold-600); color: var(--gold-400); }
.btn-sm--gold:hover { background: var(--gold-600); color: var(--on-accent); }

/* Empty state */
.empty-state { padding: 50px 24px; text-align: center; }
.empty-state-icon    { margin: 0 auto 14px; color: var(--text-muted); }
.empty-state-title   { font-family: var(--font-display); font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.empty-state-message { font-size: 13.5px; color: var(--text-secondary); }

/* -------- Billing Modal -------- */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.72);
    display: flex; align-items: center; justify-content: center;
    z-index: 500; padding: 20px;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.is-open { opacity: 1; pointer-events: auto; }
.modal {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-lg); width: 100%; max-width: 560px;
    max-height: 92vh; display: flex; flex-direction: column;
    transform: translateY(12px); transition: transform .2s;
}
.modal-overlay.is-open .modal { transform: translateY(0); }
.modal-header {
    padding: 20px 24px 16px;
    display: flex; align-items: flex-start; justify-content: space-between;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.modal-title   { font-family: var(--font-display); font-size: 17px; font-weight: 700; }
.modal-subtitle{ font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; }
.modal-close   { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 4px; border-radius: var(--r-sm); }
.modal-close:hover { color: var(--text); background: var(--panel-alt); }
.modal-body    { padding: 20px 24px; overflow-y: auto; flex: 1; min-height: 0; }
.modal-section { margin-bottom: 20px; }
.modal-section:last-child { margin-bottom: 0; }
.modal-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-tertiary); margin-bottom: 12px; }

/* Billing breakdown */
.bill-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 14px; }
.bill-row:last-child { border-bottom: none; }
.bill-row-label { color: var(--text-secondary); }
.bill-row-value { font-weight: 600; color: var(--text); font-family: var(--font-display); }
.bill-total-row { display: flex; align-items: center; justify-content: space-between; padding: 14px 0 0; margin-top: 6px; border-top: 2px solid var(--border-strong); }
.bill-total-label { font-size: 15px; font-weight: 700; }
.bill-total-value { font-size: 22px; font-weight: 800; font-family: var(--font-display); color: var(--gold-400); }

/* Form fields */
.form-row   { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.form-input {
    padding: 8px 11px; background: var(--input-bg);
    border: 1px solid var(--border); border-radius: var(--r-sm);
    color: var(--text); font-size: 14px; width: 100%;
    transition: border-color var(--transition);
}
.form-input:focus { outline: none; border-color: var(--gold-500); }
.form-input::placeholder { color: var(--text-tertiary); }
.form-input:read-only { opacity: .65; cursor: default; }

/* Modal actions */
.modal-actions { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: var(--r-md); font-size: 14px; font-weight: 600; border: 1px solid transparent; cursor: pointer; transition: all var(--transition); }
.btn-primary   { background: var(--gold-500); color: var(--on-accent); border-color: var(--gold-600); }
.btn-primary:hover { background: var(--gold-400); }
.btn-secondary { background: var(--panel-alt); color: var(--text); border-color: var(--border); }
.btn-secondary:hover { border-color: var(--border-strong); }
.btn-success   { background: var(--emerald-bg); color: var(--emerald); border-color: var(--emerald); }
.btn-success:hover { background: var(--emerald); color: #fff; }

/* -------- Receipt Pop-up -------- */
.receipt-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.8);
    display: flex; align-items: center; justify-content: center;
    z-index: 600; padding: 20px;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.receipt-overlay.is-open { opacity: 1; pointer-events: auto; }
.receipt-box {
    background: #fff; border-radius: var(--r-lg); width: 100%; max-width: 360px;
    padding: 30px 28px; font-family: 'Courier New', monospace; color: #111;
    transform: scale(.95); transition: transform .2s;
    max-height: 90vh; overflow-y: auto;
}
.receipt-overlay.is-open .receipt-box { transform: scale(1); }
.receipt-logo   { text-align: center; margin-bottom: 14px; }
.receipt-logo-name { font-family: 'Georgia', serif; font-size: 17px; font-weight: 700; color: #8b6914; }
.receipt-logo-sub  { font-size: 11px; color: #666; margin-top: 2px; }
.receipt-divider   { border: none; border-top: 1px dashed #ccc; margin: 12px 0; }
.receipt-row  { display: flex; justify-content: space-between; font-size: 12.5px; margin: 5px 0; }
.receipt-row-label { color: #555; }
.receipt-row-value { font-weight: 700; text-align: right; }
.receipt-total-row { display: flex; justify-content: space-between; font-size: 15px; font-weight: 700; margin-top: 10px; padding-top: 8px; border-top: 2px solid #333; }
.receipt-footer { text-align: center; font-size: 11px; color: #888; margin-top: 16px; line-height: 1.6; }
.receipt-ref    { text-align: center; font-size: 11px; background: #f5f5f5; padding: 6px; border-radius: 4px; margin: 10px 0; letter-spacing: .04em; }
.receipt-actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
.receipt-btn { padding: 8px 18px; border-radius: 4px; font-size: 13px; font-weight: 600; border: 1px solid #ccc; cursor: pointer; }
.receipt-btn--print { background: #8b6914; color: #fff; border-color: #8b6914; }
.receipt-btn--close { background: #f5f5f5; color: #333; }

/* Toast */
.toast-wrap { position: fixed; bottom: 28px; right: 28px; z-index: 900; display: flex; flex-direction: column; gap: 10px; }
.toast { padding: 12px 18px; border-radius: var(--r-md); font-size: 13.5px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,.5); transform: translateX(120%); opacity: 0; transition: transform .3s, opacity .3s; }
.toast.show { transform: translateX(0); opacity: 1; }
.toast--success { background: var(--emerald-bg); border: 1px solid var(--emerald); color: var(--emerald); }
.toast--error   { background: var(--red-bg);     border: 1px solid var(--red);     color: var(--red); }

@media (max-width: 900px)  { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 540px)  { .stat-grid { grid-template-columns: 1fr; } .form-row { grid-template-columns: 1fr; } }
@media print { .modal-overlay, .receipt-actions, .stat-grid, .toolbar, .panel { display: none !important; } .receipt-overlay { position: static !important; } .receipt-box { box-shadow: none; } }
</style>

<div class="stack">

    <!-- ===== Stat cards ===== -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-label">Outstanding Balance</span>
                <span class="stat-icon stat-icon--red"><?php echo icon('wallet'); ?></span>
            </div>
            <span class="stat-value">₱<?php echo number_format($statOutstanding, 2); ?></span>
            <span class="stat-sub">Pending collection</span>
        </div>
        <div class="stat-card">
            <div class="stat-card-top">
                <span class="stat-label">Collected This Month</span>
                <span class="stat-icon stat-icon--emerald"><?php echo icon('wallet'); ?></span>
            </div>
            <span class="stat-value">₱<?php echo number_format($statCollected, 2); ?></span>
            <span class="stat-sub"><?php echo date('F Y'); ?></span>
        </div>
        <div class="stat-card <?php echo $filterPay === 'unpaid' ? 'is-active' : ''; ?>" onclick="applyPayFilter('unpaid')">
            <div class="stat-card-top">
                <span class="stat-label">Unpaid Bookings</span>
                <span class="stat-icon stat-icon--amber"><?php echo icon('clock'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statUnpaid; ?></span>
            <span class="stat-sub">Awaiting payment</span>
        </div>
        <div class="stat-card <?php echo $filterPay === 'paid' ? 'is-active' : ''; ?>" onclick="applyPayFilter('paid')">
            <div class="stat-card-top">
                <span class="stat-label">Paid This Month</span>
                <span class="stat-icon stat-icon--blue"><?php echo icon('calendar-check'); ?></span>
            </div>
            <span class="stat-value"><?php echo $statPaidCount; ?></span>
            <span class="stat-sub">Transactions</span>
        </div>
    </div>

    <!-- ===== Toolbar ===== -->
    <div class="toolbar">
        <div class="toolbar-search">
            <span class="toolbar-search-icon"><?php echo icon('inbox', 'icon-sm'); ?></span>
            <input type="text" id="searchInput" placeholder="Search by name, email, or booking reference…"
                   value="<?php echo htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <select class="filter-select" id="paySelect">
            <option value="">All Payment Statuses</option>
            <option value="unpaid"  <?php echo $filterPay === 'unpaid'  ? 'selected' : ''; ?>>Unpaid</option>
            <option value="partial" <?php echo $filterPay === 'partial' ? 'selected' : ''; ?>>Partial</option>
            <option value="paid"    <?php echo $filterPay === 'paid'    ? 'selected' : ''; ?>>Paid</option>
        </select>
    </div>

    <!-- ===== Payments Table ===== -->
    <div class="panel table-wrap">
        <div class="table-head">
            <h2 class="panel-title">Payments &amp; Billing</h2>
            <span class="results-count"><?php echo count($rows); ?> record<?php echo count($rows) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Booking</th>
                        <th>Total Due</th>
                        <th>Paid</th>
                        <th>Pay Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10">
                                <?php render_empty_state(
                                    'wallet',
                                    'No payment records found',
                                    'Confirmed bookings will appear here for billing management.'
                                ); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $payStatus = $row['payment_status'] ?? 'unpaid'; ?>
                            <tr onclick="openBilling(<?php echo (int) $row['booking_id']; ?>)">
                                <td>
                                    <div class="td-ref"><?php echo htmlspecialchars($row['reference'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="margin-top:3px;">
                                        <?php if ($row['booking_type'] === 'guest'): ?>
                                            <span class="type-badge type-badge--guest">Walk-in</span>
                                        <?php else: ?>
                                            <span class="type-badge type-badge--registered">Registered</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="td-guest"><?php echo htmlspecialchars(pm_name($row), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php $em = pm_email($row); if ($em): ?>
                                        <div class="td-sub"><?php echo htmlspecialchars($em, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="td-sub"><?php echo htmlspecialchars($row['room_type'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($row['check_in'])); ?></td>
                                <td><?php echo date('M j, Y', strtotime($row['check_out'])); ?></td>
                                <td><?php echo booking_status_badge($row['booking_status']); ?></td>
                                <td class="td-amount">₱<?php echo number_format((float) $row['booking_total'], 2); ?></td>
                                <td class="td-amount">
                                    <?php if ($row['pay_id']): ?>
                                        ₱<?php echo number_format((float) $row['total_paid'], 2); ?>
                                    <?php else: ?>
                                        <span style="color:var(--text-tertiary);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo pay_status_badge($payStatus); ?></td>
                                <td onclick="event.stopPropagation()">
                                    <div class="td-actions">
                                        <button class="btn-sm btn-sm--gold" onclick="openBilling(<?php echo (int) $row['booking_id']; ?>)">
                                            <?php echo icon('wallet', 'icon-sm'); ?> Billing
                                        </button>
                                        <?php if ($row['pay_id']): ?>
                                            <button class="btn-sm" onclick="openReceipt(<?php echo (int) $row['booking_id']; ?>)">
                                                <?php echo icon('calendar', 'icon-sm'); ?> Receipt
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

<!-- ===== Billing Modal ===== -->
<div class="modal-overlay" id="billingModal" aria-modal="true" role="dialog">
    <div class="modal">
        <div class="modal-header">
            <div>
                <div class="modal-title">Billing &amp; Payment</div>
                <div class="modal-subtitle" id="billRef">—</div>
            </div>
            <button class="modal-close" onclick="closeBilling()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <!-- Summary -->
            <div class="modal-section">
                <div class="modal-section-title">Guest &amp; Stay</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;font-size:13.5px;">
                    <div><span style="color:var(--text-secondary);">Guest:</span> <strong id="billGuestName">—</strong></div>
                    <div><span style="color:var(--text-secondary);">Room:</span>  <strong id="billRoom">—</strong></div>
                    <div><span style="color:var(--text-secondary);">Check-in:</span> <span id="billCI">—</span></div>
                    <div><span style="color:var(--text-secondary);">Check-out:</span> <span id="billCO">—</span></div>
                    <div><span style="color:var(--text-secondary);">Nights:</span> <span id="billNights">—</span></div>
                    <div><span style="color:var(--text-secondary);">Guests:</span> <span id="billGuests">—</span></div>
                </div>
            </div>

            <!-- Fee inputs -->
            <div class="modal-section">
                <div class="modal-section-title">Fee Breakdown</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Room Fee (₱)</label>
                        <input class="form-input" type="number" id="fRoomFee" min="0" step="0.01" oninput="recalc()" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Extra Guest Fee (₱)</label>
                        <input class="form-input" type="number" id="fExtraGuest" min="0" step="0.01" oninput="recalc()" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Other Charges (₱)</label>
                        <input class="form-input" type="number" id="fOtherCharges" min="0" step="0.01" oninput="recalc()" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Discount (₱)</label>
                        <input class="form-input" type="number" id="fDiscount" min="0" step="0.01" oninput="recalc()" placeholder="0.00">
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Discount Reason</label>
                        <input class="form-input" type="text" id="fDiscountReason" placeholder="e.g. Senior citizen, promo code…">
                    </div>
                </div>
            </div>

            <!-- Running total -->
            <div class="modal-section">
                <div class="modal-section-title">Billing Summary</div>
                <div class="bill-row">
                    <span class="bill-row-label">Room Fee</span>
                    <span class="bill-row-value" id="bsRoomFee">₱0.00</span>
                </div>
                <div class="bill-row">
                    <span class="bill-row-label">Extra Guest Fee</span>
                    <span class="bill-row-value" id="bsExtraGuest">₱0.00</span>
                </div>
                <div class="bill-row">
                    <span class="bill-row-label">Other Charges</span>
                    <span class="bill-row-value" id="bsOtherCharges">₱0.00</span>
                </div>
                <div class="bill-row">
                    <span class="bill-row-label" style="color:var(--red);">Discount</span>
                    <span class="bill-row-value" id="bsDiscount" style="color:var(--red);">– ₱0.00</span>
                </div>
                <div class="bill-total-row">
                    <span class="bill-total-label">Total Due</span>
                    <span class="bill-total-value" id="bsTotal">₱0.00</span>
                </div>
            </div>

            <!-- Payment -->
            <div class="modal-section">
                <div class="modal-section-title">Payment</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Amount Paid (₱)</label>
                        <input class="form-input" type="number" id="fTotalPaid" min="0" step="0.01" oninput="recalc()" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select class="form-input" id="fPayMethod">
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Notes</label>
                        <input class="form-input" type="text" id="fPayNotes" placeholder="Payment notes…">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Status</label>
                        <input class="form-input" type="text" id="fPayStatus" readonly placeholder="Auto-computed">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Balance Remaining</label>
                        <input class="form-input" type="text" id="fBalance" readonly placeholder="₱0.00">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" id="saveBillBtn" onclick="saveBilling()">
                <?php echo icon('wallet', 'icon-sm'); ?> Save &amp; Record
            </button>
            <button class="btn btn-success" id="receiptBtn" onclick="openReceiptFromModal()" style="display:none;">
                <?php echo icon('calendar', 'icon-sm'); ?> View Receipt
            </button>
            <button class="btn btn-secondary" onclick="closeBilling()">Cancel</button>
        </div>
    </div>
</div>

<!-- ===== Receipt Pop-up ===== -->
<div class="receipt-overlay" id="receiptOverlay">
    <div class="receipt-box" id="receiptBox">
        <div class="receipt-logo">
            <div class="receipt-logo-name">Bien Marko's Pension House</div>
            <div class="receipt-logo-sub">1st Ave Street, Poblacion, Tabango, Leyte</div>
        </div>
        <div style="text-align:center;font-size:13px;font-weight:700;margin-bottom:8px;">OFFICIAL RECEIPT</div>
        <div class="receipt-ref" id="rcRef">REF: —</div>
        <hr class="receipt-divider">
        <div class="receipt-row"><span class="receipt-row-label">Guest</span>      <span class="receipt-row-value" id="rcGuest">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Room</span>       <span class="receipt-row-value" id="rcRoom">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Check-in</span>   <span class="receipt-row-value" id="rcCI">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Check-out</span>  <span class="receipt-row-value" id="rcCO">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Nights</span>     <span class="receipt-row-value" id="rcNights">—</span></div>
        <hr class="receipt-divider">
        <div class="receipt-row"><span class="receipt-row-label">Room Fee</span>           <span class="receipt-row-value" id="rcRoomFee">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Extra Guest Fee</span>    <span class="receipt-row-value" id="rcExtraGuest">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Other Charges</span>      <span class="receipt-row-value" id="rcOtherCharges">—</span></div>
        <div class="receipt-row" id="rcDiscountRow"><span class="receipt-row-label">Discount</span><span class="receipt-row-value" id="rcDiscount" style="color:#c0392b;">—</span></div>
        <hr class="receipt-divider">
        <div class="receipt-total-row">
            <span>TOTAL</span>
            <span id="rcTotal">₱0.00</span>
        </div>
        <div class="receipt-row" style="margin-top:8px;"><span class="receipt-row-label">Amount Paid</span> <span class="receipt-row-value" id="rcPaid">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Payment Method</span>     <span class="receipt-row-value" id="rcMethod">—</span></div>
        <div class="receipt-row"><span class="receipt-row-label">Balance</span>            <span class="receipt-row-value" id="rcBalance">—</span></div>
        <hr class="receipt-divider">
        <div class="receipt-footer">
            Thank you for choosing Bien Marko's Pension House!<br>
            We hope to see you again soon.<br>
            <span id="rcDate" style="margin-top:4px;display:block;"></span>
        </div>
        <div class="receipt-actions">
            <button class="receipt-btn receipt-btn--print" onclick="window.print()">Print</button>
            <button class="receipt-btn receipt-btn--close" onclick="closeReceipt()">Close</button>
        </div>
    </div>
</div>

<!-- ===== Toast ===== -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ============================================================
   Payments page JS
   ============================================================ */
var ROWS     = <?php echo $rowsJson; ?>;
var CSRF     = <?php echo json_encode($csrf); ?>;
var rowMap   = {};
ROWS.forEach(function (r) { rowMap[r.booking_id] = r; });

var activeBid = null; // current billing booking_id

/* ---------- Filter helpers ---------- */
function applyPayFilter(val) {
    var url = new URL(window.location.href);
    if (val) url.searchParams.set('pay_status', val);
    else url.searchParams.delete('pay_status');
    window.location.href = url.toString();
}

document.getElementById('paySelect').addEventListener('change', function () {
    applyPayFilter(this.value);
});

var stimer;
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(stimer);
    var v = this.value;
    stimer = setTimeout(function () {
        var url = new URL(window.location.href);
        if (v.trim()) url.searchParams.set('search', v.trim());
        else url.searchParams.delete('search');
        window.location.href = url.toString();
    }, 500);
});

/* ---------- Open billing modal ---------- */
function openBilling(bid) {
    var r = rowMap[bid];
    if (!r) return;
    activeBid = bid;

    document.getElementById('billRef').textContent       = r.reference;
    document.getElementById('billGuestName').textContent = r.guest_name || '—';
    document.getElementById('billRoom').textContent      = r.room_name + ' (' + r.room_type + ')';
    document.getElementById('billCI').textContent        = fmtDate(r.check_in);
    document.getElementById('billCO').textContent        = fmtDate(r.check_out);
    document.getElementById('billNights').textContent    = r.nights + ' night' + (r.nights === 1 ? '' : 's');
    document.getElementById('billGuests').textContent    = r.guests_count;

    document.getElementById('fRoomFee').value        = r.room_fee.toFixed(2);
    document.getElementById('fExtraGuest').value     = r.extra_guest_fee.toFixed(2);
    document.getElementById('fOtherCharges').value   = r.other_charges.toFixed(2);
    document.getElementById('fDiscount').value       = r.discount.toFixed(2);
    document.getElementById('fDiscountReason').value = r.discount_reason || '';
    document.getElementById('fTotalPaid').value      = r.total_paid > 0 ? r.total_paid.toFixed(2) : '';
    document.getElementById('fPayMethod').value      = r.pay_method || 'cash';
    document.getElementById('fPayNotes').value       = r.pay_notes || '';

    recalc();

    var receiptBtn = document.getElementById('receiptBtn');
    receiptBtn.style.display = r.pay_id ? '' : 'none';

    document.getElementById('billingModal').classList.add('is-open');
}

function closeBilling() {
    document.getElementById('billingModal').classList.remove('is-open');
    activeBid = null;
}
document.getElementById('billingModal').addEventListener('click', function (e) {
    if (e.target === this) closeBilling();
});

/* ---------- Recalculate running total ---------- */
function recalc() {
    var rf   = parseFloat(document.getElementById('fRoomFee').value)      || 0;
    var eg   = parseFloat(document.getElementById('fExtraGuest').value)   || 0;
    var oc   = parseFloat(document.getElementById('fOtherCharges').value) || 0;
    var disc = parseFloat(document.getElementById('fDiscount').value)     || 0;
    var paid = parseFloat(document.getElementById('fTotalPaid').value)    || 0;

    var total   = Math.max(0, rf + eg + oc - disc);
    var balance = Math.max(0, total - paid);

    var payStatus = paid <= 0 ? 'Unpaid' : (paid >= total ? 'Paid' : 'Partial');

    document.getElementById('bsRoomFee').textContent      = '₱' + fmt(rf);
    document.getElementById('bsExtraGuest').textContent   = '₱' + fmt(eg);
    document.getElementById('bsOtherCharges').textContent = '₱' + fmt(oc);
    document.getElementById('bsDiscount').textContent     = '– ₱' + fmt(disc);
    document.getElementById('bsTotal').textContent        = '₱' + fmt(total);
    document.getElementById('fPayStatus').value           = payStatus;
    document.getElementById('fBalance').value             = '₱' + fmt(balance);
}

/* ---------- Save billing ---------- */
function saveBilling() {
    if (!activeBid) return;
    var btn = document.getElementById('saveBillBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var rf   = parseFloat(document.getElementById('fRoomFee').value)      || 0;
    var eg   = parseFloat(document.getElementById('fExtraGuest').value)   || 0;
    var oc   = parseFloat(document.getElementById('fOtherCharges').value) || 0;
    var disc = parseFloat(document.getElementById('fDiscount').value)     || 0;
    var paid = parseFloat(document.getElementById('fTotalPaid').value)    || 0;
    var total = Math.max(0, rf + eg + oc - disc);

    var payStatus = paid <= 0 ? 'unpaid' : (paid >= total ? 'paid' : 'partial');

    var payload = {
        action:          'save_payment',
        csrf_token:      CSRF,
        booking_id:      activeBid,
        room_fee:        rf,
        extra_guest_fee: eg,
        other_charges:   oc,
        discount:        disc,
        discount_reason: document.getElementById('fDiscountReason').value,
        total_paid:      paid,
        payment_method:  document.getElementById('fPayMethod').value,
        payment_status:  payStatus,
        notes:           document.getElementById('fPayNotes').value,
        total_amount:    total,
    };

    fetch('api/payments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            // Update local row data for receipt
            var r = rowMap[activeBid];
            r.room_fee        = rf;
            r.extra_guest_fee = eg;
            r.other_charges   = oc;
            r.discount        = disc;
            r.total_paid      = paid;
            r.pay_method      = document.getElementById('fPayMethod').value;
            r.payment_status  = payStatus;
            r.pay_notes       = document.getElementById('fPayNotes').value;
            r.pay_id          = data.pay_id || r.pay_id || 1;

            toast('Payment recorded successfully!', 'success');
            document.getElementById('receiptBtn').style.display = '';
            btn.disabled    = false;
            btn.textContent = 'Save & Record';

            if (payStatus === 'paid') {
                setTimeout(function () { window.location.reload(); }, 1000);
            }
        } else {
            toast(data.error || 'Failed to save payment.', 'error');
            btn.disabled    = false;
            btn.textContent = 'Save & Record';
        }
    })
    .catch(function () {
        toast('Network error. Please try again.', 'error');
        btn.disabled    = false;
        btn.textContent = 'Save & Record';
    });
}

/* ---------- Receipt ---------- */
function buildReceipt(r) {
    var rf   = r.room_fee        || 0;
    var eg   = r.extra_guest_fee || 0;
    var oc   = r.other_charges   || 0;
    var disc = r.discount        || 0;
    var paid = r.total_paid      || 0;
    var total = Math.max(0, rf + eg + oc - disc);
    var bal   = Math.max(0, total - paid);

    document.getElementById('rcRef').textContent          = 'REF: ' + r.reference;
    document.getElementById('rcGuest').textContent        = r.guest_name || '—';
    document.getElementById('rcRoom').textContent         = r.room_name;
    document.getElementById('rcCI').textContent           = fmtDate(r.check_in);
    document.getElementById('rcCO').textContent           = fmtDate(r.check_out);
    document.getElementById('rcNights').textContent       = r.nights + ' night' + (r.nights === 1 ? '' : 's');
    document.getElementById('rcRoomFee').textContent      = '₱' + fmt(rf);
    document.getElementById('rcExtraGuest').textContent   = '₱' + fmt(eg);
    document.getElementById('rcOtherCharges').textContent = '₱' + fmt(oc);
    document.getElementById('rcTotal').textContent        = '₱' + fmt(total);
    document.getElementById('rcPaid').textContent         = '₱' + fmt(paid);
    document.getElementById('rcMethod').textContent       = cap(r.pay_method || 'cash');
    document.getElementById('rcBalance').textContent      = bal <= 0 ? 'Fully Paid' : '₱' + fmt(bal);
    document.getElementById('rcDate').textContent         = 'Printed: ' + new Date().toLocaleString('en-PH');

    var dRow = document.getElementById('rcDiscountRow');
    if (disc > 0) {
        document.getElementById('rcDiscount').textContent = '– ₱' + fmt(disc);
        dRow.style.display = '';
    } else {
        dRow.style.display = 'none';
    }
}

function openReceipt(bid) {
    var r = rowMap[bid];
    if (!r) return;
    buildReceipt(r);
    document.getElementById('receiptOverlay').classList.add('is-open');
}

function openReceiptFromModal() {
    if (!activeBid) return;
    closeBilling();
    openReceipt(activeBid);
}

function closeReceipt() {
    document.getElementById('receiptOverlay').classList.remove('is-open');
}
document.getElementById('receiptOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeReceipt();
});

/* ---------- Utility ---------- */
function fmt(n) { return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}
function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

function toast(msg, type) {
    var wrap = document.getElementById('toastWrap');
    var el   = document.createElement('div');
    el.className = 'toast toast--' + (type || 'success');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 20);
    setTimeout(function () {
        el.classList.remove('show');
        setTimeout(function () { try { wrap.removeChild(el); } catch(e){} }, 400);
    }, 3500);
}
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
