<?php
/**
 * Single source of truth for the bottom navigation.
 *
 * Add, remove, reorder, or relabel a tab here and every page picks up the
 * change automatically — no page file ever hard-codes this list itself.
 */

$navItems = [
    [
        'key'   => 'dashboard',
        'label' => 'Dashboard',
        'href'  => 'dashboard.php',
        'icon'  => 'layout-dashboard',
    ],
    [
        'key'   => 'bookings',
        'label' => 'Bookings',
        'href'  => 'bookings.php',
        'icon'  => 'calendar-check',
    ],
    [
        'key'   => 'receipts',
        'label' => 'Receipts',
        'href'  => 'receipts.php',
        'icon'  => 'file-text',
    ],
    [
        'key'   => 'feedback',
        'label' => 'Feedback',
        'href'  => 'feedback.php',
        'icon'  => 'message-square',
    ],
    [
        'key'   => 'guide',
        'label' => 'Guide',
        'href'  => 'user-guide.php',
        'icon'  => 'circle-help',
    ],
];
