<?php
/**
 * admin/create-admin.php
 * -----------------------------------------------------------------------
 * Admin-only: create another staff account. Guarded by
 * bienmarko_require_role('admin') — anyone not currently logged in as an
 * admin is bounced to the shared login page before anything else runs.
 *
 * Not listed in includes/nav-items.php (that file is the single source of
 * truth for sidebar links and is pre-existing — out of scope to edit here).
 * The sidebar and every other admin page still work normally from here;
 * this page just won't have its own highlighted link until a line is
 * added there. Direct URL: admin/create-admin.php.
 *
 * Submits to admin/api/create-admin.php as a classic form POST (no fetch,
 * no login.js-style JSON layer) — this is an internal tool used by an
 * already-authenticated admin, so a plain POST/redirect/GET round trip is
 * simpler and just as correct as adding a JS layer would be here.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$csrfToken = bienmarko_csrf_token();

$errorCode = $_GET['error'] ?? '';
$created   = isset($_GET['created']);

$errorMessages = [
    'invalid'   => 'Please fill in every field correctly.',
    'duplicate' => 'An admin account with that email already exists.',
    'mismatch'  => "Password and confirm password don't match.",
    'weak'      => 'Password must be at least 8 characters.',
];
$errorMessage = $errorMessages[$errorCode] ?? ($errorCode !== '' ? 'Something went wrong. Please try again.' : '');

$pdo = bienmarko_db();
$admins = $pdo->query(
    'SELECT a.id, a.name, a.email, a.created_at, c.name AS created_by_name
     FROM admins a
     LEFT JOIN admins c ON c.id = a.created_by
     ORDER BY a.created_at DESC'
)->fetchAll();

$active_page = 'create-admin';
$page_title = 'Create Admin';
$breadcrumb = 'Create Admin';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<style>
  /* Password inputs aren't covered by style.css's .field input[type="..."]
     rule (only text/email/tel are) — matched here rather than editing
     that file. Same values as that rule, just extended to type="password". */
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
    margin-bottom: 18px;
  }
  .form-alert--error { background: var(--red-bg); color: var(--red); border: 1px solid var(--red-bg-strong); }
  .form-alert--success { background: var(--emerald-bg); color: var(--emerald); border: 1px solid var(--border); }

  .create-admin-grid { display: grid; grid-template-columns: minmax(280px, 380px) 1fr; gap: 20px; align-items: start; }
  @media (max-width: 900px) { .create-admin-grid { grid-template-columns: 1fr; } }
</style>

<div class="stack">
  <div class="create-admin-grid">
    <div class="panel panel-pad">
      <div class="panel-header">
        <h2 class="panel-title">New Admin Account</h2>
      </div>

      <?php if ($errorMessage !== ''): ?>
        <div class="form-alert form-alert--error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php elseif ($created): ?>
        <div class="form-alert form-alert--success">New admin account created.</div>
      <?php endif; ?>

      <form method="POST" action="api/create-admin.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="field" style="margin-bottom:16px;">
          <label class="field-label" for="name">Full name</label>
          <div class="field-input-wrap">
            <?php echo icon('users'); ?>
            <input class="has-icon" type="text" id="name" name="name" placeholder="Juan Dela Cruz" required maxlength="150">
          </div>
        </div>

        <div class="field" style="margin-bottom:16px;">
          <label class="field-label" for="email">Email</label>
          <div class="field-input-wrap">
            <?php echo icon('mail'); ?>
            <input class="has-icon" type="email" id="email" name="email" placeholder="name@bienmarko.test" required maxlength="190">
          </div>
        </div>

        <div class="field" style="margin-bottom:16px;">
          <label class="field-label" for="password">Password</label>
          <div class="field-input-wrap">
            <?php echo icon('save'); ?>
            <input class="has-icon" type="password" id="password" name="password" placeholder="At least 8 characters" required minlength="8" maxlength="200">
          </div>
        </div>

        <div class="field" style="margin-bottom:22px;">
          <label class="field-label" for="confirm_password">Confirm password</label>
          <div class="field-input-wrap">
            <?php echo icon('save'); ?>
            <input class="has-icon" type="password" id="confirm_password" name="confirm_password" placeholder="Re-type the password" required minlength="8" maxlength="200">
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
          <?php echo icon('plus', 'icon-sm'); ?>
          <span>Create Admin</span>
        </button>
      </form>
    </div>

    <div class="panel table-wrap">
      <div style="padding:20px 20px 0;">
        <div class="panel-header" style="margin-bottom:16px;">
          <h2 class="panel-title">Existing Admins</h2>
          <span class="pill"><?php echo count($admins); ?> total</span>
        </div>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Added by</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($admins as $row): ?>
              <tr>
                <td style="padding:14px 20px; border-bottom:1px solid var(--border);"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td style="padding:14px 20px; border-bottom:1px solid var(--border); color:var(--text-secondary);"><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td style="padding:14px 20px; border-bottom:1px solid var(--border); color:var(--text-tertiary);"><?php echo htmlspecialchars($row['created_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                <td style="padding:14px 20px; border-bottom:1px solid var(--border); color:var(--text-tertiary); white-space:nowrap;"><?php echo htmlspecialchars(date('M j, Y', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
