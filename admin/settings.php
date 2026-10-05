<?php
/**
 * admin/settings.php
 * -----------------------------------------------------------------------
 * Live Settings page: property/contact details shown across the system,
 * the signed-in admin's own account (name, email, password), and
 * notification preferences. All three panels save independently via
 * fetch to api/settings.php — no full-page reloads.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$pdo  = bienmarko_db();
$csrf = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Ensure the settings table exists (additive, self-guarding — same
   approach admin/expenses.php uses for its own table).
------------------------------------------------------------------ */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `system_settings` (
        `id`                     TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
        `property_name`          VARCHAR(150) NOT NULL DEFAULT '',
        `contact_email`          VARCHAR(190) NOT NULL DEFAULT '',
        `contact_number`         VARCHAR(20)  NOT NULL DEFAULT '',
        `address`                VARCHAR(255) NOT NULL DEFAULT '',
        `notify_new_booking`     TINYINT(1) NOT NULL DEFAULT 1,
        `notify_payment`         TINYINT(1) NOT NULL DEFAULT 1,
        `notify_low_inventory`   TINYINT(1) NOT NULL DEFAULT 0,
        `notify_guest_feedback`  TINYINT(1) NOT NULL DEFAULT 0,
        `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
$pdo->exec('INSERT IGNORE INTO `system_settings` (`id`) VALUES (1)');

$settings = $pdo->query('SELECT * FROM system_settings WHERE id = 1')->fetch();
if (!$settings) {
    $settings = [
        'property_name' => '', 'contact_email' => '', 'contact_number' => '', 'address' => '',
        'notify_new_booking' => 1, 'notify_payment' => 1, 'notify_low_inventory' => 0, 'notify_guest_feedback' => 0,
    ];
}

$adminId = (int) ($_SESSION['auth_user_id'] ?? 0);
$adminStmt = $pdo->prepare('SELECT name, email FROM admins WHERE id = :id LIMIT 1');
$adminStmt->execute(['id' => $adminId]);
$admin = $adminStmt->fetch() ?: ['name' => '', 'email' => ''];

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

<style>
/* ============================================================
   Settings — page-scoped styles. Kept local to this page (same
   approach admin/expenses.php and admin/create-admin.php use).
   ============================================================ */
.field input[type="password"] {
    width: 100%;
    background: var(--input-bg);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    padding: 11px 14px;
    color: var(--text);
    font-size: 0.875rem;
    transition: border-color var(--transition);
}
.field input[type="password"]::placeholder { color: var(--text-muted); }
.field input[type="password"]:focus { outline: none; border-color: var(--gold-500); }
.field input[type="password"].has-icon { padding-left: 38px; }

.form-alert {
    padding: 12px 14px;
    border-radius: var(--r-md);
    font-size: 0.875rem;
    margin-bottom: 16px;
    display: none;
}
.form-alert--error   { background: var(--red-bg);     color: var(--red);     border: 1px solid var(--red-bg-strong); }
.form-alert--success { background: var(--emerald-bg); color: var(--emerald); border: 1px solid var(--border); }
.form-alert.is-visible { display: block; }

.panel-title-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; gap: 12px; }
.panel-sub { font-size: 0.8rem; color: var(--text-tertiary); margin-top: 2px; }

.toggle-row.is-saving .toggle-row-label { color: var(--text-muted); }

.toast-wrap { position: fixed; bottom: 28px; right: 28px; z-index: 900; display: flex; flex-direction: column; gap: 10px; }
.toast { padding: 12px 18px; border-radius: var(--r-md); font-size: 13.5px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,.5); transform: translateX(120%); opacity: 0; transition: transform .3s, opacity .3s; }
.toast.show { transform: translateX(0); opacity: 1; }
.toast--success { background: var(--emerald-bg); border: 1px solid var(--emerald); color: var(--emerald); }
.toast--error   { background: var(--red-bg);     border: 1px solid var(--red);     color: var(--red); }

/* Email (Gmail) panel */
.gmail-pill--ok    { color: var(--emerald); border-color: var(--emerald); }
.gmail-pill--bad   { color: var(--red);     border-color: var(--red); }
.gmail-pill--muted { color: var(--text-tertiary); border-color: var(--border); }
.gmail-info { font-size: 0.875rem; color: var(--text-secondary); line-height: 1.5; word-break: break-word; }
.gmail-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.gmail-actions .btn { text-decoration: none; }
.gmail-test-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.gmail-test-row .field { flex: 1 1 220px; }
.gmail-details summary { cursor: pointer; font-size: 0.8rem; color: var(--text-tertiary); }
.gmail-details p { font-size: 0.8rem; color: var(--text-tertiary); line-height: 1.5; margin-top: 8px; word-break: break-word; }
.gmail-details code { font-size: 0.78rem; color: var(--text-secondary); }
.gmail-recent { display: flex; flex-direction: column; border-top: 1px solid var(--border); }
.gmail-recent-row { display: flex; flex-wrap: wrap; gap: 4px 12px; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: 0.8rem; color: var(--text-secondary); }
.gmail-recent-row .when { color: var(--text-tertiary); min-width: 118px; }
.gmail-recent-row .to { flex: 1 1 160px; word-break: break-all; }
.gmail-recent-row .st--sent { color: var(--emerald); }
.gmail-recent-row .st--failed { color: var(--red); }
.gmail-recent-row .st--skipped { color: var(--text-tertiary); }
.gmail-recent-row .err { flex-basis: 100%; color: var(--text-tertiary); }

@media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }
</style>

<div class="stack">

  <div class="grid-2">

    <!-- ===== General Settings ===== -->
    <form class="panel panel-pad" id="generalForm" novalidate>
      <div class="panel-title-row">
        <div>
          <h2 class="panel-title">General Settings</h2>
          <p class="panel-sub">Shown across guest-facing pages and confirmations.</p>
        </div>
      </div>

      <div class="form-alert form-alert--error" id="generalAlert" role="alert"></div>

      <div class="stack" style="gap:16px;">
        <div class="field">
          <label class="field-label" for="property_name">Property Name</label>
          <div class="field-input-wrap">
            <input type="text" id="property_name" name="property_name" maxlength="150" placeholder="e.g. Bien Marko's Pension House"
                   value="<?php echo htmlspecialchars($settings['property_name'], ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="contact_email">Contact Email</label>
          <div class="field-input-wrap">
            <?php echo icon('mail'); ?>
            <input class="has-icon" type="email" id="contact_email" name="contact_email" maxlength="190" placeholder="admin@yourproperty.com"
                   value="<?php echo htmlspecialchars($settings['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="contact_number">Contact Number</label>
          <div class="field-input-wrap">
            <?php echo icon('phone'); ?>
            <input class="has-icon" type="tel" id="contact_number" name="contact_number" maxlength="20" placeholder="+63 900 000 0000"
                   value="<?php echo htmlspecialchars($settings['contact_number'], ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="address">Address</label>
          <div class="field-input-wrap">
            <?php echo icon('map-pin'); ?>
            <input class="has-icon" type="text" id="address" name="address" maxlength="255" placeholder="Property address"
                   value="<?php echo htmlspecialchars($settings['address'], ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary" id="generalSubmitBtn" style="align-self:flex-start; margin-top:4px;">
          <?php echo icon('save', 'icon-sm'); ?> Save Changes
        </button>
      </div>
    </form>

    <!-- ===== Admin Account ===== -->
    <form class="panel panel-pad" id="accountForm" novalidate>
      <div class="panel-title-row">
        <div>
          <h2 class="panel-title">Admin Account</h2>
          <p class="panel-sub">Your own sign-in details.</p>
        </div>
      </div>

      <div class="form-alert form-alert--error" id="accountAlert" role="alert"></div>

      <div class="stack" style="gap:16px;">
        <div class="field">
          <label class="field-label" for="admin_name">Full Name</label>
          <div class="field-input-wrap">
            <?php echo icon('users'); ?>
            <input class="has-icon" type="text" id="admin_name" name="name" maxlength="150" placeholder="Juan dela Cruz"
                   value="<?php echo htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="admin_email">Email</label>
          <div class="field-input-wrap">
            <?php echo icon('mail'); ?>
            <input class="has-icon" type="email" id="admin_email" name="email" maxlength="190" placeholder="name@bienmarko.test"
                   value="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="current_password">Current Password</label>
          <div class="field-input-wrap">
            <?php echo icon('save'); ?>
            <input class="has-icon" type="password" id="current_password" name="current_password" maxlength="200" placeholder="Only needed to change your password" autocomplete="current-password">
          </div>
        </div>

        <div class="acc-field-row" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
          <div class="field">
            <label class="field-label" for="new_password">New Password</label>
            <div class="field-input-wrap">
              <?php echo icon('save'); ?>
              <input class="has-icon" type="password" id="new_password" name="new_password" maxlength="200" placeholder="Leave blank to keep current" autocomplete="new-password">
            </div>
          </div>
          <div class="field">
            <label class="field-label" for="confirm_password">Confirm New Password</label>
            <div class="field-input-wrap">
              <?php echo icon('save'); ?>
              <input class="has-icon" type="password" id="confirm_password" name="confirm_password" maxlength="200" placeholder="Re-type new password" autocomplete="new-password">
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary" id="accountSubmitBtn" style="align-self:flex-start; margin-top:4px;">
          <?php echo icon('save', 'icon-sm'); ?> Save Changes
        </button>
      </div>
    </form>

  </div>

  <!-- ===== Email (Gmail) ===== -->
  <div class="panel panel-pad" id="gmailPanel">
    <div class="panel-title-row">
      <div>
        <h2 class="panel-title">Email (Gmail)</h2>
        <p class="panel-sub">Sends booking confirmations, updates, cancellations and reminders to guests.</p>
      </div>
      <span class="pill gmail-pill--muted" id="gmailPill">Checking&hellip;</span>
    </div>

    <div class="form-alert form-alert--error" id="gmailAlert" role="alert"></div>

    <div class="stack" style="gap:16px;">
      <div class="gmail-info" id="gmailInfo">Loading connection status&hellip;</div>

      <div class="gmail-actions">
        <a class="btn btn-primary" id="gmailConnectBtn" href="../gmail_authorize.php" hidden>
          <?php echo icon('mail', 'icon-sm'); ?> <span id="gmailConnectLabel">Connect Gmail</span>
        </a>
        <button type="button" class="btn btn-secondary" id="gmailDisconnectBtn" hidden>Disconnect</button>
      </div>

      <div class="gmail-test-row" id="gmailTestRow" hidden>
        <div class="field">
          <label class="field-label" for="gmailTestTo">Send a test email to <p style="color: yellow;">Existing Gmail only. Yeah</p></label>
          <div class="field-input-wrap">
            <?php echo icon('mail'); ?>
            <input class="has-icon" type="email" id="gmailTestTo" maxlength="190" placeholder="you@example.com"
                   value="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>
        <button type="button" class="btn btn-secondary" id="gmailTestBtn">
          <?php echo icon('mail', 'icon-sm'); ?> Send test
        </button>
      </div>

      <div class="gmail-recent" id="gmailRecent" hidden></div>

      <details class="gmail-details">
        <summary>Connection details</summary>
        <p>Redirect URI in use: <code id="gmailRedirect">&hellip;</code><br>
           This exact address must be listed under <em>Authorized redirect URIs</em> on the OAuth client in Google Cloud.</p>
        <p>While the Google app is in <em>Testing</em> mode, Google ends the connection every 7 days. Publish the app
           (Google Auth Platform &rarr; Audience &rarr; Publish app) to stop that.</p>
      </details>
    </div>
  </div>

  <!-- ===== Notifications ===== -->
  <div class="panel panel-pad">
    <div class="panel-title-row">
      <div>
        <h2 class="panel-title">Notifications</h2>
        <p class="panel-sub">Choose which admin alerts stay switched on. Saved automatically.</p>
      </div>
    </div>
    <div class="stack" style="gap:4px;">
      <?php
        $toggles = [
            ['key' => 'notify_new_booking',    'label' => 'New booking alerts'],
            ['key' => 'notify_payment',        'label' => 'Payment confirmations'],
            ['key' => 'notify_low_inventory',  'label' => 'Low inventory warnings'],
            ['key' => 'notify_guest_feedback', 'label' => 'Guest feedback notifications'],
        ];
        foreach ($toggles as $t):
      ?>
        <div class="toggle-row" data-toggle-row>
          <label class="toggle-row-label" for="<?php echo $t['key']; ?>"><?php echo htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8'); ?></label>
          <label class="toggle">
            <input type="checkbox" class="toggle-input" id="<?php echo $t['key']; ?>" data-notify-toggle data-key="<?php echo $t['key']; ?>"
                   <?php echo !empty($settings[$t['key']]) ? 'checked' : ''; ?>>
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ===== Toast ===== -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ============================================================
   Settings page JS
   ============================================================ */
var CSRF = <?php echo json_encode($csrf); ?>;

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

function postJSON(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
}

/* ---------- General Settings ---------- */
document.getElementById('generalForm').addEventListener('submit', function (e) {
    e.preventDefault();
    hideAlert('generalAlert');

    var propertyName  = document.getElementById('property_name').value.trim();
    var contactEmail  = document.getElementById('contact_email').value.trim();
    var contactNumber = document.getElementById('contact_number').value.trim();
    var address       = document.getElementById('address').value.trim();

    if (!propertyName)  { showAlert('generalAlert', 'Property name is required.'); return; }
    if (!contactEmail)  { showAlert('generalAlert', 'Contact email is required.'); return; }
    if (!contactNumber) { showAlert('generalAlert', 'Contact number is required.'); return; }
    if (!address)        { showAlert('generalAlert', 'Address is required.'); return; }

    var btn = document.getElementById('generalSubmitBtn');
    btn.disabled = true;

    postJSON('api/settings.php', {
        action: 'save_general',
        csrf_token: CSRF,
        property_name: propertyName,
        contact_email: contactEmail,
        contact_number: contactNumber,
        address: address
    }).then(function (data) {
        btn.disabled = false;
        if (data.success) {
            toast('General settings saved.', 'success');
        } else {
            showAlert('generalAlert', data.error || 'Something went wrong. Please try again.');
        }
    }).catch(function () {
        btn.disabled = false;
        showAlert('generalAlert', 'Network error. Please try again.');
    });
});

/* ---------- Admin Account ---------- */
document.getElementById('accountForm').addEventListener('submit', function (e) {
    e.preventDefault();
    hideAlert('accountAlert');

    var name            = document.getElementById('admin_name').value.trim();
    var email           = document.getElementById('admin_email').value.trim();
    var currentPassword = document.getElementById('current_password').value;
    var newPassword     = document.getElementById('new_password').value;
    var confirmPassword = document.getElementById('confirm_password').value;

    if (!name)  { showAlert('accountAlert', 'Full name is required.'); return; }
    if (!email) { showAlert('accountAlert', 'Email is required.'); return; }
    if ((newPassword || confirmPassword) && newPassword !== confirmPassword) {
        showAlert('accountAlert', "New password and confirm password don't match.");
        return;
    }

    var btn = document.getElementById('accountSubmitBtn');
    btn.disabled = true;

    postJSON('api/settings.php', {
        action: 'update_account',
        csrf_token: CSRF,
        name: name,
        email: email,
        current_password: currentPassword,
        new_password: newPassword,
        confirm_password: confirmPassword
    }).then(function (data) {
        btn.disabled = false;
        if (data.success) {
            toast(data.admin && data.admin.password_changed ? 'Account and password updated.' : 'Account updated.', 'success');
            document.getElementById('current_password').value = '';
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
        } else {
            showAlert('accountAlert', data.error || 'Something went wrong. Please try again.');
        }
    }).catch(function () {
        btn.disabled = false;
        showAlert('accountAlert', 'Network error. Please try again.');
    });
});

/* ---------- Email (Gmail) ---------- */
(function () {
    var pill       = document.getElementById('gmailPill');
    var info       = document.getElementById('gmailInfo');
    var connectBtn = document.getElementById('gmailConnectBtn');
    var connectLbl = document.getElementById('gmailConnectLabel');
    var discBtn    = document.getElementById('gmailDisconnectBtn');
    var testRow    = document.getElementById('gmailTestRow');
    var testBtn    = document.getElementById('gmailTestBtn');
    var testTo     = document.getElementById('gmailTestTo');
    var recentBox  = document.getElementById('gmailRecent');
    var redirectEl = document.getElementById('gmailRedirect');

    function gmailApi(action, extra) {
        var payload = { action: action, csrf_token: CSRF };
        if (extra) { for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { payload[k] = extra[k]; } } }
        return postJSON('api/gmail.php', payload);
    }

    function setPill(text, cls) {
        pill.textContent = text;
        pill.className = 'pill gmail-pill--' + cls;
    }

    function fmtWhen(s) {
        if (!s) { return ''; }
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return String(s); }
        return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function renderRecent(rows) {
        recentBox.innerHTML = '';
        if (!rows || !rows.length) { recentBox.hidden = true; return; }
        rows.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'gmail-recent-row';

            var when = document.createElement('span');
            when.className = 'when';
            when.textContent = fmtWhen(r.created_at);

            var to = document.createElement('span');
            to.className = 'to';
            to.textContent = (r.kind || '') + ' → ' + (r.recipient || '(no address)');

            var st = document.createElement('span');
            st.className = 'st--' + r.status;
            st.textContent = r.status;

            row.appendChild(when);
            row.appendChild(to);
            row.appendChild(st);

            if (r.error) {
                var err = document.createElement('span');
                err.className = 'err';
                err.textContent = r.error;
                row.appendChild(err);
            }
            recentBox.appendChild(row);
        });
        recentBox.hidden = false;
    }

    function render(g, recent) {
        redirectEl.textContent = g.redirect_uri || '(could not be detected — set redirect_uri in includes/gmail-config.php)';
        connectBtn.hidden = true;
        discBtn.hidden = true;
        testRow.hidden = true;

        if (!g.configured) {
            setPill('Not configured', 'muted');
            info.textContent = 'Add the Google Client ID and Client Secret to includes/gmail-config.php, then reload this page.';
        } else if (g.connected) {
            setPill('Connected', 'ok');
            info.textContent = 'Sending as ' + (g.sender_email || 'the connected Gmail account') +
                (g.connected_at ? ' · connected ' + fmtWhen(g.connected_at) : '') + '.';
            connectLbl.textContent = 'Switch account';
            connectBtn.className = 'btn btn-secondary';
            connectBtn.hidden = false;
            discBtn.hidden = false;
            testRow.hidden = false;
        } else if (g.status === 'needs_reauth') {
            setPill('Reconnect needed', 'bad');
            info.textContent = 'Booking emails are paused. ' + (g.last_error || 'The Gmail connection needs to be re-authorized.');
            connectLbl.textContent = 'Reconnect Gmail';
            connectBtn.className = 'btn btn-primary';
            connectBtn.hidden = false;
            discBtn.hidden = false;
        } else {
            setPill('Not connected', 'muted');
            info.textContent = 'Connect the Gmail account that booking emails should be sent from. You only need to do this once.';
            connectLbl.textContent = 'Connect Gmail';
            connectBtn.className = 'btn btn-primary';
            connectBtn.hidden = false;
        }
        renderRecent(recent);
    }

    function load() {
        return gmailApi('status').then(function (data) {
            if (data.success) { render(data.gmail, data.recent); }
            else { setPill('Error', 'bad'); info.textContent = data.error || 'Could not load the Gmail status.'; }
        }).catch(function () {
            setPill('Error', 'bad');
            info.textContent = 'Network error while loading the Gmail status.';
        });
    }

    testBtn.addEventListener('click', function () {
        hideAlert('gmailAlert');
        var to = testTo.value.trim();
        if (!to) { showAlert('gmailAlert', 'Enter an email address to send the test to.'); return; }
        testBtn.disabled = true;
        gmailApi('send_test', { to: to }).then(function (data) {
            testBtn.disabled = false;
            if (data.success) { toast('Test email sent to ' + data.sent_to + '.', 'success'); }
            else { showAlert('gmailAlert', data.error || 'The test email could not be sent.'); }
            load();
        }).catch(function () {
            testBtn.disabled = false;
            showAlert('gmailAlert', 'Network error. Please try again.');
        });
    });

    discBtn.addEventListener('click', function () {
        if (!window.confirm('Disconnect Gmail? Booking emails will stop until you connect again.')) { return; }
        hideAlert('gmailAlert');
        discBtn.disabled = true;
        gmailApi('disconnect').then(function (data) {
            discBtn.disabled = false;
            if (data.success) { toast('Gmail disconnected.', 'success'); }
            else { showAlert('gmailAlert', data.error || 'Could not disconnect Gmail.'); }
            load();
        }).catch(function () {
            discBtn.disabled = false;
            showAlert('gmailAlert', 'Network error. Please try again.');
        });
    });

    // Result of the Google round-trip (set by gmail_callback.php / gmail_authorize.php).
    try {
        var qs = new URLSearchParams(window.location.search);
        if (qs.get('gmail') === 'connected') { toast('Gmail connected. Booking emails are now active.', 'success'); }
        if (qs.get('gmail_error')) { showAlert('gmailAlert', qs.get('gmail_error')); }
        if (qs.has('gmail') || qs.has('gmail_error')) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    } catch (e) { /* very old browser: just skip the toast */ }

    load();
})();

/* ---------- Notifications (auto-save on change) ---------- */
document.querySelectorAll('[data-notify-toggle]').forEach(function (input) {
    input.addEventListener('change', function () {
        var row = input.closest('[data-toggle-row]');
        row.classList.add('is-saving');

        var payload = { action: 'save_notifications', csrf_token: CSRF };
        document.querySelectorAll('[data-notify-toggle]').forEach(function (i) {
            payload[i.getAttribute('data-key')] = i.checked;
        });

        postJSON('api/settings.php', payload).then(function (data) {
            row.classList.remove('is-saving');
            if (data.success) {
                toast('Notification preferences saved.', 'success');
            } else {
                input.checked = !input.checked; // revert on failure
                toast(data.error || 'Could not save preference.', 'error');
            }
        }).catch(function () {
            row.classList.remove('is-saving');
            input.checked = !input.checked;
            toast('Network error. Please try again.', 'error');
        });
    });
});
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
