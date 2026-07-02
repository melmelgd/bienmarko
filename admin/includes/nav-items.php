<?php
/**
 * nav-items.php
 * -----------------------------------------------------------------------
 * The ONLY list of sidebar links in the whole project. sidebar.php loops
 * over this array to build the menu; no page file ever hand-writes a
 * sidebar link. Add or rename a menu item here and every page picks it
 * up automatically the next time sidebar.php is included.
 *
 * 'id' must match the $active_page value each page sets, and is also
 * used as a fallback match against the current filename so the correct
 * link still highlights even if a page forgets to set $active_page.
 * -----------------------------------------------------------------------
 */

return [
    ['id' => 'dashboard',      'label' => 'Dashboard',      'href' => 'dashboard.php',      'icon' => 'grid'],
    ['id' => 'bookings',       'label' => 'Bookings',       'href' => 'bookings.php',       'icon' => 'calendar'],
    ['id' => 'payments',       'label' => 'Payments',       'href' => 'payments.php',       'icon' => 'receipt'],
    ['id' => 'accommodations', 'label' => 'Accommodations', 'href' => 'accommodations.php', 'icon' => 'bed'],
    ['id' => 'inventory',      'label' => 'Inventory',      'href' => 'inventory.php',      'icon' => 'clipboard'],
    ['id' => 'expenses',       'label' => 'Expenses',       'href' => 'expenses.php',       'icon' => 'trend-down'],
    ['id' => 'report',         'label' => 'Report',         'href' => 'report.php',         'icon' => 'bar-chart'],
    ['id' => 'feedback',       'label' => 'Feedback',       'href' => 'feedback.php',       'icon' => 'message'],
    ['id' => 'settings',       'label' => 'Settings',       'href' => 'settings.php',       'icon' => 'settings'],
    ['id' => 'help',           'label' => 'Help',           'href' => 'help.php',           'icon' => 'help'],
    ['id' => 'backup',         'label' => 'Backup',         'href' => 'backup.php',         'icon' => 'database'],
];
