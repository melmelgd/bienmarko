<?php
/**
 * admin/help.php
 * -----------------------------------------------------------------------
 * Help & Support: a searchable FAQ accordion covering the admin panel's
 * actual features, a Contact Support card wired to the property's
 * configured contact details (see admin/settings.php), and a small
 * live System Information panel for troubleshooting.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$pdo = bienmarko_db();

/* Same guard as admin/settings.php, so this page also works standalone
   even if Settings has never been opened yet. */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `system_settings` (
        `id`                     TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
        `property_name`          VARCHAR(150) NOT NULL DEFAULT '',
        `contact_email`          VARCHAR(190) NOT NULL DEFAULT '',
        `contact_number`         VARCHAR(20)  NOT NULL DEFAULT '',
        `address`                VARCHAR(255) NOT NULL DEFAULT '',
        `notify_new_booking`     TINYINT(1) NOT NULL DEFAULT 1,
        `notify_payment`         TINYINT(1) NOT NULL DEFAULT 1,
        `notify_low_inventory`   TINYINT(1) NOT NULL DEFAULT 0,
        `notify_guest_feedback`  TINYINT(1) NOT NULL DEFAULT 0,
        `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
$pdo->exec('INSERT IGNORE INTO `system_settings` (`id`) VALUES (1)');

$settings = $pdo->query('SELECT property_name, contact_email, contact_number FROM system_settings WHERE id = 1')->fetch();
if (!$settings) {
    $settings = ['property_name' => '', 'contact_email' => '', 'contact_number' => ''];
}

$supportEmail = $settings['contact_email'] !== '' ? $settings['contact_email'] : 'support@bienmarko.test';
$supportPhone = $settings['contact_number'];

$mysqlVersion = 'Unknown';
try {
    $mysqlVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (\Throwable $e) {
    // Leave as 'Unknown' — informational panel only, never worth failing the page over.
}

$faqs = [
    [
        'category' => 'Bookings',
        'q' => 'How do I create a walk-in booking for a guest without an account?',
        'a' => 'Go to Bookings → Add Booking. Walk-in bookings don\'t require a guest account — enter the guest\'s name and contact details directly on the form. The system checks the selected room and dates for conflicts automatically before saving.',
    ],
    [
        'category' => 'Bookings',
        'q' => 'A guest says they can\'t modify their own reservation. Why?',
        'a' => 'Registered guests can only modify or cancel a booking up to one (1) week before check-in. Requests made closer to the date need to be handled directly from the Bookings page here in the admin panel.',
    ],
    [
        'category' => 'Payments',
        'q' => 'How do I record a cash payment?',
        'a' => 'Open the booking from the Bookings or Payments page and use the Record Payment action. Enter the amount and method (Cash or GCash) — the system computes the running balance automatically from the room rate, extra guests, and any charges or discounts you\'ve added.',
    ],
    [
        'category' => 'Inventory',
        'q' => 'How do low-stock items get flagged?',
        'a' => 'Every inventory item has a minimum stock level you set when adding it. Once its on-hand quantity drops to or below that minimum, it appears in the Inventory page\'s low-stock filter and (if enabled) in your notification feed — see Settings → Notifications.',
    ],
    [
        'category' => 'Feedback',
        'q' => 'Why isn\'t a guest\'s feedback showing on the public site yet?',
        'a' => 'All feedback is moderated. New submissions sit in the Feedback page awaiting review — approve the ones you want published, or reject/hide anything inappropriate. Nothing goes live automatically.',
    ],
    [
        'category' => 'Reports',
        'q' => 'Where do I generate financial or occupancy reports?',
        'a' => 'The Reports page covers financial summaries, occupancy and booking trends, guest insights, and inventory statistics, with export options for the ones you need to share or print.',
    ],
    [
        'category' => 'Data & Backup',
        'q' => 'How do I back up the database?',
        'a' => 'Go to Backup & Restore and click Create Backup. It generates a full snapshot of every table — bookings, guests, payments, inventory, and feedback included — and adds it to your backup history, where you can download or restore from it any time.',
    ],
    [
        'category' => 'Data & Backup',
        'q' => 'What happens when I restore a backup?',
        'a' => 'Restoring replaces the entire current database with the contents of that backup — this cannot be undone, so it\'s worth taking a fresh backup first if you\'re unsure. You\'ll be asked to type a confirmation phrase before it runs, as a safeguard against restoring by accident.',
    ],
    [
        'category' => 'Account',
        'q' => 'How do I change my admin password?',
        'a' => 'Go to Settings → Admin Account. Enter your current password along with the new one, and confirm it. Your name and email can be updated from the same panel.',
    ],
    [
        'category' => 'Account',
        'q' => 'How do I update the property\'s contact details shown to guests?',
        'a' => 'Go to Settings → General Settings to update the property name, contact email, contact number, and address used across the system.',
    ],
];

$active_page = 'help';
$page_title = 'Help & Support';
$breadcrumb = 'Help';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<style>
/* ============================================================
   Help & Support — page-scoped styles.
   ============================================================ */
.help-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start; }
@media (max-width: 1000px) { .help-layout { grid-template-columns: 1fr; } }

.help-search { position: relative; margin-bottom: 18px; }
.help-search input {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border);
    border-radius: var(--r-md); padding: 11px 14px 11px 38px; color: var(--text); font-size: 0.875rem;
}
.help-search input::placeholder { color: var(--text-muted); }
.help-search input:focus { outline: none; border-color: var(--gold-500); }
.help-search .icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }

.faq-category-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin: 18px 0 8px; }
.faq-category-label:first-child { margin-top: 0; }

.faq-entry { border: 1px solid var(--border); border-radius: var(--r-md); background: var(--bg-sidebar); overflow: hidden; }
.faq-entry .faq-item { width: 100%; border: none; border-radius: 0; background: transparent; cursor: pointer; font: inherit; text-align: left; }
.faq-entry .faq-item:hover { border-color: transparent; background: rgba(255,255,255,.03); }
.faq-chevron { transition: transform .2s ease; flex-shrink: 0; }
.faq-item[aria-expanded="true"] .faq-chevron { transform: rotate(90deg); }
.faq-answer { max-height: 0; overflow: hidden; transition: max-height .25s ease; padding: 0 16px; color: var(--text-tertiary); font-size: 0.85rem; line-height: 1.65; }
.faq-answer.is-open { padding: 0 16px 16px; }
.faq-empty { color: var(--text-muted); font-size: 0.875rem; padding: 24px 4px; text-align: center; }

.support-actions { display: flex; flex-direction: column; gap: 10px; }
.support-actions .btn { width: 100%; justify-content: center; }

.sysinfo-list { display: flex; flex-direction: column; gap: 10px; font-size: 0.825rem; }
.sysinfo-row { display: flex; justify-content: space-between; gap: 12px; color: var(--text-tertiary); }
.sysinfo-row strong { color: var(--text-secondary); font-weight: 600; }
</style>

<div class="help-layout">

  <!-- ===== FAQ ===== -->
  <div class="panel panel-pad">
    <h2 class="panel-title" style="margin-bottom:16px;">Frequently Asked Questions</h2>

    <div class="help-search">
      <?php echo icon('database', 'icon icon-sm'); ?>
      <input type="text" id="faqSearch" placeholder="Search help topics…" autocomplete="off">
    </div>

    <div class="faq-list" id="faqList">
      <?php
        $currentCategory = null;
        foreach ($faqs as $i => $faq):
            if ($faq['category'] !== $currentCategory):
                $currentCategory = $faq['category'];
      ?>
        <div class="faq-category-label" data-faq-category-label><?php echo htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
        <div class="faq-entry" data-faq-entry data-faq-search="<?php echo htmlspecialchars(mb_strtolower($faq['q'] . ' ' . $faq['a'] . ' ' . $faq['category']), ENT_QUOTES, 'UTF-8'); ?>">
          <button type="button" class="faq-item" aria-expanded="false" aria-controls="faq-answer-<?php echo $i; ?>">
            <span><?php echo htmlspecialchars($faq['q'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php echo icon('chevron-right', 'icon icon-sm faq-chevron'); ?>
          </button>
          <div class="faq-answer" id="faq-answer-<?php echo $i; ?>">
            <p><?php echo htmlspecialchars($faq['a'], ENT_QUOTES, 'UTF-8'); ?></p>
          </div>
        </div>
      <?php endforeach; ?>
      <p class="faq-empty" id="faqEmpty" style="display:none;">No help topics match your search.</p>
    </div>
  </div>

  <!-- ===== Sidebar ===== -->
  <div class="stack" style="gap:24px;">

    <div class="panel panel-pad contact-card">
      <h3>Contact Support</h3>
      <p><?php echo htmlspecialchars($settings['property_name'] !== '' ? $settings['property_name'] : "Bien Marko's Pension House", ENT_QUOTES, 'UTF-8'); ?> admin support</p>
      <div class="support-actions">
        <a class="btn btn-primary" href="mailto:<?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>?subject=<?php echo rawurlencode('Admin Panel Support Request'); ?>">
          <?php echo icon('mail', 'icon-sm'); ?> Email <?php echo htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php if ($supportPhone !== ''): ?>
        <a class="btn btn-secondary" href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $supportPhone), ENT_QUOTES, 'UTF-8'); ?>">
          <?php echo icon('phone', 'icon-sm'); ?> Call <?php echo htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php endif; ?>
      </div>
      <?php if ($settings['contact_email'] === ''): ?>
        <p style="margin-top:12px; font-size:0.78rem;">No contact email set yet — add one in <a href="settings.php" style="color:var(--gold-500);">Settings</a> so support requests reach the right inbox.</p>
      <?php endif; ?>
    </div>

    <div class="panel panel-pad">
      <h3 style="margin-bottom:14px; font-size:1rem;">Quick Links</h3>
      <div class="stack" style="gap:8px;">
        <a class="faq-item" href="settings.php"><span><?php echo icon('settings', 'icon-sm'); ?> Settings</span> <?php echo icon('chevron-right', 'icon icon-sm'); ?></a>
        <a class="faq-item" href="backup.php"><span><?php echo icon('database', 'icon-sm'); ?> Backup &amp; Restore</span> <?php echo icon('chevron-right', 'icon icon-sm'); ?></a>
      </div>
    </div>

    <div class="panel panel-pad">
      <h3 style="margin-bottom:14px; font-size:1rem;">System Information</h3>
      <div class="sysinfo-list">
        <div class="sysinfo-row"><span>PHP Version</span><strong><?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="sysinfo-row"><span>MySQL Version</span><strong><?php echo htmlspecialchars($mysqlVersion, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="sysinfo-row"><span>Server Time</span><strong><?php echo htmlspecialchars(date('M j, Y g:i A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
      </div>
    </div>

  </div>
</div>

<script>
/* ---------- FAQ accordion ---------- */
document.querySelectorAll('.faq-entry .faq-item').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        var answer = document.getElementById(btn.getAttribute('aria-controls'));

        if (expanded) {
            answer.style.maxHeight = null;
            answer.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        } else {
            btn.setAttribute('aria-expanded', 'true');
            answer.classList.add('is-open');
            answer.style.maxHeight = answer.scrollHeight + 'px';
        }
    });
});

/* ---------- FAQ search filter ---------- */
document.getElementById('faqSearch').addEventListener('input', function () {
    var term = this.value.trim().toLowerCase();
    var visibleCount = 0;

    document.querySelectorAll('[data-faq-entry]').forEach(function (entry) {
        var matches = term === '' || entry.getAttribute('data-faq-search').indexOf(term) !== -1;
        entry.style.display = matches ? '' : 'none';
        if (matches) visibleCount++;
    });

    // Hide/show category labels based on whether any entry under them is visible.
    document.querySelectorAll('[data-faq-category-label]').forEach(function (label) {
        var sibling = label.nextElementSibling;
        var hasVisible = false;
        while (sibling && !sibling.hasAttribute('data-faq-category-label')) {
            if (sibling.style.display !== 'none') hasVisible = true;
            sibling = sibling.nextElementSibling;
        }
        label.style.display = hasVisible ? '' : 'none';
    });

    document.getElementById('faqEmpty').style.display = visibleCount === 0 ? '' : 'none';
});
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>