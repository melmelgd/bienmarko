/**
 * BienMarko — shared login page behavior.
 * Vanilla JS, no build step, no framework — same conventions as
 * user/assets/js/app.js and admin/assets/js/script.js.
 *
 * The form works without this file too: it's a real <form method="post">
 * pointed at user/api/auth.php. This file only upgrades that into an
 * in-page fetch() submit so a wrong password doesn't cost a full page
 * reload. There used to be a Guest/Staff toggle here that picked which
 * endpoint the form posted to and relabeled the submit button — that's
 * gone now, because user/api/auth.php (like its admin/api/auth.php twin)
 * checks the submitted credentials against both the guest and staff
 * tables itself and signs the person into whichever one actually
 * matches. Nothing client-side needs to choose a role anymore.
 */

document.addEventListener('DOMContentLoaded', function () {
  initPasswordVisibility();
  initLoginForm();
  cleanErrorFromUrl();
});

function initPasswordVisibility() {
  var btn = document.getElementById('togglePassword');
  var input = document.getElementById('password');
  if (!btn || !input) return;

  btn.addEventListener('click', function () {
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.setAttribute('aria-pressed', String(!showing));
    btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
  });
}

function initLoginForm() {
  var form = document.getElementById('loginForm');
  var status = document.getElementById('loginStatus');
  var submitBtn = document.getElementById('loginSubmit');
  if (!form || !submitBtn) return;

  var lockoutTimer = null;

  function setLoading(isLoading) {
    submitBtn.disabled = isLoading;
    submitBtn.setAttribute('data-loading', isLoading ? 'true' : 'false');
  }

  function showError(message) {
    if (!status) return;
    status.textContent = message;
    status.classList.add('is-visible');
  }

  function hideError() {
    if (!status) return;
    status.textContent = '';
    status.classList.remove('is-visible');
  }

  function clearLockoutCountdown() {
    if (lockoutTimer) {
      window.clearInterval(lockoutTimer);
      lockoutTimer = null;
    }
  }

  function startLockoutCountdown(seconds) {
    clearLockoutCountdown();
    var remaining = Math.max(0, seconds);
    submitBtn.disabled = true;

    function tick() {
      if (remaining <= 0) {
        clearLockoutCountdown();
        hideError();
        submitBtn.disabled = false;
        return;
      }
      showError('Too many attempts. Try again in ' + remaining + 's.');
      remaining -= 1;
    }

    tick();
    lockoutTimer = window.setInterval(tick, 1000);
  }

  // Picks up a lockout that the no-JS fallback already rendered server-side
  // (login.php sets data-wait on this element), so refreshing mid-lockout
  // still counts down instead of showing a static message forever.
  var initialWait = parseInt(status && status.getAttribute('data-wait') || '0', 10);
  if (initialWait > 0) {
    startLockoutCountdown(initialWait);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearLockoutCountdown();

    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      return;
    }

    hideError();
    setLoading(true);

    var payload = {
      email: form.email.value,
      password: form.password.value,
      remember: form.remember.checked,
      csrf_token: form.csrf_token.value,
      website: form.website.value
    };

    fetch(form.getAttribute('action'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        var retryAfter = response.headers.get('Retry-After');
        return response.json().then(function (data) {
          return { data: data, retryAfter: retryAfter };
        });
      })
      .then(function (result) {
        var data = result.data;
        if (data && data.success && data.redirect) {
          window.location.href = data.redirect;
          return;
        }
        setLoading(false);
        var message = (data && data.message) || 'Something went wrong. Please try again.';
        if (result.retryAfter) {
          startLockoutCountdown(parseInt(result.retryAfter, 10));
        } else {
          showError(message);
        }
      })
      .catch(function () {
        setLoading(false);
        showError('Something went wrong. Please try again.');
      });
  });
}

/**
 * Drops ?error=...&wait=...&role=... from the address bar once they've
 * done their job (a refresh shouldn't keep re-displaying a one-time
 * flash message). ?role= used to be left in place so a refresh reopened
 * on the right tab — with the tab gone, it has nothing left to select,
 * so it's cleared out along with the rest now.
 */
function cleanErrorFromUrl() {
  if (!window.history || !window.history.replaceState) return;
  var url = new URL(window.location.href);
  if (!url.searchParams.has('error') && !url.searchParams.has('role')) return;
  url.searchParams.delete('error');
  url.searchParams.delete('wait');
  url.searchParams.delete('role');
  var query = url.searchParams.toString();
  window.history.replaceState(null, '', url.pathname + (query ? '?' + query : ''));
}
