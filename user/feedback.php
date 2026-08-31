<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'feedback';
$pageTitle   = 'Feedback';

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
$pdo     = bienmarko_db();

/* ------------------------------------------------------------------
   Ensure feedback table (and its moderation columns) exist — safe on
   every request, a no-op once they're already there. Same pattern as
   dashboard.php's bookings table guard.
------------------------------------------------------------------ */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `feedback` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `guest_id`     INT UNSIGNED NOT NULL,
        `rating`       TINYINT UNSIGNED NOT NULL,
        `category`     ENUM('general','room','service','cleanliness','other') NOT NULL DEFAULT 'general',
        `message`      TEXT NOT NULL,
        `is_read`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_guest`     (`guest_id`),
        KEY `idx_created`   (`created_at`),
        KEY `idx_read`      (`is_read`),
        KEY `idx_published` (`is_published`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_read`");

$stmt = $pdo->prepare(
    'SELECT id, rating, category, message, created_at
     FROM feedback
     WHERE guest_id = :gid
     ORDER BY created_at DESC'
);
$stmt->execute(['gid' => $guestId]);
$pastFeedback = $stmt->fetchAll();

$csrfToken = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Helpers
------------------------------------------------------------------ */
function fb_category_label(string $category): string
{
    $map = [
        'general'     => 'General',
        'room'        => 'Room & Amenities',
        'service'     => 'Staff & Service',
        'cleanliness' => 'Cleanliness',
        'other'       => 'Other',
    ];
    return $map[$category] ?? 'General';
}

function fb_stars_html(int $rating): string
{
    $html = '<span class="fb-stars" aria-label="' . $rating . ' out of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $cls = $i <= $rating ? 'icon fb-star is-filled' : 'icon fb-star';
        $html .= '<img class="' . $cls . '" src="assets/icons/star.svg" alt="">';
    }
    return $html . '</span>';
}

require __DIR__ . '/includes/head.php';
?>
<style>
/* ============================================================
   user/feedback.php — page-scoped styles
   ============================================================ */
.fb-list { display: flex; flex-direction: column; gap: 14px; }
.fb-item {
  border: 1px solid var(--line);
  border-radius: var(--radius-md);
  background: var(--surface-raised);
  padding: 16px;
}
.fb-item__top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
.fb-stars { display: inline-flex; gap: 2px; }
.fb-star { width: 14px; height: 14px; opacity: 0.3; }
.fb-star.is-filled { opacity: 1; }
.fb-item__date { font-size: 12px; color: var(--paper-faint); white-space: nowrap; }
.fb-item__category {
  display: inline-block;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ember);
  background: rgba(232, 163, 61, 0.14);
  border-radius: var(--radius-full);
  padding: 3px 10px;
  margin-bottom: 8px;
}
.fb-item__message { font-size: 14px; color: var(--paper-dim); line-height: 1.5; }
</style>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('Feedback', "We'd love to hear about your stay"); ?>
    </div>

    <div class="content-grid content-grid--stack">
        <section class="panel">
            <div class="panel__head"><h2>Share Your Feedback</h2></div>
            <form class="form" id="feedbackForm" novalidate>
                <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form__field">
                    <label>Your rating</label>
                    <div class="star-rating" id="starRating" role="radiogroup" aria-label="Rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button
                                type="button"
                                class="star-rating__star"
                                data-value="<?php echo $i; ?>"
                                role="radio"
                                aria-checked="false"
                                aria-label="<?php echo $i; ?> star<?php echo $i === 1 ? '' : 's'; ?>"
                            >
                                <?php render_icon('star'); ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating" id="ratingInput" value="0">
                </div>
                <div class="form__field">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="general">General</option>
                        <option value="room">Room &amp; Amenities</option>
                        <option value="service">Staff &amp; Service</option>
                        <option value="cleanliness">Cleanliness</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form__field">
                    <label for="message">Your feedback</label>
                    <textarea id="message" name="message" rows="4" placeholder="Tell us about your experience..." required></textarea>
                </div>
                <button type="submit" class="btn btn--primary">
                    <?php render_icon('send'); ?> Send Feedback
                </button>
                <p class="form__status" id="feedbackFormStatus" role="status"></p>
            </form>
        </section>

        <section class="panel">
            <div class="panel__head"><h2>Your Feedback History</h2></div>
            <div id="feedbackHistoryWrap">
                <?php if (empty($pastFeedback)): ?>
                    <?php render_empty_state(
                        'message-square',
                        'No feedback submitted yet',
                        'Feedback you send will be listed here.'
                    ); ?>
                <?php else: ?>
                    <ul class="fb-list" id="feedbackHistoryList">
                        <?php foreach ($pastFeedback as $fb): ?>
                            <li class="fb-item">
                                <div class="fb-item__top">
                                    <?php echo fb_stars_html((int) $fb['rating']); ?>
                                    <span class="fb-item__date"><?php echo date('M j, Y', strtotime($fb['created_at'])); ?></span>
                                </div>
                                <span class="fb-item__category"><?php echo htmlspecialchars(fb_category_label($fb['category'])); ?></span>
                                <p class="fb-item__message"><?php echo nl2br(htmlspecialchars($fb['message'])); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>

</main>
<?php require __DIR__ . '/includes/foot.php'; ?>
