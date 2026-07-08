<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'feedback';
$pageTitle   = 'Feedback';

$pastFeedback = []; // e.g. [['date' => 'May 16, 2026', 'rating' => 5, 'message' => '...']]

require __DIR__ . '/includes/head.php';
?>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('Feedback', "We'd love to hear about your stay"); ?>
    </div>

    <div class="content-grid content-grid--stack">
        <section class="panel">
            <div class="panel__head"><h2>Share Your Feedback</h2></div>
            <form class="form" id="feedbackForm" novalidate>
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
            <?php render_empty_state(
                'message-square',
                'No feedback submitted yet',
                'Feedback you send will be listed here.'
            ); ?>
        </section>
    </div>

</main>
<?php require __DIR__ . '/includes/foot.php'; ?>
