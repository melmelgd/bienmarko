<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'dashboard';
$pageTitle   = 'Dashboard';

$upcomingStay    = null; // e.g. ['date' => 'May 14, 2026', 'room' => 'Barkada Room', 'nights' => 2]
$activeBookings  = [];   // e.g. [['room' => 'Barkada Room', 'dates' => 'May 14-16, 2026']]
$pendingPayments = [];   // e.g. [['room' => 'Barkada Room', 'amount' => 4200]]

$activeBookingsCount  = count($activeBookings);
$pendingPaymentsCount = count($pendingPayments);
$pendingPaymentsTotal = array_sum(array_column($pendingPayments, 'amount'));

$statCards = [
    [
        'variant' => 'olive',
        'icon'    => 'bed-double',
        'label'   => 'Upcoming Stay',
        'value'   => $upcomingStay ? $upcomingStay['date'] : 'No upcoming stay',
        'meta'    => $upcomingStay
            ? sprintf('%s · %d night%s', $upcomingStay['room'], $upcomingStay['nights'], $upcomingStay['nights'] === 1 ? '' : 's')
            : 'Book your next visit',
    ],
    [
        'variant' => 'navy',
        'icon'    => 'circle-check',
        'label'   => 'Active Booking',
        'value'   => (string) $activeBookingsCount,
        'meta'    => $activeBookingsCount > 0 ? $activeBookings[0]['dates'] : 'No active bookings',
    ],
    [
        'variant' => 'purple',
        'icon'    => 'wallet',
        'label'   => 'Pending Payment',
        'value'   => '₱' . number_format($pendingPaymentsTotal),
        'meta'    => $pendingPaymentsCount > 0
            ? sprintf('%d payment%s due', $pendingPaymentsCount, $pendingPaymentsCount === 1 ? '' : 's')
            : 'No pending payments',
    ],
];

$greetingName = !empty($currentUser['name']) ? $currentUser['name'] : null;

require __DIR__ . '/includes/head.php';
?>
<main class="page" id="main-content">

    <div class="page-head">
        <div class="page-head__text">
            <h1 class="page-head__title">Welcome<?php echo $greetingName ? ', ' . htmlspecialchars($greetingName) : ''; ?>! <span aria-hidden="true"></span></h1>
            <p class="page-head__subtitle">Here's what's happening with your bookings</p>
        </div>
        <div class="page-head__actions">
            <a href="user-guide.php" class="btn btn--ghost"><?php render_icon('circle-help'); ?> User Guide</a>
            <a href="bookings.php" class="btn btn--primary"><?php render_icon('calendar-plus'); ?> Book Now</a>
        </div>
    </div>

    <div class="stat-grid">
        <?php foreach ($statCards as $card): render_stat_card($card); endforeach; ?>
    </div>

    <div class="content-grid">
        <section class="panel">
            <div class="panel__head">
                <h2>Your Upcoming Stay</h2>
            </div>
            <?php
            // TODO: once $upcomingStay is populated, render the room photo,
            // reference number, and dates here instead of the empty state.
            render_empty_state(
                'bed-double',
                'No upcoming stay yet',
                'Once you book a stay with us, the details will show up here.',
                'Book Now',
                'bookings.php'
            );
            ?>
        </section>

        <aside class="panel quick-actions">
            <h2>Quick Actions</h2>
            <a href="bookings.php" class="quick-action">
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
    </div>

</main>

<?php require __DIR__ . '/includes/support-modal.php'; ?>
<?php require __DIR__ . '/includes/foot.php'; ?>
