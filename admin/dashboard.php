<?php
/**
 * dashboard.php
 * -----------------------------------------------------------------------
 * This page owns ZERO sidebar markup. It sets a few variables, includes
 * the three shared partials, and writes only its own content between
 * topbar.php and foot.php.
 * -----------------------------------------------------------------------
 */
$active_page = 'dashboard';
$page_title = 'Dashboard Overview';
$breadcrumb = 'Dashboard';
$page_action_label = 'New Booking';
$show_period_toggle = true;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
?>

<div class="stack">

  <div class="stat-grid stat-grid--5">
    <div class="stat-card">
      <div class="stat-card-top">
        <span class="stat-label">Upcoming Stays</span>
        <span class="stat-icon stat-icon--emerald"><?php echo icon('calendar-check'); ?></span>
      </div>
      <span class="stat-value">0</span>
    </div>
    <div class="stat-card">
      <div class="stat-card-top">
        <span class="stat-label">Active Bookings</span>
        <span class="stat-icon stat-icon--blue"><?php echo icon('bed'); ?></span>
      </div>
      <span class="stat-value">0</span>
    </div>
    <div class="stat-card">
      <div class="stat-card-top">
        <span class="stat-label">Pending Payments</span>
        <span class="stat-icon stat-icon--amber"><?php echo icon('wallet'); ?></span>
      </div>
      <span class="stat-value">0</span>
    </div>
    <div class="stat-card">
      <div class="stat-card-top">
        <span class="stat-label">Nights Booked</span>
        <span class="stat-icon stat-icon--red"><?php echo icon('moon'); ?></span>
      </div>
      <span class="stat-value">0</span>
    </div>
    <div class="stat-card">
      <div class="stat-card-top">
        <span class="stat-label">Occupancy Rate</span>
        <span class="stat-icon stat-icon--fuchsia"><?php echo icon('pie-chart'); ?></span>
      </div>
      <span class="stat-value">0%</span>
    </div>
  </div>

  <div class="dashboard-grid">

    <!-- Booking Trends -->
    <div class="panel panel-pad">
      <h2 class="panel-title" style="margin-bottom:20px;">Booking Trends</h2>

      <div class="panel-header" style="margin-bottom:24px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <?php echo icon('bar-chart'); ?>
          <span style="font-weight:600; font-size:0.9rem;">Bookings Trend</span>
        </div>
        <span class="pill">This Week</span>
      </div>

      <div class="chart-frame">
        <div class="chart-gridlines">
          <?php foreach ([100, 80, 60, 40, 20] as $mark): ?>
            <div class="chart-gridline">
              <span class="chart-gridline-label"><?php echo $mark; ?></span>
              <span class="chart-gridline-rule"></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="chart-placeholder">
          <div class="chart-placeholder-box">
            <?php echo icon('inbox', 'icon-lg'); ?>
            <span>No booking data yet</span>
          </div>
        </div>
      </div>

      <div class="chart-days">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day): ?>
          <span><?php echo $day; ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Revenue Breakdown -->
    <div class="panel panel-pad">
      <div class="panel-header" style="margin-bottom:0;">
        <h2 class="panel-title">Revenue Breakdown</h2>
        <span class="pill">This Week</span>
      </div>
      <p class="revenue-figure">₱0.00</p>

      <div class="donut-placeholder">
        <span>No revenue data yet</span>
      </div>

      <p class="legend-title">Revenue by Room Type</p>
      <div class="legend-list">
        <?php foreach (['Room Type 1', 'Room Type 2', 'Room Type 3'] as $room): ?>
          <div class="legend-row">
            <span class="legend-label"><span class="legend-dot"></span><?php echo htmlspecialchars($room, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="legend-value">0%</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
