<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/booking-stay.php';   // completed = admin confirmed check-out

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
/* Link every new feedback to the booking it is about (one feedback per booking). */
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `booking_id` INT UNSIGNED NULL DEFAULT NULL AFTER `guest_id`");
$pdo->exec("ALTER TABLE `feedback` ADD UNIQUE INDEX IF NOT EXISTS `uq_feedback_booking` (`booking_id`)");
/* Which accommodation the feedback is about (copied from the booking at submit time). */
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_id` INT UNSIGNED NULL DEFAULT NULL AFTER `booking_id`");
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_type` VARCHAR(100) NULL DEFAULT NULL AFTER `accommodation_id`");
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_name` VARCHAR(150) NULL DEFAULT NULL AFTER `accommodation_type`");
/* Set when the guest edits their feedback (NULL = never edited). */
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL DEFAULT NULL AFTER `created_at`");

/* Guests may edit their feedback for this long after sending it (24 hours).
   Keep in sync with FB_EDIT_WINDOW_SECONDS in api/feedback.php. */
$fbEditWindow = 86400;

/* ------------------------------------------------------------------
   Which stays can this guest review right now?
   Only bookings that are COMPLETED and don't have feedback yet.
   Wrapped in try/catch because the bookings/accommodations tables are
   created by other pages — if this page is opened first they may not
   exist yet, which simply means "nothing to review".
------------------------------------------------------------------ */
$reviewableStays = [];
$hasAnyBooking   = false;
try {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.reference, b.check_in, b.check_out,
                a.name AS room_name, a.type AS room_type
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         LEFT JOIN feedback f  ON f.booking_id = b.id
         WHERE b.guest_id = :gid
           AND " . bk_completed_sql($pdo, 'b') . "
           AND f.id IS NULL
         ORDER BY b.check_out DESC, b.id DESC"
    );
    $stmt->execute(['gid' => $guestId]);
    $reviewableStays = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE guest_id = :gid AND status <> 'cancelled'");
    $stmt->execute(['gid' => $guestId]);
    $hasAnyBooking = (int) $stmt->fetchColumn() > 0;
} catch (Throwable $e) {
    $reviewableStays = [];
}
$canGiveFeedback = !empty($reviewableStays);

/* Opened from a reminder (email / bell / dashboard banner): ?booking=ID
   moves that stay to the top so it is the preselected, recommended one. */
$wantedBooking = (int) ($_GET['booking'] ?? 0);
if ($wantedBooking > 0) {
    usort($reviewableStays, static fn($a, $b) => ((int) $b['id'] === $wantedBooking) <=> ((int) $a['id'] === $wantedBooking));
}

try {
    $stmt = $pdo->prepare(
        'SELECT f.id, f.rating, f.category, f.message, f.created_at, f.updated_at,
                TIMESTAMPDIFF(SECOND, f.created_at, NOW()) AS age_seconds,
                COALESCE(a.name, f.accommodation_name) AS room_name,
                COALESCE(a.type, f.accommodation_type) AS room_type
         FROM feedback f
         LEFT JOIN bookings b       ON b.id = f.booking_id
         LEFT JOIN accommodations a ON a.id = COALESCE(f.accommodation_id, b.accommodation_id)
         WHERE f.guest_id = :gid
         ORDER BY f.created_at DESC'
    );
    $stmt->execute(['gid' => $guestId]);
    $pastFeedback = $stmt->fetchAll();
} catch (Throwable $e) {
    $pastFeedback = [];
}

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

/** "23h 41m left" / "12m left" / "less than a minute left" */
function fb_remaining_label(int $seconds): string
{
    if ($seconds >= 3600) {
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm left to edit';
    }
    if ($seconds >= 60) {
        return floor($seconds / 60) . 'm left to edit';
    }
    return 'Less than a minute left to edit';
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
.fb-item__room { font-size: 12px; color: var(--paper-faint); margin-left: 6px; }
.fb-accom {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  border: 1px solid var(--line); border-radius: var(--radius-md);
  background: var(--surface-raised); padding: 10px 12px; font-size: 14px;
}
.fb-accom__type {
  font-weight: 600; color: var(--ember);
  background: rgba(232, 163, 61, 0.14); border-radius: var(--radius-full); padding: 3px 10px;
}
.fb-accom__name { color: var(--paper-dim); }
.fb-prompt {
  border: 1px solid var(--ember);
  background: rgba(232, 163, 61, 0.10);
  border-radius: var(--radius-md);
  padding: 12px 14px;
  margin-bottom: 14px;
  font-size: 14px;
  line-height: 1.5;
  color: var(--paper-dim);
}
.fb-prompt strong { color: var(--ember); }
.fb-locked { text-align: center; padding: 8px 0; }
.fb-locked p { color: var(--paper-dim); font-size: 14px; line-height: 1.5; margin: 6px 0 14px; }

/* ---- Edit (24-hour window) ---- */
.fb-item__edited { color: var(--paper-faint); }
.fb-item__foot {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px; margin-top: 12px;
}
.fb-item__note { font-size: 12px; color: var(--paper-faint); }
.fb-item__note.is-open { color: var(--ember); }
.fb-item .btn--sm { padding: 6px 14px; font-size: 12.5px; }
.fb-item .btn--sm .icon { width: 14px; height: 14px; }
.modal--edit { width: min(92vw, 500px); max-height: 90vh; overflow-y: auto; }
.fb-edit__actions { display: flex; gap: 10px; flex-wrap: wrap; }
.modal__body .fb-edit__hint { font-size: 12.5px; color: var(--paper-faint); margin: 0 0 14px; line-height: 1.5; }
</style>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('Feedback', "We'd love to hear about your stay"); ?>
    </div>

    <div class="content-grid content-grid--stack">
        <section class="panel">
            <div class="panel__head"><h2>Share Your Feedback</h2></div>

            <?php if (!$canGiveFeedback): ?>
                <div class="fb-locked">
                    <?php render_empty_state(
                        'message-square',
                        'Feedback opens after your stay',
                        $hasAnyBooking
                            ? 'You can leave feedback once your booking is completed. We will notify you here when it is ready.'
                            : 'Book a room and complete your stay to share your experience with us.'
                    ); ?>
                    
                </div>
            <?php else: ?>
            <?php $recommended = $reviewableStays[0]; ?>
            <div class="fb-prompt" id="fbPrompt">
                <strong>How was your stay?</strong>
                Your booking <?php echo htmlspecialchars($recommended['reference']); ?>
                (<?php echo htmlspecialchars($recommended['room_name']); ?>,
                <?php echo date('M j', strtotime($recommended['check_in'])); ?>&ndash;<?php echo date('M j, Y', strtotime($recommended['check_out'])); ?>)
                is complete &mdash; we&rsquo;d love your feedback on it.
            </div>
            <form class="form" id="feedbackForm" novalidate>
                <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form__field">
                    <label for="bookingId">Which stay are you reviewing?</label>
                    <select id="bookingId" name="booking_id" required>
                        <?php foreach ($reviewableStays as $i => $stay): ?>
                            <option value="<?php echo (int) $stay['id']; ?>"
                                    data-type="<?php echo htmlspecialchars((string) $stay['room_type']); ?>"
                                    data-name="<?php echo htmlspecialchars((string) $stay['room_name']); ?>"<?php echo $i === 0 ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($stay['room_name']); ?>
                                &mdash; <?php echo date('M j', strtotime($stay['check_in'])); ?>&ndash;<?php echo date('M j, Y', strtotime($stay['check_out'])); ?>
                                (<?php echo htmlspecialchars($stay['reference']); ?>)<?php echo $i === 0 ? ' — recommended' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__field">
                    <label>Accommodation type</label>
                    <div class="fb-accom" id="fbAccom" aria-live="polite">
                        <span class="fb-accom__type" id="fbAccomType"><?php echo htmlspecialchars((string) $reviewableStays[0]['room_type']); ?></span>
                        <span class="fb-accom__name" id="fbAccomName"><?php echo htmlspecialchars((string) $reviewableStays[0]['room_name']); ?></span>
                    </div>
                </div>
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
            <?php endif; ?>
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
                            <?php $fbRemaining = max(0, $fbEditWindow - max(0, (int) $fb['age_seconds'])); ?>
                            <li class="fb-item"
                                data-feedback-id="<?php echo (int) $fb['id']; ?>"
                                data-rating="<?php echo (int) $fb['rating']; ?>"
                                data-category="<?php echo htmlspecialchars((string) $fb['category'], ENT_QUOTES); ?>"
                                data-message="<?php echo htmlspecialchars((string) $fb['message'], ENT_QUOTES); ?>"
                                data-remaining="<?php echo $fbRemaining; ?>">
                                <div class="fb-item__top">
                                    <?php echo fb_stars_html((int) $fb['rating']); ?>
                                    <span class="fb-item__date">
                                        <?php echo date('M j, Y', strtotime($fb['created_at'])); ?>
                                        <span class="fb-item__edited"<?php echo empty($fb['updated_at']) ? ' hidden' : ''; ?>> &middot; Edited</span>
                                    </span>
                                </div>
                                <span class="fb-item__category"><?php echo htmlspecialchars(fb_category_label($fb['category'])); ?></span>
                                <?php if (!empty($fb['room_type']) || !empty($fb['room_name'])): ?>
                                    <span class="fb-item__room">
                                        <?php echo htmlspecialchars(trim(($fb['room_type'] ?? '') . (!empty($fb['room_type']) && !empty($fb['room_name']) ? ' · ' : '') . ($fb['room_name'] ?? ''))); ?>
                                    </span>
                                <?php endif; ?>
                                <p class="fb-item__message"><?php echo nl2br(htmlspecialchars($fb['message'])); ?></p>
                                <div class="fb-item__foot">
                                    <?php if ($fbRemaining > 0): ?>
                                        <button type="button" class="btn btn--ghost btn--sm fb-edit-btn">
                                            <?php render_icon('pencil'); ?> Edit
                                        </button>
                                        <span class="fb-item__note is-open"><?php echo htmlspecialchars(fb_remaining_label($fbRemaining)); ?></span>
                                    <?php else: ?>
                                        <span class="fb-item__note">Editing closed &mdash; feedback can only be edited within 24 hours of sending.</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>

</main>

<?php if (!empty($pastFeedback)): ?>
<dialog id="fbEditModal" class="modal modal--edit" aria-labelledby="fbEditTitle">
    <div class="modal__head">
        <h2 id="fbEditTitle">Edit your feedback</h2>
        <button type="button" class="modal__close" id="fbEditClose" aria-label="Close">
            <?php render_icon('x'); ?>
        </button>
    </div>
    <div class="modal__body">
        <p class="fb-edit__hint">You can edit your feedback within 24 hours of sending it. Edited feedback is reviewed again before it is shown publicly.</p>
        <form class="form" id="fbEditForm" novalidate>
            <input type="hidden" id="fbEditCsrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" id="fbEditId" value="0">
            <div class="form__field">
                <label>Your rating</label>
                <div class="star-rating" id="fbEditStars" role="radiogroup" aria-label="Rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="star-rating__star" data-value="<?php echo $i; ?>"
                                role="radio" aria-checked="false"
                                aria-label="<?php echo $i; ?> star<?php echo $i === 1 ? '' : 's'; ?>">
                            <?php render_icon('star'); ?>
                        </button>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="form__field">
                <label for="fbEditCategory">Category</label>
                <select id="fbEditCategory">
                    <option value="general">General</option>
                    <option value="room">Room &amp; Amenities</option>
                    <option value="service">Staff &amp; Service</option>
                    <option value="cleanliness">Cleanliness</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form__field">
                <label for="fbEditMessage">Your feedback</label>
                <textarea id="fbEditMessage" rows="5" maxlength="2000" required></textarea>
            </div>
            <div class="fb-edit__actions">
                <button type="submit" class="btn btn--primary" id="fbEditSave"><?php render_icon('send'); ?> Save changes</button>
                <button type="button" class="btn btn--ghost" id="fbEditCancel">Cancel</button>
            </div>
            <p class="form__status" id="fbEditStatus" role="status"></p>
        </form>
    </div>
</dialog>

<script>
/* 24-hour edit window for sent feedback. The server is the real gatekeeper
   (api/feedback.php, action "update"); this script only mirrors it so the
   Edit button disappears the moment the window closes. */
(function () {
  var list = document.getElementById('feedbackHistoryList');
  var modal = document.getElementById('fbEditModal');
  var form = document.getElementById('fbEditForm');
  if (!list || !modal || !form) return;

  var CATEGORY_LABELS = {
    general: 'General', room: 'Room & Amenities', service: 'Staff & Service',
    cleanliness: 'Cleanliness', other: 'Other'
  };
  var idEl = document.getElementById('fbEditId');
  var csrfEl = document.getElementById('fbEditCsrf');
  var catEl = document.getElementById('fbEditCategory');
  var msgEl = document.getElementById('fbEditMessage');
  var statusEl = document.getElementById('fbEditStatus');
  var saveBtn = document.getElementById('fbEditSave');
  var stars = document.querySelectorAll('#fbEditStars .star-rating__star');
  var rating = 0;
  var activeItem = null;
  var loadedAt = Date.now();

  /* ---- helpers ---- */
  function setRating(value) {
    rating = value;
    stars.forEach(function (star) {
      var v = Number(star.getAttribute('data-value'));
      star.classList.toggle('is-active', v <= value);
      star.setAttribute('aria-checked', String(v === value));
    });
  }

  function showStatus(message, isError) {
    statusEl.textContent = message;
    statusEl.classList.toggle('is-error', !!isError);
    statusEl.classList.add('is-visible');
  }

  function clearStatus() {
    statusEl.textContent = '';
    statusEl.classList.remove('is-visible', 'is-error');
  }

  function starsHtml(value) {
    var html = '<span class="fb-stars" aria-label="' + value + ' out of 5 stars">';
    for (var i = 1; i <= 5; i++) {
      html += '<img class="icon fb-star' + (i <= value ? ' is-filled' : '') + '" src="assets/icons/star.svg" alt="">';
    }
    return html + '</span>';
  }

  function remainingLabel(seconds) {
    if (seconds >= 3600) {
      return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm left to edit';
    }
    if (seconds >= 60) return Math.floor(seconds / 60) + 'm left to edit';
    return 'Less than a minute left to edit';
  }

  /* Seconds left, counted from when the page loaded (immune to the
     visitor's clock being wrong — only elapsed time is used). */
  function secondsLeft(item) {
    var base = Number(item.getAttribute('data-remaining')) || 0;
    return base - Math.floor((Date.now() - loadedAt) / 1000);
  }

  function closeEditing(item) {
    var btn = item.querySelector('.fb-edit-btn');
    if (btn) btn.remove();
    var note = item.querySelector('.fb-item__note');
    if (note) {
      note.classList.remove('is-open');
      note.textContent = 'Editing closed \u2014 feedback can only be edited within 24 hours of sending.';
    }
    item.setAttribute('data-remaining', '0');
  }

  function refreshItems() {
    var items = list.querySelectorAll('.fb-item');
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      if (!item.querySelector('.fb-edit-btn')) continue;
      var left = secondsLeft(item);
      if (left <= 0) {
        closeEditing(item);
        if (activeItem === item && modal.open) {
          showStatus('The 24-hour edit window for this feedback has just closed.', true);
          saveBtn.disabled = true;
        }
      } else {
        var note = item.querySelector('.fb-item__note');
        if (note) note.textContent = remainingLabel(left);
      }
    }
  }

  function setMessageText(el, text) {
    el.textContent = '';
    var lines = String(text).split(/\r?\n/);
    lines.forEach(function (line, i) {
      if (i > 0) el.appendChild(document.createElement('br'));
      el.appendChild(document.createTextNode(line));
    });
  }

  function closeModal() {
    if (modal.close) modal.close(); else modal.removeAttribute('open');
  }

  /* ---- open the editor ---- */
  list.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.fb-edit-btn') : null;
    if (!btn) return;
    var item = btn.closest('.fb-item');
    if (!item) return;
    if (secondsLeft(item) <= 0) { refreshItems(); return; }

    activeItem = item;
    idEl.value = item.getAttribute('data-feedback-id');
    setRating(Number(item.getAttribute('data-rating')) || 0);
    catEl.value = item.getAttribute('data-category') || 'general';
    msgEl.value = item.getAttribute('data-message') || '';
    saveBtn.disabled = false;
    clearStatus();

    if (typeof modal.showModal === 'function') modal.showModal();
    else modal.setAttribute('open', '');
    msgEl.focus();
  });

  stars.forEach(function (star) {
    star.addEventListener('click', function () {
      setRating(Number(star.getAttribute('data-value')));
    });
  });

  document.getElementById('fbEditClose').addEventListener('click', closeModal);
  document.getElementById('fbEditCancel').addEventListener('click', closeModal);

  /* ---- save ---- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!activeItem) return;

    if (!rating) { showStatus('Please choose a star rating.', true); return; }
    var message = msgEl.value.trim();
    if (!message) { showStatus('Please share a few words about your stay.', true); return; }

    saveBtn.disabled = true;
    showStatus('Saving\u2026', false);

    fetch('api/feedback.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'update',
        feedback_id: Number(idEl.value),
        rating: rating,
        category: catEl.value,
        message: message,
        csrf_token: csrfEl.value
      })
    })
      .then(function (res) {
        return res.json().then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      })
      .then(function (result) {
        if (!result.ok || !result.data.success) {
          if (result.status === 403 && activeItem) {
            /* Window closed on the server — lock the item locally too. */
            closeEditing(activeItem);
          }
          throw new Error((result.data && result.data.message) || 'Could not save your changes. Please try again.');
        }

        var fb = result.data.feedback;
        var item = activeItem;
        item.setAttribute('data-rating', String(fb.rating));
        item.setAttribute('data-category', fb.category);
        item.setAttribute('data-message', fb.message);
        item.setAttribute('data-remaining', String(fb.seconds_left));
        loadedAt = Date.now();

        var oldStars = item.querySelector('.fb-stars');
        if (oldStars) oldStars.outerHTML = starsHtml(fb.rating);
        var cat = item.querySelector('.fb-item__category');
        if (cat) cat.textContent = CATEGORY_LABELS[fb.category] || 'General';
        var msg = item.querySelector('.fb-item__message');
        if (msg) setMessageText(msg, fb.message);
        var edited = item.querySelector('.fb-item__edited');
        if (edited) edited.hidden = false;

        refreshItems();
        showStatus('Changes saved.', false);
        window.setTimeout(closeModal, 600);
      })
      .catch(function (err) {
        showStatus(err.message, true);
      })
      .finally(function () {
        if (!activeItem || secondsLeft(activeItem) > 0) saveBtn.disabled = false;
      });
  });

  /* Count down + lock buttons the moment a window closes. */
  window.setInterval(refreshItems, 15000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refreshItems();
  });
})();
</script>
<?php endif; ?>

<?php if ($canGiveFeedback): ?>
<script>
(function () {
  var sel = document.getElementById('bookingId');
  var typeEl = document.getElementById('fbAccomType');
  var nameEl = document.getElementById('fbAccomName');
  if (!sel || !typeEl || !nameEl) return;
  function sync() {
    var opt = sel.options[sel.selectedIndex];
    typeEl.textContent = (opt && opt.getAttribute('data-type')) || '';
    nameEl.textContent = (opt && opt.getAttribute('data-name')) || '';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/foot.php'; ?>