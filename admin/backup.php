<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$active_page = 'backup';
$page_title = 'Backup & Restore';
$breadcrumb = 'Backup';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="stack">
  <div class="panel panel-pad backup-header">
    <div>
      <h2 class="panel-title" style="margin-bottom:4px;">Backup &amp; Restore</h2>
      <p class="backup-meta">Last backup: <strong>Never</strong></p>
    </div>
    <div class="backup-actions">
      <button type="button" class="btn btn-secondary">
        <?php echo icon('upload', 'icon-sm'); ?> Restore
      </button>
      <button type="button" class="btn btn-primary">
        <?php echo icon('download', 'icon-sm'); ?> Create Backup
      </button>
    </div>
  </div>

  <?php
    render_empty_panel(
        'database',
        'No backups yet',
        'Your backup history will appear here once you create one.'
    );
  ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
