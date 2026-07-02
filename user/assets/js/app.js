/**
 * BienMarko User Dashboard — shared front-end behavior.
 * No backend exists yet: form "submissions" below only simulate success in
 * the browser. Swap the NOTE-marked block in initFormSimulation() for a
 * real fetch() call to a PHP handler once the database is in place.
 */

document.addEventListener('DOMContentLoaded', function () {
  initNotifications();
  initLogoutConfirm();
  initSupportModal();
  initStarRating();

  initFormSimulation('bookingForm', 'bookingFormStatus', "Request sent — we'll be in touch to confirm.");
  initFormSimulation('feedbackForm', 'feedbackFormStatus', 'Feedback sent — thank you!', resetStarRating);
  initFormSimulation('profileForm', 'profileFormStatus', 'Changes saved.');
});

function initNotifications() {
  var toggle = document.getElementById('notifToggle');
  var panel = document.getElementById('notifPanel');
  if (!toggle || !panel) return;

  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    var isOpen = !panel.hidden;
    panel.hidden = isOpen;
    toggle.setAttribute('aria-expanded', String(!isOpen));
  });

  document.addEventListener('click', function (e) {
    if (!panel.hidden && !panel.contains(e.target) && e.target !== toggle) {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }
  });
}

function initLogoutConfirm() {
  var link = document.getElementById('logoutLink');
  if (!link) return;
  link.addEventListener('click', function (e) {
    var confirmed = window.confirm('Log out of BienMarko?');
    if (!confirmed) {
      e.preventDefault();
    }
  });
}

function initSupportModal() {
  var openBtn = document.getElementById('contactSupportBtn');
  var modal = document.getElementById('supportModal');
  var closeBtn = document.getElementById('supportModalClose');
  if (!openBtn || !modal) return;

  openBtn.addEventListener('click', function () {
    if (typeof modal.showModal === 'function') {
      modal.showModal();
    } else {
      modal.setAttribute('open', '');
    }
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      modal.close ? modal.close() : modal.removeAttribute('open');
    });
  }

  modal.addEventListener('click', function (e) {
    var rect = modal.getBoundingClientRect();
    var inDialog = rect.top <= e.clientY && e.clientY <= rect.top + rect.height
      && rect.left <= e.clientX && e.clientX <= rect.left + rect.width;
    if (!inDialog) {
      modal.close ? modal.close() : modal.removeAttribute('open');
    }
  });
}

function initStarRating() {
  var wrap = document.getElementById('starRating');
  if (!wrap) return;
  var stars = wrap.querySelectorAll('.star-rating__star');
  var input = document.getElementById('ratingInput');

  function setRating(value) {
    if (input) input.value = String(value);
    stars.forEach(function (star) {
      var starValue = Number(star.getAttribute('data-value'));
      var active = starValue <= value;
      star.classList.toggle('is-active', active);
      star.setAttribute('aria-checked', String(starValue === value));
    });
  }

  stars.forEach(function (star) {
    star.addEventListener('click', function () {
      setRating(Number(star.getAttribute('data-value')));
    });
  });
}

function resetStarRating() {
  var wrap = document.getElementById('starRating');
  if (!wrap) return;
  wrap.querySelectorAll('.star-rating__star').forEach(function (star) {
    star.classList.remove('is-active');
    star.setAttribute('aria-checked', 'false');
  });
  var input = document.getElementById('ratingInput');
  if (input) input.value = '0';
}

function initFormSimulation(formId, statusId, message, afterReset) {
  var form = document.getElementById(formId);
  var status = document.getElementById(statusId);
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      return;
    }

    // NOTE: no backend exists yet — this only simulates a successful submission.
    // Replace with a real fetch() to a PHP handler once the database is in place.
    form.reset();
    if (typeof afterReset === 'function') afterReset();

    if (status) {
      status.textContent = message;
      status.classList.add('is-visible');
      window.clearTimeout(status._hideTimer);
      status._hideTimer = window.setTimeout(function () {
        status.classList.remove('is-visible');
      }, 4000);
    }
  });
}
