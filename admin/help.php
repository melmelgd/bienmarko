<?php
$active_page = 'help';
$page_title = 'Help Center';
$breadcrumb = 'Help';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';

$faqs = [
    'How do I add a new booking?',
    'How do I record a payment?',
    'How do I add a new room or accommodation type?',
    'How do I back up my data?',
];
?>

<div class="stack">
  <div class="panel panel-pad">
    <h2 class="panel-title" style="margin-bottom:16px;">Frequently Asked Questions</h2>
    <div class="faq-list">
      <?php foreach ($faqs as $q): ?>
        <a href="#" class="faq-item">
          <span><?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php echo icon('chevron-right', 'icon-sm'); ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="panel panel-pad contact-card">
    <h3>Still need help?</h3>
    <p>Reach out to support and we'll get back to you.</p>
    <button type="button" class="btn btn-primary">Contact Support</button>
  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
