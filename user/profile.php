<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = ''; // Reached via the topbar avatar or a Quick Action, not a bottom-nav tab.
$pageTitle   = 'Edit Profile';

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
$pdo     = bienmarko_db();

// Additive migration — safe to run on every request.
$pdo->exec("ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `avatar_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `phone`");

$stmt = $pdo->prepare(
    'SELECT first_name, last_name, email, phone, avatar_filename
     FROM guests WHERE id = :id LIMIT 1'
);
$stmt->execute(['id' => $guestId]);
$guest = $stmt->fetch() ?: [
    'first_name'      => '',
    'last_name'       => '',
    'email'           => '',
    'phone'           => '',
    'avatar_filename' => null,
];

$avatarUrl = $guest['avatar_filename']
    ? '../admin/assets/uploads/avatars/' . rawurlencode($guest['avatar_filename'])
    : null;

$initials = strtoupper(substr($guest['first_name'], 0, 1) . substr($guest['last_name'], 0, 1));

$csrfToken = bienmarko_csrf_token();

require __DIR__ . '/includes/head.php';
?>
<style>
/* ============================================================
   user/profile.php — page-scoped styles
   ============================================================ */
.avatar-upload { display: flex; align-items: center; gap: 18px; margin-bottom: 8px; }
.avatar-upload__photo {
  width: 72px;
  height: 72px;
  border-radius: var(--radius-full);
  background: var(--surface-raised);
  border: 1px solid var(--line);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex: none;
}
.avatar-upload__photo img { width: 100%; height: 100%; object-fit: cover; }
.avatar-upload__initials { font-family: var(--font-display); font-weight: 700; font-size: 24px; color: var(--ember); }
.avatar-upload__actions { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
.avatar-upload__actions .btn { padding: 8px 16px; font-size: 13px; cursor: pointer; }
.avatar-upload__remove {
  background: none;
  border: none;
  padding: 0;
  color: var(--danger);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.avatar-upload__remove[hidden] { display: none; }
.avatar-upload__hint { font-size: 12.5px; color: var(--paper-dim); margin-top: 2px; }
</style>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('Edit Profile', 'Keep your contact information up to date'); ?>
    </div>

    <section class="panel panel--narrow">
        <form class="form" id="profileForm" novalidate enctype="multipart/form-data">
            <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" id="removeAvatarFlag" name="remove_avatar" value="0">

            <div class="form__field">
                <label>Profile photo</label>
                <div class="avatar-upload">
                    <span class="avatar-upload__photo" id="avatarPreview" data-initials="<?php echo htmlspecialchars($initials); ?>">
                        <?php if ($avatarUrl): ?>
                            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="">
                        <?php else: ?>
                            <span class="avatar-upload__initials"><?php echo htmlspecialchars($initials); ?></span>
                        <?php endif; ?>
                    </span>
                    <div class="avatar-upload__actions">
                        <label class="btn btn--ghost" for="avatarInput">
                            <?php render_icon('camera'); ?> Change Photo
                        </label>
                        <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp" hidden>
                        <button type="button" class="avatar-upload__remove" id="removeAvatarBtn" <?php echo $avatarUrl ? '' : 'hidden'; ?>>Remove photo</button>
                    </div>
                </div>
                <p class="avatar-upload__hint">JPG, PNG, or WEBP. Max 5MB.</p>
            </div>

            <div class="form__row">
                <div class="form__field">
                    <label for="firstName">First name</label>
                    <input type="text" id="firstName" name="firstName" placeholder="Enter your first name" value="<?php echo htmlspecialchars($guest['first_name']); ?>" maxlength="100" required>
                </div>
                <div class="form__field">
                    <label for="lastName">Last name</label>
                    <input type="text" id="lastName" name="lastName" placeholder="Enter your last name" value="<?php echo htmlspecialchars($guest['last_name']); ?>" maxlength="100" required>
                </div>
            </div>
            <div class="form__field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="you@example.com" value="<?php echo htmlspecialchars($guest['email']); ?>" required>
            </div>
            <div class="form__field">
                <label for="phone">Phone number</label>
                <input type="tel" id="phone" name="phone" placeholder="+63 900 000 0000" value="<?php echo htmlspecialchars($guest['phone']); ?>" required>
            </div>

            <button type="submit" class="btn btn--primary">Save Changes</button>
            <p class="form__status" id="profileFormStatus" role="status"></p>
        </form>
    </section>

</main>
<?php require __DIR__ . '/includes/foot.php'; ?>
