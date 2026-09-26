<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'receipts';
$pageTitle   = 'Receipts';

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
$pdo     = bienmarko_db();

/* ------------------------------------------------------------------
   Ensure payments table exists — same shape as admin/payments.php's
   own guard, so this page works correctly even if it's the very
   first page in the app to touch the table.
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
   Pull every payment recorded against this guest's bookings.
------------------------------------------------------------------ */
$receipts = [];
try {
    $stmt = $pdo->prepare(
        "SELECT p.id AS pay_id, p.room_fee, p.extra_guest_fee, p.other_charges,
                p.discount, p.discount_reason, p.total_paid, p.payment_method,
                p.payment_status, p.notes, p.paid_at, p.created_at AS pay_created_at,
                b.id AS booking_id, b.reference, b.check_in, b.check_out,
                b.guests_count, b.total_amount,
                a.name AS room_name, a.type AS room_type
         FROM payments p
         JOIN bookings b       ON b.id = p.booking_id
         JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.guest_id = :gid
           AND p.total_paid > 0
         ORDER BY COALESCE(p.paid_at, p.created_at) DESC"
    );
    $stmt->execute(['gid' => $guestId]);
    $receipts = $stmt->fetchAll();
} catch (Throwable $e) {
    $receipts = [];
}

/* ------------------------------------------------------------------
   Stat cards
------------------------------------------------------------------ */
$lifetimePaid = 0.0;
foreach ($receipts as $r) {
    $lifetimePaid += (float) $r['total_paid'];
}
$receiptCount  = count($receipts);
$lastReceipt   = $receipts[0] ?? null;
$lastPaidLabel = $lastReceipt
    ? date('M j, Y', strtotime($lastReceipt['paid_at'] ?? $lastReceipt['pay_created_at']))
    : '—';

$statCards = [
    [
        'variant' => 'olive',
        'icon'    => 'wallet',
        'label'   => 'Total Paid',
        'value'   => '₱' . number_format($lifetimePaid, 2),
        'meta'    => $receiptCount > 0 ? 'Across all stays' : 'No payments yet',
    ],
    [
        'variant' => 'navy',
        'icon'    => 'file-text',
        'label'   => 'Receipts',
        'value'   => (string) $receiptCount,
        'meta'    => $receiptCount === 1 ? '1 payment on record' : $receiptCount . ' payments on record',
    ],
    [
        'variant' => 'purple',
        'icon'    => 'circle-check',
        'label'   => 'Last Payment',
        'value'   => $lastPaidLabel,
        'meta'    => $lastReceipt ? htmlspecialchars_decode((string) $lastReceipt['reference']) : 'Book a stay to get started',
    ],
];

/* ------------------------------------------------------------------
   Payment status badge helper
------------------------------------------------------------------ */
function receipt_status_class(string $status): string
{
    return match ($status) {
        'paid'    => 'rc-badge--green',
        'partial' => 'rc-badge--amber',
        'unpaid'  => 'rc-badge--red',
        default   => 'rc-badge--grey',
    };
}

require __DIR__ . '/includes/head.php';
?>

<style>
/* ============================================================
   receipts.php — page-scoped styles only
   ============================================================ */

.rc-list { display: flex; flex-direction: column; gap: 10px; }
.rc-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px;
    background: var(--surface-raised);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    transition: border-color .15s;
}
.rc-item:hover { border-color: var(--ember-strong); }
.rc-item__ref {
    font-family: var(--font-display); font-size: 12px; font-weight: 700;
    color: var(--ember); letter-spacing: .04em; flex-shrink: 0;
    min-width: 100px;
}
.rc-item__body { flex: 1; min-width: 0; }
.rc-item__room { font-weight: 700; font-size: 13.5px; margin-bottom: 1px; }
.rc-item__dates { font-size: 12px; color: var(--paper-dim); }
.rc-item__right {
    display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0;
}
.rc-item__amount { font-family: var(--font-display); font-size: 15px; font-weight: 700; }
.rc-badge {
    display: inline-flex; align-items: center;
    padding: 2px 9px; border-radius: var(--radius-full);
    font-size: 10px; font-weight: 700; letter-spacing: .03em;
}
.rc-badge--green  { background: rgba(40,160,90,.2);  color: #4fd882; }
.rc-badge--amber  { background: rgba(220,160,40,.2); color: var(--ember-strong); }
.rc-badge--red    { background: rgba(220,80,60,.2);  color: var(--danger); }
.rc-badge--grey   { background: rgba(140,140,150,.2); color: var(--paper-dim); }
.rc-item__btn {
    padding: 7px 14px; border-radius: var(--radius-full);
    background: transparent; border: 1px solid var(--ember);
    color: var(--ember); font-size: 12px; font-weight: 600;
    white-space: nowrap;
}
.rc-item__btn:hover { background: rgba(232, 163, 61, .1); }

/* ---------- Receipt overlay (printable) ---------- */
.rc-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.75);
    display: flex; align-items: center; justify-content: center;
    z-index: 600; padding: 20px;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.rc-overlay.is-open { opacity: 1; pointer-events: auto; }
.rc-box {
    background: #fff; border-radius: var(--radius-lg); width: 100%; max-width: 360px;
    padding: 30px 28px; font-family: 'Courier New', monospace; color: #111;
    transform: scale(.95); transition: transform .2s;
    max-height: 90vh; overflow-y: auto;
}
.rc-overlay.is-open .rc-box { transform: scale(1); }
.rc-box__logo   { text-align: center; margin-bottom: 14px; }
.rc-box__name   { font-family: 'Georgia', serif; font-size: 17px; font-weight: 700; color: #8b6914; }
.rc-box__sub    { font-size: 11px; color: #666; margin-top: 2px; }
.rc-box__title  { text-align: center; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
.rc-box__ref    { text-align: center; font-size: 11px; background: #f5f5f5; padding: 6px; border-radius: 4px; margin: 10px 0; letter-spacing: .04em; }
.rc-divider     { border: none; border-top: 1px dashed #ccc; margin: 12px 0; }
.rc-row         { display: flex; justify-content: space-between; font-size: 12.5px; margin: 5px 0; }
.rc-row__label  { color: #555; }
.rc-row__value  { font-weight: 700; text-align: right; }
.rc-total-row   { display: flex; justify-content: space-between; font-size: 15px; font-weight: 700; margin-top: 10px; padding-top: 8px; border-top: 2px solid #333; }
.rc-box__footer { text-align: center; font-size: 11px; color: #888; margin-top: 16px; line-height: 1.6; }
.rc-actions     { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
.rc-action-btn  { padding: 8px 18px; border-radius: 4px; font-size: 13px; font-weight: 600; border: 1px solid #ccc; cursor: pointer; }
.rc-action-btn--print { background: #8b6914; color: #fff; border-color: #8b6914; }
.rc-action-btn--close { background: #f5f5f5; color: #333; }

@media print {
    .topbar, .bottom-nav, .page-head, .stat-grid, .rc-list, .rc-actions { display: none !important; }
    .rc-overlay { position: static !important; background: none !important; opacity: 1 !important; }
    .rc-box { box-shadow: none; max-height: none; }
}

@media (max-width: 600px) {
    .rc-item { flex-wrap: wrap; }
    .rc-item__right { flex-direction: row; align-items: center; width: 100%; justify-content: space-between; }
}
</style>

<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('Receipts', 'Download and review your payment receipts'); ?>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <?php foreach ($statCards as $card): render_stat_card($card); endforeach; ?>
    </div>

    <section class="panel">
        <div class="panel__head">
            <h2>Payment History</h2>
        </div>

        <?php if (empty($receipts)): ?>
            <?php render_empty_state(
                'file-text',
                'No receipts yet',
                'Once a payment is processed, your receipt will appear here for download.'
            ); ?>
        <?php else: ?>
            <div class="rc-list">
                <?php foreach ($receipts as $r): ?>
                    <?php
                    $ciLabel = date('M j', strtotime($r['check_in']));
                    $coLabel = date('M j, Y', strtotime($r['check_out']));
                    $status  = $r['payment_status'] ?? 'unpaid';
                    ?>
                    <div class="rc-item">
                        <div class="rc-item__ref"><?php echo htmlspecialchars($r['reference']); ?></div>
                        <div class="rc-item__body">
                            <div class="rc-item__room"><?php echo htmlspecialchars($r['room_name']); ?></div>
                            <div class="rc-item__dates">
                                <?php echo htmlspecialchars($ciLabel); ?> → <?php echo htmlspecialchars($coLabel); ?>
                            </div>
                        </div>
                        <div class="rc-item__right">
                            <span class="rc-item__amount">₱<?php echo number_format((float) $r['total_paid'], 2); ?></span>
                            <span class="rc-badge <?php echo receipt_status_class($status); ?>">
                                <?php echo ucfirst(htmlspecialchars($status)); ?>
                            </span>
                        </div>
                        <button type="button" class="rc-item__btn" onclick="openReceipt(<?php echo (int) $r['pay_id']; ?>)">
                            View Receipt
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<!-- ===== Receipt pop-up ===== -->
<div class="rc-overlay" id="rcOverlay">
    <div class="rc-box" id="rcBox">
        <div class="rc-box__logo">
            <div class="rc-box__name">Bien Marko's Pension House</div>
            <div class="rc-box__sub">1st Ave Street, Poblacion, Tabango, Leyte</div>
        </div>
        <div class="rc-box__title">OFFICIAL RECEIPT</div>
        <div class="rc-box__ref" id="rcRef">REF: —</div>
        <hr class="rc-divider">
        <div class="rc-row"><span class="rc-row__label">Room</span>      <span class="rc-row__value" id="rcRoom">—</span></div>
        <div class="rc-row"><span class="rc-row__label">Check-in</span>  <span class="rc-row__value" id="rcCI">—</span></div>
        <div class="rc-row"><span class="rc-row__label">Check-out</span> <span class="rc-row__value" id="rcCO">—</span></div>
        <div class="rc-row"><span class="rc-row__label">Nights</span>    <span class="rc-row__value" id="rcNights">—</span></div>
        <hr class="rc-divider">
        <div class="rc-row"><span class="rc-row__label">Room Fee</span>        <span class="rc-row__value" id="rcRoomFee">—</span></div>
        <div class="rc-row"><span class="rc-row__label">Extra Guest Fee</span> <span class="rc-row__value" id="rcExtraGuest">—</span></div>
        <div class="rc-row"><span class="rc-row__label">Other Charges</span>   <span class="rc-row__value" id="rcOtherCharges">—</span></div>
        <div class="rc-row" id="rcDiscountRow"><span class="rc-row__label">Discount</span><span class="rc-row__value" id="rcDiscount" style="color:#c0392b;">—</span></div>
        <hr class="rc-divider">
        <div class="rc-total-row">
            <span>TOTAL</span>
            <span id="rcTotal">₱0.00</span>
        </div>
        <div class="rc-row" style="margin-top:8px;"><span class="rc-row__label">Amount Paid</span> <span class="rc-row__value" id="rcPaid">—</span></div>
        <div class="rc-row"><span class="rc-row__label">Payment Method</span> <span class="rc-row__value" id="rcMethod">—</span></div>
        <div class="rc-row"><span class="rc-row__label">Balance</span>        <span class="rc-row__value" id="rcBalance">—</span></div>
        <hr class="rc-divider">
        <div class="rc-box__footer">
            Thank you for choosing Bien Marko's Pension House!<br>
            We hope to see you again soon.<br>
            <span id="rcPrintedAt" style="margin-top:4px;display:block;"></span>
        </div>
        <div class="rc-actions">
            <button type="button" class="rc-action-btn rc-action-btn--print" onclick="window.print()">Print</button>
            <button type="button" class="rc-action-btn rc-action-btn--close" onclick="closeReceipt()">Close</button>
        </div>
    </div>
</div>

<script>
var RECEIPTS = <?php echo json_encode(array_map(function ($r) {
    $nights = max(1, (int) round(
        (strtotime($r['check_out']) - strtotime($r['check_in'])) / 86400
    ));
    return [
        'pay_id'          => (int) $r['pay_id'],
        'reference'       => $r['reference'],
        'room_name'       => $r['room_name'],
        'room_type'       => $r['room_type'],
        'check_in'        => date('M j, Y', strtotime($r['check_in'])),
        'check_out'       => date('M j, Y', strtotime($r['check_out'])),
        'nights'          => $nights,
        'room_fee'        => (float) $r['room_fee'],
        'extra_guest_fee' => (float) $r['extra_guest_fee'],
        'other_charges'   => (float) $r['other_charges'],
        'discount'        => (float) $r['discount'],
        'total_paid'      => (float) $r['total_paid'],
        'payment_method'  => $r['payment_method'],
    ];
}, $receipts)); ?>;

var rcMap = {};
RECEIPTS.forEach(function (r) { rcMap[r.pay_id] = r; });

function rcFmt(n) {
    return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function rcCap(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '—';
}

function openReceipt(payId) {
    var r = rcMap[payId];
    if (!r) return;

    var total = Math.max(0, r.room_fee + r.extra_guest_fee + r.other_charges - r.discount);
    var bal   = Math.max(0, total - r.total_paid);

    document.getElementById('rcRef').textContent          = 'REF: ' + r.reference;
    document.getElementById('rcRoom').textContent          = r.room_name + ' (' + r.room_type + ')';
    document.getElementById('rcCI').textContent             = r.check_in;
    document.getElementById('rcCO').textContent             = r.check_out;
    document.getElementById('rcNights').textContent         = r.nights + ' night' + (r.nights === 1 ? '' : 's');
    document.getElementById('rcRoomFee').textContent        = '₱' + rcFmt(r.room_fee);
    document.getElementById('rcExtraGuest').textContent     = '₱' + rcFmt(r.extra_guest_fee);
    document.getElementById('rcOtherCharges').textContent   = '₱' + rcFmt(r.other_charges);
    document.getElementById('rcTotal').textContent          = '₱' + rcFmt(total);
    document.getElementById('rcPaid').textContent           = '₱' + rcFmt(r.total_paid);
    document.getElementById('rcMethod').textContent         = rcCap(r.payment_method);
    document.getElementById('rcBalance').textContent        = bal <= 0 ? 'Fully Paid' : '₱' + rcFmt(bal);
    document.getElementById('rcPrintedAt').textContent      = 'Printed: ' + new Date().toLocaleString('en-PH');

    var dRow = document.getElementById('rcDiscountRow');
    if (r.discount > 0) {
        document.getElementById('rcDiscount').textContent = '– ₱' + rcFmt(r.discount);
        dRow.style.display = '';
    } else {
        dRow.style.display = 'none';
    }

    document.getElementById('rcOverlay').classList.add('is-open');
}

function closeReceipt() {
    document.getElementById('rcOverlay').classList.remove('is-open');
}
document.getElementById('rcOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeReceipt();
});
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
