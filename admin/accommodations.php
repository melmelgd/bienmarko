<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/accommodations-data.php';

$dbError = null;
$accommodations  = [];
$categories      = [];
$roomsByCategory = [];
$counts = ['total' => 0, 'available' => 0, 'booked' => 0, 'unavailable' => 0, 'maintenance' => 0];

try {
    acc_ensure_table();
    acc_ensure_categories_table();
    $pdo = bienmarko_db();
    $categories      = acc_get_categories();
    $roomsByCategory = acc_get_accommodations_by_category();
    $counts          = acc_status_counts();
} catch (Throwable $e) {
    $dbError = 'We could not load accommodations right now. Please try again in a moment.';
}

$csrfToken = bienmarko_csrf_token();

$roomTypeOptions = ['Single Room', 'Twin Room', 'Double Room', 'Deluxe Room', 'Family Room', 'Suite', 'Dormitory'];

$active_page        = 'accommodations';
$page_title         = 'Accommodations';
$breadcrumb         = 'Accommodations';
$page_action_label  = null;   // We use our own buttons in the page body
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<style>
/* =============================================================
   Accommodations — page-scoped styles
   ============================================================= */

/* ---- Alert banner ---- */
.acc-alert-banner {
  background: var(--red-bg);
  border: 1px solid var(--red-bg-strong);
  color: var(--red);
  border-radius: var(--r-md);
  padding: 12px 16px;
  font-size: 0.875rem;
  margin-bottom: 20px;
}

/* ---- Stat row ---- */
.acc-stat-row {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  margin-bottom: 24px;
}
@media (max-width: 900px) { .acc-stat-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 560px) { .acc-stat-row { grid-template-columns: repeat(2, 1fr); } }

/* ---- Two-column layout: categories sidebar + rooms panel ---- */
.acc-layout {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 20px;
  align-items: start;
}
@media (max-width: 860px) {
  .acc-layout { grid-template-columns: 1fr; }
}

/* ---- Category sidebar ---- */
.acc-cat-sidebar {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  position: sticky;
  top: 20px;
}
.acc-cat-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 16px 12px;
  border-bottom: 1px solid var(--border);
}
.acc-cat-header h3 {
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.acc-cat-list { padding: 8px; }
.acc-cat-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 10px;
  border-radius: var(--r-md);
  cursor: pointer;
  transition: background var(--transition);
  border: none;
  background: none;
  width: 100%;
  text-align: left;
  color: var(--text-secondary);
  font-size: 0.875rem;
  font-weight: 500;
}
.acc-cat-item:hover { background: var(--panel-alt); color: var(--text); }
.acc-cat-item.is-active { background: var(--gold-tint-bg); color: var(--gold-400); }
.acc-cat-item-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
.acc-cat-item-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.acc-cat-badge {
  font-size: 0.72rem; font-weight: 700;
  background: var(--panel-alt); color: var(--text-muted);
  border: 1px solid var(--border);
  border-radius: var(--r-full);
  padding: 2px 8px;
  flex-shrink: 0;
}
.acc-cat-item.is-active .acc-cat-badge { background: var(--gold-tint-bg); color: var(--gold-500); border-color: var(--gold-600); }
.acc-cat-actions { display: none; gap: 4px; flex-shrink: 0; }
.acc-cat-item:hover .acc-cat-actions,
.acc-cat-item.is-active .acc-cat-actions { display: flex; }
.acc-cat-divider { height: 1px; background: var(--border); margin: 8px 8px; }
.acc-cat-uncategorised {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 10px;
  border-radius: var(--r-md);
  cursor: pointer;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
  color: var(--text-muted);
  font-size: 0.825rem;
  font-style: italic;
  transition: background var(--transition), color var(--transition);
}
.acc-cat-uncategorised:hover { background: var(--panel-alt); color: var(--text-secondary); }
.acc-cat-uncategorised.is-active { background: var(--panel-alt); color: var(--text); }

/* ---- Rooms panel ---- */
.acc-rooms-panel { display: flex; flex-direction: column; gap: 28px; }

/* ---- Section per category ---- */
.acc-section {}
.acc-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}
.acc-section-title {
  display: flex;
  align-items: center;
  gap: 10px;
}
.acc-section-title h2 {
  font-size: 1.05rem;
  font-weight: 700;
}
.acc-section-count {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--text-muted);
  background: var(--panel-alt);
  border: 1px solid var(--border);
  border-radius: var(--r-full);
  padding: 2px 10px;
}
.acc-section-add {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.825rem;
  font-weight: 600;
  color: var(--gold-400);
  background: none;
  border: 1px solid var(--gold-600);
  border-radius: var(--r-full);
  padding: 6px 14px;
  cursor: pointer;
  transition: background var(--transition), color var(--transition);
  white-space: nowrap;
}
.acc-section-add:hover { background: var(--gold-tint-bg); }

/* ---- Search + filter toolbar ---- */
.acc-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.acc-search { position: relative; flex: 1; min-width: 200px; max-width: 340px; }
.acc-search svg {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: var(--text-muted);
}
.acc-search input {
  width: 100%;
  background: var(--input-bg);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 10px 14px 10px 38px;
  color: var(--text);
  font-size: 0.875rem;
  transition: border-color var(--transition);
}
.acc-search input::placeholder { color: var(--text-muted); }
.acc-search input:focus { outline: none; border-color: var(--gold-500); }
.acc-filters {
  display: flex; gap: 2px; flex-wrap: wrap;
  background: var(--panel); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 4px;
}
.acc-filters button {
  border: none; background: none; color: var(--text-secondary);
  font-size: 0.8rem; font-weight: 600; padding: 7px 12px;
  border-radius: calc(var(--r-md) - 4px);
  transition: background var(--transition), color var(--transition);
  white-space: nowrap;
}
.acc-filters button:hover { color: var(--text); }
.acc-filters button.is-active { background: var(--gold-500); color: var(--on-accent); }

/* ---- Room grid ---- */
.acc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}
.acc-grid-empty {
  grid-column: 1 / -1;
  text-align: center;
  padding: 32px 20px;
  color: var(--text-tertiary);
  font-size: 0.875rem;
}

/* ---- Room card ---- */
.acc-card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: border-color var(--transition);
}
.acc-card:hover { border-color: var(--border-strong); }
.acc-card-media {
  position: relative;
  aspect-ratio: 16 / 10;
  background: var(--bg-sidebar);
  display: flex; align-items: center; justify-content: center;
  color: var(--text-muted);
  overflow: hidden;
}
.acc-card-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
.acc-card-media .icon-lg { width: 36px; height: 36px; }
.acc-card-price {
  position: absolute; right: 10px; bottom: 10px;
  background: rgba(10, 10, 11, 0.82);
  color: var(--text);
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 0.875rem;
  padding: 4px 10px;
  border-radius: var(--r-full);
  border: 1px solid var(--border-strong);
}
.acc-card-price small { font-family: var(--font-body); font-weight: 500; color: var(--text-tertiary); font-size: 0.7rem; }
.acc-card-body { padding: 14px; display: flex; flex-direction: column; gap: 10px; flex: 1; }
.acc-card-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.acc-card-name { font-size: 0.95rem; font-weight: 700; line-height: 1.3; }
.acc-card-type { color: var(--text-tertiary); font-size: 0.78rem; margin-top: 2px; }
.acc-card-meta { display: flex; align-items: center; gap: 6px; color: var(--text-secondary); font-size: 0.8rem; }
.acc-card-meta .icon-sm { color: var(--text-muted); }
.acc-card-amenities { display: flex; flex-wrap: wrap; gap: 5px; }
.acc-tag {
  font-size: 0.7rem; font-weight: 600; color: var(--text-secondary);
  background: var(--panel-alt); border: 1px solid var(--border);
  border-radius: var(--r-full); padding: 2px 9px;
}
.acc-tag--more { color: var(--gold-400); border-color: var(--gold-600); }
.acc-card-actions { display: flex; gap: 8px; margin-top: auto; padding-top: 4px; }
.acc-card-actions .icon-btn { width: 32px; height: 32px; }
.acc-card-actions .icon-btn--danger:hover { background: var(--red-bg-strong); color: var(--red); border-color: var(--red-bg-strong); }
.acc-cat-actions .icon-btn--danger:hover { background: var(--red-bg-strong); color: var(--red); border-color: var(--red-bg-strong); }

/* ---- Status select on card ---- */
.status-select {
  appearance: none; -webkit-appearance: none;
  border: 1px solid transparent;
  border-radius: var(--r-full);
  font-size: 0.7rem; font-weight: 700;
  padding: 4px 24px 4px 9px;
  cursor: pointer;
  background-repeat: no-repeat;
  background-position: right 7px center;
  background-size: 10px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23a1a1aa' stroke-width='3'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  flex-shrink: 0;
  transition: opacity var(--transition);
}
.status-select:disabled { opacity: 0.6; cursor: wait; }
.status-select:focus { outline: none; }
.status-select--available   { background-color: var(--emerald-bg); color: var(--emerald); }
.status-select--booked      { background-color: var(--blue-bg); color: var(--blue); }
.status-select--maintenance { background-color: var(--amber-bg); color: var(--gold-400); }
.status-select--unavailable { background-color: var(--red-bg); color: var(--red); }
.status-select option { background: var(--panel); color: var(--text); }

/* ---- No results ---- */
.acc-no-results { display: none; text-align: center; padding: 48px 20px; color: var(--text-tertiary); }
.acc-no-results .icon-lg { margin: 0 auto; color: var(--text-muted); }

/* ---- Modals ---- */
.acc-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
  z-index: 100;
  opacity: 0; visibility: hidden;
  transition: opacity var(--transition);
}
.acc-modal-overlay.is-open { opacity: 1; visibility: visible; }
.acc-modal {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  width: 100%; max-width: 560px;
  max-height: 90vh;
  display: flex; flex-direction: column;
  overflow: hidden;
}
.acc-modal-sm { max-width: 440px; }
.acc-confirm-modal { max-width: 420px; }
.acc-modal > form {
  display: flex; flex-direction: column;
  flex: 1; min-height: 0;
}
.acc-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 20px 0;
  flex-shrink: 0;
}
.acc-modal-header h2 { font-size: 1.05rem; }
.acc-modal-body { padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; flex: 1; min-height: 0; }
.acc-modal-footer {
  display: flex; justify-content: flex-end; gap: 10px;
  padding: 16px 20px; border-top: 1px solid var(--border);
  flex-shrink: 0;
}

/* ---- Form helpers ---- */
.acc-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.field select,
.field textarea,
.field input[type="number"],
.field input[type="text"] {
  width: 100%;
  background: var(--input-bg);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 10px 14px;
  color: var(--text);
  font-size: 0.875rem;
  font-family: inherit;
  transition: border-color var(--transition);
}
.field select:focus,
.field textarea:focus,
.field input[type="number"]:focus,
.field input[type="text"]:focus { outline: none; border-color: var(--gold-500); }
.field textarea { resize: vertical; min-height: 72px; }
.field-label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
.field-input-wrap { position: relative; }
.field-input-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
.field-input-wrap input.has-icon { padding-left: 38px; }

.form-alert {
  border-radius: var(--r-md);
  padding: 10px 14px;
  font-size: 0.825rem;
  font-weight: 500;
}
.form-alert--error { background: var(--red-bg); color: var(--red); border: 1px solid var(--red-bg-strong); }

/* ---- Image upload ---- */
.acc-image-field { display: flex; gap: 14px; align-items: flex-start; }
.acc-image-preview {
  width: 80px; height: 80px; border-radius: var(--r-md);
  background: var(--input-bg); border: 1px solid var(--border);
  overflow: hidden; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.acc-image-preview img { width: 100%; height: 100%; object-fit: cover; }
.acc-image-controls { display: flex; flex-direction: column; gap: 8px; }
.acc-upload-btn { cursor: pointer; width: fit-content; }
.acc-image-hint { color: var(--text-tertiary); font-size: 0.75rem; }
.acc-remove-image-row { display: flex; align-items: center; gap: 6px; font-size: 0.8rem; color: var(--text-secondary); cursor: pointer; }

/* ---- Danger button ---- */
.btn-danger { background: var(--red-bg-strong); color: var(--red); }
.btn-danger:hover { background: var(--red-bg); }

/* ---- Page-level add buttons row ---- */
.acc-page-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}

/* ---- Responsive ---- */
@media (max-width: 640px) {
  .acc-field-row { grid-template-columns: 1fr; }
  .acc-layout { gap: 16px; }
}
</style>

<div class="stack">

  <!-- Stat cards row -->
  <div class="acc-stat-row">
    <?php
      render_stat_card('Total Units',        (string) $counts['total'],       'bed',            'fuchsia');
      render_stat_card('Available',          (string) $counts['available'],   'calendar-check', 'emerald');
      render_stat_card('Booked',             (string) $counts['booked'],      'calendar',       'blue');
      render_stat_card('Under Maintenance',  (string) $counts['maintenance'], 'alert-triangle', 'amber');
      render_stat_card('Unavailable',        (string) $counts['unavailable'], 'close',          'red');
    ?>
  </div>

  <?php if ($dbError): ?>
    <div class="acc-alert-banner"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php else: ?>

  <!-- Page-level action buttons -->
  <div class="acc-page-actions">
    <button type="button" class="btn btn-primary" id="btnAddCategory">
      <?php echo icon('plus', 'icon-sm'); ?>
      <span>Add Category</span>
    </button>
    <button type="button" class="btn btn-secondary" id="btnAddRoomGlobal">
      <?php echo icon('plus', 'icon-sm'); ?>
      <span>Add Room</span>
    </button>
  </div>

  <!-- Search + filter toolbar -->
  <div class="acc-toolbar" id="accToolbar" style="<?php echo empty($categories) && empty($roomsByCategory) ? 'display:none' : ''; ?>">
    <div class="acc-search">
      <?php echo acc_icon('search', 'icon-sm'); ?>
      <input type="text" id="accSearchInput" placeholder="Search rooms&hellip;">
    </div>
    <div class="acc-filters" id="accFilters">
      <button type="button" class="is-active" data-acc-filter="">All</button>
      <?php foreach (ACC_STATUSES as $s): ?>
        <button type="button" data-acc-filter="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(acc_status_label($s), ENT_QUOTES, 'UTF-8'); ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (empty($categories) && empty($roomsByCategory)): ?>
    <!-- Absolutely empty state -->
    <div class="panel" style="text-align:center; padding: 56px 20px;">
      <?php echo icon('bed', 'icon-lg'); ?>
      <p style="margin-top:14px; font-weight:700; font-size:1rem;">No accommodations yet</p>
      <p style="font-size:0.875rem; color:var(--text-secondary); margin-top:6px;">Start by adding a category (e.g. "Couples Room"), then add rooms inside it.</p>
    </div>
  <?php else: ?>

  <!-- Two-column layout -->
  <div class="acc-layout">

    <!-- Left: Category sidebar -->
    <div class="acc-cat-sidebar">
      <div class="acc-cat-header">
        <h3>Categories</h3>
        <button type="button" class="icon-btn" id="btnAddCategorySidebar" title="Add category" aria-label="Add category">
          <?php echo icon('plus', 'icon-sm'); ?>
        </button>
      </div>
      <div class="acc-cat-list">

        <!-- "All" filter -->
        <button type="button" class="acc-cat-item is-active" data-cat-filter="all">
          <span class="acc-cat-item-left">
            <?php echo acc_icon('folder', 'icon-sm'); ?>
            <span class="acc-cat-item-name">All Rooms</span>
          </span>
          <span class="acc-cat-badge"><?php echo $counts['total']; ?></span>
        </button>

        <?php if (!empty($categories)): ?>
          <div class="acc-cat-divider"></div>
          <?php foreach ($categories as $cat): ?>
            <?php
              $catId    = (int) $cat['id'];
              $catRooms = $roomsByCategory[$catId] ?? [];
              $catCount = count($catRooms);
              $editJson = htmlspecialchars(
                  json_encode(['id' => $catId, 'name' => $cat['name'], 'description' => $cat['description'] ?? ''], JSON_UNESCAPED_UNICODE),
                  ENT_QUOTES, 'UTF-8'
              );
            ?>
            <button type="button" class="acc-cat-item" data-cat-filter="<?php echo $catId; ?>">
              <span class="acc-cat-item-left">
                <?php echo acc_icon('tag', 'icon-sm'); ?>
                <span class="acc-cat-item-name"><?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
              <span style="display:flex;align-items:center;gap:4px;">
                <span class="acc-cat-badge"><?php echo $catCount; ?></span>
                <span class="acc-cat-actions">
                  <button type="button" class="icon-btn" style="width:24px;height:24px;" data-cat-edit='<?php echo $editJson; ?>' title="Edit category" aria-label="Edit <?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo acc_icon('edit', 'icon-sm'); ?>
                  </button>
                  <button type="button" class="icon-btn icon-btn--danger" style="width:24px;height:24px;" data-cat-delete="<?php echo $catId; ?>" data-cat-delete-name="<?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>" title="Delete category" aria-label="Delete <?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo acc_icon('trash', 'icon-sm'); ?>
                  </button>
                </span>
              </span>
            </button>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($roomsByCategory['null'] ?? [])): ?>
          <div class="acc-cat-divider"></div>
          <button type="button" class="acc-cat-uncategorised" data-cat-filter="uncategorised">
            <span>Uncategorised</span>
            <span class="acc-cat-badge"><?php echo count($roomsByCategory['null']); ?></span>
          </button>
        <?php endif; ?>

      </div>
    </div><!-- /.acc-cat-sidebar -->

    <!-- Right: Rooms panel -->
    <div class="acc-rooms-panel" id="accRoomsPanel">

      <?php
        // Build list of sections to render: named categories first, then uncategorised
        $sections = [];
        foreach ($categories as $cat) {
            $catId = (int) $cat['id'];
            $sections[] = [
                'key'   => (string) $catId,
                'label' => $cat['name'],
                'rooms' => $roomsByCategory[$catId] ?? [],
                'cat_id' => $catId,
            ];
        }
        // Uncategorised rooms
        if (!empty($roomsByCategory['null'])) {
            $sections[] = [
                'key'    => 'null',
                'label'  => 'Uncategorised',
                'rooms'  => $roomsByCategory['null'],
                'cat_id' => null,
            ];
        }
        // If no categories exist but there are rooms without categories
        if (empty($sections) && !empty($roomsByCategory)) {
            foreach ($roomsByCategory as $k => $rooms) {
                $sections[] = [
                    'key'    => (string) $k,
                    'label'  => 'Uncategorised',
                    'rooms'  => $rooms,
                    'cat_id' => null,
                ];
            }
        }
      ?>

      <?php foreach ($sections as $section): ?>
        <div class="acc-section"
             data-section-key="<?php echo htmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>">

          <div class="acc-section-header">
            <div class="acc-section-title">
              <?php echo acc_icon($section['cat_id'] ? 'tag' : 'folder', 'icon-sm'); ?>
              <h2><?php echo htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8'); ?></h2>
              <span class="acc-section-count" data-section-count><?php echo count($section['rooms']); ?></span>
            </div>
            <?php if ($section['cat_id']): ?>
              <button type="button" class="acc-section-add"
                      data-add-room-to-cat="<?php echo (int) $section['cat_id']; ?>"
                      data-add-room-cat-name="<?php echo htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo icon('plus', 'icon-sm'); ?>
                Add Room
              </button>
            <?php endif; ?>
          </div>

          <div class="acc-grid" data-section-grid>

            <?php if (empty($section['rooms'])): ?>
              <div class="acc-grid-empty" data-empty-msg>
                No rooms in this category yet.
                <?php if ($section['cat_id']): ?>
                  Click <strong>Add Room</strong> to add the first one.
                <?php endif; ?>
              </div>
            <?php else: ?>
              <?php foreach ($section['rooms'] as $row): ?>
                <?php
                  $amenities = acc_decode_amenities($row['amenities']);
                  $editPayload = [
                      'id'             => (int) $row['id'],
                      'name'           => $row['name'],
                      'type'           => $row['type'],
                      'capacity'       => (int) $row['capacity'],
                      'price'          => (float) $row['price'],
                      'status'         => $row['status'],
                      'description'    => $row['description'],
                      'amenities'      => $amenities,
                      'image_filename' => $row['image_filename'],
                      'category_id'    => $row['category_id'],
                  ];
                  $searchText = mb_strtolower($row['name'] . ' ' . $row['type']);
                ?>
                <div class="acc-card"
                     data-acc-card
                     data-acc-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>"
                     data-acc-search-text="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">

                  <div class="acc-card-media">
                    <?php if ($row['image_filename']): ?>
                      <img src="<?php echo ACC_UPLOAD_URL_BASE . '/' . rawurlencode($row['image_filename']); ?>"
                           alt="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                    <?php else: ?>
                      <?php echo icon('bed', 'icon-lg'); ?>
                    <?php endif; ?>
                    <span class="acc-card-price">
                      &#8369;<?php echo number_format((float) $row['price'], 2); ?><small>/night</small>
                    </span>
                  </div>

                  <div class="acc-card-body">
                    <div class="acc-card-heading">
                      <div>
                        <h3 class="acc-card-name"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="acc-card-type"><?php echo htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8'); ?></p>
                      </div>
                      <select class="status-select status-select--<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>"
                              data-acc-status-select="<?php echo (int) $row['id']; ?>"
                              aria-label="Change status for <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php foreach (ACC_STATUSES as $s): ?>
                          <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $s === $row['status'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(acc_status_label($s), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="acc-card-meta">
                      <?php echo icon('users', 'icon-sm'); ?>
                      <span><?php echo (int) $row['capacity']; ?> guest<?php echo ((int) $row['capacity'] === 1) ? '' : 's'; ?></span>
                    </div>

                    <?php if ($amenities): ?>
                      <div class="acc-card-amenities">
                        <?php foreach (array_slice($amenities, 0, 3) as $a): ?>
                          <span class="acc-tag"><?php echo htmlspecialchars($a, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endforeach; ?>
                        <?php if (count($amenities) > 3): ?>
                          <span class="acc-tag acc-tag--more">+<?php echo count($amenities) - 3; ?> more</span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                    <div class="acc-card-actions">
                      <button type="button" class="icon-btn"
                              data-acc-edit='<?php echo htmlspecialchars((string) json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'
                              aria-label="Edit <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>" title="Edit room">
                        <?php echo acc_icon('edit', 'icon-sm'); ?>
                      </button>
                      <button type="button" class="icon-btn icon-btn--danger"
                              data-acc-delete="<?php echo (int) $row['id']; ?>"
                              data-acc-delete-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                              aria-label="Delete <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>" title="Delete room">
                        <?php echo acc_icon('trash', 'icon-sm'); ?>
                      </button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

          </div><!-- /.acc-grid -->
        </div><!-- /.acc-section -->
      <?php endforeach; ?>

      <!-- Global no-results message (shown when search/filter hides everything) -->
      <div class="acc-no-results" id="accNoResults">
        <?php echo icon('inbox', 'icon-lg'); ?>
        <p style="margin-top:12px; font-weight:600; color:var(--text);">No matches found</p>
        <p style="font-size:0.875rem; margin-top:4px;">Try a different search term or filter.</p>
      </div>

    </div><!-- /.acc-rooms-panel -->
  </div><!-- /.acc-layout -->

  <?php endif; // not empty ?>
  <?php endif; // no dbError ?>

</div><!-- /.stack -->


<!-- ================================================================
     MODAL: Add / Edit CATEGORY
     ================================================================ -->
<div class="acc-modal-overlay" id="catModalOverlay" aria-hidden="true">
  <div class="acc-modal acc-modal-sm" role="dialog" aria-modal="true" aria-labelledby="catModalTitle">
    <div class="acc-modal-header">
      <h2 id="catModalTitle">Add Category</h2>
      <button type="button" class="icon-btn" data-cat-modal-close aria-label="Close"><?php echo icon('close', 'icon-sm'); ?></button>
    </div>
    <form id="catForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="create_category">
      <input type="hidden" name="cat_id" value="">
      <div class="acc-modal-body">
        <div class="form-alert form-alert--error" id="catAlert" style="display:none;" role="alert"></div>
        <div class="field">
          <label class="field-label" for="catName">Category Name</label>
          <input type="text" id="catName" name="cat_name" maxlength="100" placeholder="e.g. Couples Room" required>
        </div>
        <div class="field">
          <label class="field-label" for="catDesc">Description <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
          <textarea id="catDesc" name="cat_description" maxlength="500" rows="3" placeholder="Short description of this category"></textarea>
        </div>
      </div>
      <div class="acc-modal-footer">
        <button type="button" class="btn btn-secondary" data-cat-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="catSubmitBtn">Add Category</button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================
     MODAL: Delete CATEGORY confirmation
     ================================================================ -->
<div class="acc-modal-overlay" id="catConfirmOverlay" aria-hidden="true">
  <div class="acc-modal acc-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="catConfirmTitle">
    <div class="acc-modal-header">
      <h2 id="catConfirmTitle">Delete Category</h2>
      <button type="button" class="icon-btn" data-cat-confirm-close aria-label="Close"><?php echo icon('close', 'icon-sm'); ?></button>
    </div>
    <div class="acc-modal-body">
      <p id="catConfirmText">Delete this category? Rooms in it will become uncategorised.</p>
    </div>
    <div class="acc-modal-footer">
      <button type="button" class="btn btn-secondary" data-cat-confirm-close>Cancel</button>
      <button type="button" class="btn btn-danger" id="catConfirmYes">Delete Category</button>
    </div>
  </div>
</div>

<!-- ================================================================
     MODAL: Add / Edit ROOM
     ================================================================ -->
<div class="acc-modal-overlay" id="accModalOverlay" aria-hidden="true">
  <div class="acc-modal" role="dialog" aria-modal="true" aria-labelledby="accModalTitle">
    <div class="acc-modal-header">
      <h2 id="accModalTitle">Add Room</h2>
      <button type="button" class="icon-btn" data-acc-modal-close aria-label="Close"><?php echo icon('close', 'icon-sm'); ?></button>
    </div>
    <form id="accForm" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="id" value="">

      <div class="acc-modal-body">
        <div class="form-alert form-alert--error" id="accAlert" style="display:none;" role="alert"></div>

        <!-- Category selector -->
        <div class="field">
          <label class="field-label" for="accCategory">Category</label>
          <select id="accCategory" name="category_id">
            <option value="">— No Category —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?php echo (int) $cat['id']; ?>"><?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="accName">Room Name</label>
          <div class="field-input-wrap">
            <?php echo icon('bed'); ?>
            <input class="has-icon" type="text" id="accName" name="name" maxlength="150" placeholder="e.g. Room 1" required>
          </div>
        </div>

        <div class="acc-field-row">
          <div class="field">
            <label class="field-label" for="accTypeSelect">Room Type</label>
            <select id="accTypeSelect" name="type">
              <?php foreach ($roomTypeOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
              <option value="other">Other (specify)</option>
            </select>
            <input type="text" id="accTypeOther" name="type_other" maxlength="100" placeholder="Enter custom room type" style="display:none; margin-top:8px;">
          </div>

          <div class="field">
            <label class="field-label" for="accCapacity">Capacity (guests)</label>
            <input type="number" id="accCapacity" name="capacity" min="1" max="50" step="1" value="1" required>
          </div>
        </div>

        <div class="acc-field-row">
          <div class="field">
            <label class="field-label" for="accPrice">Price per Night (&#8369;)</label>
            <input type="number" id="accPrice" name="price" min="0" max="999999" step="0.01" placeholder="0.00" required>
          </div>
          <div class="field">
            <label class="field-label" for="accStatus">Status</label>
            <select id="accStatus" name="status">
              <?php foreach (ACC_STATUSES as $s): ?>
                <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(acc_status_label($s), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="accAmenities">Amenities</label>
          <input type="text" id="accAmenities" name="amenities" maxlength="500" placeholder="Wi-Fi, Air Conditioning, Private Bathroom (comma-separated)">
        </div>

        <div class="field">
          <label class="field-label" for="accDescription">Description</label>
          <textarea id="accDescription" name="description" maxlength="2000" rows="3" placeholder="Short description guests will see"></textarea>
        </div>

        <div class="field">
          <label class="field-label">Photo</label>
          <div class="acc-image-field">
            <div class="acc-image-preview" id="accImagePreview"><?php echo icon('bed', 'icon-lg'); ?></div>
            <div class="acc-image-controls">
              <label class="btn btn-secondary acc-upload-btn">
                <?php echo icon('upload', 'icon-sm'); ?> Choose Photo
                <input type="file" name="image" id="accImageInput" accept="image/png,image/jpeg,image/webp" style="display:none;">
              </label>
              <p class="acc-image-hint">JPG, PNG, or WEBP. Max 5MB.</p>
              <label class="acc-remove-image-row" id="accRemoveImageRow" style="display:none;">
                <input type="checkbox" name="remove_image" value="1"> Remove current photo
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="acc-modal-footer">
        <button type="button" class="btn btn-secondary" data-acc-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="accSubmitBtn">Add Room</button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================
     MODAL: Delete ROOM confirmation
     ================================================================ -->
<div class="acc-modal-overlay" id="accConfirmOverlay" aria-hidden="true">
  <div class="acc-modal acc-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="accConfirmTitle">
    <div class="acc-modal-header">
      <h2 id="accConfirmTitle">Delete Room</h2>
      <button type="button" class="icon-btn" data-acc-confirm-close aria-label="Close"><?php echo icon('close', 'icon-sm'); ?></button>
    </div>
    <div class="acc-modal-body">
      <p id="accConfirmText">Delete this room? This can&rsquo;t be undone.</p>
    </div>
    <div class="acc-modal-footer">
      <button type="button" class="btn btn-secondary" data-acc-confirm-close>Cancel</button>
      <button type="button" class="btn btn-danger" id="accConfirmYes">Delete</button>
    </div>
  </div>
</div>

<script src="assets/js/accommodations.js" defer></script>
<?php require __DIR__ . '/includes/foot.php'; ?>
