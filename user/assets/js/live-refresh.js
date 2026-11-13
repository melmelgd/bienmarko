/**
 * live-refresh.js
 * -----------------------------------------------------------------------
 * Keeps a dashboard page live without the visitor pressing refresh.
 * Same file is used by the admin and the user portal (per-portal copy,
 * matching the project's convention); behaviour is configured by the
 * data-* attributes on its <script> tag (written by includes/foot.php):
 *
 *   data-live-endpoint  api/live.php
 *   data-live-mode      "admin" | "user"   (which bell markup to update)
 *   data-live-page      file name of the current page
 *   data-live-base      fingerprint of the data the page was rendered with
 *   data-live-notif     fingerprint of the bell contents at render time
 *
 * What it does
 *   1. Polls the endpoint every few seconds (only while the tab is visible;
 *      backs off after errors; sleeps after 20 min with no interaction).
 *   2. Notification bell: re-rendered IN PLACE when it changes (no reload).
 *   3. Page data: when the fingerprint changes, the page is reloaded —
 *      but only when it is safe: no modal/dialog/bell open, nothing
 *      typed-but-unsaved, no text selected, no recent click/keypress, and
 *      the page isn't holding a half-finished flow (window.BM_LIVE_HOLD).
 *      Otherwise a small "New updates available · Refresh" pill appears
 *      and the refresh happens as soon as the page is idle again.
 *   4. Circuit breaker: never more than 3 automatic reloads per minute.
 *
 * Public hooks:  window.BMLive.poll()   check right now
 *                window.BMLive.reload() refresh now
 * Page hook:     window.BM_LIVE_HOLD = function () { return true; }
 *                → page is mid-flow, don't reload it automatically.
 * ES5, no dependencies.
 * -----------------------------------------------------------------------
 */
(function () {
  'use strict';

  var script = document.querySelector('script[data-live-endpoint]');
  if (!script || window.BMLive) return;

  var CFG = {
    endpoint:    script.getAttribute('data-live-endpoint') || 'api/live.php',
    mode:        script.getAttribute('data-live-mode') || 'user',
    page:        script.getAttribute('data-live-page') || '',
    pollMs:      5000,
    maxBackoff:  60000,
    quietMs:     2500,            // idle time required before auto-reload
    typingMs:    5000,            // after a keypress
    sleepAfter:  20 * 60 * 1000,  // stop polling after this long untouched
    breakerMax:  3,
    breakerSpan: 60000
  };

  var pageSig  = script.getAttribute('data-live-base') || null;
  var notifSig = script.getAttribute('data-live-notif') || '';
  var pending = false, stopped = false, sleeping = false, inFlight = false;
  var failures = 0, timer = null, applyTimer = null, pillShown = false;
  var lastTouch = Date.now(), lastKey = 0;

  /* ---------------------------------------------------------------- UI */

  var styleDone = false;
  function ensureStyle() {
    if (styleDone) return;
    styleDone = true;
    var css =
      '#bmLiveToast{position:fixed;left:50%;bottom:calc(16px + env(safe-area-inset-bottom,0px));' +
      'transform:translate(-50%,12px);opacity:0;z-index:99999;display:flex;align-items:center;gap:10px;' +
      'max-width:calc(100vw - 24px);padding:10px 14px;border-radius:999px;font:500 13px/1.3 Inter,system-ui,sans-serif;' +
      'color:#F3F0E8;background:rgba(24,22,18,.96);border:1px solid rgba(212,175,95,.55);' +
      'box-shadow:0 8px 28px rgba(0,0,0,.35);transition:opacity .25s,transform .25s;pointer-events:none}' +
      '#bmLiveToast.is-on{opacity:1;transform:translate(-50%,0);pointer-events:auto}' +
      '#bmLiveToast button{font:inherit;font-weight:600;color:#1a1812;background:#D4AF5F;border:0;' +
      'border-radius:999px;padding:5px 12px;cursor:pointer}' +
      '#bmLiveToast .bm-live-dot{width:8px;height:8px;border-radius:50%;background:#D4AF5F;flex:none}' +
      '@keyframes bmLivePulse{0%{transform:scale(1)}40%{transform:scale(1.25)}100%{transform:scale(1)}}' +
      '.bm-live-pulse{animation:bmLivePulse .7s ease-out 2}';
    var st = document.createElement('style');
    st.id = 'bmLiveStyle';
    st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  var toastEl = null, toastTimer = null;
  function toast(text, actionLabel, onAction, autoHideMs) {
    ensureStyle();
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.id = 'bmLiveToast';
      toastEl.setAttribute('role', 'status');
      toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastEl);
    }
    toastEl.innerHTML = '<span class="bm-live-dot"></span><span class="bm-live-msg"></span>';
    toastEl.querySelector('.bm-live-msg').textContent = text;
    if (actionLabel) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = actionLabel;
      b.addEventListener('click', onAction);
      toastEl.appendChild(b);
    }
    window.clearTimeout(toastTimer);
    // next frame so the transition runs
    window.requestAnimationFrame(function () { toastEl.classList.add('is-on'); });
    if (autoHideMs) toastTimer = window.setTimeout(hideToast, autoHideMs);
  }
  function hideToast() {
    if (toastEl) toastEl.classList.remove('is-on');
  }

  /* ---------------------------------------------------- busy detection */

  function modalOpen() {
    if (document.querySelector('dialog[open]')) return true;
    var bell = document.getElementById('notifDropdown');
    if (bell && !bell.hidden) return true;
    var panel = document.getElementById('notifPanel');
    if (panel && !panel.hidden) return true;

    var nodes = document.querySelectorAll(
      '[class*="modal"],[class*="overlay"],[class*="drawer"],[class*="backdrop"],[class*="scrim"],' +
      '[role="dialog"],[aria-modal="true"]'
    );
    var vw = window.innerWidth, vh = window.innerHeight;
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el.id === 'bmLiveToast' || el.hidden) continue;
      var cs = window.getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.05) continue;
      if (cs.position !== 'fixed') continue;
      var r = el.getBoundingClientRect();
      if (r.width >= vw * 0.4 && r.height >= vh * 0.4) return true;
    }
    return false;
  }

  // Text the visitor typed that hasn't been saved/submitted (real keystrokes only).
  function hasUnsavedInput() {
    var fields = document.querySelectorAll('input, textarea');
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      if (!f.__bmEdited) continue;
      if (f.type === 'checkbox' || f.type === 'radio') {
        if (f.checked !== f.defaultChecked) return true;
      } else if (f.value !== f.defaultValue) {
        return true;
      }
    }
    return false;
  }

  function busyReason() {
    if (document.hidden) return 'hidden';
    if (typeof window.BM_LIVE_HOLD === 'function') {
      try { if (window.BM_LIVE_HOLD()) return 'page'; } catch (e) { /* ignore */ }
    }
    if (modalOpen()) return 'modal';
    if (hasUnsavedInput()) return 'unsaved';
    var sel = window.getSelection ? String(window.getSelection()) : '';
    if (sel && sel.length > 0) return 'selecting';
    var now = Date.now();
    if (now - lastKey < CFG.typingMs) return 'typing';
    if (now - lastTouch < CFG.quietMs) return 'recent';
    return '';
  }

  /* -------------------------------------------------------- reloading */

  var LOG_KEY = 'bm_live_reloads';
  function readLog() {
    try { return JSON.parse(sessionStorage.getItem(LOG_KEY) || '[]') || []; } catch (e) { return []; }
  }
  function writeLog(a) {
    try { sessionStorage.setItem(LOG_KEY, JSON.stringify(a)); } catch (e) { /* ignore */ }
  }

  function doReload() {
    try {
      sessionStorage.setItem('bm_live_after', JSON.stringify({
        y: window.pageYOffset || 0,
        u: location.pathname + location.search,
        t: Date.now()
      }));
    } catch (e) { /* ignore */ }
    window.location.reload();
  }

  // Automatic reload, guarded by the circuit breaker.
  function autoReload() {
    var now = Date.now();
    var log = readLog().filter(function (t) { return now - t < CFG.breakerSpan; });
    if (log.length >= CFG.breakerMax) {
      pending = true;
      window.clearInterval(applyTimer);
      pillShown = false;
      showPill();
      return;
    }
    log.push(now);
    writeLog(log);
    doReload();
  }

  function showPill() {
    if (pillShown) return;          // don't rebuild it every tick (would eat clicks)
    pillShown = true;
    toast('New updates available', 'Refresh', function () {
      if (hasUnsavedInput() && !window.confirm('You have unsaved changes on this page. Refresh anyway?')) return;
      doReload();
    });
  }

  function tryApply() {
    if (!pending) return;
    var why = busyReason();
    if (!why) { autoReload(); return; }
    if (why !== 'hidden') showPill();
  }

  function markPending() {
    if (pending) return;
    pending = true;
    window.clearInterval(applyTimer);
    applyTimer = window.setInterval(tryApply, 1000);
    tryApply();
  }

  /* ------------------------------------------------------------- bell */

  function applyBell(bell) {
    var unread = Number(bell.unread) || 0;
    var grew = false;

    if (CFG.mode === 'admin') {
      var btn = document.getElementById('notifBellBtn');
      var list = document.getElementById('notifList');
      var actions = document.querySelector('.notif-dropdown-actions');
      if (list && typeof bell.list_html === 'string') list.innerHTML = bell.list_html;
      if (actions && typeof bell.actions_html === 'string') actions.innerHTML = bell.actions_html;
      if (btn) {
        btn.setAttribute('aria-label', unread + ' unread notification' + (unread === 1 ? '' : 's'));
        var dot = btn.querySelector('.notif-dot');
        if (dot) {
          var prev = parseInt(dot.textContent, 10) || 0;
          grew = dot.style.display !== 'none' ? unread > prev : unread > 0;
          dot.style.display = unread === 0 ? 'none' : '';
          dot.textContent = unread > 99 ? '99+' : String(unread);
          if (grew) pulse(dot);
        }
      }
      return;
    }

    /* user portal */
    var toggle = document.getElementById('notifToggle');
    var panel = document.getElementById('notifPanel');
    if (!toggle || !panel) return;

    var items = bell.items || [];
    var head = panel.querySelector('.topbar__notif-head') || panel.querySelector('.topbar__notif-title');
    while (head && head.nextSibling) panel.removeChild(head.nextSibling);

    var markBtn = panel.querySelector('[data-notif-action="mark_all_read"]');
    var clearBtn = panel.querySelector('[data-notif-action="delete_all"]');
    if (markBtn) markBtn.disabled = unread === 0;
    if (clearBtn) clearBtn.disabled = items.length === 0;

    if (!items.length) {
      var empty = document.createElement('div');
      empty.className = 'empty-state empty-state--compact';
      var p = document.createElement('p');
      p.className = 'empty-state__message';
      p.textContent = 'No new notifications';
      empty.appendChild(p);
      panel.appendChild(empty);
    } else {
      var ul = document.createElement('ul');
      ul.className = 'topbar__notif-list';
      items.forEach(function (n) {
        var li = document.createElement('li');
        if (!n.is_read) li.className = 'is-unread';
        li.setAttribute('data-notif-id', String(n.id));
        var inner;
        if (n.href) {
          inner = document.createElement('a');
          inner.href = n.href;
          inner.style.color = 'inherit';
          inner.style.textDecoration = 'underline';
        } else {
          inner = document.createElement('span');
        }
        inner.textContent = n.message;
        li.appendChild(inner);
        var t = document.createElement('span');
        t.className = 'topbar__notif-time';
        t.textContent = n.time || '';
        li.appendChild(t);
        var d = document.createElement('button');
        d.type = 'button';
        d.className = 'topbar__notif-del';
        d.setAttribute('data-notif-delete', String(n.id));
        d.setAttribute('aria-label', 'Delete notification');
        d.innerHTML = '&times;';
        li.appendChild(d);
        ul.appendChild(li);
      });
      panel.appendChild(ul);
    }

    var badge = toggle.querySelector('.topbar__badge');
    var dotOnly = toggle.classList.contains('shell-bell');
    if (unread > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'topbar__badge';
        toggle.appendChild(badge);
        grew = true;
      } else if (!dotOnly) {
        grew = unread > (parseInt(badge.textContent, 10) || 0);
      }
      if (dotOnly) {
        badge.setAttribute('aria-label', unread + ' unread');
      } else {
        badge.textContent = String(unread);
      }
      if (grew) pulse(badge);
    } else if (badge && badge.parentNode) {
      badge.parentNode.removeChild(badge);
    }
  }

  function pulse(el) {
    ensureStyle();
    el.classList.remove('bm-live-pulse');
    void el.offsetWidth; // restart animation
    el.classList.add('bm-live-pulse');
  }

  /* ---------------------------------------------------------- polling */

  function delay() {
    return failures ? Math.min(CFG.maxBackoff, CFG.pollMs * Math.pow(2, failures)) : CFG.pollMs;
  }

  function schedule(ms) {
    window.clearTimeout(timer);
    if (stopped || sleeping) return;
    timer = window.setTimeout(poll, typeof ms === 'number' ? ms : delay());
  }

  function sessionEnded() {
    stopped = true;
    window.clearInterval(applyTimer);
    toast('Your session has ended.', 'Sign in', function () { window.location.reload(); });
  }

  function poll() {
    window.clearTimeout(timer);
    if (stopped) return;
    if (document.hidden || inFlight) { schedule(); return; }
    if (Date.now() - lastTouch > CFG.sleepAfter) { sleeping = true; return; }

    inFlight = true;
    var url = CFG.endpoint +
      '?page=' + encodeURIComponent(CFG.page) +
      '&n=' + encodeURIComponent(notifSig) +
      '&_=' + Date.now();

    window.fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (res) {
        if (res.status === 401 || res.status === 403 || res.redirected) {
          var e = new Error('session'); e.session = true; throw e;
        }
        if (!res.ok) throw new Error('http ' + res.status);
        var type = res.headers.get('Content-Type') || '';
        if (type.indexOf('json') === -1) {
          var e2 = new Error('session'); e2.session = true; throw e2;
        }
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) throw new Error('bad payload');
        failures = 0;

        if (data.bell) applyBell(data.bell);
        if (typeof data.notif_sig === 'string') notifSig = data.notif_sig;

        if (pageSig === null) {
          pageSig = data.sig;                 // no server baseline: first poll is the baseline
        } else if (data.sig !== pageSig) {
          markPending();
        }
      })
      .catch(function (err) {
        if (err && err.session) { sessionEnded(); return; }
        failures = Math.min(failures + 1, 6);
      })
      .then(function () {
        inFlight = false;
        schedule();
      });
  }

  /* ----------------------------------------------------- page events */

  function touch() {
    lastTouch = Date.now();
    if (sleeping) { sleeping = false; poll(); }
  }
  ['pointerdown', 'touchstart', 'wheel'].forEach(function (ev) {
    document.addEventListener(ev, touch, { passive: true });
  });
  document.addEventListener('keydown', function () { lastKey = Date.now(); touch(); }, true);

  // Remember which fields the visitor actually typed in (ignores scripted changes).
  document.addEventListener('input', function (e) {
    if (e.isTrusted && e.target) e.target.__bmEdited = true;
  }, true);
  document.addEventListener('change', function (e) {
    if (e.isTrusted && e.target) e.target.__bmEdited = true;
  }, true);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { touch(); poll(); }
  });
  window.addEventListener('online', function () { failures = 0; poll(); });

  /* After an automatic reload: restore scroll and confirm it happened. */
  (function afterReload() {
    var raw = null;
    try {
      raw = sessionStorage.getItem('bm_live_after');
      sessionStorage.removeItem('bm_live_after');
    } catch (e) { /* ignore */ }
    if (!raw) return;
    var info = null;
    try { info = JSON.parse(raw); } catch (e) { info = null; }
    if (!info || Date.now() - info.t > 20000 || info.u !== location.pathname + location.search) return;

    function restore() { window.scrollTo(0, info.y || 0); }
    restore();
    window.addEventListener('load', restore);
    window.setTimeout(function () { toast('Updated with the latest data', null, null, 2500); }, 300);
  })();

  window.BMLive = {
    poll: function () { failures = 0; poll(); },
    reload: doReload
  };

  schedule(CFG.pollMs);
})();
