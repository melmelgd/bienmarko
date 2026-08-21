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
 *   - "Room Number" field appears (and becomes required) only when
 *     Accommodation Type is set to "Room" — toggleRoomNumberField()
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

  var roomNumberField = document.querySelector('[data-acc-room-number-field]');
  var roomNumberInput = modalForm ? modalForm.querySelector('[name="room_number"]') : null;
  var ROOM_NUMBER_TYPE_VALUE = 'Room'; /* mirrors ACC_TYPE_REQUIRING_ROOM_NUMBER server-side */

  var priceLabel = document.getElementById('accPriceLabel');
  var POOL_TYPE_VALUE = 'Swimming Pool'; /* mirrors the pool-pricing checks in the booking APIs */

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

  /**
   * Shows/hides + (un)requires the Room Number field based on the current
   * Accommodation Type selection. Hidden fields must not stay `required`
   * — a required-but-invisible form control blocks submission (and some
   * browsers throw rather than showing a validation bubble), so the
   * attribute is added/removed in lockstep with the display toggle rather
   * than just visually hiding it.
   */
  function toggleRoomNumberField() {
    if (!typeSelect || !roomNumberField || !roomNumberInput) return;
    var isRoom = typeSelect.value === ROOM_NUMBER_TYPE_VALUE;
    roomNumberField.style.display = isRoom ? '' : 'none';
    if (isRoom) {
      roomNumberInput.setAttribute('required', 'required');
    } else {
      roomNumberInput.removeAttribute('required');
      roomNumberInput.value = '';
    }
  }

  /**
   * Swaps the price field's label between "Price per Night" and "Price
   * per Person" based on the current Accommodation Type — Swimming Pool
   * is billed per person (see the booking APIs), every other type per
   * night. Cosmetic only; the underlying `price` column and field are
   * unchanged, just interpreted differently downstream.
   */
  function togglePriceLabel() {
    if (!typeSelect || !priceLabel) return;
    var isPool = typeSelect.value === POOL_TYPE_VALUE;
    priceLabel.innerHTML = isPool ? 'Price per Person (&#8369;)' : 'Price per Night (&#8369;)';
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
      setField('room_number', data.room_number || '');

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

    toggleRoomNumberField();
    togglePriceLabel();

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

  if (typeSelect) {
    typeSelect.addEventListener('change', function () {
      if (typeOtherInput) {
        typeOtherInput.style.display = typeSelect.value === 'other' ? 'block' : 'none';
        if (typeSelect.value === 'other') typeOtherInput.focus();
      }
      toggleRoomNumberField();
      togglePriceLabel();
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
     Manage Photos modal — extra gallery photos per room, on top of the
     single cover photo the add/edit form controls. Every mutation here
     updates the modal AND the underlying card in place (no full page
     reload) so uploading several photos in a row stays smooth.
     --------------------------------------------------------------------- */
  var PHOTOS_URL_BASE = 'assets/uploads/accommodations/';
  var TOURS_URL_BASE  = 'assets/uploads/tours/';

  var photosModalOverlay  = document.querySelector('[data-acc-photos-modal]');
  var photosModalTitle    = document.querySelector('[data-acc-photos-modal-title]');
  var photosGrid          = document.querySelector('[data-acc-photos-grid]');
  var photosLoadingEl     = document.querySelector('[data-acc-photos-loading]');
  var photosEmptyEl       = document.querySelector('[data-acc-photos-empty]');
  var photosAlertEl       = document.querySelector('[data-acc-photos-alert]');
  var photosInput         = document.querySelector('[data-acc-photos-input]');
  var photosModalCloseEls = Array.prototype.slice.call(document.querySelectorAll('[data-acc-photos-modal-close]'));

  var currentPhotosAccId = null;
  var photosLastFocused  = null;

  function showPhotosAlert(msg) {
    if (!photosAlertEl) return;
    photosAlertEl.textContent = msg;
    photosAlertEl.style.display = 'block';
  }
  function hidePhotosAlert() {
    if (!photosAlertEl) return;
    photosAlertEl.style.display = 'none';
    photosAlertEl.textContent = '';
  }

  function starIconSmall() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>';
  }
  function trashIconSmall() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 7 20 7"></polyline><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path><path d="M19 7l-1 13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7"></path></svg>';
  }
  function globeIconSmall() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"></path></svg>';
  }

  function findCard(id) {
    return grid ? grid.querySelector('[data-acc-card][data-acc-id="' + id + '"]') : null;
  }

  /** Merges new fields into a card's data-acc-edit JSON payload so the
      Edit modal stays accurate even though it wasn't the one that changed
      the data. Silently no-ops on malformed/missing payloads. */
  function patchCardEditPayload(card, patch) {
    var editBtn = card.querySelector('[data-acc-edit]');
    if (!editBtn) return;
    try {
      var payload = JSON.parse(editBtn.getAttribute('data-acc-edit'));
      Object.keys(patch).forEach(function (key) { payload[key] = patch[key]; });
      editBtn.setAttribute('data-acc-edit', JSON.stringify(payload));
    } catch (e) { /* malformed payload — leave it alone rather than crash */ }
  }

  /** Keeps a card's cover image, edit payload, and "Manage Photos" button
      label in sync after a photo mutation — without a full page reload. */
  function updateCardCover(id, filename, photoCount) {
    var card = findCard(id);
    if (!card) return;

    var media = card.querySelector('[data-acc-cover-media]');
    if (media) {
      var img = media.querySelector('img');
      if (filename) {
        var src = PHOTOS_URL_BASE + encodeURIComponent(filename);
        if (img) {
          img.src = src;
        } else {
          img = document.createElement('img');
          img.src = src;
          img.loading = 'lazy';
          img.alt = '';
          media.insertBefore(img, media.firstChild);
          var placeholderIcon = media.querySelector('svg.icon-lg');
          if (placeholderIcon) placeholderIcon.remove();
        }
      } else if (img) {
        img.remove();
      }
    }

    patchCardEditPayload(card, { image_filename: filename || null });

    if (typeof photoCount === 'number') {
      patchCardEditPayload(card, { photo_count: photoCount });
      var photosBtn = card.querySelector('[data-acc-photos-toggle]');
      if (photosBtn) {
        photosBtn.title = photoCount ? ('Manage Photos (' + photoCount + ')') : 'Manage Photos';
      }
    }
  }

  /** Keeps a card's 360° badge + toggle button state in sync after a tour
      mutation — without a full page reload. */
  function updateCardTour(id, tourFilename) {
    var card = findCard(id);
    if (!card) return;

    var toggleBtn = card.querySelector('[data-acc-tour-toggle]');
    if (toggleBtn) {
      toggleBtn.setAttribute('data-acc-tour-current', tourFilename || '');
      toggleBtn.classList.toggle('is-tour-active', !!tourFilename);
    }

    var media = card.querySelector('[data-acc-cover-media]');
    if (media) {
      var badge = media.querySelector('[data-acc-tour-badge]');
      if (tourFilename && !badge) {
        badge = document.createElement('span');
        badge.className = 'acc-tour-badge';
        badge.setAttribute('data-acc-tour-badge', '');
        badge.title = '360\u00B0 virtual tour uploaded';
        badge.innerHTML = globeIconSmall() + ' 360\u00B0';
        media.appendChild(badge);
      } else if (!tourFilename && badge) {
        badge.remove();
      }
    }

    patchCardEditPayload(card, { tour_filename: tourFilename || null });
  }

  function renderPhotosGrid(photos, cover) {
    if (!photosGrid) return;
    photosGrid.innerHTML = '';

    if (!photos.length) {
      if (photosEmptyEl) photosEmptyEl.style.display = 'block';
      return;
    }
    if (photosEmptyEl) photosEmptyEl.style.display = 'none';

    photos.forEach(function (photo) {
      var isCover = !!cover && photo.filename === cover;
      var tile = document.createElement('div');
      tile.className = 'acc-photo-tile' + (isCover ? ' is-cover' : '');
      tile.innerHTML =
        '<img src="' + PHOTOS_URL_BASE + encodeURIComponent(photo.filename) + '" alt="">' +
        (isCover ? '<span class="acc-photo-cover-tag">Cover</span>' : '') +
        '<div class="acc-photo-tile-actions">' +
          (isCover ? '' :
            '<button type="button" class="acc-photo-set-cover-btn" data-set-cover="' + photo.id + '">' + starIconSmall() + 'Cover</button>') +
          '<button type="button" class="acc-photo-delete-btn" data-delete-photo="' + photo.id + '">' + trashIconSmall() + 'Delete</button>' +
        '</div>';
      photosGrid.appendChild(tile);
    });
  }

  function fetchPhotos(id) {
    if (photosLoadingEl) photosLoadingEl.style.display = 'block';
    if (photosGrid) photosGrid.innerHTML = '';
    if (photosEmptyEl) photosEmptyEl.style.display = 'none';
    hidePhotosAlert();

    var formData = new FormData();
    formData.set('action', 'list_photos');
    formData.set('id', id);
    formData.set('csrf_token', csrfToken());

    fetch(API_URL, { method: 'POST', body: formData })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (photosLoadingEl) photosLoadingEl.style.display = 'none';
        if (json && json.success) {
          renderPhotosGrid(json.photos || [], json.cover);
        } else {
          showPhotosAlert((json && json.error) || 'Could not load photos.');
        }
      })
      .catch(function () {
        if (photosLoadingEl) photosLoadingEl.style.display = 'none';
        showPhotosAlert('Network error. Please try again.');
      });
  }

  function openPhotosModal(id, name) {
    if (!photosModalOverlay) return;
    photosLastFocused = document.activeElement;
    currentPhotosAccId = id;

    if (photosModalTitle) photosModalTitle.textContent = 'Manage Photos \u2014 ' + name;
    if (photosInput) photosInput.value = '';

    photosModalOverlay.classList.add('is-open');
    photosModalOverlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    fetchPhotos(id);
  }

  function closePhotosModal() {
    if (!photosModalOverlay) return;
    photosModalOverlay.classList.remove('is-open');
    photosModalOverlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    currentPhotosAccId = null;
    if (photosLastFocused && typeof photosLastFocused.focus === 'function') photosLastFocused.focus();
  }

  document.querySelectorAll('[data-acc-photos-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-acc-photos-toggle');
      var name = btn.getAttribute('data-acc-photos-name') || 'this room';
      openPhotosModal(id, name);
    });
  });

  photosModalCloseEls.forEach(function (btn) {
    btn.addEventListener('click', closePhotosModal);
  });
  if (photosModalOverlay) {
    photosModalOverlay.addEventListener('click', function (e) {
      if (e.target === photosModalOverlay) closePhotosModal();
    });
  }

  if (photosInput) {
    photosInput.addEventListener('change', function () {
      var files = photosInput.files;
      if (!files || !files.length || !currentPhotosAccId) return;
      hidePhotosAlert();

      var formData = new FormData();
      formData.set('action', 'upload_photos');
      formData.set('id', currentPhotosAccId);
      formData.set('csrf_token', csrfToken());
      Array.prototype.forEach.call(files, function (file) {
        formData.append('photos[]', file);
      });

      photosInput.disabled = true;

      fetch(API_URL, { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          photosInput.disabled = false;
          photosInput.value = '';
          if (json && json.success) {
            renderPhotosGrid(json.photos || [], json.cover);
            updateCardCover(currentPhotosAccId, json.cover, (json.photos || []).length);
            if (json.warning) showPhotosAlert(json.warning);
          } else {
            showPhotosAlert((json && json.error) || 'Could not upload photos.');
          }
        })
        .catch(function () {
          photosInput.disabled = false;
          photosInput.value = '';
          showPhotosAlert('Network error. Please try again.');
        });
    });
  }

  if (photosGrid) {
    photosGrid.addEventListener('click', function (e) {
      var setCoverBtn = e.target.closest('[data-set-cover]');
      var deleteBtn   = e.target.closest('[data-delete-photo]');

      if (setCoverBtn && currentPhotosAccId) {
        var photoId = setCoverBtn.getAttribute('data-set-cover');
        setCoverBtn.disabled = true;

        var fd = new FormData();
        fd.set('action', 'set_cover_photo');
        fd.set('id', currentPhotosAccId);
        fd.set('photo_id', photoId);
        fd.set('csrf_token', csrfToken());

        fetch(API_URL, { method: 'POST', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (json) {
            if (json && json.success) {
              fetchPhotos(currentPhotosAccId);
              updateCardCover(currentPhotosAccId, json.cover);
            } else {
              setCoverBtn.disabled = false;
              showPhotosAlert((json && json.error) || 'Could not set cover photo.');
            }
          })
          .catch(function () {
            setCoverBtn.disabled = false;
            showPhotosAlert('Network error. Please try again.');
          });
        return;
      }

      if (deleteBtn && currentPhotosAccId) {
        if (!window.confirm('Delete this photo?')) return;
        var delPhotoId = deleteBtn.getAttribute('data-delete-photo');
        deleteBtn.disabled = true;

        var fd2 = new FormData();
        fd2.set('action', 'delete_photo');
        fd2.set('id', currentPhotosAccId);
        fd2.set('photo_id', delPhotoId);
        fd2.set('csrf_token', csrfToken());

        fetch(API_URL, { method: 'POST', body: fd2 })
          .then(function (res) { return res.json(); })
          .then(function (json) {
            if (json && json.success) {
              renderPhotosGrid(json.photos || [], json.cover);
              updateCardCover(currentPhotosAccId, json.cover, (json.photos || []).length);
            } else {
              deleteBtn.disabled = false;
              showPhotosAlert((json && json.error) || 'Could not delete photo.');
            }
          })
          .catch(function () {
            deleteBtn.disabled = false;
            showPhotosAlert('Network error. Please try again.');
          });
      }
    });
  }

  /* ---------------------------------------------------------------------
     360° Tour modal — upload/replace/remove the self-contained tour HTML
     file for a room. Same in-place update approach as Manage Photos.
     --------------------------------------------------------------------- */
  var tourModalOverlay   = document.querySelector('[data-acc-tour-modal]');
  var tourModalTitle     = document.querySelector('[data-acc-tour-modal-title]');
  var tourStatusEl       = document.querySelector('[data-acc-tour-status]');
  var tourAlertEl        = document.querySelector('[data-acc-tour-alert]');
  var tourInput          = document.querySelector('[data-acc-tour-input]');
  var tourModalCloseEls  = Array.prototype.slice.call(document.querySelectorAll('[data-acc-tour-modal-close]'));

  var currentTourAccId = null;
  var tourLastFocused  = null;
  var TOUR_MAX_BYTES   = 30 * 1024 * 1024; // mirrors ACC_MAX_TOUR_BYTES server-side

  function showTourAlert(msg) {
    if (!tourAlertEl) return;
    tourAlertEl.textContent = msg;
    tourAlertEl.style.display = 'block';
  }
  function hideTourAlert() {
    if (!tourAlertEl) return;
    tourAlertEl.style.display = 'none';
    tourAlertEl.textContent = '';
  }

  function handleTourRemove() {
    if (!currentTourAccId) return;
    if (!window.confirm('Remove the 360\u00B0 tour from this room?')) return;

    var formData = new FormData();
    formData.set('action', 'delete_tour');
    formData.set('id', currentTourAccId);
    formData.set('csrf_token', csrfToken());

    fetch(API_URL, { method: 'POST', body: formData })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (json && json.success) {
          renderTourStatus(null);
          updateCardTour(currentTourAccId, null);
        } else {
          showTourAlert((json && json.error) || 'Could not remove tour file.');
        }
      })
      .catch(function () {
        showTourAlert('Network error. Please try again.');
      });
  }

  function renderTourStatus(filename) {
    if (!tourStatusEl) return;

    if (filename) {
      tourStatusEl.innerHTML =
        '<div class="acc-tour-status-card">' +
          '<div class="acc-tour-status-head">' +
            '<span class="acc-tour-status-icon acc-tour-status-icon--active">' + globeIconSmall() + '</span>' +
            '<div class="acc-tour-status-text"><strong>360\u00B0 tour uploaded</strong><span>Guests and users can view this room in 360\u00B0 when browsing.</span></div>' +
          '</div>' +
          '<div class="acc-tour-actions">' +
            '<a class="btn btn-secondary" href="' + TOURS_URL_BASE + encodeURIComponent(filename) + '" target="_blank" rel="noopener">Preview</a>' +
            '<label class="btn btn-secondary acc-upload-btn" data-acc-tour-replace-label>Replace File</label>' +
            '<button type="button" class="btn btn-danger" data-acc-tour-remove>Remove</button>' +
          '</div>' +
        '</div>';

      var replaceLabel = tourStatusEl.querySelector('[data-acc-tour-replace-label]');
      if (replaceLabel && tourInput) {
        replaceLabel.addEventListener('click', function (e) {
          e.preventDefault();
          tourInput.click();
        });
      }
      var removeBtn = tourStatusEl.querySelector('[data-acc-tour-remove]');
      if (removeBtn) removeBtn.addEventListener('click', handleTourRemove);
    } else {
      tourStatusEl.innerHTML =
        '<div class="acc-tour-status-card">' +
          '<div class="acc-tour-status-head">' +
            '<span class="acc-tour-status-icon acc-tour-status-icon--empty">' + globeIconSmall() + '</span>' +
            '<div class="acc-tour-status-text"><strong>No tour uploaded yet</strong><span>Upload a 360\u00B0 tour HTML export for this room.</span></div>' +
          '</div>' +
          '<div class="acc-tour-actions">' +
            '<label class="btn btn-primary acc-upload-btn" data-acc-tour-upload-label>Upload Tour File</label>' +
          '</div>' +
        '</div>';

      var uploadLabel = tourStatusEl.querySelector('[data-acc-tour-upload-label]');
      if (uploadLabel && tourInput) {
        uploadLabel.addEventListener('click', function (e) {
          e.preventDefault();
          tourInput.click();
        });
      }
    }
  }

  function openTourModal(id, name, currentFilename) {
    if (!tourModalOverlay) return;
    tourLastFocused = document.activeElement;
    currentTourAccId = id;

    if (tourModalTitle) tourModalTitle.textContent = '360\u00B0 Virtual Tour \u2014 ' + name;
    if (tourInput) tourInput.value = '';
    hideTourAlert();
    renderTourStatus(currentFilename || null);

    tourModalOverlay.classList.add('is-open');
    tourModalOverlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeTourModal() {
    if (!tourModalOverlay) return;
    tourModalOverlay.classList.remove('is-open');
    tourModalOverlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    currentTourAccId = null;
    if (tourLastFocused && typeof tourLastFocused.focus === 'function') tourLastFocused.focus();
  }

  document.querySelectorAll('[data-acc-tour-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-acc-tour-toggle');
      var name = btn.getAttribute('data-acc-tour-name') || 'this room';
      var current = btn.getAttribute('data-acc-tour-current') || '';
      openTourModal(id, name, current);
    });
  });

  tourModalCloseEls.forEach(function (btn) {
    btn.addEventListener('click', closeTourModal);
  });
  if (tourModalOverlay) {
    tourModalOverlay.addEventListener('click', function (e) {
      if (e.target === tourModalOverlay) closeTourModal();
    });
  }

  if (tourInput) {
    tourInput.addEventListener('change', function () {
      var file = tourInput.files && tourInput.files[0];
      if (!file || !currentTourAccId) return;
      hideTourAlert();

      var ext = (file.name.split('.').pop() || '').toLowerCase();
      if (ext !== 'html' && ext !== 'htm') {
        showTourAlert('The 360\u00B0 tour must be an HTML file (.html).');
        tourInput.value = '';
        return;
      }
      if (file.size > TOUR_MAX_BYTES) {
        showTourAlert('360\u00B0 tour file is too large (max 30MB).');
        tourInput.value = '';
        return;
      }

      var formData = new FormData();
      formData.set('action', 'upload_tour');
      formData.set('id', currentTourAccId);
      formData.set('csrf_token', csrfToken());
      formData.set('tour_html', file);

      tourInput.disabled = true;

      fetch(API_URL, { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          tourInput.disabled = false;
          tourInput.value = '';
          if (json && json.success) {
            renderTourStatus(json.tour_filename);
            updateCardTour(currentTourAccId, json.tour_filename);
          } else {
            showTourAlert((json && json.error) || 'Could not upload tour file.');
          }
        })
        .catch(function () {
          tourInput.disabled = false;
          tourInput.value = '';
          showTourAlert('Network error. Please try again.');
        });
    });
  }

  /* ---------------------------------------------------------------------
     Escape closes whichever overlay is open
     --------------------------------------------------------------------- */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (modalOverlay && modalOverlay.classList.contains('is-open')) closeModal();
    if (confirmOverlay && confirmOverlay.classList.contains('is-open')) closeConfirm();
    if (photosModalOverlay && photosModalOverlay.classList.contains('is-open')) closePhotosModal();
    if (tourModalOverlay && tourModalOverlay.classList.contains('is-open')) closeTourModal();
  });
})();
