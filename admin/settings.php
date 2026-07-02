<?php
$active_page = 'settings';
$page_title = 'Settings';
$breadcrumb = 'Settings';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="grid-2">

  <form class="panel panel-pad" method="post" action="">
    <h2 class="panel-title" style="margin-bottom:18px;">General Settings</h2>

    <div class="stack" style="gap:16px;">
      <div class="field">
        <label class="field-label" for="property_name">Property Name</label>
        <div class="field-input-wrap">
          <input type="text" id="property_name" name="property_name" placeholder="e.g. Sunset Cove Resort">
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="contact_email">Contact Email</label>
        <div class="field-input-wrap">
          <?php echo icon('mail'); ?>
          <input class="has-icon" type="email" id="contact_email" name="contact_email" placeholder="admin@yourproperty.com">
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="contact_number">Contact Number</label>
        <div class="field-input-wrap">
          <?php echo icon('phone'); ?>
          <input class="has-icon" type="tel" id="contact_number" name="contact_number" placeholder="+63 900 000 0000">
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="address">Address</label>
        <div class="field-input-wrap">
          <?php echo icon('map-pin'); ?>
          <input class="has-icon" type="text" id="address" name="address" placeholder="Property address">
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="align-self:flex-start; margin-top:4px;">
        <?php echo icon('save', 'icon-sm'); ?> Save Changes
      </button>
    </div>
  </form>

  <div class="panel panel-pad">
    <h2 class="panel-title" style="margin-bottom:18px;">Notifications</h2>
    <div class="stack" style="gap:4px;">
      <?php
        $toggles = [
            ['label' => 'New booking alerts', 'checked' => true],
            ['label' => 'Payment confirmations', 'checked' => true],
            ['label' => 'Low inventory warnings', 'checked' => false],
            ['label' => 'Guest feedback notifications', 'checked' => false],
        ];
        foreach ($toggles as $i => $t):
            $id = 'toggle_' . $i;
      ?>
        <div class="toggle-row">
          <label class="toggle-row-label" for="<?php echo $id; ?>"><?php echo htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8'); ?></label>
          <label class="toggle">
            <input type="checkbox" class="toggle-input" id="<?php echo $id; ?>" name="<?php echo $id; ?>" <?php echo $t['checked'] ? 'checked' : ''; ?>>
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
