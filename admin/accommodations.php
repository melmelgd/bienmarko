<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/accommodations-data.php';

$dbError = null;
$accommodations = [];
$counts = ['total' => 0, 'available' => 0, 'booked' => 0, 'unavailable' => 0, 'maintenance' => 0];

try {
    acc_ensure_table();
    $pdo = bienmarko_db();
    $accommodations = $pdo->query(
        'SELECT `id`, `name`, `type`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`
         FROM `accommodations`
         ORDER BY `name` ASC'
    )->fetchAll();
    $counts = acc_status_counts();
} catch (Throwable $e) {
    $dbError = 'We could not load accommodations right now. Please try again in a moment.';
}

$csrfToken = bienmarko_csrf_token();

$roomTypeOptions = ['Single Room', 'Twin Room', 'Double Room', 'Deluxe Room', 'Family Room', 'Suite', 'Dormitory'];

$active_page = 'accommodations';
$page_title = 'Accommodations';
$breadcrumb = 'Accommodations';
$page_action_label = 'Add Accommodation';
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<style>
/* ============================================================
   Accommodations — page-scoped styles. Kept local to this page
   (same approach admin/create-admin.php already uses) rather
   than editing assets/css/style.css.
   ============================================================ */

.acc-alert-banner {
  background: var(--red-bg);
  border: 1px solid var(--red-bg-strong);
  color: var(--red);
  border-radius: var(--r-md);
  padding: 12px 16px;
  font-size: 0.875rem;
  margin-bottom: 20px;
}

.acc-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.acc-search { position: relative; flex: 1; min-width: 220px; max-width: 360px; }
.acc-search .icon {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: var(--text-muted);
}
.acc-search input {
  width: 100%;
  background: var(--input-bg);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 11px 14px 11px 38px;
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
  font-size: 0.8rem; font-weight: 600; padding: 8px 12px;
  border-radius: calc(var(--r-md) - 4px);
  transition: background var(--transition), color var(--transition);
  white-space: nowrap;
}
.acc-filters button:hover { color: var(--text); }
.acc-filters button.is-active { background: var(--gold-500); color: var(--on-accent); }

.acc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
}
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
.acc-card-media .icon-lg { width: 40px; height: 40px; }
.acc-card-price {
  position: absolute; right: 10px; bottom: 10px;
  background: rgba(10, 10, 11, 0.82);
  color: var(--text);
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 0.9rem;
  padding: 5px 10px;
  border-radius: var(--r-full);
  border: 1px solid var(--border-strong);
}
.acc-card-price small { font-family: var(--font-body); font-weight: 500; color: var(--text-tertiary); font-size: 0.7rem; }

.acc-card-body { padding: 16px; display: flex; flex-direction: column; gap: 12px; flex: 1; }
.acc-card-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.acc-card-name { font-size: 1rem; font-weight: 700; line-height: 1.3; }
.acc-card-type { color: var(--text-tertiary); font-size: 0.8rem; margin-top: 2px; }

.acc-card-meta { display: flex; align-items: center; gap: 6px; color: var(--text-secondary); font-size: 0.825rem; }
.acc-card-meta .icon-sm { color: var(--text-muted); }

.acc-card-amenities { display: flex; flex-wrap: wrap; gap: 6px; }
.acc-tag {
  font-size: 0.72rem; font-weight: 600; color: var(--text-secondary);
  background: var(--panel-alt); border: 1px solid var(--border);
  border-radius: var(--r-full); padding: 3px 10px;
}
.acc-tag--more { color: var(--gold-400); border-color: var(--gold-600); }

.acc-card-actions { display: flex; gap: 8px; margin-top: auto; padding-top: 4px; }
.acc-card-actions .icon-btn { width: 34px; height: 34px; }
.acc-card-actions .icon-btn--danger:hover { background: var(--red-bg-strong); color: var(--red); border-color: var(--red-bg-strong); }

.status-select {
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  border: 1px solid transparent;
  border-radius: var(--r-full);
  font-size: 0.72rem; font-weight: 700;
  padding: 5px 26px 5px 10px;
  cursor: pointer;
  background-repeat: no-repeat;
  background-position: right 8px center;
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

.acc-no-results { display: none; text-align: center; padding: 48px 20px; color: var(--text-tertiary); }
.acc-no-results .icon-lg { margin: 0 auto; color: var(--text-muted); }

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
.acc-confirm-modal { max-width: 420px; }
.acc-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 20px 0;
}
.acc-modal-header h2 { font-size: 1.1rem; }
.acc-modal-body { padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
.acc-modal-footer {
  display: flex; justify-content: flex-end; gap: 10px;
  padding: 16px 20px; border-top: 1px solid var(--border);
}

.acc-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.field select,
.field textarea,
.field input[type="number"] {
  width: 100%;
  background: var(--input-bg);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 11px 14px;
  color: var(--text);
  font-size: 0.875rem;
  font-family: inherit;
  transition: border-color var(--transition);
}
.field select:focus,
.field textarea:focus,
.field input[type="number"]:focus { outline: none; border-color: var(--gold-500); }
.field textarea { resize: vertical; min-height: 72px; }

.form-alert {
  border-radius: var(--r-md);
  padding: 10px 14px;
  font-size: 0.825rem;
  font-weight: 500;
}
.form-alert--error { background: var(--red-bg); color: var(--red); border: 1px solid var(--red-bg-strong); }

.acc-image-field { display: flex; gap: 14px; align-items: flex-start; }
.acc-image-preview {
  width: 88px; height: 88px; border-radius: var(--r-md);
  background: var(--input-bg); border: 1px solid var(--border);
  overflow: hidden; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.acc-image-preview img { width: 100%; height: 100%; object-fit: cover; }
.acc-image-controls { display: flex; flex-direction: column; gap: 8px; }
.acc-upload-btn { cursor: pointer; width: fit-content; }
.acc-image-hint { color: var(--text-tertiary); font-size: 0.75rem; }
.acc-remove-image-row { display: flex; align-items: center; gap: 6px; font-size: 0.8rem; color: var(--text-secondary); cursor: pointer; }

.btn-danger { background: var(--red-bg-strong); color: var(--red); }
.btn-danger:hover { background: var(--red-bg); }

@media (max-width: 640px) {
  .acc-field-row { grid-template-columns: 1fr; }
  .acc-toolbar { flex-direction: column; align-items: stretch; }
  .acc-search { max-width: none; }
}
</style>

<div class="stack">

  <div class="stat-grid stat-grid--5">
    <?php
      render_stat_card('Total Units', (string) $counts['total'], 'bed', 'fuchsia');
      render_stat_card('Available', (string) $counts['available'], 'calendar-check', 'emerald');
      render_stat_card('Booked', (string) $counts['booked'], 'calendar', 'blue');
      render_stat_card('Under Maintenance', (string) $counts['maintenance'], 'alert-triangle', 'amber');
      render_stat_card('Unavailable', (string) $counts['unavailable'], 'close', 'red');
    ?>
  </div>

  <?php if ($dbError): ?>
    <div class="acc-alert-banner"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <?php if (!$dbError && empty($accommodations)): ?>

    <?php render_empty_panel('bed', 'No accommodations yet', 'Add your first room or unit to start managing availability and bookings.'); ?>

  <?php elseif (!$dbError): ?>

    <div class="acc-toolbar">
      <div class="acc-search">
        <?php echo acc_icon('search', 'icon-sm'); ?>
        <input type="text" data-acc-search-input placeholder="Search by room name or type&hellip;">
      </div>
      <div class="acc-filters">
        <button type="button" class="is-active" data-acc-filter="">All</button>
        <?php foreach (ACC_STATUSES as $s): ?>
          <button type="button" data-acc-filter="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(acc_status_label($s), ENT_QUOTES, 'UTF-8'); ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="acc-grid" data-acc-grid>
      <?php foreach ($accommodations as $row): ?>
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
          ];
          $searchText = mb_strtolower($row['name'] . ' ' . $row['type']);
        ?>
        <div class="acc-card" data-acc-card
             data-acc-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>"
             data-acc-search-text="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">

          <div class="acc-card-media">
            <?php if ($row['image_filename']): ?>
              <img src="<?php echo ACC_UPLOAD_URL_BASE . '/' . rawurlencode($row['image_filename']); ?>" alt="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
            <?php else: ?>
              <?php echo icon('bed', 'icon-lg'); ?>
            <?php endif; ?>
            <span class="acc-card-price">&#8369;<?php echo number_format((float) $row['price'], 2); ?><small>/night</small></span>
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
                <?php foreach (array_slice($amenities, 0, 4) as $a): ?>
                  <span class="acc-tag"><?php echo htmlspecialchars($a, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endforeach; ?>
                <?php if (count($amenities) > 4): ?>
                  <span class="acc-tag acc-tag--more">+<?php echo count($amenities) - 4; ?> more</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="acc-card-actions">
              <button type="button" class="icon-btn"
                      data-acc-edit='<?php echo htmlspecialchars((string) json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'
                      aria-label="Edit <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>" title="Edit">
                <?php echo acc_icon('edit', 'icon-sm'); ?>
              </button>
              <button type="button" class="icon-btn icon-btn--danger"
                      data-acc-delete="<?php echo (int) $row['id']; ?>"
                      data-acc-delete-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                      aria-label="Delete <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>" title="Delete">
                <?php echo acc_icon('trash', 'icon-sm'); ?>
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="acc-no-results" data-acc-no-results>
      <?php echo icon('inbox', 'icon-lg'); ?>
      <p style="margin-top:12px; font-weight:600; color:var(--text);">No matches found</p>
      <p style="font-size:0.875rem; margin-top:4px;">Try a different search term or filter.</p>
    </div>

  <?php endif; ?>

</div>

<!-- Add / Edit modal -->
<div class="acc-modal-overlay" data-acc-modal aria-hidden="true">
  <div class="acc-modal" role="dialog" aria-modal="true" aria-labelledby="accModalTitle">
    <div class="acc-modal-header">
      <h2 id="accModalTitle" data-acc-modal-title>Add Accommodation</h2>
      <button type="button" class="icon-btn" data-acc-modal-close aria-label="Close dialog">
        <?php echo icon('close', 'icon-sm'); ?>
      </button>
    </div>

    <form data-acc-form enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="id" value="">

      <div class="acc-modal-body">
        <div class="form-alert form-alert--error" data-acc-alert style="display:none;" role="alert"></div>

        <div class="field">
          <label class="field-label" for="accName">Room Name</label>
          <div class="field-input-wrap">
            <?php echo icon('bed'); ?>
            <input class="has-icon" type="text" id="accName" name="name" maxlength="150" placeholder="e.g. Garden Twin Room" required>
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
            <div class="acc-image-preview" data-acc-image-preview><?php echo icon('bed', 'icon-lg'); ?></div>
            <div class="acc-image-controls">
              <label class="btn btn-secondary acc-upload-btn">
                <?php echo icon('upload', 'icon-sm'); ?> Choose Photo
                <input type="file" name="image" accept="image/png, image/jpeg, image/webp" style="display:none;">
              </label>
              <p class="acc-image-hint">JPG, PNG, or WEBP. Max 5MB.</p>
              <label class="acc-remove-image-row" data-acc-remove-image-row style="display:none;">
                <input type="checkbox" name="remove_image" value="1"> Remove current photo
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="acc-modal-footer">
        <button type="button" class="btn btn-secondary" data-acc-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" data-acc-submit>Add Accommodation</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete confirmation modal -->
<div class="acc-modal-overlay" data-acc-confirm aria-hidden="true">
  <div class="acc-modal acc-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="accConfirmTitle">
    <div class="acc-modal-header">
      <h2 id="accConfirmTitle">Delete Accommodation</h2>
      <button type="button" class="icon-btn" data-acc-confirm-close aria-label="Close dialog">
        <?php echo icon('close', 'icon-sm'); ?>
      </button>
    </div>
    <div class="acc-modal-body">
      <p data-acc-confirm-text>Delete this accommodation? This can&rsquo;t be undone.</p>
    </div>
    <div class="acc-modal-footer">
      <button type="button" class="btn btn-secondary" data-acc-confirm-close>Cancel</button>
      <button type="button" class="btn btn-danger" data-acc-confirm-yes>Delete</button>
    </div>
  </div>
</div>

<script src="assets/js/accommodations.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>
