/**
 * script.js
 * -----------------------------------------------------------------------
 * Vanilla JS, no build step, no framework. Only handles UI chrome that
 * genuinely needs a click handler:
 *   1. Desktop sidebar collapse/expand (persisted in localStorage so it
 *      survives full page reloads between dashboard.php, bookings.php, etc.)
 *   2. Mobile sidebar slide-over open/close
 *   3. Week / Month / Year pill active-state switching
 *   4. Notification bell dropdown (open/close, click-to-view, mark read,
 *      delete, clear all) — the markup itself is server-rendered by
 *      includes/topbar.php on every page; this just wires it up once.
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

  /* ---- Notification bell (rendered by includes/topbar.php on every
     admin page). Handles: open/close, clicking a notification (marks
     it read, then follows its link to Bookings/Payments if it has
     one), deleting a single notification, "Mark all read", and
     "Clear all". ---- */
  var notifWrap = document.querySelector('.notif-wrap');
  if (notifWrap) {
    var notifBtn      = document.getElementById('notifBellBtn');
    var notifDropdown = document.getElementById('notifDropdown');
    var notifList     = document.getElementById('notifList');
    var notifCsrf      = notifWrap.getAttribute('data-csrf-token') || '';

    if (notifBtn && notifDropdown && notifList) {
      var notifDot = notifBtn.querySelector('.notif-dot');

      var notifPost = function (action, extra) {
        var payload = { action: action, csrf_token: notifCsrf };
        if (extra) {
          for (var key in extra) {
            if (Object.prototype.hasOwnProperty.call(extra, key)) {
              payload[key] = extra[key];
            }
          }
        }
        return fetch('api/notifications.php', {
          method: 'POST',
          keepalive: true,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
      };

      var notifUpdateBadge = function () {
        var count = notifList.querySelectorAll('.notif-item.is-unread').length;
        notifBtn.setAttribute('aria-label', count + ' unread notification' + (count === 1 ? '' : 's'));
        if (notifDot) {
          if (count === 0) {
            notifDot.style.display = 'none';
          } else {
            notifDot.style.display = '';
            notifDot.textContent = count > 99 ? '99+' : String(count);
          }
        }
      };

      var notifShowEmptyState = function () {
        notifList.innerHTML =
          '<div class="empty-state" id="notifEmptyState">' +
          '<p class="empty-state-title">No notifications yet</p>' +
          '<p class="empty-state-message">New booking and payment activity will show up here.</p>' +
          '</div>';
        var markAllBtn  = document.getElementById('notifMarkAllBtn');
        var clearAllBtn = document.getElementById('notifClearAllBtn');
        if (markAllBtn) markAllBtn.remove();
        if (clearAllBtn) clearAllBtn.remove();
      };

      var notifOpen = function () {
        notifDropdown.hidden = false;
        notifBtn.setAttribute('aria-expanded', 'true');
      };
      var notifClose = function () {
        notifDropdown.hidden = true;
        notifBtn.setAttribute('aria-expanded', 'false');
      };

      notifBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (notifDropdown.hidden) {
          notifOpen();
        } else {
          notifClose();
        }
      });

      document.addEventListener('click', notifClose);
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') notifClose();
      });

      /* Everything inside the dropdown is handled here via delegation,
         and stops the click from bubbling to the document-level
         "close on outside click" handler above. */
      notifDropdown.addEventListener('click', function (e) {
        e.stopPropagation();

        var deleteBtn = e.target.closest('.notif-item-delete');
        if (deleteBtn) {
          e.preventDefault();
          var item = deleteBtn.closest('.notif-item');
          var id = deleteBtn.getAttribute('data-id');
          notifPost('delete', { id: id }).then(function () {
            if (item) item.remove();
            notifUpdateBadge();
            if (!notifList.querySelector('.notif-item')) notifShowEmptyState();
          });
          return;
        }

        if (e.target.closest('#notifMarkAllBtn')) {
          var markAllBtn = e.target.closest('#notifMarkAllBtn');
          notifPost('mark_all_read').then(function () {
            notifList.querySelectorAll('.notif-item.is-unread').forEach(function (el) {
              el.classList.remove('is-unread');
            });
            notifUpdateBadge();
            markAllBtn.remove();
          });
          return;
        }

        if (e.target.closest('#notifClearAllBtn')) {
          notifPost('delete_all').then(function () {
            notifShowEmptyState();
            notifUpdateBadge();
          });
          return;
        }

        /* Clicking a notification itself: mark it read (fire-and-forget
           so a normal <a href> navigation to Bookings/Payments isn't
           blocked or delayed). Items with no related booking left are
           plain <div>s with no href, so this just marks them read
           in place. */
        var item = e.target.closest('.notif-item');
        if (item && item.classList.contains('is-unread')) {
          item.classList.remove('is-unread');
          notifUpdateBadge();
          notifPost('mark_read', { id: item.getAttribute('data-id') });
        }
      });
    }
  }
})();
