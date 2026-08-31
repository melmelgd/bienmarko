/**
 * BienMarko User Dashboard — shared front-end behavior.
 * bookingForm has no matching element on any current page (booking runs
 * through its own dedicated flow) — the simulation call is kept as a
 * harmless no-op. feedbackForm and profileForm are wired to their real
 * PHP endpoints below.
 */

document.addEventListener('DOMContentLoaded', function () {
  initNotifications();
  initLogoutConfirm();
  initSupportModal();
  initStarRating();

  initFormSimulation('bookingForm', 'bookingFormStatus', "Request sent — we'll be in touch to confirm.");
  initFeedbackForm();
  initProfileForm();
});

function initNotifications() {
  var toggle = document.getElementById('notifToggle');
  var panel = document.getElementById('notifPanel');
  if (!toggle || !panel) return;
  var badge = toggle.querySelector('.topbar__badge');
  var marked = false;

  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    var isOpen = !panel.hidden;
    panel.hidden = isOpen;
    toggle.setAttribute('aria-expanded', String(!isOpen));
    if (!isOpen) {
      markNotificationsRead();
    }
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

  // Marks every unread notification as read once the panel is opened.
  // Runs once per page load (marked guard) and only when there's
  // actually a badge showing, so a click with nothing unread doesn't
  // fire a wasted request.
  function markNotificationsRead() {
    if (marked || !badge) return;
    marked = true;
    var csrf = toggle.getAttribute('data-csrf') || '';
    fetch('api/notifications.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_all_read', csrf_token: csrf })
    }).then(function () {
      if (badge && badge.parentNode) badge.parentNode.removeChild(badge);
      var items = panel.querySelectorAll('.topbar__notif-list li.is-unread');
      for (var i = 0; i < items.length; i++) {
        items[i].classList.remove('is-unread');
      }
    }).catch(function () { marked = false; });
  }
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

function showFormStatus(status, message, isError) {
  if (!status) return;
  status.textContent = message;
  status.classList.toggle('is-error', !!isError);
  status.classList.add('is-visible');
  window.clearTimeout(status._hideTimer);
  status._hideTimer = window.setTimeout(function () {
    status.classList.remove('is-visible');
  }, 4000);
}

var FEEDBACK_CATEGORY_LABELS = {
  general: 'General',
  room: 'Room & Amenities',
  service: 'Staff & Service',
  cleanliness: 'Cleanliness',
  other: 'Other'
};

function feedbackStarsHtml(rating) {
  var html = '<span class="fb-stars" aria-label="' + rating + ' out of 5 stars">';
  for (var i = 1; i <= 5; i++) {
    html += '<img class="icon fb-star' + (i <= rating ? ' is-filled' : '') + '" src="assets/icons/star.svg" alt="">';
  }
  return html + '</span>';
}

function prependFeedbackHistoryItem(fb) {
  var wrap = document.getElementById('feedbackHistoryWrap');
  if (!wrap) return;

  var list = document.getElementById('feedbackHistoryList');
  if (!list) {
    wrap.innerHTML = '<ul class="fb-list" id="feedbackHistoryList"></ul>';
    list = document.getElementById('feedbackHistoryList');
  }

  var li = document.createElement('li');
  li.className = 'fb-item';
  li.innerHTML =
    '<div class="fb-item__top">' + feedbackStarsHtml(fb.rating) + '<span class="fb-item__date">Just now</span></div>' +
    '<span class="fb-item__category">' + (FEEDBACK_CATEGORY_LABELS[fb.category] || 'General') + '</span>' +
    '<p class="fb-item__message"></p>';
  li.querySelector('.fb-item__message').textContent = fb.message;
  list.insertBefore(li, list.firstChild);
}

function initFeedbackForm() {
  var form = document.getElementById('feedbackForm');
  var status = document.getElementById('feedbackFormStatus');
  var csrfInput = document.getElementById('csrfToken');
  var ratingInput = document.getElementById('ratingInput');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      return;
    }

    var rating = Number((ratingInput && ratingInput.value) || 0);
    if (!rating) {
      showFormStatus(status, 'Please choose a star rating.', true);
      return;
    }

    var submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch('api/feedback.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'submit',
        rating: rating,
        category: (document.getElementById('category') || {}).value || 'general',
        message: (document.getElementById('message') || {}).value || '',
        csrf_token: csrfInput ? csrfInput.value : ''
      })
    })
      .then(function (res) {
        return res.json().then(function (data) { return { ok: res.ok, data: data }; });
      })
      .then(function (result) {
        if (!result.ok || !result.data.success) {
          throw new Error((result.data && result.data.message) || 'Could not send your feedback. Please try again.');
        }
        prependFeedbackHistoryItem(result.data.feedback);
        form.reset();
        resetStarRating();
        showFormStatus(status, 'Feedback sent — thank you!', false);
      })
      .catch(function (err) {
        showFormStatus(status, err.message, true);
      })
      .finally(function () {
        if (submitBtn) submitBtn.disabled = false;
      });
  });
}

function initProfileForm() {
  var form = document.getElementById('profileForm');
  var status = document.getElementById('profileFormStatus');
  var csrfInput = document.getElementById('csrfToken');
  if (!form) return;

  var fileInput = document.getElementById('avatarInput');
  var removeFlag = document.getElementById('removeAvatarFlag');
  var removeBtn = document.getElementById('removeAvatarBtn');
  var preview = document.getElementById('avatarPreview');

  function setPreviewImage(src) {
    if (!preview) return;
    preview.innerHTML = '<img src="' + src + '" alt="">';
  }

  function setPreviewInitials(initials) {
    if (!preview) return;
    preview.innerHTML = '<span class="avatar-upload__initials">' + initials + '</span>';
  }

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      if (!fileInput.files || !fileInput.files[0]) return;
      if (removeFlag) removeFlag.value = '0';
      if (removeBtn) removeBtn.hidden = false;
      var reader = new FileReader();
      reader.onload = function (e) { setPreviewImage(e.target.result); };
      reader.readAsDataURL(fileInput.files[0]);
    });
  }

  if (removeBtn) {
    removeBtn.addEventListener('click', function () {
      if (removeFlag) removeFlag.value = '1';
      if (fileInput) fileInput.value = '';
      setPreviewInitials(preview ? preview.getAttribute('data-initials') || '' : '');
      removeBtn.hidden = true;
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      return;
    }

    var submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    var formData = new FormData(form);
    formData.set('csrf_token', csrfInput ? csrfInput.value : '');

    fetch('api/profile.php', { method: 'POST', body: formData })
      .then(function (res) {
        return res.json().then(function (data) { return { ok: res.ok, data: data }; });
      })
      .then(function (result) {
        if (!result.ok || !result.data.success) {
          throw new Error((result.data && result.data.message) || 'Could not save your changes. Please try again.');
        }

        var user = result.data.user || {};
        showFormStatus(status, 'Changes saved.', false);

        var usernameEl = document.querySelector('.topbar__username');
        if (usernameEl && user.name) usernameEl.textContent = user.name;

        document.querySelectorAll('.topbar__avatar').forEach(function (el) {
          if (user.avatar) {
            el.innerHTML = '<img src="' + user.avatar + '" alt="">';
          } else if (user.initials) {
            el.textContent = user.initials;
          }
        });

        if (preview) {
          if (user.avatar) {
            setPreviewImage(user.avatar);
          } else {
            setPreviewInitials(user.initials || (preview.getAttribute('data-initials') || ''));
          }
        }

        if (removeBtn) removeBtn.hidden = !user.avatar;
        if (removeFlag) removeFlag.value = '0';
        if (fileInput) fileInput.value = '';
      })
      .catch(function (err) {
        showFormStatus(status, err.message, true);
      })
      .finally(function () {
        if (submitBtn) submitBtn.disabled = false;
      });
  });
}
