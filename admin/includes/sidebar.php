<?php
/**
 * sidebar.php
 * -----------------------------------------------------------------------
 * THE SIDEBAR — separated from every page on purpose.
 *
 * This is the only file in the whole project that renders sidebar markup.
 * dashboard.php, bookings.php, payments.php, and every other page include
 * this exact file unchanged. It has no idea what a "booking" or a
 * "payment" is — it only knows the list of links from nav-items.php and
 * which one is currently active. Page content NEVER lives in this file,
 * and this file is NEVER copy-pasted into a page.
 *
 * Reads (optional — both have safe fallbacks so a page can never break it):
 *   $active_page  - id of the current nav item, e.g. 'bookings'.
 *                   If a page forgets to set this, the link is still
 *                   highlighted correctly by matching the current
 *                   filename against nav-items.php's 'href' values.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/icons.php';
$nav_items = require __DIR__ . '/nav-items.php';
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<aside class="sidebar">
  <script>
    /* Restore collapsed state before paint, so there's no flash of an
       expanded sidebar on every new page load. */
    (function () {
      try {
        if (localStorage.getItem('admin-sidebar-collapsed') === '1') {
          document.currentScript.parentElement.classList.add('is-collapsed');
        }
      } catch (e) { /* localStorage unavailable — just skip restoring */ }
    })();
  </script>

  <div class="sidebar-header">
    <div class="sidebar-brand">
      <span class="sidebar-logo"><?php echo icon('palm'); ?></span>
      <span class="sidebar-brand-name">Admin</span>
    </div>
    <button type="button" class="sidebar-collapse-btn" aria-label="Collapse sidebar">
      <?php echo icon('menu'); ?>
    </button>
    <button type="button" class="sidebar-close-btn" aria-label="Close menu">
      <?php echo icon('close'); ?>
    </button>
  </div>

  <nav class="sidebar-nav" aria-label="Main navigation">
    <?php foreach ($nav_items as $item):
        $is_active = isset($active_page)
            ? ($active_page === $item['id'])
            : ($current_script === $item['href']);
    ?>
      <a
        href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
        class="nav-link<?php echo $is_active ? ' is-active' : ''; ?>"
        <?php echo $is_active ? 'aria-current="page"' : ''; ?>
      >
        <?php echo icon($item['icon']); ?>
        <span class="nav-link-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="logout.php" class="nav-link-logout">
      <?php echo icon('logout'); ?>
      <span>Log Out</span>
    </a>
  </div>
</aside>
<div class="sidebar-overlay"></div>
