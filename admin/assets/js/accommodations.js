/**
 * accommodations.js
 * -----------------------------------------------------------------------
 * Page-specific behaviour for admin/accommodations.php: the add/edit
 * modal, delete confirmation, quick status changes on each card, featured
 * toggle (star button + featured filter), image preview, and client-side
 * search/filter over the already-rendered card grid.
 *
 * New in this revision:
 *   - Featured star button on each card calls action=set_featured
 *   - "Featured" filter tab in the toolbar works client-side
 *   - openModal() now reads/sets the is_featured checkbox
 *
 * Every mutation (create/update/delete/status/featured change) reloads
 * the page on success so the stat cards and grid always reflect the DB.
 * -----------------------------------------------------------------------
 */
(function () {
  'use strict';

  var API_URL = 'api/accommodations.php';

  var grid = document.querySelector('[data-acc-grid]');
  var cards = grid ? Array.prototype.slice.call(grid.querySelectorAll('[data-acc-card]')) : [];
  var noResultsEl = document.querySelector('[data-acc-no-results]');

  var searchInput = document.querySelector('[data-acc-search-input]');
  var filterButtons = Array.prototype.slice.call(document.querySelectorAll('[data-acc-filter]'));
  var activeStatus = '';   // '' = all, 'featured' = featured-only, else status string

  var addTriggerBtn = document.querySelector('.btn-action'); /* "Add Accommodation" in topbar */

  var modalOverlay = document.querySelector('[data-acc-modal]');
  var modalForm = document.querySelector('[data-acc-form]');
  var modalTitle = document.querySelector('[data-acc-modal-title]');
  var modalAlert = document.querySelector('[data-acc-alert]');
  var modalSubmitBtn = document.querySelector('[data-acc-submit]');
  var modalCloseEls = Array.prototype.slice.call(document.querySelectorAll('[data-acc-modal-close]'));

  var typeSelect = modalForm ? modalForm.querySelector('[name="type"]') : null;
  var typeOtherInput = modalForm ? modalForm.querySelector('[name="type_other"]') : null;

  var featuredCheckbox = modalForm ? modalForm.querySelector('[name="is_featured"]') : null;

  var imageInput = modalForm ? modalForm.querySelector('[name="image"]') : null;
  var imagePreview = document.querySelector('[data-acc-image-preview]');
  var removeImageRow = document.querySelector('[data-acc-remove-image-row]');
  var removeImageCheckbox = modalForm ? modalForm.querySelector('[name="remove_image"]') : null;

  var confirmOverlay = document.querySelector('[data-acc-confirm]');
  var confirmText = document.querySelector('[data-acc-confirm-text]');
  var confirmYesBtn = document.querySelector('[data-acc-confirm-yes]');
  var confirmCloseEls = Array.prototype.slice.call(document.querySelectorAll('[data-acc-confirm-close]'));
  var pendingDeleteId = null;

  var lastFocused = null;

  function csrfToken() {
    var el = modalForm ? modalForm.querySelector('[name="csrf_token"]') : null;
    return el ? el.value : '';
  }

  function setField(name, value) {
    if (!modalForm) return;
    var field = modalForm.querySelector('[name="' + name + '"]');
    if (field) field.value = value;
  }

  function showAlert(message) {
    if (!modalAlert) return;
    modalAlert.textContent = message;
    modalAlert.style.display = 'block';
  }

  function hideAlert() {
    if (!modalAlert) return;
    modalAlert.style.display = 'none';
    modalAlert.textContent = '';
  }

  /* ---------------------------------------------------------------------
     Add / edit modal
     --------------------------------------------------------------------- */
  function openModal(mode, data) {
    if (!modalForm || !modalOverlay) return;
    lastFocused = document.activeElement;

    modalForm.reset();
    hideAlert();
    if (typeOtherInput) typeOtherInput.style.display = 'none';
    if (imagePreview) imagePreview.innerHTML = '';
    if (removeImageCheckbox) removeImageCheckbox.checked = false;
    if (featuredCheckbox) featuredCheckbox.checked = false;

    setField('action', mode === 'edit' ? 'update' : 'create');
    setField('id', mode === 'edit' && data && data.id ? data.id : '');

    if (modalTitle) modalTitle.textContent = mode === 'edit' ? 'Edit Accommodation' : 'Add Accommodation';
    if (modalSubmitBtn) modalSubmitBtn.textContent = mode === 'edit' ? 'Save Changes' : 'Add Accommodation';

    if (removeImageRow) {
      removeImageRow.style.display = (mode === 'edit' && data && data.image_filename) ? 'flex' : 'none';
    }

    if (mode === 'edit' && data) {
      setField('name', data.name || '');
      setField('capacity', data.capacity != null ? data.capacity : '');
      setField('price', data.price != null ? data.price : '');
      setField('status', data.status || 'available');
      setField('description', data.description || '');
      setField('amenities', Array.isArray(data.amenities) ? data.amenities.join(', ') : '');

      // Featured checkbox
      if (featuredCheckbox) featuredCheckbox.checked = !!data.is_featured;

      if (typeSelect) {
        var knownValues = Array.prototype.map.call(typeSelect.options, function (o) { return o.value; });
        if (data.type && knownValues.indexOf(data.type) !== -1) {
          typeSelect.value = data.type;
        } else {
          typeSelect.value = 'other';
          if (typeOtherInput) {
            typeOtherInput.style.display = 'block';
            typeOtherInput.value = data.type || '';
          }
        }
      }

      if (data.image_filename && imagePreview) {
        imagePreview.innerHTML = '<img src="assets/uploads/accommodations/'
          + encodeURIComponent(data.image_filename) + '" alt="">';
      }
    } else {
      if (typeSelect && typeSelect.options.length) typeSelect.value = typeSelect.options[0].value;
      setField('status', 'available');
      setField('capacity', 1);
    }

    modalOverlay.classList.add('is-open');
    modalOverlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    window.setTimeout(function () {
      var firstField = modalForm.querySelector('[name="name"]');
      if (firstField) firstField.focus();
    }, 50);
  }

  function closeModal() {
    if (!modalOverlay) return;
    modalOverlay.classList.remove('is-open');
    modalOverlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  if (addTriggerBtn) {
    addTriggerBtn.addEventListener('click', function () {
      openModal('create', null);
    });
  }

  document.querySelectorAll('[data-acc-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var raw = btn.getAttribute('data-acc-edit');
      try {
        openModal('edit', JSON.parse(raw));
      } catch (e) {
        /* malformed data on the button — ignore rather than crash the page */
      }
    });
  });

  modalCloseEls.forEach(function (btn) {
    btn.addEventListener('click', closeModal);
  });

  if (modalOverlay) {
    modalOverlay.addEventListener('click', function (e) {
      if (e.target === modalOverlay) closeModal();
    });
  }

  if (typeSelect && typeOtherInput) {
    typeSelect.addEventListener('change', function () {
      typeOtherInput.style.display = typeSelect.value === 'other' ? 'block' : 'none';
      if (typeSelect.value === 'other') typeOtherInput.focus();
    });
  }

  if (imageInput && imagePreview) {
    imageInput.addEventListener('change', function () {
      var file = imageInput.files && imageInput.files[0];
      if (!file) return;
      var url = URL.createObjectURL(file);
      imagePreview.innerHTML = '<img src="' + url + '" alt="">';
      if (removeImageRow) removeImageRow.style.display = 'none';
    });
  }

  if (modalForm) {
    modalForm.addEventListener('submit', function (e) {
      e.preventDefault();
      hideAlert();

      if (modalSubmitBtn) modalSubmitBtn.disabled = true;
      var originalLabel = modalSubmitBtn ? modalSubmitBtn.textContent : '';
      if (modalSubmitBtn) modalSubmitBtn.textContent = 'Saving\u2026';

      var formData = new FormData(modalForm);

      fetch(API_URL, { method: 'POST', body: formData })
        .then(function (res) {
          return res.json().catch(function () {
            throw new Error('Invalid response from server.');
          });
        })
        .then(function (json) {
          if (json && json.success) {
            window.location.reload();
            return;
          }
          showAlert((json && json.error) || 'Something went wrong. Please try again.');
          if (modalSubmitBtn) {
            modalSubmitBtn.disabled = false;
            modalSubmitBtn.textContent = originalLabel;
          }
        })
        .catch(function () {
          showAlert('Network error. Please check your connection and try again.');
          if (modalSubmitBtn) {
            modalSubmitBtn.disabled = false;
            modalSubmitBtn.textContent = originalLabel;
          }
        });
    });
  }

  /* ---------------------------------------------------------------------
     Delete confirmation
     --------------------------------------------------------------------- */
  document.querySelectorAll('[data-acc-delete]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      lastFocused = document.activeElement;
      pendingDeleteId = btn.getAttribute('data-acc-delete');
      var name = btn.getAttribute('data-acc-delete-name') || 'this accommodation';
      if (confirmText) confirmText.textContent = 'Delete "' + name + '"? This can\u2019t be undone.';
      if (confirmOverlay) {
        confirmOverlay.classList.add('is-open');
        confirmOverlay.setAttribute('aria-hidden', 'false');
      }
    });
  });

  function closeConfirm() {
    if (confirmOverlay) {
      confirmOverlay.classList.remove('is-open');
      confirmOverlay.setAttribute('aria-hidden', 'true');
    }
    pendingDeleteId = null;
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  confirmCloseEls.forEach(function (btn) {
    btn.addEventListener('click', closeConfirm);
  });

  if (confirmOverlay) {
    confirmOverlay.addEventListener('click', function (e) {
      if (e.target === confirmOverlay) closeConfirm();
    });
  }

  if (confirmYesBtn) {
    confirmYesBtn.addEventListener('click', function () {
      if (!pendingDeleteId) return;
      confirmYesBtn.disabled = true;

      var formData = new FormData();
      formData.set('action', 'delete');
      formData.set('id', pendingDeleteId);
      formData.set('csrf_token', csrfToken());

      fetch(API_URL, { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success) {
            window.location.reload();
            return;
          }
          confirmYesBtn.disabled = false;
          window.alert((json && json.error) || 'Could not delete. Please try again.');
        })
        .catch(function () {
          confirmYesBtn.disabled = false;
          window.alert('Network error. Please try again.');
        });
    });
  }

  /* ---------------------------------------------------------------------
     Quick status change (select right on each card)
     --------------------------------------------------------------------- */
  document.querySelectorAll('[data-acc-status-select]').forEach(function (select) {
    var original = select.value;
    select.addEventListener('change', function () {
      var id = select.getAttribute('data-acc-status-select');
      var newStatus = select.value;

      var formData = new FormData();
      formData.set('action', 'set_status');
      formData.set('id', id);
      formData.set('status', newStatus);
      formData.set('csrf_token', csrfToken());

      select.disabled = true;

      fetch(API_URL, { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success) {
            window.location.reload();
            return;
          }
          select.value = original;
          select.disabled = false;
          window.alert((json && json.error) || 'Could not update status.');
        })
        .catch(function () {
          select.value = original;
          select.disabled = false;
          window.alert('Network error. Please try again.');
        });
    });
  });

  /* ---------------------------------------------------------------------
     Featured star toggle — one click flips is_featured on the card
     --------------------------------------------------------------------- */
  document.querySelectorAll('[data-acc-featured-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-acc-featured-toggle');
      var current = parseInt(btn.getAttribute('data-acc-featured-current'), 10) || 0;
      var next = current === 1 ? 0 : 1;

      btn.disabled = true;

      var formData = new FormData();
      formData.set('action', 'set_featured');
      formData.set('id', id);
      formData.set('featured', next);
      formData.set('csrf_token', csrfToken());

      fetch(API_URL, { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success) {
            window.location.reload();
            return;
          }
          btn.disabled = false;
          window.alert((json && json.error) || 'Could not update featured status.');
        })
        .catch(function () {
          btn.disabled = false;
          window.alert('Network error. Please try again.');
        });
    });
  });

  /* ---------------------------------------------------------------------
     Search + status/featured filter — client-side, over rendered cards
     --------------------------------------------------------------------- */
  function applyFilters() {
    var term = ((searchInput && searchInput.value) || '').trim().toLowerCase();
    var visibleCount = 0;

    cards.forEach(function (card) {
      var haystack = card.getAttribute('data-acc-search-text') || '';
      var cardStatus = card.getAttribute('data-acc-status') || '';
      var cardFeatured = card.getAttribute('data-acc-featured') || '0';

      var matchesTerm = !term || haystack.indexOf(term) !== -1;

      var matchesFilter = true;
      if (activeStatus === 'featured') {
        matchesFilter = cardFeatured === '1';
      } else if (activeStatus !== '') {
        matchesFilter = cardStatus === activeStatus;
      }

      var visible = matchesTerm && matchesFilter;
      card.style.display = visible ? '' : 'none';
      if (visible) visibleCount += 1;
    });

    if (noResultsEl) {
      noResultsEl.style.display = (cards.length > 0 && visibleCount === 0) ? 'block' : 'none';
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
  }

  filterButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      filterButtons.forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      activeStatus = btn.getAttribute('data-acc-filter') || '';
      applyFilters();
    });
  });

  /* ---------------------------------------------------------------------
     Escape closes whichever overlay is open
     --------------------------------------------------------------------- */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (modalOverlay && modalOverlay.classList.contains('is-open')) closeModal();
    if (confirmOverlay && confirmOverlay.classList.contains('is-open')) closeConfirm();
  });
})();
