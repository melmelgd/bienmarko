/**
 * script.js
 * -----------------------------------------------------------------------
 * Vanilla JS, no build step, no framework. Only handles UI chrome that
 * genuinely needs a click handler:
 *   1. Desktop sidebar collapse/expand (persisted in localStorage so it
 *      survives full page reloads between dashboard.php, bookings.php, etc.)
 *   2. Mobile sidebar slide-over open/close
 *   3. Week / Month / Year pill active-state switching
 * Nothing here touches page content — this file only knows about the
 * sidebar/topbar chrome, same separation as the PHP includes.
 * -----------------------------------------------------------------------
 */
(function () {
  'use strict';

  var COLLAPSE_KEY = 'admin-sidebar-collapsed';
  var sidebar = document.querySelector('.sidebar');
  var body = document.body;

  /* ---- Desktop collapse / expand ---- */
  var collapseBtn = document.querySelector('.sidebar-collapse-btn');
  if (collapseBtn && sidebar) {
    collapseBtn.addEventListener('click', function () {
      var collapsed = sidebar.classList.toggle('is-collapsed');
      try {
        localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
      } catch (e) {
        /* localStorage unavailable (private mode, etc.) — fine, just won't persist */
      }
    });
  }

  /* ---- Mobile slide-over open / close ---- */
  var mobileToggle = document.querySelector('.topbar-mobile-toggle');
  var closeBtn = document.querySelector('.sidebar-close-btn');
  var overlay = document.querySelector('.sidebar-overlay');

  function openMobileSidebar() {
    if (sidebar) sidebar.classList.add('is-mobile-open');
    body.classList.add('sidebar-open');
  }

  function closeMobileSidebar() {
    if (sidebar) sidebar.classList.remove('is-mobile-open');
    body.classList.remove('sidebar-open');
  }

  if (mobileToggle) mobileToggle.addEventListener('click', openMobileSidebar);
  if (closeBtn) closeBtn.addEventListener('click', closeMobileSidebar);
  if (overlay) overlay.addEventListener('click', closeMobileSidebar);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMobileSidebar();
  });

  /* ---- Period pill (Week / Month / Year) — visual only, no data yet ---- */
  var periodButtons = document.querySelectorAll('.period-toggle button');
  periodButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      periodButtons.forEach(function (b) {
        b.classList.remove('is-active');
      });
      btn.classList.add('is-active');
    });
  });
})();
