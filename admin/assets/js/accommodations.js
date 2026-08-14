/**
 * accommodations.js  (category-aware rewrite)
 * -----------------------------------------------------------------------
 * Handles:
 *  - Category sidebar: filter clicks, add/edit/delete category modals
 *  - Room modal: add/edit/delete rooms (with category pre-fill)
 *  - Quick status select on each card
 *  - Client-side search + status filter across all rendered cards
 *
 * Every mutation reloads the page on success so stat cards + grids
 * always reflect the true database state and CSRF tokens stay fresh.
 * -----------------------------------------------------------------------
 */
(function () {
  'use strict';

  var API_URL = 'api/accommodations.php';

  /* ------------------------------------------------------------------
     Shared helpers
     ------------------------------------------------------------------ */
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function csrfToken(form) {
    var el = form ? form.querySelector('[name="csrf_token"]') : null;
    return el ? el.value : '';
  }

  function openOverlay(el) {
    if (!el) return;
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeOverlay(el) {
    if (!el) return;
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function showAlert(alertEl, msg) {
    if (!alertEl) return;
    alertEl.textContent = msg;
    alertEl.style.display = 'block';
  }

  function hideAlert(alertEl) {
    if (!alertEl) return;
    alertEl.style.display = 'none';
    alertEl.textContent = '';
  }

  function setDisabled(btn, disabled, label) {
    if (!btn) return;
    btn.disabled = disabled;
    if (label !== undefined) btn.textContent = label;
  }

  /* ------------------------------------------------------------------
     Category sidebar — filter
     ------------------------------------------------------------------ */
  var catFilterBtns = qsa('[data-cat-filter]');
  var sections      = qsa('.acc-section');
  var allCards      = qsa('[data-acc-card]');

  var activeStatusFilter = '';
  var activeCatFilter    = 'all';   // 'all' | category id string | 'uncategorised'

  function applyAllFilters() {
    var term = (qs('#accSearchInput') ? qs('#accSearchInput').value : '').trim().toLowerCase();
    var totalVisible = 0;

    sections.forEach(function (section) {
      var sectionKey    = section.getAttribute('data-section-key') || '';
      var sectionCards  = qsa('[data-acc-card]', section);
      var sectionVisible = 0;

      // Does this section belong to the active category filter?
      var catMatch = true;
      if (activeCatFilter !== 'all') {
        if (activeCatFilter === 'uncategorised') {
          catMatch = sectionKey === 'null';
        } else {
          catMatch = sectionKey === activeCatFilter;
        }
      }

      if (!catMatch) {
        section.style.display = 'none';
        return;
      }
      section.style.display = '';

      sectionCards.forEach(function (card) {
        var hay    = (card.getAttribute('data-acc-search-text') || '').toLowerCase();
        var status = card.getAttribute('data-acc-status') || '';
        var ok = (!term || hay.indexOf(term) !== -1)
               && (!activeStatusFilter || status === activeStatusFilter);
        card.style.display = ok ? '' : 'none';
        if (ok) { sectionVisible += 1; totalVisible += 1; }
      });

      // Show/hide the empty-msg inside this section
      var emptyMsg = qs('[data-empty-msg]', section);
      var grid     = qs('[data-section-grid]', section);
      if (emptyMsg) {
        // Only reveal when section has NO real cards at all (not filtered out)
        var realCards = qsa('[data-acc-card]', section);
        emptyMsg.style.display = realCards.length === 0 ? '' : 'none';
      }
    });

    var noResultsEl = qs('#accNoResults');
    if (noResultsEl) {
      noResultsEl.style.display = (allCards.length > 0 && totalVisible === 0) ? 'block' : 'none';
    }
  }

  // Category sidebar click
  catFilterBtns.forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      // Don't trigger filter when clicking edit/delete buttons inside the row
      // (split closest() calls for broadest browser compatibility)
      if (e.target.closest('[data-cat-edit]') || e.target.closest('[data-cat-delete]')) return;
      catFilterBtns.forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      activeCatFilter = btn.getAttribute('data-cat-filter') || 'all';
      applyAllFilters();
    });
  });

  // Search input
  var searchInput = qs('#accSearchInput');
  if (searchInput) {
    searchInput.addEventListener('input', applyAllFilters);
  }

  // Status filter buttons
  var statusFilterBtns = qsa('[data-acc-filter]');
  statusFilterBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      statusFilterBtns.forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      activeStatusFilter = btn.getAttribute('data-acc-filter') || '';
      applyAllFilters();
    });
  });

  /* ------------------------------------------------------------------
     CATEGORY modal (add / edit)
     ------------------------------------------------------------------ */
  var catOverlay   = qs('#catModalOverlay');
  var catForm      = qs('#catForm');
  var catTitle     = qs('#catModalTitle');
  var catAlert     = qs('#catAlert');
  var catSubmitBtn = qs('#catSubmitBtn');
  var lastFocused  = null;

  function openCatModal(mode, data) {
    if (!catForm || !catOverlay) return;
    lastFocused = document.activeElement;
    catForm.reset();
    hideAlert(catAlert);

    if (mode === 'edit' && data) {
      catTitle.textContent = 'Edit Category';
      catSubmitBtn.textContent = 'Save Changes';
      catForm.querySelector('[name="action"]').value  = 'update_category';
      catForm.querySelector('[name="cat_id"]').value  = data.id;
      catForm.querySelector('[name="cat_name"]').value = data.name || '';
      catForm.querySelector('[name="cat_description"]').value = data.description || '';
    } else {
      catTitle.textContent = 'Add Category';
      catSubmitBtn.textContent = 'Add Category';
      catForm.querySelector('[name="action"]').value = 'create_category';
      catForm.querySelector('[name="cat_id"]').value = '';
    }

    openOverlay(catOverlay);
    setTimeout(function () {
      var f = catForm.querySelector('[name="cat_name"]');
      if (f) f.focus();
    }, 60);
  }

  function closeCatModal() {
    closeOverlay(catOverlay);
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  // Open triggers
  function bindOpenCat() {
    var btnPage    = qs('#btnAddCategory');
    var btnSidebar = qs('#btnAddCategorySidebar');
    if (btnPage)    btnPage.addEventListener('click',    function () { openCatModal('create'); });
    if (btnSidebar) btnSidebar.addEventListener('click', function () { openCatModal('create'); });

    qsa('[data-cat-edit]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.stopPropagation();
        try { openCatModal('edit', JSON.parse(el.getAttribute('data-cat-edit'))); } catch (_) {}
      });
    });
  }
  bindOpenCat();

  qsa('[data-cat-modal-close]').forEach(function (el) {
    el.addEventListener('click', closeCatModal);
  });
  if (catOverlay) {
    catOverlay.addEventListener('click', function (e) {
      if (e.target === catOverlay) closeCatModal();
    });
  }

  // Category form submit
  if (catForm) {
    catForm.addEventListener('submit', function (e) {
      e.preventDefault();
      hideAlert(catAlert);
      setDisabled(catSubmitBtn, true, 'Saving\u2026');

      var fd = new FormData(catForm);
      fetch(API_URL, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success) { window.location.reload(); return; }
          showAlert(catAlert, (json && json.error) || 'Something went wrong.');
          setDisabled(catSubmitBtn, false, catForm.querySelector('[name="action"]').value === 'update_category' ? 'Save Changes' : 'Add Category');
        })
        .catch(function () {
          showAlert(catAlert, 'Network error. Please try again.');
          setDisabled(catSubmitBtn, false);
        });
    });
  }

  /* ------------------------------------------------------------------
     CATEGORY delete confirmation
     ------------------------------------------------------------------ */
  var catConfirmOverlay  = qs('#catConfirmOverlay');
  var catConfirmText     = qs('#catConfirmText');
  var catConfirmYes      = qs('#catConfirmYes');
  var pendingDeleteCatId = null;

  qsa('[data-cat-delete]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      lastFocused = document.activeElement;
      pendingDeleteCatId = el.getAttribute('data-cat-delete');
      var name = el.getAttribute('data-cat-delete-name') || 'this category';
      if (catConfirmText) catConfirmText.textContent =
        'Delete \u201c' + name + '\u201d? Rooms inside it will become uncategorised.';
      openOverlay(catConfirmOverlay);
    });
  });

  function closeCatConfirm() {
    closeOverlay(catConfirmOverlay);
    pendingDeleteCatId = null;
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  qsa('[data-cat-confirm-close]').forEach(function (el) {
    el.addEventListener('click', closeCatConfirm);
  });
  if (catConfirmOverlay) {
    catConfirmOverlay.addEventListener('click', function (e) {
      if (e.target === catConfirmOverlay) closeCatConfirm();
    });
  }

  if (catConfirmYes) {
    catConfirmYes.addEventListener('click', function () {
      if (!pendingDeleteCatId) return;
      setDisabled(catConfirmYes, true, 'Deleting\u2026');

      var fd = new FormData();
      fd.set('action', 'delete_category');
      fd.set('cat_id', pendingDeleteCatId);
      fd.set('csrf_token', csrfToken(catForm));

      fetch(API_URL, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success) { window.location.reload(); return; }
          setDisabled(catConfirmYes, false, 'Delete Category');
          window.alert((json && json.error) || 'Could not delete. Please try again.');
        })
        .catch(function () {
          setDisabled(catConfirmYes, false, 'Delete Category');
          window.alert('Network error. Please try again.');
        });
    });
  }

  /* ------------------------------------------------------------------
     ROOM modal (add / edit)
     ------------------------------------------------------------------ */
  var accOverlay    = qs('#accModalOverlay');
  var accForm       = qs('#accForm');
  var accTitle      = qs('#accModalTitle');
  var accAlert      = qs('#accAlert');
  var accSubmitBtn  = qs('#accSubmitBtn');
  var typeSelect    = accForm ? accForm.querySelector('[name="type"]')       : null;
  var typeOtherInput = accForm ? accForm.querySelector('[name="type_other"]') : null;
  var imageInput    = qs('#accImageInput');
  var imagePreview  = qs('#accImagePreview');
  var removeImgRow  = qs('#accRemoveImageRow');
  var removeImgChk  = accForm ? accForm.querySelector('[name="remove_image"]') : null;
  var catSelect     = accForm ? accForm.querySelector('[name="category_id"]')  : null;

  // setFormVal declared BEFORE openRoomModal which uses it
  function setFormVal(name, value) {
    if (!accForm) return;
    var el = accForm.querySelector('[name="' + name + '"]');
    if (el) el.value = value;
  }

  function openRoomModal(mode, data, prefillCatId) {
    if (!accForm || !accOverlay) return;
    lastFocused = document.activeElement;

    accForm.reset();
    hideAlert(accAlert);
    if (typeOtherInput) typeOtherInput.style.display = 'none';
    if (imagePreview)   imagePreview.innerHTML = '';
    if (removeImgChk)   removeImgChk.checked = false;
    if (removeImgRow)   removeImgRow.style.display = 'none';

    if (mode === 'edit' && data) {
      accTitle.textContent      = 'Edit Room';
      accSubmitBtn.textContent  = 'Save Changes';
      accForm.querySelector('[name="action"]').value = 'update';
      accForm.querySelector('[name="id"]').value     = data.id || '';

      setFormVal('name',        data.name || '');
      setFormVal('capacity',    data.capacity != null ? data.capacity : 1);
      setFormVal('price',       data.price   != null ? data.price    : '');
      setFormVal('status',      data.status  || 'available');
      setFormVal('description', data.description || '');
      setFormVal('amenities',   Array.isArray(data.amenities) ? data.amenities.join(', ') : '');

      // Category
      if (catSelect) {
        catSelect.value = data.category_id != null ? data.category_id : '';
      }

      // Room type
      if (typeSelect) {
        var knownVals = Array.prototype.map.call(typeSelect.options, function (o) { return o.value; });
        if (data.type && knownVals.indexOf(data.type) !== -1) {
          typeSelect.value = data.type;
        } else {
          typeSelect.value = 'other';
          if (typeOtherInput) { typeOtherInput.style.display = 'block'; typeOtherInput.value = data.type || ''; }
        }
      }

      // Image
      if (data.image_filename && imagePreview) {
        imagePreview.innerHTML = '<img src="assets/uploads/accommodations/' + encodeURIComponent(data.image_filename) + '" alt="">';
        if (removeImgRow) removeImgRow.style.display = 'flex';
      }
    } else {
      accTitle.textContent      = 'Add Room';
      accSubmitBtn.textContent  = 'Add Room';
      accForm.querySelector('[name="action"]').value = 'create';
      accForm.querySelector('[name="id"]').value     = '';
      setFormVal('status', 'available');
      setFormVal('capacity', 1);

      // Pre-fill category if triggered from a section's "Add Room" button
      if (catSelect && prefillCatId != null) {
        catSelect.value = prefillCatId;
      }
    }

    openOverlay(accOverlay);
    setTimeout(function () {
      var f = accForm.querySelector('[name="name"]');
      if (f) f.focus();
    }, 60);
  }

  function closeRoomModal() {
    closeOverlay(accOverlay);
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  // Global "Add Room" button (no pre-fill)
  var btnAddRoomGlobal = qs('#btnAddRoomGlobal');
  if (btnAddRoomGlobal) {
    btnAddRoomGlobal.addEventListener('click', function () { openRoomModal('create', null, null); });
  }

  // Per-section "Add Room" buttons (pre-fills category)
  qsa('[data-add-room-to-cat]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openRoomModal('create', null, btn.getAttribute('data-add-room-to-cat'));
    });
  });

  // Edit room buttons on cards
  qsa('[data-acc-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      try { openRoomModal('edit', JSON.parse(btn.getAttribute('data-acc-edit'))); } catch (_) {}
    });
  });

  qsa('[data-acc-modal-close]').forEach(function (el) {
    el.addEventListener('click', closeRoomModal);
  });
  if (accOverlay) {
    accOverlay.addEventListener('click', function (e) {
      if (e.target === accOverlay) closeRoomModal();
    });
  }

  // Room type "Other" toggle
  if (typeSelect && typeOtherInput) {
    typeSelect.addEventListener('change', function () {
      var isOther = typeSelect.value === 'other';
      typeOtherInput.style.display = isOther ? 'block' : 'none';
      if (isOther) typeOtherInput.focus();
    });
  }

  // Image preview
  if (imageInput && imagePreview) {
    imageInput.addEventListener('change', function () {
      var file = imageInput.files && imageInput.files[0];
      if (!file) return;
      imagePreview.innerHTML = '<img src="' + URL.createObjectURL(file) + '" alt="">';
      if (removeImgRow) removeImgRow.style.display = 'none';
    });
  }

  // Room form submit
  if (accForm) {
    accForm.addEventListener('submit', function (e) {
      e.preventDefault();
      hideAlert(accAlert);
      var origLabel = accSubmitBtn ? accSubmitBtn.textContent : '';
      setDisabled(accSubmitBtn, true, 'Saving\u2026');

      fetch(API_URL, { method: 'POST', body: new FormData(accForm) })
        .then(function (r) { return r.json().catch(function () { throw new Error('bad json'); }); })
        .then(function (json) {
          if (json && json.success) { window.location.reload(); return; }
          showAlert(accAlert, (json && json.error) || 'Something went wrong. Please try again.');
          setDisabled(accSubmitBtn, false, origLabel);
        })
        .catch(function () {
          showAlert(accAlert, 'Network error. Please check your connection and try again.');
          setDisabled(accSubmitBtn, false, origLabel);
        });
    });
  }

  /* ------------------------------------------------------------------
     ROOM delete confirmation
     ------------------------------------------------------------------ */
  var accConfirmOverlay  = qs('#accConfirmOverlay');
  var accConfirmText     = qs('#accConfirmText');
  var accConfirmYes      = qs('#accConfirmYes');
  var pendingDeleteRoomId = null;

  qsa('[data-acc-delete]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      lastFocused = document.activeElement;
      pendingDeleteRoomId = btn.getAttribute('data-acc-delete');
      var name = btn.getAttribute('data-acc-delete-name') || 'this room';
      if (accConfirmText) accConfirmText.textContent =
        'Delete \u201c' + name + '\u201d? This can\u2019t be undone.';
      openOverlay(accConfirmOverlay);
    });
  });

  function closeRoomConfirm() {
    closeOverlay(accConfirmOverlay);
    pendingDeleteRoomId = null;
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  qsa('[data-acc-confirm-close]').forEach(function (el) {
    el.addEventListener('click', closeRoomConfirm);
  });
  if (accConfirmOverlay) {
    accConfirmOverlay.addEventListener('click', function (e) {
      if (e.target === accConfirmOverlay) closeRoomConfirm();
    });
  }

  if (accConfirmYes) {
    accConfirmYes.addEventListener('click', function () {
      if (!pendingDeleteRoomId) return;
      setDisabled(accConfirmYes, true, 'Deleting\u2026');

      var fd = new FormData();
      fd.set('action', 'delete');
      fd.set('id', pendingDeleteRoomId);
      fd.set('csrf_token', csrfToken(accForm));

      fetch(API_URL, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success) { window.location.reload(); return; }
          setDisabled(accConfirmYes, false, 'Delete');
          window.alert((json && json.error) || 'Could not delete. Please try again.');
        })
        .catch(function () {
          setDisabled(accConfirmYes, false, 'Delete');
          window.alert('Network error. Please try again.');
        });
    });
  }

  /* ------------------------------------------------------------------
     Quick status select on each room card
     ------------------------------------------------------------------ */
  qsa('[data-acc-status-select]').forEach(function (select) {
    var original = select.value;
    select.addEventListener('change', function () {
      var id        = select.getAttribute('data-acc-status-select');
      var newStatus = select.value;
      select.disabled = true;

      var fd = new FormData();
      fd.set('action',     'set_status');
      fd.set('id',         id);
      fd.set('status',     newStatus);
      fd.set('csrf_token', csrfToken(accForm));

      fetch(API_URL, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success) { window.location.reload(); return; }
          select.value    = original;
          select.disabled = false;
          window.alert((json && json.error) || 'Could not update status.');
        })
        .catch(function () {
          select.value    = original;
          select.disabled = false;
          window.alert('Network error. Please try again.');
        });
    });
  });

  /* ------------------------------------------------------------------
     Escape closes whichever overlay is open
     ------------------------------------------------------------------ */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (catOverlay        && catOverlay.classList.contains('is-open'))        closeCatModal();
    if (catConfirmOverlay && catConfirmOverlay.classList.contains('is-open')) closeCatConfirm();
    if (accOverlay        && accOverlay.classList.contains('is-open'))        closeRoomModal();
    if (accConfirmOverlay && accConfirmOverlay.classList.contains('is-open')) closeRoomConfirm();
  });

})();
