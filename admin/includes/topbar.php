<?php
/**
 * topbar.php
 * -----------------------------------------------------------------------
 * Renders the page header bar (title, breadcrumb, period pills, bell,
 * action button) and opens .app-main + <main class="content">. It knows
 * nothing about sidebar markup — it sits NEXT TO the sidebar, never
 * inside it — and nothing about what a page's body contains.
 *
 * Reads (all optional, with sane defaults):
 *   $page_title          - also used by head.php for the <title> tag
 *   $breadcrumb           - defaults to $page_title
 *   $page_action_label    - omit/leave null to hide the gold action button
 *   $show_period_toggle    - bool, Week/Month/Year pills (Dashboard only)
 *
 * NOTE: this file intentionally leaves <main class="content"> open.
 * The including page's own markup follows directly after, and
 * includes/foot.php closes both <main> and the wrappers opened here.
 * -----------------------------------------------------------------------
 */
$page_title = $page_title ?? 'Dashboard';
$breadcrumb = $breadcrumb ?? $page_title;
$page_action_label = $page_action_label ?? null;
$show_period_toggle = $show_period_toggle ?? false;
require_once __DIR__ . '/icons.php';
?>
<div class="app-main">
  <header class="topbar">
    <div class="topbar-inner">
      <div class="topbar-left">
        <button type="button" class="topbar-mobile-toggle" aria-label="Open menu">
          <?php echo icon('menu', 'icon-lg'); ?>
        </button>
        <div class="topbar-titles">
          <h1 class="page-title"><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
          <p class="breadcrumb">
            <a href="dashboard.php">Home</a>
            <span class="sep">/</span>
            <span class="current"><?php echo htmlspecialchars($breadcrumb, ENT_QUOTES, 'UTF-8'); ?></span>
          </p>
        </div>
      </div>

      <div class="topbar-right">
        <?php if ($show_period_toggle): ?>
          <div class="period-toggle">
            <button type="button" class="is-active">Week</button>
            <button type="button">Month</button>
            <button type="button">Year</button>
          </div>
        <?php endif; ?>

        <button type="button" class="icon-btn" aria-label="1 unread notification">
          <?php echo icon('bell'); ?>
          <span class="notif-dot" aria-hidden="true">1</span>
        </button>

        <?php if ($page_action_label): ?>
          <button type="button" class="btn btn-primary btn-action">
            <?php echo icon('plus', 'icon-sm'); ?>
            <span class="btn-label"><?php echo htmlspecialchars($page_action_label, ENT_QUOTES, 'UTF-8'); ?></span>
          </button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="content">
