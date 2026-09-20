<?php
/**
 * admin/backup.php
 * -----------------------------------------------------------------------
 * Backup & Restore: create a full database backup, download or delete
 * a stored one, or restore the database from an existing backup or an
 * uploaded .sql file. All actions run through admin/api/backup.php;
 * downloads run through admin/backup-download.php.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/backup-data.php';

$pdo = bienmarko_db();
$csrf = bienmarko_csrf_token();

backup_ensure_table($pdo);
backup_ensure_storage_dir();

$rows = $pdo->query(
    'SELECT b.id, b.filename, b.file_size, b.created_at, a.name AS created_by_name
     FROM backups b
     LEFT JOIN admins a ON a.id = b.created_by
     ORDER BY b.created_at DESC'
)->fetchAll();

$lastBackup = $rows[0] ?? null;

$rowsForJs = array_map(static function ($row) {
    return [
        'id'         => (int) $row['id'],
        'filename'   => $row['filename'],
        'size'       => backup_format_bytes((int) $row['file_size']),
        'created_at' => date('M j, Y g:i A', strtotime($row['created_at'])),
    ];
}, $rows);
$rowsJson = json_encode($rowsForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$active_page = 'backup';
$page_title = 'Backup & Restore';
$breadcrumb = 'Backup & Restore';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<style>
/* ============================================================
   Backup & Restore — page-scoped styles.
   ============================================================ */
.table-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 0; margin-bottom: 14px; }
.results-count { font-size: 12.5px; color: var(--text-secondary); }
tbody tr { border-bottom: 1px solid var(--border); }
tbody tr:last-child { border-bottom: none; }
tbody td { padding: 12px 14px; font-size: 13.5px; color: var(--text); vertical-align: middle; }
.td-actions { display: flex; gap: 6px; }

.icon-btn-sm {
    width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--r-sm); border: 1px solid var(--border); background: var(--panel-alt);
    cursor: pointer; transition: background var(--transition), color var(--transition), border-color var(--transition);
    text-decoration: none;
}
.icon-btn-sm--view   { color: var(--gold-400); }
.icon-btn-sm--view:hover   { background: var(--amber-bg); border-color: var(--gold-600); }
.icon-btn-sm--edit   { color: var(--blue); }
.icon-btn-sm--edit:hover   { background: var(--blue-bg); border-color: var(--blue); }
.icon-btn-sm--danger { color: var(--red); }
.icon-btn-sm--danger:hover { background: var(--red-bg); border-color: var(--red); }

.td-name { font-weight: 700; }
.td-sub  { color: var(--text-secondary); font-size: 0.8rem; }

/* Modal shell (same shape used by admin/expenses.php) */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    display: flex; align-items: center; justify-content: center;
    padding: 20px; z-index: 500;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.is-open { opacity: 1; pointer-events: auto; }
.modal {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-lg); width: 100%; max-width: 520px;
    max-height: 92vh; display: flex; flex-direction: column;
    transform: translateY(12px); transition: transform .2s;
}
.modal-overlay.is-open .modal { transform: translateY(0); }
.modal-confirm { max-width: 440px; }
.modal-header {
    padding: 20px 24px 16px;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.modal-header-left { display: flex; align-items: flex-start; gap: 12px; }
.modal-icon-box {
    width: 40px; height: 40px; border-radius: var(--r-md);
    background: var(--red-bg); color: var(--red);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.modal-title    { font-family: var(--font-display); font-size: 17px; font-weight: 700; }
.modal-subtitle { font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; }
.modal-close    { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 4px; border-radius: var(--r-sm); flex-shrink: 0; }
.modal-close:hover { color: var(--text); background: var(--panel-alt); }
.modal-body     { padding: 20px 24px; overflow-y: auto; flex: 1; min-height: 0; }
.modal-actions  { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }

.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.form-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.form-input, .form-select {
    padding: 9px 12px; background: var(--input-bg);
    border: 1px solid var(--border); border-radius: var(--r-sm);
    color: var(--text); font-size: 14px; width: 100%;
    transition: border-color var(--transition);
    font-family: inherit;
}
.form-input:focus, .form-select:focus { outline: none; border-color: var(--gold-500); }
.form-hint { font-size: 12px; color: var(--text-tertiary); margin-top: 4px; }
.form-alert {
    background: var(--red-bg); border: 1px solid var(--red-bg-strong); color: var(--red);
    border-radius: var(--r-md); padding: 10px 14px; font-size: 13px; margin-bottom: 16px; display: none;
}
.form-alert.is-visible { display: block; }

.restore-source-toggle { display: flex; gap: 8px; margin-bottom: 16px; }
.restore-source-toggle button {
    flex: 1; padding: 9px 10px; border-radius: var(--r-sm); border: 1px solid var(--border);
    background: var(--panel-alt); color: var(--text-secondary); font-size: 13px; font-weight: 600; cursor: pointer;
}
.restore-source-toggle button.is-active { background: var(--gold-500); color: var(--on-accent); border-color: var(--gold-500); }
.restore-warning {
    background: var(--red-bg); border: 1px solid var(--red-bg-strong); color: var(--red);
    border-radius: var(--r-md); padding: 12px 14px; font-size: 13px; margin-bottom: 16px; line-height: 1.5;
}

.btn-danger  { background: var(--red-bg); color: var(--red); border-color: var(--red); }
.btn-danger:hover  { background: var(--red); color: #fff; }
.btn[disabled] { opacity: .55; cursor: not-allowed; }
.btn .icon-sm.spin { animation: bkp-spin 0.8s linear infinite; }
@keyframes bkp-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.toast-wrap { position: fixed; bottom: 28px; right: 28px; z-index: 900; display: flex; flex-direction: column; gap: 10px; }
.toast { padding: 12px 18px; border-radius: var(--r-md); font-size: 13.5px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,.5); transform: translateX(120%); opacity: 0; transition: transform .3s, opacity .3s; }
.toast.show { transform: translateX(0); opacity: 1; }
.toast--success { background: var(--emerald-bg); border: 1px solid var(--emerald); color: var(--emerald); }
.toast--error   { background: var(--red-bg);     border: 1px solid var(--red);     color: var(--red); }
</style>

<div class="stack">

  <!-- ===== Header ===== -->
  <div class="panel panel-pad">
    <div class="backup-header">
      <div>
        <h2 class="panel-title">Backup &amp; Restore</h2>
        <p class="backup-meta">
          Last backup: <strong><?php echo $lastBackup ? htmlspecialchars(date('M j, Y g:i A', strtotime($lastBackup['created_at'])), ENT_QUOTES, 'UTF-8') : 'Never'; ?></strong>
        </p>
      </div>
      <div class="backup-actions">
        <button type="button" class="btn btn-secondary" id="openRestoreBtn">
          <?php echo icon('upload', 'icon-sm'); ?> Restore
        </button>
        <button type="button" class="btn btn-primary" id="createBackupBtn">
          <?php echo icon('download', 'icon-sm'); ?> <span id="createBackupLabel">Create Backup</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ===== Backup History ===== -->
  <?php if (empty($rows)): ?>
    <?php render_table_empty(
        ['Filename', 'Created', 'Size', 'Created By', 'Actions'],
        'database',
        'No backups yet',
        'Click "Create Backup" above to generate your first full database backup.'
    ); ?>
  <?php else: ?>
    <div class="panel table-wrap">
      <div class="table-head">
        <h2 class="panel-title">Backup History</h2>
        <span class="results-count"><?php echo count($rows); ?> backup<?php echo count($rows) === 1 ? '' : 's'; ?></span>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Filename</th>
              <th>Created</th>
              <th>Size</th>
              <th>Created By</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td class="td-name"><?php echo htmlspecialchars($row['filename'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="td-sub"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="td-sub"><?php echo htmlspecialchars(backup_format_bytes((int) $row['file_size']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="td-sub"><?php echo htmlspecialchars($row['created_by_name'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <div class="td-actions">
                    <a class="icon-btn-sm icon-btn-sm--view" href="backup-download.php?id=<?php echo (int) $row['id']; ?>"
                       title="Download" aria-label="Download <?php echo htmlspecialchars($row['filename'], ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo icon('download', 'icon-sm'); ?>
                    </a>
                    <button class="icon-btn-sm icon-btn-sm--edit" onclick="openRestoreModal(<?php echo (int) $row['id']; ?>)"
                            title="Restore from this backup" aria-label="Restore from <?php echo htmlspecialchars($row['filename'], ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo icon('refresh', 'icon-sm'); ?>
                    </button>
                    <button class="icon-btn-sm icon-btn-sm--danger" onclick="openDeleteConfirm(<?php echo (int) $row['id']; ?>)"
                            title="Delete" aria-label="Delete <?php echo htmlspecialchars($row['filename'], ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo icon('trash', 'icon-sm'); ?>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<!-- ===== Restore Modal ===== -->
<div class="modal-overlay" id="restoreModal" aria-modal="true" role="dialog">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-header-left">
        <span class="modal-icon-box"><?php echo icon('refresh', 'icon-sm'); ?></span>
        <div>
          <div class="modal-title">Restore Database</div>
          <div class="modal-subtitle">Overwrite the current database with a backup.</div>
        </div>
      </div>
      <button class="modal-close" onclick="closeRestoreModal()" aria-label="Close"><?php echo icon('close'); ?></button>
    </div>
    <div class="modal-body">
      <div class="form-alert" id="restoreAlert" role="alert"></div>

      <p class="restore-warning">
        <?php echo icon('alert-triangle', 'icon-sm'); ?>
        This replaces every table — bookings, guests, payments, inventory, and feedback — with the contents of the selected backup. This cannot be undone.
      </p>

      <div class="restore-source-toggle">
        <button type="button" id="sourceHistoryBtn" class="is-active" onclick="setRestoreSource('history')">Use a saved backup</button>
        <button type="button" id="sourceUploadBtn" onclick="setRestoreSource('upload')">Upload a file</button>
      </div>

      <div class="form-group" id="restoreHistoryGroup">
        <label class="form-label" for="restoreBackupSelect">Backup to restore</label>
        <select class="form-select" id="restoreBackupSelect">
          <option value="">Select a backup&hellip;</option>
        </select>
      </div>

      <div class="form-group" id="restoreUploadGroup" style="display:none;">
        <label class="form-label" for="restoreFileInput">.sql backup file</label>
        <input class="form-input" type="file" id="restoreFileInput" accept=".sql">
        <p class="form-hint">Max 50MB. Must be a plain .sql dump.</p>
      </div>

      <div class="form-group">
        <label class="form-label" for="restoreConfirmInput">Type <strong>RESTORE</strong> to confirm</label>
        <input class="form-input" type="text" id="restoreConfirmInput" placeholder="RESTORE" autocomplete="off">
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeRestoreModal()">Cancel</button>
      <button class="btn btn-danger" id="restoreConfirmBtn" disabled onclick="submitRestore()">
        <?php echo icon('refresh', 'icon-sm'); ?> Restore Database
      </button>
    </div>
  </div>
</div>

<!-- ===== Delete Confirmation Modal ===== -->
<div class="modal-overlay" id="deleteModal" aria-modal="true" role="dialog">
  <div class="modal modal-confirm">
    <div class="modal-header">
      <div class="modal-title">Delete Backup</div>
      <button class="modal-close" onclick="closeDeleteConfirm()" aria-label="Close"><?php echo icon('close'); ?></button>
    </div>
    <div class="modal-body">
      <p id="deleteText" style="font-size:14px;color:var(--text-secondary);">Delete this backup file? This can&rsquo;t be undone.</p>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeDeleteConfirm()">Cancel</button>
      <button class="btn btn-danger" id="deleteConfirmBtn" onclick="confirmDelete()">
        <?php echo icon('trash', 'icon-sm'); ?> Delete
      </button>
    </div>
  </div>
</div>

<!-- ===== Toast ===== -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ============================================================
   Backup & Restore page JS
   ============================================================ */
var ROWS = <?php echo $rowsJson; ?>;
var CSRF = <?php echo json_encode($csrf); ?>;
var rowMap = {};
ROWS.forEach(function (r) { rowMap[r.id] = r; });

var deletingId = null;

function toast(msg, type) {
    var wrap = document.getElementById('toastWrap');
    var el   = document.createElement('div');
    el.className = 'toast toast--' + (type || 'success');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 20);
    setTimeout(function () {
        el.classList.remove('show');
        setTimeout(function () { try { wrap.removeChild(el); } catch (e) {} }, 400);
    }, 3500);
}

function showAlert(id, msg) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.classList.add('is-visible');
}
function hideAlert(id) {
    document.getElementById(id).classList.remove('is-visible');
}

/* ---------- Create Backup ---------- */
document.getElementById('createBackupBtn').addEventListener('click', function () {
    var btn = document.getElementById('createBackupBtn');
    var label = document.getElementById('createBackupLabel');
    btn.disabled = true;
    label.textContent = 'Creating…';
    btn.querySelector('.icon-sm').classList.add('spin');

    var fd = new FormData();
    fd.append('action', 'create');
    fd.append('csrf_token', CSRF);

    fetch('api/backup.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                toast('Backup created successfully.', 'success');
                setTimeout(function () { window.location.reload(); }, 700);
            } else {
                btn.disabled = false;
                label.textContent = 'Create Backup';
                btn.querySelector('.icon-sm').classList.remove('spin');
                toast(data.error || 'Could not create backup.', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            label.textContent = 'Create Backup';
            btn.querySelector('.icon-sm').classList.remove('spin');
            toast('Network error. Please try again.', 'error');
        });
});

/* ---------- Delete ---------- */
function openDeleteConfirm(id) {
    deletingId = id;
    var row = rowMap[id];
    document.getElementById('deleteText').textContent = row
        ? 'Delete "' + row.filename + '"? This can\u2019t be undone.'
        : 'Delete this backup file? This can\u2019t be undone.';
    document.getElementById('deleteModal').classList.add('is-open');
}
function closeDeleteConfirm() {
    document.getElementById('deleteModal').classList.remove('is-open');
    deletingId = null;
}
function confirmDelete() {
    if (!deletingId) return;
    var btn = document.getElementById('deleteConfirmBtn');
    btn.disabled = true;

    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', CSRF);
    fd.append('id', deletingId);

    fetch('api/backup.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            if (data.success) {
                toast('Backup deleted.', 'success');
                closeDeleteConfirm();
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                toast(data.error || 'Failed to delete backup.', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            toast('Network error. Please try again.', 'error');
        });
}

/* ---------- Restore ---------- */
var restoreSource = 'history';

function populateRestoreSelect(preselectId) {
    var select = document.getElementById('restoreBackupSelect');
    select.innerHTML = '<option value="">Select a backup&hellip;</option>';
    ROWS.forEach(function (r) {
        var opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = r.filename + ' — ' + r.created_at;
        if (preselectId && Number(preselectId) === r.id) opt.selected = true;
        select.appendChild(opt);
    });
}

function setRestoreSource(source) {
    restoreSource = source;
    document.getElementById('sourceHistoryBtn').classList.toggle('is-active', source === 'history');
    document.getElementById('sourceUploadBtn').classList.toggle('is-active', source === 'upload');
    document.getElementById('restoreHistoryGroup').style.display = source === 'history' ? '' : 'none';
    document.getElementById('restoreUploadGroup').style.display = source === 'upload' ? '' : 'none';
}

function openRestoreModal(preselectId) {
    hideAlert('restoreAlert');
    populateRestoreSelect(preselectId);
    setRestoreSource('history');
    document.getElementById('restoreFileInput').value = '';
    document.getElementById('restoreConfirmInput').value = '';
    document.getElementById('restoreConfirmBtn').disabled = true;
    document.getElementById('restoreModal').classList.add('is-open');
}
function closeRestoreModal() {
    document.getElementById('restoreModal').classList.remove('is-open');
}

document.getElementById('openRestoreBtn').addEventListener('click', function () { openRestoreModal(null); });

document.getElementById('restoreConfirmInput').addEventListener('input', function () {
    document.getElementById('restoreConfirmBtn').disabled = this.value.trim() !== 'RESTORE';
});

function submitRestore() {
    hideAlert('restoreAlert');

    var confirmText = document.getElementById('restoreConfirmInput').value.trim();
    if (confirmText !== 'RESTORE') {
        showAlert('restoreAlert', 'Please type RESTORE to confirm.');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'restore');
    fd.append('csrf_token', CSRF);
    fd.append('confirm', confirmText);

    if (restoreSource === 'history') {
        var backupId = document.getElementById('restoreBackupSelect').value;
        if (!backupId) {
            showAlert('restoreAlert', 'Please select a backup to restore from.');
            return;
        }
        fd.append('backup_id', backupId);
    } else {
        var fileInput = document.getElementById('restoreFileInput');
        if (!fileInput.files || !fileInput.files.length) {
            showAlert('restoreAlert', 'Please choose a .sql file to upload.');
            return;
        }
        fd.append('restore_file', fileInput.files[0]);
    }

    var btn = document.getElementById('restoreConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Restoring…';

    fetch('api/backup.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                toast('Database restored successfully.', 'success');
                closeRestoreModal();
                setTimeout(function () { window.location.reload(); }, 700);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<?php echo icon('refresh', 'icon-sm'); ?> Restore Database';
                showAlert('restoreAlert', data.error || 'Restore failed. Please try again.');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<?php echo icon('refresh', 'icon-sm'); ?> Restore Database';
            showAlert('restoreAlert', 'Network error. Please try again.');
        });
}
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
