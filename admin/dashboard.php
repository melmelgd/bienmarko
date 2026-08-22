<?php
/**
 * admin/dashboard.php
 * -----------------------------------------------------------------------
 * Full live dashboard: real booking counts, revenue, occupancy rate,
 * notification bell with unread booking alerts, and a recent bookings
 * table — all pulled from the database.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';

$active_page        = 'dashboard';
$page_title         = 'Dashboard Overview';
$breadcrumb         = 'Dashboard';
$page_action_label  = null;        // No action button on this page
$show_period_toggle = false;

$pdo   = bienmarko_db();
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

/* ------------------------------------------------------------------
   Ensure bookings table exists
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

/* ------------------------------------------------------------------
   Ensure admin_notifications table exists
------------------------------------------------------------------ */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `admin_notifications` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `booking_id` INT UNSIGNED NOT NULL,
        `message`    VARCHAR(400) NOT NULL,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_booking` (`booking_id`),
        KEY `idx_read`    (`is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

/* ------------------------------------------------------------------
   Ensure bookings table has guest-details columns (migration guard)
   Same additive guard used by admin/bookings.php and
   admin/payments.php: guest (no-account) walk-in bookings store
   guest_id = NULL plus a name/email directly on the booking row, so
   the Recent Bookings query below needs these columns to fall back
   to for that case.
------------------------------------------------------------------ */
$pdo->exec("ALTER TABLE `bookings` MODIFY COLUMN `guest_id` INT UNSIGNED NULL DEFAULT NULL");
$pdo->exec("
    ALTER TABLE `bookings`
        ADD COLUMN IF NOT EXISTS `guest_name` VARCHAR(200) NULL DEFAULT NULL AFTER `total_amount`,
        ADD COLUMN IF NOT EXISTS `email`      VARCHAR(200) NULL DEFAULT NULL AFTER `guest_name`
");

/* ------------------------------------------------------------------
   STAT 1 — Upcoming stays (check_in >= today, confirmed/pending)
------------------------------------------------------------------ */
$stmtUpcoming = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings
     WHERE status IN ('confirmed','pending')
       AND check_in >= :today"
);
$stmtUpcoming->execute(['today' => $today]);
$statUpcoming = (int) $stmtUpcoming->fetchColumn();

/* ------------------------------------------------------------------
   STAT 2 — Active bookings (currently checked in: check_in <= today
             and check_out > today)
------------------------------------------------------------------ */
$stmtActive = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings
     WHERE status IN ('confirmed','pending')
       AND check_in  <= :today
       AND check_out >  :today2"
);
$stmtActive->execute(['today' => $today, 'today2' => $today]);
$statActive = (int) $stmtActive->fetchColumn();

/* ------------------------------------------------------------------
   STAT 3 — Pending payments (confirmed, check_in >= today)
            Payment is settled on arrival/departure in this system.
------------------------------------------------------------------ */
$stmtPendingPay = $pdo->prepare(
    "SELECT COUNT(*), COALESCE(SUM(total_amount),0)
     FROM bookings
     WHERE status = 'confirmed'
       AND check_in >= :today"
);
$stmtPendingPay->execute(['today' => $today]);
[$statPendingCount, $statPendingTotal] = $stmtPendingPay->fetch(\PDO::FETCH_NUM);
$statPendingCount = (int) $statPendingCount;
$statPendingTotal = (float) $statPendingTotal;

/* ------------------------------------------------------------------
   STAT 4 — Nights booked (sum of nights for all confirmed/pending
             bookings with check_in in the current month)
------------------------------------------------------------------ */
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$stmtNights = $pdo->prepare(
    "SELECT COALESCE(SUM(DATEDIFF(
        LEAST(check_out, :month_end),
        GREATEST(check_in, :month_start)
     )), 0)
     FROM bookings
     WHERE status IN ('confirmed','pending')
       AND check_in  <= :month_end2
       AND check_out >= :month_start2"
);
$stmtNights->execute([
    'month_start'  => $monthStart,
    'month_end'    => $monthEnd,
    'month_start2' => $monthStart,
    'month_end2'   => $monthEnd,
]);
$statNights = (int) $stmtNights->fetchColumn();

/* ------------------------------------------------------------------
   STAT 5 — Occupancy rate (this month)
             = nights booked / (total rooms × days in month)
------------------------------------------------------------------ */
$stmtRoomCount = $pdo->query("SELECT COUNT(*) FROM accommodations WHERE status = 'available'");
$totalRooms    = (int) $stmtRoomCount->fetchColumn();
$daysInMonth   = (int) date('t');
$maxNights     = $totalRooms * $daysInMonth;
$occupancyRate = $maxNights > 0 ? round(($statNights / $maxNights) * 100) : 0;

/* ------------------------------------------------------------------
   Notifications — unread
------------------------------------------------------------------ */
$stmtNotifs = $pdo->query(
    "SELECT n.id, n.message, n.created_at, n.is_read, n.booking_id
     FROM admin_notifications n
     ORDER BY n.created_at DESC
     LIMIT 20"
);
$allNotifications = $stmtNotifs->fetchAll();
$unreadCount      = count(array_filter($allNotifications, fn($n) => !(bool)$n['is_read']));

/* ------------------------------------------------------------------
   Recent bookings table (last 10)
------------------------------------------------------------------ */
$stmtRecent = $pdo->query(
    "SELECT b.id, b.reference, b.check_in, b.check_out,
            b.guests_count, b.status, b.total_amount, b.created_at,
            a.name   AS room_name,
            a.type   AS room_type,
            g.first_name, g.last_name, g.email AS reg_email,
            b.guest_name, b.email AS guest_book_email
     FROM bookings b
     JOIN accommodations a ON a.id = b.accommodation_id
     LEFT JOIN guests    g ON g.id = b.guest_id
     ORDER BY b.created_at DESC
     LIMIT 10"
);
$recentBookings = $stmtRecent->fetchAll();

/* ------------------------------------------------------------------
   Booking trend — last 7 days (count per day)
------------------------------------------------------------------ */
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend[$d] = 0;
}
$stmtTrend = $pdo->prepare(
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
     FROM bookings
     WHERE created_at >= :since
     GROUP BY DATE(created_at)"
);
$stmtTrend->execute(['since' => date('Y-m-d', strtotime('-6 days'))]);
foreach ($stmtTrend->fetchAll() as $row) {
    if (isset($trend[$row['day']])) {
        $trend[$row['day']] = (int) $row['cnt'];
    }
}
$trendMax    = max(array_values($trend)) ?: 1;
$trendValues = array_values($trend);
$trendDays   = array_map(fn($d) => date('D', strtotime($d)), array_keys($trend));

/* ------------------------------------------------------------------
   Helper: status badge
------------------------------------------------------------------ */
function admin_badge(string $status): string
{
    $map = [
        'confirmed'  => ['class' => 'ab--green',  'label' => 'Confirmed'],
        'pending'    => ['class' => 'ab--amber',  'label' => 'Pending'],
        'cancelled'  => ['class' => 'ab--red',    'label' => 'Cancelled'],
        'completed'  => ['class' => 'ab--blue',   'label' => 'Completed'],
    ];
    $b = $map[$status] ?? ['class' => 'ab--grey', 'label' => ucfirst($status)];
    return '<span class="ab ' . $b['class'] . '">' . htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Guest display name/email for the Recent Bookings table. Registered
 * bookings pull from the joined guests row; guest (no-account) walk-
 * ins fall back to the name/email stored directly on the booking
 * itself. Mirrors bk_name()/bk_email() in admin/bookings.php and
 * pm_name()/pm_email() in admin/payments.php.
 */
function dash_guest_name(array $bk): string
{
    if (!empty($bk['first_name'])) {
        return trim($bk['first_name'] . ' ' . $bk['last_name']);
    }
    return $bk['guest_name'] ?? '—';
}

function dash_guest_email(array $bk): string
{
    return $bk['reg_email'] ?? $bk['guest_book_email'] ?? '';
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';

/* Inject unread count into topbar before including it */
$_topbar_notif_count    = $unreadCount;
$_topbar_notifications  = $allNotifications;
require __DIR__ . '/includes/topbar.php';
?>

<style>
/* ============================================================
   admin/dashboard.php — page-scoped styles only.
   Uses CSS variables from assets/css/style.css.
   ============================================================ */

/* ---------- Stack + grid ---------- */
.stack      { display: flex; flex-direction: column; gap: 24px; }
.dash-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

/* ---------- Stat cards ---------- */
.stat-grid      { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; }
.stat-card      { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 18px 20px; }
.stat-card-top  { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.stat-label     { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); }
.stat-icon      { width: 36px; height: 36px; border-radius: var(--r-md); display: flex; align-items: center; justify-content: center; }
.stat-icon--emerald { background: var(--emerald-bg); color: var(--emerald); }
.stat-icon--blue    { background: var(--blue-bg);    color: var(--blue); }
.stat-icon--amber   { background: var(--amber-bg);   color: var(--gold-400); }
.stat-icon--red     { background: var(--red-bg);     color: var(--red); }
.stat-icon--fuchsia { background: var(--fuchsia-bg); color: var(--fuchsia); }
.stat-value     { font-family: var(--font-display); font-size: 26px; font-weight: 800; color: var(--text); display: block; }
.stat-sub       { font-size: 11.5px; color: var(--text-tertiary); margin-top: 3px; }

/* ---------- Panel ---------- */
.panel        { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); overflow: hidden; }
.panel-pad    { padding: 20px 22px; }
.panel-title  { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: var(--text); }
.panel-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px;
}
.pill {
    font-size: 11px; font-weight: 600;
    padding: 4px 10px; border-radius: var(--r-full);
    background: var(--panel-alt); border: 1px solid var(--border);
    color: var(--text-secondary);
}

/* ---------- Bar chart ---------- */
.chart-frame    { position: relative; height: 140px; margin-bottom: 8px; }
.chart-bars     { display: flex; align-items: flex-end; gap: 6px; height: 100%; }
.chart-bar-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; height: 100%; }
.chart-bar {
    width: 100%; border-radius: 4px 4px 0 0;
    background: linear-gradient(180deg, var(--gold-400), var(--gold-600));
    min-height: 4px;
    transition: opacity .15s;
}
.chart-bar:hover { opacity: .8; }
.chart-bar--zero { background: var(--border); }
.chart-days  { display: flex; gap: 6px; }
.chart-day   { flex: 1; font-size: 10.5px; color: var(--text-tertiary); text-align: center; }

/* ---------- Donut placeholder ---------- */
.donut-area {
    display: flex; align-items: center; justify-content: center;
    height: 120px;
}
.donut-placeholder-text { font-size: 13px; color: var(--text-tertiary); }
.revenue-figure {
    font-family: var(--font-display); font-size: 28px; font-weight: 800;
    color: var(--gold-400); margin: 10px 0 16px;
}
.legend-title { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 10px; }
.legend-list  { display: flex; flex-direction: column; gap: 8px; }
.legend-row   { display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
.legend-label { display: flex; align-items: center; gap: 8px; color: var(--text-secondary); }
.legend-dot   { width: 8px; height: 8px; border-radius: 50%; background: var(--gold-500); flex-shrink: 0; }
.legend-value { font-weight: 600; color: var(--text); }

/* ---------- Revenue pie chart ---------- */
.pie-chart-row  { display: flex; align-items: center; gap: 24px; }
.pie-chart-wrap { position: relative; width: 150px; height: 150px; flex-shrink: 0; }
.pie-chart-svg  { width: 100%; height: 100%; filter: drop-shadow(0 4px 10px rgba(0,0,0,.35)); }
.pie-slice      { stroke: var(--panel); stroke-width: 1.5; transition: opacity .15s; cursor: pointer; }
.pie-slice:hover{ opacity: .82; }
.pie-legend     { flex: 1; min-width: 0; }
.pie-legend .legend-list { gap: 12px; }
.pie-legend .legend-row  { font-size: 12.5px; }
.pie-legend .legend-value{ display: flex; align-items: baseline; gap: 5px; white-space: nowrap; }
@media (max-width: 560px) {
    .pie-chart-row { flex-direction: column; align-items: flex-start; }
}

/* ---------- Recent bookings table ---------- */
.table-wrap  { overflow: hidden; }
.table-head  {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 20px 0; margin-bottom: 14px;
}
.table-head a {
    font-size: 12.5px; color: var(--gold-400); font-weight: 600;
    text-decoration: none;
}
.table-head a:hover { color: var(--gold-300); }
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; white-space: nowrap; }
thead th {
    padding: 10px 16px;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: var(--text-tertiary);
    border-bottom: 1px solid var(--border);
    text-align: left;
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--panel-alt); }
tbody td { padding: 13px 16px; font-size: 13.5px; color: var(--text); vertical-align: middle; }
.td-ref   { font-family: var(--font-display); font-size: 12px; font-weight: 700; color: var(--gold-400); letter-spacing: .04em; }
.td-guest { font-weight: 600; }
.td-sub   { font-size: 11.5px; color: var(--text-secondary); margin-top: 1px; }
.td-amount{ font-family: var(--font-display); font-weight: 700; }

/* ---------- Status badges ---------- */
.ab {
    display: inline-flex; align-items: center;
    padding: 3px 9px; border-radius: var(--r-full);
    font-size: 11px; font-weight: 700; letter-spacing: .03em;
}
.ab--green { background: var(--emerald-bg); color: var(--emerald); }
.ab--amber { background: var(--amber-bg);   color: var(--gold-400); }
.ab--red   { background: var(--red-bg);     color: var(--red); }
.ab--blue  { background: var(--blue-bg);    color: var(--blue); }
.ab--grey  { background: var(--panel-alt);  color: var(--text-secondary); }

/* ---------- Empty state ---------- */
.empty-state { padding: 40px 24px; text-align: center; }
.empty-state-icon { margin: 0 auto 12px; color: var(--text-muted); }
.empty-state-title   { font-family: var(--font-display); font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.empty-state-message { font-size: 13.5px; color: var(--text-secondary); }

/* ---------- Notification panel override ---------- */
.notif-count-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 18px; height: 18px; padding: 0 5px;
    background: var(--red); color: #fff;
    border-radius: var(--r-full); font-size: 10px; font-weight: 700;
    position: absolute; top: -4px; right: -4px;
}
.notif-list-item {
    padding: 12px 16px; border-bottom: 1px solid var(--border);
    display: flex; flex-direction: column; gap: 3px;
}
.notif-list-item:last-child { border-bottom: none; }
.notif-list-item.unread { background: rgba(249,185,85,.06); }
.notif-msg  { font-size: 13px; color: var(--text); line-height: 1.45; }
.notif-time { font-size: 11px; color: var(--text-tertiary); }

/* ---------- Responsive ---------- */
@media (max-width: 1100px) {
    .stat-grid { grid-template-columns: repeat(3, 1fr); }
    .dash-grid { grid-template-columns: 1fr; }
}
@media (max-width: 700px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .stat-grid { grid-template-columns: 1fr; }
}
</style>

<?php
/* ---- Topbar notification override ----
   The existing topbar hardcodes "1" in the bell — we override it here
   by outputting a replacement after including topbar.php. Since topbar
   opens <main class="content">, our overriding JS runs inside that. */
?>
<script>
(function () {
    /* Replace the hardcoded bell badge with the real unread count */
    var btn = document.querySelector('.icon-btn[aria-label]');
    if (!btn) return;
    var count = <?php echo (int) $unreadCount; ?>;
    btn.setAttribute('aria-label', count + ' unread notification' + (count === 1 ? '' : 's'));

    var dot = btn.querySelector('.notif-dot');
    if (dot) {
        if (count === 0) {
            dot.style.display = 'none';
        } else {
            dot.textContent = count > 99 ? '99+' : count;
        }
    }

    /* Populate notification panel */
    var panel = document.querySelector('.topbar-right');
    if (!panel) return;

    var notifData = <?php echo json_encode(
        array_map(function ($n) {
            return [
                'id'      => (int)  $n['id'],
                'msg'     => $n['message'],
                'time'    => date('M j · g:i A', strtotime($n['created_at'])),
                'unread'  => !(bool) $n['is_read'],
                'bk_id'   => (int)  $n['booking_id'],
            ];
        }, $allNotifications)
    ); ?>;

    /* Build a proper notification dropdown */
    var existing = btn.parentElement;
    var dropdown = document.createElement('div');
    dropdown.id        = 'notifDropdown';
    dropdown.className = 'notif-dropdown';
    dropdown.hidden    = true;
    dropdown.style.cssText =
        'position:absolute;top:calc(100% + 8px);right:0;width:320px;max-height:400px;' +
        'background:var(--panel);border:1px solid var(--border);border-radius:var(--r-lg);' +
        'overflow-y:auto;z-index:200;box-shadow:0 12px 40px rgba(0,0,0,.5);';

    var header = '<div style="padding:14px 16px;border-bottom:1px solid var(--border);' +
        'display:flex;align-items:center;justify-content:space-between;">' +
        '<span style="font-family:var(--font-display);font-size:14px;font-weight:700;">Notifications</span>' +
        (count > 0 ? '<button id="markAllReadBtn" style="font-size:12px;color:var(--gold-400);background:none;border:none;cursor:pointer;font-weight:600;">Mark all read</button>' : '') +
        '</div>';

    var body = '';
    if (notifData.length === 0) {
        body = '<div class="empty-state" style="padding:28px 16px;">' +
               '<p class="empty-state-message">No notifications yet.</p></div>';
    } else {
        notifData.forEach(function (n) {
            body += '<div class="notif-list-item' + (n.unread ? ' unread' : '') + '" data-id="' + n.id + '">' +
                    '<span class="notif-msg">' + escHtml(n.msg) + '</span>' +
                    '<span class="notif-time">' + escHtml(n.time) + '</span>' +
                    '</div>';
        });
    }

    dropdown.innerHTML = header + body;
    existing.style.position = 'relative';
    existing.appendChild(dropdown);

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.hidden = !dropdown.hidden;
    });

    document.addEventListener('click', function () {
        dropdown.hidden = true;
    });
    dropdown.addEventListener('click', function (e) { e.stopPropagation(); });

    var markAllBtn = document.getElementById('markAllReadBtn');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            fetch('api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read', csrf_token: '<?php echo htmlspecialchars(bienmarko_csrf_token()); ?>' }),
            }).then(function () {
                /* Update UI */
                document.querySelectorAll('.notif-list-item.unread').forEach(function (el) {
                    el.classList.remove('unread');
                });
                if (dot) dot.style.display = 'none';
                btn.setAttribute('aria-label', '0 unread notifications');
            });
        });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
})();
</script>

<div class="stack">

    <!-- ===== Stat cards ===== -->
    <div class="stat-grid">
        <?php
        render_stat_card('Upcoming Stays',   (string) $statUpcoming,  'calendar-check', 'emerald');
        render_stat_card('Active Now',       (string) $statActive,    'bed',            'blue');
        render_stat_card('Pending Payments', (string) $statPendingCount, 'wallet',      'amber');
        render_stat_card('Nights This Month',(string) $statNights,    'moon',           'red');
        render_stat_card('Occupancy Rate',   $occupancyRate . '%',    'pie-chart',      'fuchsia');
        ?>
    </div>

    <!-- ===== Charts row ===== -->
    <div class="dash-grid">

        <!-- Booking Trend (last 7 days) -->
        <div class="panel panel-pad">
            <div class="panel-header">
                <h2 class="panel-title">Booking Trend</h2>
                <span class="pill">Last 7 Days</span>
            </div>

            <?php if ($trendMax === 1 && array_sum($trendValues) === 0): ?>
                <div class="chart-frame" style="display:flex;align-items:center;justify-content:center;">
                    <div style="text-align:center;color:var(--text-tertiary);">
                        <?php echo icon('inbox', 'icon-lg'); ?>
                        <span style="display:block;margin-top:8px;font-size:13px;">No booking data yet</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="chart-frame">
                    <div class="chart-bars">
                        <?php foreach ($trendValues as $i => $val): ?>
                            <?php
                            $pct = $trendMax > 0 ? round(($val / $trendMax) * 100) : 0;
                            $isEmpty = ($val === 0);
                            ?>
                            <div class="chart-bar-wrap">
                                <div class="chart-bar <?php echo $isEmpty ? 'chart-bar--zero' : ''; ?>"
                                     style="height:<?php echo max(4, $pct); ?>%"
                                     title="<?php echo htmlspecialchars($trendDays[$i]); ?>: <?php echo $val; ?> booking<?php echo $val === 1 ? '' : 's'; ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="chart-days">
                <?php foreach ($trendDays as $day): ?>
                    <span class="chart-day"><?php echo htmlspecialchars($day); ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Revenue Breakdown -->
        <div class="panel panel-pad">
            <div class="panel-header">
                <h2 class="panel-title">Revenue Breakdown</h2>
                <span class="pill"><?php echo date('M Y'); ?></span>
            </div>

            <?php
            /* Revenue this month from confirmed/completed bookings */
            $stmtRev = $pdo->prepare(
                "SELECT COALESCE(SUM(b.total_amount), 0) AS revenue,
                        a.type,
                        COUNT(*) AS cnt
                 FROM bookings b
                 JOIN accommodations a ON a.id = b.accommodation_id
                 WHERE b.status IN ('confirmed','completed')
                   AND b.check_in >= :month_start
                   AND b.check_in <= :month_end
                 GROUP BY a.type
                 ORDER BY revenue DESC
                 LIMIT 5"
            );
            $stmtRev->execute(['month_start' => $monthStart, 'month_end' => $monthEnd]);
            $revenueRows = $stmtRev->fetchAll();
            $totalRevenue = array_sum(array_column($revenueRows, 'revenue'));

            /* Build pie-slice geometry (SVG path per accommodation type) */
            $pieColors  = ['var(--gold-400)', 'var(--blue)', 'var(--emerald)', 'var(--fuchsia)', 'var(--red)'];
            $pieCx      = 90;
            $pieCy      = 90;
            $pieR       = 80;
            $pieSlices  = [];
            $cumulative = 0;
            foreach ($revenueRows as $i => $row) {
                $fraction    = $totalRevenue > 0 ? ($row['revenue'] / $totalRevenue) : 0;
                $pct         = $totalRevenue > 0 ? round($fraction * 100) : 0;
                $startAngle  = $cumulative;
                $endAngle    = $cumulative + ($fraction * 360);
                $cumulative  = $endAngle;

                $x1 = $pieCx + $pieR * cos(deg2rad($startAngle - 90));
                $y1 = $pieCy + $pieR * sin(deg2rad($startAngle - 90));
                $x2 = $pieCx + $pieR * cos(deg2rad($endAngle - 90));
                $y2 = $pieCy + $pieR * sin(deg2rad($endAngle - 90));
                $largeArc = (($endAngle - $startAngle) > 180) ? 1 : 0;

                $pieSlices[] = [
                    'path'   => sprintf('M%.3F,%.3F L%.3F,%.3F A%d,%d 0 %d,1 %.3F,%.3F Z', $pieCx, $pieCy, $x1, $y1, $pieR, $pieR, $largeArc, $x2, $y2),
                    'color'  => $pieColors[$i % count($pieColors)],
                    'type'   => $row['type'],
                    'amount' => $row['revenue'],
                    'pct'    => $pct,
                ];
            }
            ?>
            <p class="revenue-figure">₱<?php echo number_format($totalRevenue, 2); ?></p>

            <?php if (empty($revenueRows)): ?>
                <div class="donut-area">
                    <span class="donut-placeholder-text">No revenue data yet</span>
                </div>
            <?php else: ?>
                <div class="pie-chart-row">
                    <div class="pie-chart-wrap">
                        <svg class="pie-chart-svg" viewBox="0 0 180 180" role="img" aria-label="Revenue breakdown by accommodation type">
                            <?php if (count($pieSlices) === 1): ?>
                                <circle class="pie-slice" cx="<?php echo $pieCx; ?>" cy="<?php echo $pieCy; ?>" r="<?php echo $pieR; ?>"
                                        fill="<?php echo $pieSlices[0]['color']; ?>">
                                    <title><?php echo htmlspecialchars($pieSlices[0]['type']); ?>: ₱<?php echo number_format($pieSlices[0]['amount'], 2); ?> (<?php echo $pieSlices[0]['pct']; ?>%)</title>
                                </circle>
                            <?php else: ?>
                                <?php foreach ($pieSlices as $slice): ?>
                                    <path class="pie-slice" d="<?php echo $slice['path']; ?>" fill="<?php echo $slice['color']; ?>">
                                        <title><?php echo htmlspecialchars($slice['type']); ?>: ₱<?php echo number_format($slice['amount'], 2); ?> (<?php echo $slice['pct']; ?>%)</title>
                                    </path>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </svg>
                    </div>
                    <div class="pie-legend">
                        <span class="legend-title">By Accommodation Type</span>
                        <div class="legend-list">
                            <?php foreach ($pieSlices as $slice): ?>
                                <div class="legend-row">
                                    <span class="legend-label">
                                        <span class="legend-dot" style="background:<?php echo $slice['color']; ?>;"></span>
                                        <?php echo htmlspecialchars($slice['type']); ?>
                                    </span>
                                    <span class="legend-value">
                                        ₱<?php echo number_format($slice['amount'], 2); ?>
                                        <span style="color:var(--text-tertiary);font-weight:500;">(<?php echo $slice['pct']; ?>%)</span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ===== Recent Bookings Table ===== -->
    <div class="panel table-wrap">
        <div class="table-head">
            <h2 class="panel-title">Recent Bookings</h2>
            <a href="bookings.php">View all →</a>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Guests</th>
                        <th>Status</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentBookings)): ?>
                        <tr>
                            <td colspan="7">
                                <?php render_empty_state(
                                    'calendar',
                                    'No bookings yet',
                                    'New bookings from guests will appear here automatically.'
                                ); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentBookings as $bk): ?>
                            <tr>
                                <td>
                                    <div class="td-guest">
                                        <?php echo htmlspecialchars(dash_guest_name($bk), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="td-sub"><?php echo htmlspecialchars(dash_guest_email($bk), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($bk['room_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="td-sub"><?php echo htmlspecialchars($bk['room_type'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($bk['check_in'])); ?></td>
                                <td><?php echo date('M j, Y', strtotime($bk['check_out'])); ?></td>
                                <td><?php echo (int) $bk['guests_count']; ?></td>
                                <td><?php echo admin_badge($bk['status']); ?></td>
                                <td class="td-amount">₱<?php echo number_format((float) $bk['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.stack -->

<?php require __DIR__ . '/includes/foot.php'; ?>