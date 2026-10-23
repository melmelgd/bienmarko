<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/booking-stay.php';   // admin check-in / check-out stages

$currentPage = 'dashboard';
$pageTitle   = 'Dashboard';

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
$pdo     = bienmarko_db();

/* ------------------------------------------------------------------
   Ensure bookings table exists (safe on every request — no-op when
   the table already exists).
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

$today = date('Y-m-d');

/* ------------------------------------------------------------------
   1. Next upcoming confirmed stay
------------------------------------------------------------------ */
$stmtUpcoming = $pdo->prepare(
    "SELECT b.id, b.reference, b.check_in, b.check_out, b.guests_count,
            b.total_amount, b.status,
            a.name AS room_name, a.type AS room_type, a.image_filename
     FROM bookings b
     JOIN accommodations a ON a.id = b.accommodation_id
     WHERE b.guest_id = :gid
       AND b.status IN ('confirmed','pending')
       AND b.check_in >= :today
       AND " . bk_not_checked_in_sql($pdo, 'b') . "
     ORDER BY b.check_in ASC
     LIMIT 1"
);
$stmtUpcoming->execute(['gid' => $guestId, 'today' => $today]);
$upcomingStay = $stmtUpcoming->fetch() ?: null;

/* ------------------------------------------------------------------
   1b. Current stay — the admin has checked the guest in and has not
       yet checked them out. Shown instead of "upcoming".
------------------------------------------------------------------ */
$currentStay = null;
if (bk_has_stay_columns($pdo)) {
    $stmtCurrent = $pdo->prepare(
        "SELECT b.id, b.reference, b.check_in, b.check_out, b.guests_count,
                b.total_amount, b.status, b.checked_in_at,
                a.name AS room_name, a.type AS room_type, a.image_filename
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.guest_id = :gid
           AND b.status <> 'cancelled'
           AND b.checked_in_at IS NOT NULL
           AND b.checked_out_at IS NULL
         ORDER BY b.checked_in_at DESC
         LIMIT 1"
    );
    $stmtCurrent->execute(['gid' => $guestId]);
    $currentStay = $stmtCurrent->fetch() ?: null;
}
// What the panel / stat card features: the in-progress stay wins over a future one.
$featuredStay = $currentStay ?: $upcomingStay;
$featuredIsCurrent = $currentStay !== null;

/* ------------------------------------------------------------------
   2. Active bookings count (confirmed + pending, not yet checked out)
------------------------------------------------------------------ */
$stmtActive = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings
     WHERE guest_id = :gid
       AND status IN ('confirmed','pending')
       AND check_out >= :today
       AND " . (bk_has_stay_columns($pdo) ? 'checked_out_at IS NULL' : '1=1')
);
$stmtActive->execute(['gid' => $guestId, 'today' => $today]);
$activeBookingsCount = (int) $stmtActive->fetchColumn();

/* ------------------------------------------------------------------
   3. Pending payments total (confirmed bookings not yet completed)
      In this system payment is on check-in/check-out, so every
      confirmed future booking is "pending payment".
------------------------------------------------------------------ */
$stmtPending = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount), 0), COUNT(*)
     FROM bookings
     WHERE guest_id = :gid
       AND status = 'confirmed'
       AND check_in >= :today"
);
$stmtPending->execute(['gid' => $guestId, 'today' => $today]);
[$pendingTotal, $pendingCount] = $stmtPending->fetch(\PDO::FETCH_NUM);
$pendingPaymentsTotal = (float) $pendingTotal;
$pendingPaymentsCount = (int) $pendingCount;

/* ------------------------------------------------------------------
   4. Recent bookings list (last 5)
------------------------------------------------------------------ */
$stmtRecent = $pdo->prepare(
    "SELECT b.id, b.reference, b.check_in, b.check_out,
            b.guests_count, b.status, " . bk_stay_select($pdo) . ", b.total_amount,
            a.name AS room_name, a.type AS room_type
     FROM bookings b
     JOIN accommodations a ON a.id = b.accommodation_id
     WHERE b.guest_id = :gid
     ORDER BY b.created_at DESC
     LIMIT 5"
);
$stmtRecent->execute(['gid' => $guestId]);
$recentBookings = $stmtRecent->fetchAll();

/* ------------------------------------------------------------------
   Build stat cards
------------------------------------------------------------------ */
if ($featuredStay) {
    $nights = (int) round(
        (strtotime($featuredStay['check_out']) - strtotime($featuredStay['check_in'])) / 86400
    );
    $upcomingLabel = $featuredIsCurrent
        ? 'Checked in'
        : date('M j, Y', strtotime($featuredStay['check_in']));
    $upcomingMeta  = $featuredStay['room_name'] . ' · ' . $nights . ' night' . ($nights === 1 ? '' : 's');
} else {
    $upcomingLabel = 'No upcoming stay';
    $upcomingMeta  = 'Book your next visit';
}

$statCards = [
    [
        'variant' => 'olive',
        'icon'    => 'bed-double',
        'label'   => $featuredIsCurrent ? 'Current Stay' : 'Upcoming Stay',
        'value'   => $upcomingLabel,
        'meta'    => $upcomingMeta,
    ],
    [
        'variant' => 'navy',
        'icon'    => 'circle-check',
        'label'   => 'Active Bookings',
        'value'   => (string) $activeBookingsCount,
        'meta'    => $activeBookingsCount > 0
            ? $activeBookingsCount . ' booking' . ($activeBookingsCount === 1 ? '' : 's') . ' active'
            : 'No active bookings',
    ],
    [
        'variant' => 'purple',
        'icon'    => 'wallet',
        'label'   => 'Pending Payment',
        'value'   => '₱' . number_format($pendingPaymentsTotal, 2),
        'meta'    => $pendingPaymentsCount > 0
            ? $pendingPaymentsCount . ' payment' . ($pendingPaymentsCount === 1 ? '' : 's') . ' due on check-in'
            : 'No pending payments',
    ],
];

$greetingName = $_SESSION['auth_name'] ?? null;

/* ------------------------------------------------------------------
   Status badge helper
------------------------------------------------------------------ */
function dash_status_class(string $colour): string
{
    return match ($colour) {
        'green'  => 'dash-badge--green',
        'amber'  => 'dash-badge--amber',
        'red'    => 'dash-badge--red',
        'blue'   => 'dash-badge--blue',
        'teal'   => 'dash-badge--teal',
        default  => 'dash-badge--grey',
    };
}

require __DIR__ . '/includes/head.php';
?>

<style>
/* ============================================================
   dashboard.php — page-scoped styles only
   ============================================================ */

/* ---------- Hero banner ---------- */
.hero {
    position: relative; overflow: hidden;
    display: flex; align-items: center; justify-content: space-between;
    gap: 24px; flex-wrap: wrap;
    padding: 40px;
    margin-bottom: 24px;
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    background:
        radial-gradient(760px 280px at 100% 0%, rgba(232,163,61,.18), transparent 65%),
        radial-gradient(520px 240px at 0% 100%, rgba(134,184,232,.08), transparent 65%),
        var(--surface);
    box-shadow: var(--shadow-panel);
}
.hero__text { flex: 1 1 340px; min-width: 0; }
.hero__title {
    font-size: clamp(26px, 4.2vw, 40px);
    line-height: 1.15; letter-spacing: -.015em;
    overflow-wrap: break-word;
}
.hero__subtitle { margin-top: 10px; color: var(--paper-dim); font-size: 16px; }
.hero__actions { display: flex; gap: 12px; flex-wrap: wrap; flex: none; }
.hero__actions .btn { padding: 13px 22px; font-size: 14.5px; }

/* ---------- Upcoming stay card ---------- */
.upcoming-card {
    display: flex;
    gap: 22px;
    align-items: flex-start;
}
.upcoming-card__img {
    width: 200px; height: 140px;
    border-radius: var(--radius-sm);
    background: var(--surface-raised);
    border: 1px solid var(--line);
    flex-shrink: 0; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
}
.upcoming-card__img img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.upcoming-card__img .icon { width: 30px; height: 30px; color: var(--paper-faint); }
.upcoming-card__body { flex: 1; min-width: 0; }
.upcoming-card__ref {
    font-size: 11px; font-weight: 700; letter-spacing: .06em;
    color: var(--ember); text-transform: uppercase; margin-bottom: 4px;
}
.upcoming-card__room { font-size: 17px; font-weight: 700; margin-bottom: 2px; }
.upcoming-card__type { font-size: 12px; color: var(--paper-dim); margin-bottom: 10px; }
.upcoming-card__dates {
    display: flex; gap: 20px; flex-wrap: wrap;
}
.upcoming-date-block { display: flex; flex-direction: column; gap: 1px; }
.upcoming-date-block__lbl { font-size: 10px; font-weight: 700; color: var(--paper-faint); text-transform: uppercase; letter-spacing: .06em; }
.upcoming-date-block__val { font-size: 14px; font-weight: 600; color: var(--paper); }
.upcoming-card__footer {
    margin-top: 16px; padding-top: 14px;
    border-top: 1px solid var(--line);
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px;
}
.upcoming-card__total {
    font-family: var(--font-display); font-size: 16px; font-weight: 700;
    color: var(--ember-strong);
}
.upcoming-card__total small {
    font-family: var(--font-body); font-size: 11px; color: var(--paper-dim); font-weight: 400;
}
.upcoming-nights {
    font-size: 13px; color: var(--paper-dim);
}

/* ---------- Section headings: title on the left, chip/link on the right ---------- */
.panel__head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px 14px; flex-wrap: wrap;
}

/* ---------- Recent bookings list ---------- */
.recent-panel { margin-top: 16px; }
.dash-bk-list { display: flex; flex-direction: column; gap: 10px; }
.dash-bk-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas: "ref right" "body right";
    gap: 2px 14px; align-items: center;
    padding: 14px 16px;
    background: var(--surface-raised);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    transition: border-color .15s;
}
.dash-bk-item:hover { border-color: var(--ember-strong); }
.dash-bk-ref {
    grid-area: ref;
    font-family: var(--font-display); font-size: 12px; font-weight: 700;
    color: var(--ember); letter-spacing: .04em;
}
.dash-bk-body { grid-area: body; min-width: 0; }
.dash-bk-room { font-weight: 700; font-size: 14px; margin-bottom: 1px; }
.dash-bk-dates { font-size: 12.5px; color: var(--paper-dim); }
.dash-bk-right {
    grid-area: right;
    display: flex; flex-direction: column; align-items: flex-end; gap: 4px;
}
.dash-bk-amount { font-family: var(--font-display); font-size: 15px; font-weight: 700; }
.dash-badge {
    display: inline-flex; align-items: center;
    padding: 2px 8px; border-radius: var(--radius-full);
    font-size: 10px; font-weight: 700; letter-spacing: .03em;
}
.dash-badge--green  { background: rgba(40,160,90,.2);  color: #4fd882; }
.dash-badge--amber  { background: rgba(220,160,40,.2); color: var(--ember-strong); }
.dash-badge--red    { background: rgba(220,80,60,.2);  color: var(--danger); }
.dash-badge--blue   { background: rgba(80,140,220,.2); color: #86b8e8; }
.dash-badge--teal   { background: rgba(30,170,170,.2); color: #5fd8d0; }
.dash-badge--grey   { background: rgba(140,140,150,.2); color: var(--paper-dim); }

/* ---------- Countdown chip ---------- */
.countdown-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    background: rgba(232,163,61,.12);
    border: 1px solid rgba(232,163,61,.3);
    border-radius: var(--radius-full);
    font-size: 13px; font-weight: 600; color: var(--ember-strong);
}
.countdown-chip .icon { width: 13px; height: 13px; }

/* ---------- Responsive ---------- */
@media (max-width: 860px) {
    .hero { padding: 32px 28px; }
}
@media (max-width: 600px) {
    .hero { padding: 26px 20px; gap: 18px; }
    .hero__subtitle { font-size: 14.5px; }
    .hero__actions { width: 100%; }
    .hero__actions .btn { flex: 1 1 0; justify-content: center; }
    .upcoming-card { flex-direction: column; width: 100%; }
    .upcoming-card__img { width: 100%; height: 180px; }
    .upcoming-card__body { width: 100%; }
    .upcoming-card__footer .btn { width: 100%; justify-content: center; }
}
@media (min-width: 700px) {
    .dash-bk-item {
        grid-template-columns: 200px minmax(0, 1fr) auto;
        grid-template-areas: "ref body right";
        gap: 14px 20px;
    }
    .dash-bk-right { flex-direction: row; align-items: center; gap: 16px; }
    .dash-bk-amount { min-width: 96px; text-align: right; }
}
</style>

<main class="page" id="main-content">

    <section class="hero">
        <div class="hero__text">
            <h1 class="hero__title">
                Welcome<?php echo $greetingName ? ', ' . htmlspecialchars($greetingName) : ''; ?>! <span aria-hidden="true"></span>
            </h1>
            <p class="hero__subtitle">Here's what's happening with your bookings</p>
        </div>
        <div class="hero__actions">
            <a href="user-guide.php" class="btn btn--ghost"><?php render_icon('circle-help'); ?> User Guide</a>
            <a href="bookings.php?new=1" class="btn btn--primary"><?php render_icon('calendar-plus'); ?> Book Now</a>
        </div>
    </section>

    <?php bm_fb_render_banner($feedbackPendingStays ?? []); ?>

    <!-- Stat cards -->
    <div class="stat-grid">
        <?php foreach ($statCards as $card): render_stat_card($card); endforeach; ?>
    </div>

    <div class="content-grid">

        <!-- Upcoming stay panel -->
        <section class="panel">
            <div class="panel__head">
                <h2><?php echo $featuredIsCurrent ? 'Your Current Stay' : 'Your Upcoming Stay'; ?></h2>
                <?php if ($featuredStay && $featuredIsCurrent): ?>
                    <span class="countdown-chip">
                        <?php render_icon('circle-check'); ?>
                        Checked in<?php echo !empty($featuredStay['checked_in_at']) ? ' · ' . htmlspecialchars(date('M j, g:i A', strtotime($featuredStay['checked_in_at']))) : ''; ?>
                    </span>
<?php elseif ($featuredStay): ?>
                    <?php
                    $daysUntil = (int) round((strtotime($featuredStay['check_in']) - strtotime($today)) / 86400);
                    ?>
                    <span class="countdown-chip">
                        <?php render_icon('calendar-clock'); ?>
                        <?php if ($daysUntil === 0): ?>
                            Check-in today!
                        <?php elseif ($daysUntil === 1): ?>
                            Tomorrow
                        <?php else: ?>
                            In <?php echo $daysUntil; ?> days
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($featuredStay): ?>
                <?php
                $nights = (int) round(
                    (strtotime($featuredStay['check_out']) - strtotime($featuredStay['check_in'])) / 86400
                );
                $imgBase = '../admin/assets/uploads/accommodations/';
                ?>
                <div class="upcoming-card">
                    <div class="upcoming-card__img">
                        <?php if (!empty($featuredStay['image_filename'])): ?>
                            <img src="<?php echo htmlspecialchars($imgBase . $featuredStay['image_filename']); ?>"
                                 alt="<?php echo htmlspecialchars($featuredStay['room_name']); ?>"
                                 loading="lazy">
                        <?php else: ?>
                            <?php render_icon('bed-double'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="upcoming-card__body">
                        <div class="upcoming-card__ref"><?php echo htmlspecialchars($featuredStay['reference']); ?></div>
                        <div class="upcoming-card__room"><?php echo htmlspecialchars($featuredStay['room_name']); ?></div>
                        <div class="upcoming-card__type"><?php echo htmlspecialchars($featuredStay['room_type']); ?></div>
                        <div class="upcoming-card__dates">
                            <div class="upcoming-date-block">
                                <span class="upcoming-date-block__lbl">Check-in</span>
                                <span class="upcoming-date-block__val">
                                    <?php echo date('M j, Y', strtotime($featuredStay['check_in'])); ?>
                                </span>
                            </div>
                            <div class="upcoming-date-block">
                                <span class="upcoming-date-block__lbl">Check-out</span>
                                <span class="upcoming-date-block__val">
                                    <?php echo date('M j, Y', strtotime($featuredStay['check_out'])); ?>
                                </span>
                            </div>
                            <div class="upcoming-date-block">
                                <span class="upcoming-date-block__lbl">Guests</span>
                                <span class="upcoming-date-block__val">
                                    <?php echo (int) $featuredStay['guests_count']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="upcoming-card__footer">
                            <div>
                                <div class="upcoming-card__total">
                                    ₱<?php echo number_format((float) $featuredStay['total_amount'], 2); ?>
                                    <small>estimated total</small>
                                </div>
                                <div class="upcoming-nights">
                                    <?php echo $nights; ?> night<?php echo $nights === 1 ? '' : 's'; ?> ·
                                    paid on check-in / check-out
                                </div>
                            </div>
                            <a href="bookings.php" class="btn btn--ghost" style="font-size:13px">
                                <?php render_icon('calendar-check'); ?> View All Bookings
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php render_empty_state(
                    'bed-double',
                    'No upcoming stay yet',
                    'Once you book a stay with us, the details will show up here.',
                    'Book Now',
                    'bookings.php?new=1'
                ); ?>
            <?php endif; ?>
        </section>

        <!-- Quick actions -->
        <aside class="panel quick-actions">
            <h2>Quick Actions</h2>
            <a href="bookings.php?new=1" class="quick-action">
                <span class="quick-action__icon"><?php render_icon('calendar-plus'); ?></span>
                Make New Booking
            </a>
            <button type="button" class="quick-action" id="contactSupportBtn">
                <span class="quick-action__icon"><?php render_icon('life-buoy'); ?></span>
                Contact Support
            </button>
            <a href="profile.php" class="quick-action">
                <span class="quick-action__icon"><?php render_icon('pencil'); ?></span>
                Edit Profile
            </a>
        </aside>

    </div><!-- .content-grid -->

    <!-- Recent Bookings -->

</main>

<?php require __DIR__ . '/includes/support-modal.php'; ?>
<?php require __DIR__ . '/includes/foot.php'; ?>