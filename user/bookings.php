<?php
require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'bookings';
$pageTitle   = 'My Bookings';

$bookings = []; // e.g. [['reference' => 'BM-1001', 'room' => 'Barkada Room', 'dates' => 'May 14-16, 2026', 'status' => 'Confirmed']]

require __DIR__ . '/includes/head.php';
?>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('My Bookings', 'View your reservations and their status'); ?>
    </div>

    <div class="content-grid content-grid--stack">
        <section class="panel">
            <div class="panel__head"><h2>Your Bookings</h2></div>
            <?php render_empty_state(
                'inbox',
                'No bookings yet',
                'Once you submit a request below, it will appear here with its status.'
            ); ?>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2>Request a Booking</h2>
                <p class="panel__subtitle">Tell us your preferred dates and we'll confirm availability.</p>
            </div>
            <form class="form" id="bookingForm" novalidate>
                <div class="form__row">
                    <div class="form__field">
                        <label for="checkIn">Check-in</label>
                        <input type="date" id="checkIn" name="checkIn" required>
                    </div>
                    <div class="form__field">
                        <label for="checkOut">Check-out</label>
                        <input type="date" id="checkOut" name="checkOut" required>
                    </div>
                </div>
                <div class="form__row">
                    <div class="form__field">
                        <label for="guests">Guests</label>
                        <input type="number" id="guests" name="guests" min="1" max="20" value="1" required>
                    </div>
                    <div class="form__field">
                        <label for="roomPreference">Room preference <span class="form__optional">(optional)</span></label>
                        <input type="text" id="roomPreference" name="roomPreference" placeholder="e.g. Barkada Room">
                    </div>
                </div>
                <div class="form__field">
                    <label for="notes">Notes <span class="form__optional">(optional)</span></label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Anything else we should know?"></textarea>
                </div>
                <button type="submit" class="btn btn--primary">
                    <?php render_icon('send'); ?> Send Request
                </button>
                <p class="form__status" id="bookingFormStatus" role="status"></p>
            </form>
        </section>
    </div>

</main>
<?php require __DIR__ . '/includes/foot.php'; ?>
