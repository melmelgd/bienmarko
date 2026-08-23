<?php
/**
 * user/guest-book.php
 * -----------------------------------------------------------------------
 * Standalone booking page for guests who do not have an account.
 * No login or session is required to access this page.
 *
 * Query-string parameters forwarded from the landing page:
 *   ?room=<name>        pre-selects the accommodation
 *   ?checkin=YYYY-MM-DD pre-fills check-in date
 *   ?checkout=YYYY-MM-DD pre-fills check-out date
 *
 * The form posts to user/api/guest-book.php which handles availability
 * checking, booking insertion, admin notification, and returns a JSON
 * response.  On success this page shows a confirmation overlay with a
 * reference number — no redirect, no account required.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();

// Pull query params (sanitised — only used for HTML attribute values)
$preRoom     = trim(strip_tags((string) ($_GET['room']     ?? '')));
$preCheckin  = trim(strip_tags((string) ($_GET['checkin']  ?? '')));
$preCheckout = trim(strip_tags((string) ($_GET['checkout'] ?? '')));

// Validate date format so we never emit garbage into value=
$dateRx = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRx, $preCheckin))  $preCheckin  = '';
if (!preg_match($dateRx, $preCheckout)) $preCheckout = '';

// Today's date — used as min for date inputs
$today = date('Y-m-d');

// Load all accommodations for the dropdown (same endpoint logic, inline)
$rooms = [];
try {
    $pdo = bienmarko_db();

    // Defensive: make sure tour_filename exists even if no admin has
    // opened admin/accommodations.php yet since this feature was added.
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $colCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'accommodations' AND COLUMN_NAME = 'tour_filename'"
        );
        $colCheck->execute(['db' => $dbName]);
        if ((int) $colCheck->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `accommodations` ADD COLUMN `tour_filename` VARCHAR(255) NULL AFTER `image_filename`");
        }
    } catch (Throwable $e) {
        // best-effort; SELECT below still works, tour_filename just won't be present
    }

    $stmt = $pdo->query(
        "SELECT `id`, `name`, `type`, `capacity`, `price`, `status`, `tour_filename`, `image_filename`
         FROM `accommodations`
         ORDER BY `name` ASC"
    );
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // DB unavailable — rooms array stays empty; form validation will catch it
}

/**
 * Gallery photos per room, keyed by accommodation id — cover photo first
 * (if set), then extra gallery photos, de-duplicated. Same combine logic
 * as bk_combine_photos() in user/api/bookings.php, duplicated here per
 * this project's per-portal convention (bk_* vs gb_* helpers) rather
 * than shared across portals.
 */
$photosByRoom = [];
if ($rooms) {
    try {
        // Defensive: make sure the gallery table exists even if no admin
        // has used "Manage Photos" yet since this feature was added.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `accommodation_photos` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accommodation_id` INT UNSIGNED NOT NULL,
                `filename`         VARCHAR(255) NOT NULL,
                `sort_order`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_accommodation` (`accommodation_id`),
                CONSTRAINT `fk_acc_photos_accommodation_gb` FOREIGN KEY (`accommodation_id`)
                    REFERENCES `accommodations` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        foreach ($rooms as $r) {
            $photosByRoom[(int) $r['id']] = $r['image_filename'] ? [$r['image_filename']] : [];
        }

        $roomIds      = array_map(static function ($r) { return (int) $r['id']; }, $rooms);
        $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
        $photoStmt    = $pdo->prepare(
            "SELECT `accommodation_id`, `filename`
             FROM `accommodation_photos`
             WHERE `accommodation_id` IN ($placeholders)
             ORDER BY `sort_order` ASC, `id` ASC"
        );
        $photoStmt->execute($roomIds);
        foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $accId = (int) $p['accommodation_id'];
            if (!isset($photosByRoom[$accId])) {
                $photosByRoom[$accId] = [];
            }
            if (!in_array($p['filename'], $photosByRoom[$accId], true)) {
                $photosByRoom[$accId][] = $p['filename'];
            }
        }
    } catch (Throwable $e) {
        // best-effort; the "View Photos" button simply won't show for any room
        // whose photos couldn't be loaded
    }
}

// CSRF token for the form
$csrf = bienmarko_csrf_token();

// Escape helper
function gb_e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Guest Booking — Bien Marko's Pension House</title>
<meta name="description" content="Book a room at Bien Marko's Pension House without creating an account.">
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Great+Vibes&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/guest-book.css">
</head>
<body>

<!-- ═══ HEADER ═══════════════════════════════════════════════════════ -->
<header class="gb-header">
    <a class="gb-brand" href="../index.html" aria-label="Back to Bien Marko's Pension House homepage">
        <span>Bien Marko's</span>
        <strong>Pension House</strong>
    </a>
    <nav class="gb-header-nav">
        <a href="login.php">Log in</a>
        <a class="gb-btn gb-btn--gold gb-btn--sm" href="register.php">Create Account</a>
    </nav>
</header>

<main class="gb-main">

    <!-- ═══ BREADCRUMB ════════════════════════════════════════════════ -->
    <nav class="gb-breadcrumb" aria-label="Breadcrumb">
        <a href="../index.html">Home</a>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
        <span>Guest Booking</span>
    </nav>

    <!-- ═══ PAGE TITLE ════════════════════════════════════════════════ -->
    <div class="gb-page-head">
        <p class="gb-eyebrow">No account needed</p>
        <h1>Guest Booking</h1>
        <p class="gb-page-sub">Fill in your details below and we will confirm your reservation by email or phone. You can <a href="register.php">create a free account</a> to manage bookings, view history, and leave feedback.</p>
    </div>

    <!-- ═══ ACCOUNT NUDGE BANNER ══════════════════════════════════════ -->
    <div class="gb-nudge" role="note">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v4m0 4h.01"></path></svg>
        <span>Guest bookings <strong>cannot be modified or cancelled online</strong>. For changes, contact us directly. <a href="register.php">Create an account</a> for full self-service access.</span>
    </div>

    <!-- ═══ BOOKING FORM ══════════════════════════════════════════════ -->
    <form class="gb-form" id="guestBookForm" novalidate autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= gb_e($csrf) ?>">

        <!-- ── Section 1: Stay Details ─────────────────────────────── -->
        <section class="gb-section">
            <h2 class="gb-section-title">
                <span class="gb-section-num">1</span>
                Stay Details
            </h2>

            <div class="gb-row gb-row--3">
                <!-- Accommodation -->
                <div class="gb-field gb-field--full">
                    <label class="gb-label" for="gb_room">Accommodation <span class="gb-required" aria-hidden="true">*</span></label>
                    <div class="gb-select-wrap">
                        <select class="gb-input" id="gb_room" name="room_id" required aria-required="true" aria-describedby="gb_room_err">
                            <option value="">— Select a room —</option>
                            <?php foreach ($rooms as $room): ?>
                                <?php
                                $disabled = ($room['status'] === 'unavailable' || $room['status'] === 'maintenance');
                                $label    = gb_e($room['name']);
                                if ($room['status'] === 'maintenance') $label .= ' (Under Maintenance)';
                                if ($room['status'] === 'unavailable') $label .= ' (Unavailable)';
                                $selected = ($preRoom !== '' && $preRoom === $room['name']) ? 'selected' : '';
                                ?>
                                <option value="<?= (int) $room['id'] ?>"
                                    <?= $selected ?>
                                    <?= $disabled ? 'disabled' : '' ?>
                                    data-capacity="<?= (int) $room['capacity'] ?>"
                                    data-price="<?= gb_e($room['price']) ?>"
                                    data-type="<?= gb_e($room['type']) ?>"
                                    data-tour="<?= gb_e((string) ($room['tour_filename'] ?? '')) ?>"
                                    data-photos="<?= gb_e(json_encode($photosByRoom[(int) $room['id']] ?? [], JSON_UNESCAPED_UNICODE)) ?>">
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <svg class="gb-select-caret" width="12" height="8" viewBox="0 0 12 8" fill="none" aria-hidden="true"><path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <span class="gb-field-err" id="gb_room_err" role="alert"></span>
                </div>
            </div>

            <div class="gb-row gb-row--2">
                <!-- Check-in -->
                <div class="gb-field">
                    <label class="gb-label" for="gb_checkin">Check-in Date <span class="gb-required" aria-hidden="true">*</span></label>
                    <input class="gb-input" type="date" id="gb_checkin" name="checkin"
                           value="<?= gb_e($preCheckin) ?>"
                           min="<?= gb_e($today) ?>"
                           required aria-required="true" aria-describedby="gb_checkin_err">
                    <span class="gb-field-err" id="gb_checkin_err" role="alert"></span>
                </div>

                <!-- Check-out -->
                <div class="gb-field">
                    <label class="gb-label" for="gb_checkout">Check-out Date <span class="gb-required" aria-hidden="true">*</span></label>
                    <input class="gb-input" type="date" id="gb_checkout" name="checkout"
                           value="<?= gb_e($preCheckout) ?>"
                           min="<?= gb_e($today) ?>"
                           required aria-required="true" aria-describedby="gb_checkout_err">
                    <span class="gb-field-err" id="gb_checkout_err" role="alert"></span>
                </div>
            </div>

            <div class="gb-row gb-row--2">
                <!-- Number of guests -->
                <div class="gb-field">
                    <label class="gb-label" for="gb_guests">Number of Guests <span class="gb-required" aria-hidden="true">*</span></label>
                    <input class="gb-input" type="number" id="gb_guests" name="guests"
                           min="1" max="50" value="1"
                           required aria-required="true" aria-describedby="gb_guests_err">
                    <span class="gb-field-err" id="gb_guests_err" role="alert"></span>
                </div>
            </div>

            <!-- Room summary pill — shown when a room is selected -->
            <div class="gb-room-summary" id="gbRoomSummary" hidden>
                <div class="gb-room-summary-inner">
                    <span class="gb-room-summary-name" id="gbRoomSummaryName"></span>
                    <span class="gb-room-summary-detail" id="gbRoomSummaryDetail"></span>
                </div>
                <button type="button" class="gb-photo-btn" id="gbPhotoBtn" hidden>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                    <span id="gbPhotoBtnLabel">Photos</span>
                </button>
                <button type="button" class="gb-tour-btn" id="gbTourBtn" hidden>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"></path></svg>
                    <span>360&deg; View</span>
                </button>
                <span class="gb-room-summary-price" id="gbRoomSummaryPrice"></span>
            </div>

            <!-- Additional guest names — shown for any accommodation whose
                 admin-configured capacity allows more than one guest. -->
            <div class="gb-field gb-field--full" id="gbCompanionsField" hidden>
                <label class="gb-label">Additional Guest Names <span class="gb-muted-label">(optional)</span></label>
                <div id="gbCompanionsList" style="display:flex;flex-direction:column;gap:8px;"></div>
                <button type="button" id="gbAddCompanionBtn" class="gb-btn gb-btn--outline gb-btn--sm" style="margin-top:8px;align-self:flex-start;">
                    + Add name
                </button>
                <span class="gb-field-hint" id="gbCompanionsHint"></span>
            </div>
        </section>

        <!-- ── Section 2: Guest Information ───────────────────────── -->
        <section class="gb-section">
            <h2 class="gb-section-title">
                <span class="gb-section-num">2</span>
                Your Information
            </h2>

            <div class="gb-row gb-row--2">
                <div class="gb-field">
                    <label class="gb-label" for="gb_fname">First Name <span class="gb-required" aria-hidden="true">*</span></label>
                    <input class="gb-input" type="text" id="gb_fname" name="first_name"
                           maxlength="80" required aria-required="true" aria-describedby="gb_fname_err"
                           placeholder="Juan">
                    <span class="gb-field-err" id="gb_fname_err" role="alert"></span>
                </div>
                <div class="gb-field">
                    <label class="gb-label" for="gb_lname">Last Name <span class="gb-required" aria-hidden="true">*</span></label>
                    <input class="gb-input" type="text" id="gb_lname" name="last_name"
                           maxlength="80" required aria-required="true" aria-describedby="gb_lname_err"
                           placeholder="Dela Cruz">
                    <span class="gb-field-err" id="gb_lname_err" role="alert"></span>
                </div>
            </div>

            <div class="gb-row gb-row--2">
                <div class="gb-field">
                    <label class="gb-label" for="gb_email">Email Address <span class="gb-required" aria-hidden="true">*</span></label>
                    <input class="gb-input" type="email" id="gb_email" name="email"
                           maxlength="200" required aria-required="true" aria-describedby="gb_email_err"
                           placeholder="juan@example.com" inputmode="email">
                    <span class="gb-field-err" id="gb_email_err" role="alert"></span>
                </div>
                <div class="gb-field">
                    <label class="gb-label" for="gb_phone">Phone Number <span class="gb-required" aria-hidden="true">*</span></label>
                    <input class="gb-input" type="tel" id="gb_phone" name="phone"
                           maxlength="30" required aria-required="true" aria-describedby="gb_phone_err"
                           placeholder="09XX XXX XXXX" inputmode="tel">
                    <span class="gb-field-err" id="gb_phone_err" role="alert"></span>
                </div>
            </div>

            <div class="gb-row gb-row--1">
                <div class="gb-field">
                    <label class="gb-label" for="gb_address">Home Address <span class="gb-muted-label">(optional)</span></label>
                    <input class="gb-input" type="text" id="gb_address" name="address"
                           maxlength="255"
                           placeholder="Barangay, Municipality, Province">
                </div>
            </div>

            <div class="gb-row gb-row--1">
                <div class="gb-field">
                    <label class="gb-label" for="gb_requests">Special Requests <span class="gb-muted-label">(optional)</span></label>
                    <textarea class="gb-input gb-textarea" id="gb_requests" name="requests"
                              maxlength="500" rows="3"
                              placeholder="Any notes for the resort (early check-in, dietary needs, etc.)"></textarea>
                </div>
            </div>
        </section>

        <!-- ── Section 3: Payment Method ──────────────────────────── -->
        <section class="gb-section">
            <h2 class="gb-section-title">
                <span class="gb-section-num">3</span>
                Payment Method
            </h2>
            <p class="gb-section-note">Payment is collected upon check-in or check-out. Choose your preferred method so we can prepare accordingly.</p>

            <div class="gb-payment-options" role="group" aria-label="Payment method">
                <label class="gb-payment-card">
                    <input type="radio" name="payment_method" value="cash" checked>
                    <span class="gb-payment-card-inner">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"></rect><path d="M2 10h20M6 14h2"></path></svg>
                        <strong>Cash</strong>
                        <span>Pay in person at check-in or check-out</span>
                    </span>
                </label>
                <label class="gb-payment-card">
                    <input type="radio" name="payment_method" value="gcash">
                    <span class="gb-payment-card-inner">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"></rect><path d="M12 18h.01"></path></svg>
                        <strong>GCash</strong>
                        <span>We will send payment details via email/SMS</span>
                    </span>
                </label>
            </div>
        </section>

        <!-- ── Billing Summary ─────────────────────────────────────── -->
        <section class="gb-section gb-section--summary" id="gbSummarySection" hidden>
            <h2 class="gb-section-title">
                <span class="gb-section-num">4</span>
                Booking Summary
            </h2>
            <div class="gb-summary-card">
                <div class="gb-summary-row">
                    <span>Accommodation</span>
                    <strong id="gbSumRoom">—</strong>
                </div>
                <div class="gb-summary-row">
                    <span>Check-in</span>
                    <strong id="gbSumCheckin">—</strong>
                </div>
                <div class="gb-summary-row">
                    <span>Check-out</span>
                    <strong id="gbSumCheckout">—</strong>
                </div>
                <div class="gb-summary-row">
                    <span>Duration</span>
                    <strong id="gbSumNights">—</strong>
                </div>
                <div class="gb-summary-row">
                    <span>Guests</span>
                    <strong id="gbSumGuests">—</strong>
                </div>
                <div class="gb-summary-divider"></div>
                <div class="gb-summary-row gb-summary-row--total">
                    <span>Estimated Total</span>
                    <strong id="gbSumTotal">—</strong>
                </div>
                <p class="gb-summary-disclaimer">Final billing is computed by our staff. Additional charges or discounts may apply.</p>
            </div>
        </section>

        <!-- ── Submit ─────────────────────────────────────────────── -->
        <div class="gb-submit-row">
            <span class="gb-form-err" id="gbFormErr" role="alert"></span>
            <button class="gb-btn gb-btn--gold gb-btn--submit" type="submit" id="gbSubmitBtn">
                <span class="gb-btn-label">Confirm Guest Booking</span>
                <span class="gb-btn-spinner" hidden aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                </span>
            </button>
        </div>

    </form>

</main>

<!-- ═══ SUCCESS OVERLAY ═══════════════════════════════════════════════ -->
<div class="gb-success-overlay" id="gbSuccessOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="gbSuccessTitle">
    <div class="gb-success-dialog">
        <div class="gb-success-icon">
            <svg class="gb-success-checkmark" viewBox="0 0 52 52" aria-hidden="true">
                <circle class="gb-success-checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
                <path class="gb-success-checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
            </svg>
        </div>
        <h2 id="gbSuccessTitle">Booking Confirmed!</h2>
        <p>Your reservation request has been received. Please keep your reference number.</p>
        <div class="gb-success-ref">
            <span>Reference Number</span>
            <strong id="gbSuccessRef">—</strong>
        </div>
        <div class="gb-success-details">
            <div class="gb-success-detail-row">
                <span>Room</span>
                <strong id="gbSuccessRoom"></strong>
            </div>
            <div class="gb-success-detail-row">
                <span>Check-in</span>
                <strong id="gbSuccessCheckin"></strong>
            </div>
            <div class="gb-success-detail-row">
                <span>Check-out</span>
                <strong id="gbSuccessCheckout"></strong>
            </div>
        </div>
        <p class="gb-success-note">A confirmation will be sent to your email. For changes or cancellations, please contact us directly.</p>
        <a class="gb-btn gb-btn--gold" href="../index.html">Back to Home</a>
    </div>
</div>
<div class="gb-success-backdrop" id="gbSuccessBackdrop" aria-hidden="true"></div>

<!-- ═══ 360° TOUR OVERLAY ═════════════════════════════════════════════ -->
<div class="gb-tour-overlay" id="gbTourOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="gbTourTitle">
    <div class="gb-tour-dialog">
        <div class="gb-tour-dialog__head">
            <h2 id="gbTourTitle">360&deg; Room Tour</h2>
            <button type="button" class="gb-tour-close" id="gbTourClose" aria-label="Close 360&deg; tour">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="gb-tour-frame-wrap">
            <iframe id="gbTourFrame" src="about:blank" title="360 degree room tour" loading="lazy" allow="accelerometer; gyroscope; fullscreen" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
        </div>
    </div>
</div>
<div class="gb-tour-backdrop" id="gbTourBackdrop" aria-hidden="true"></div>

<!-- ═══ PHOTO GALLERY OVERLAY ═══════════════════════════════════════════ -->
<div class="gb-photo-overlay" id="gbPhotoOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="gbPhotoTitle">
    <div class="gb-photo-dialog">
        <div class="gb-photo-dialog__head">
            <h2 id="gbPhotoTitle">Room Photos</h2>
            <button type="button" class="gb-photo-close" id="gbPhotoClose" aria-label="Close photos">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="gb-photo-frame-wrap">
            <img id="gbPhotoImg" src="" alt="">
            <button type="button" class="gb-photo-nav gb-photo-nav--prev" id="gbPhotoPrev" aria-label="Previous photo" hidden>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>
            <button type="button" class="gb-photo-nav gb-photo-nav--next" id="gbPhotoNext" aria-label="Next photo" hidden>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
        </div>
        <div class="gb-photo-dialog__caption" id="gbPhotoCaption"></div>
    </div>
</div>
<div class="gb-photo-backdrop" id="gbPhotoBackdrop" aria-hidden="true"></div>

<script>
(function () {
    'use strict';

    var form         = document.getElementById('guestBookForm');
    var submitBtn    = document.getElementById('gbSubmitBtn');
    var btnLabel     = submitBtn.querySelector('.gb-btn-label');
    var btnSpinner   = submitBtn.querySelector('.gb-btn-spinner');
    var formErr      = document.getElementById('gbFormErr');

    var roomSel      = document.getElementById('gb_room');
    var checkinIn    = document.getElementById('gb_checkin');
    var checkoutIn   = document.getElementById('gb_checkout');
    var guestsIn     = document.getElementById('gb_guests');

    // Summary elements
    var sumSection   = document.getElementById('gbSummarySection');
    var sumRoom      = document.getElementById('gbSumRoom');
    var sumCheckin   = document.getElementById('gbSumCheckin');
    var sumCheckout  = document.getElementById('gbSumCheckout');
    var sumNights    = document.getElementById('gbSumNights');
    var sumGuests    = document.getElementById('gbSumGuests');
    var sumTotal     = document.getElementById('gbSumTotal');

    // Room summary pill
    var roomSummary      = document.getElementById('gbRoomSummary');
    var roomSummaryName  = document.getElementById('gbRoomSummaryName');
    var roomSummaryDetail = document.getElementById('gbRoomSummaryDetail');
    var roomSummaryPrice = document.getElementById('gbRoomSummaryPrice');
    var tourBtn          = document.getElementById('gbTourBtn');
    var photoBtn         = document.getElementById('gbPhotoBtn');
    var photoBtnLabel    = document.getElementById('gbPhotoBtnLabel');

    // 360° Tour overlay
    var tourOverlay  = document.getElementById('gbTourOverlay');
    var tourBackdrop = document.getElementById('gbTourBackdrop');
    var tourClose    = document.getElementById('gbTourClose');
    var tourFrame    = document.getElementById('gbTourFrame');
    var tourTitle    = document.getElementById('gbTourTitle');
    var TOUR_BASE    = '../admin/assets/uploads/tours/';

    // Photo gallery overlay
    var photoOverlay  = document.getElementById('gbPhotoOverlay');
    var photoBackdrop = document.getElementById('gbPhotoBackdrop');
    var photoClose    = document.getElementById('gbPhotoClose');
    var photoImg      = document.getElementById('gbPhotoImg');
    var photoTitle    = document.getElementById('gbPhotoTitle');
    var photoCaption  = document.getElementById('gbPhotoCaption');
    var photoPrevBtn  = document.getElementById('gbPhotoPrev');
    var photoNextBtn  = document.getElementById('gbPhotoNext');
    var PHOTO_BASE    = '../admin/assets/uploads/accommodations/';
    var galleryPhotos = [];   // filenames for the currently selected room
    var galleryIndex  = 0;
    var galleryName   = '';

    // Success overlay
    var successOverlay  = document.getElementById('gbSuccessOverlay');
    var successBackdrop = document.getElementById('gbSuccessBackdrop');
    var successRef      = document.getElementById('gbSuccessRef');
    var successRoom     = document.getElementById('gbSuccessRoom');
    var successCheckin  = document.getElementById('gbSuccessCheckin');
    var successCheckout = document.getElementById('gbSuccessCheckout');

    // Additional guest names — any accommodation type, capped by the
    // room's admin-configured capacity.
    var companionsField = document.getElementById('gbCompanionsField');
    var companionsList   = document.getElementById('gbCompanionsList');
    var addCompanionBtn  = document.getElementById('gbAddCompanionBtn');
    var companionsHint   = document.getElementById('gbCompanionsHint');
    var companionMax     = 0; // max additional guest names allowed for the currently selected room (capacity - 1)

    /* ── helpers ──────────────────────────────────────────────────── */
    function formatDate(ymd) {
        if (!ymd) return '—';
        var parts = ymd.split('-');
        if (parts.length !== 3) return ymd;
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(parts[1], 10) - 1] + ' ' + parseInt(parts[2], 10) + ', ' + parts[0];
    }

    function nightsBetween(checkin, checkout) {
        var a = new Date(checkin);
        var b = new Date(checkout);
        var diff = (b - a) / 86400000;
        return diff > 0 ? Math.round(diff) : 0;
    }

    function formatPeso(num) {
        return '₱' + parseFloat(num).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fieldErr(id, msg) {
        var el = document.getElementById(id);
        if (el) { el.textContent = msg; }
    }

    function clearErrors() {
        form.querySelectorAll('.gb-field-err').forEach(function (el) { el.textContent = ''; });
        form.querySelectorAll('.gb-input').forEach(function (el) { el.classList.remove('gb-input--error'); });
        formErr.textContent = '';
    }

    function isPoolType(type) {
        return (type || '').trim().toLowerCase() === 'swimming pool';
    }

    /* ── additional guest name rows ──────────────────────────────── */
    function addCompanionRow(value) {
        if (companionsList.children.length >= companionMax) return;

        var row = document.createElement('div');
        row.style.display = 'flex';
        row.style.gap = '8px';

        var input = document.createElement('input');
        input.type = 'text';
        input.name = 'companion_names[]';
        input.className = 'gb-input gb-companion-input';
        input.maxLength = 100;
        input.placeholder = 'Guest name';
        input.value = value || '';
        input.style.flex = '1';

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'gb-btn gb-btn--outline gb-btn--sm';
        removeBtn.textContent = '−';
        removeBtn.setAttribute('aria-label', 'Remove name');
        removeBtn.addEventListener('click', function () {
            row.remove();
            refreshAddCompanionBtnState();
        });

        row.appendChild(input);
        row.appendChild(removeBtn);
        companionsList.appendChild(row);
        refreshAddCompanionBtnState();
    }

    function refreshAddCompanionBtnState() {
        var atCap = companionsList.children.length >= companionMax;
        addCompanionBtn.disabled = atCap;
        addCompanionBtn.textContent = atCap ? 'Maximum guests reached' : '+ Add name';
    }

    addCompanionBtn && addCompanionBtn.addEventListener('click', function () {
        addCompanionRow('');
    });

    function resetCompanionRows(seedCount) {
        companionsList.innerHTML = '';
        // Pre-seed one blank row per additional guest — the primary guest's
        // own name is already captured in Section 2, so this covers the
        // rest of the party — never exceeding the room's capacity.
        var seed = Math.max(0, Math.min(seedCount, companionMax));
        for (var i = 0; i < seed; i++) addCompanionRow('');
        refreshAddCompanionBtnState();
    }

    // Non-destructive resync used when the guest count changes after a room
    // is already selected — adds/removes trailing rows instead of wiping
    // names the user already typed.
    function syncCompanionRowCount(desiredExtra) {
        var target = Math.max(0, Math.min(desiredExtra, companionMax));
        while (companionsList.children.length < target) addCompanionRow('');
        while (companionsList.children.length > target) {
            companionsList.removeChild(companionsList.lastElementChild);
        }
        refreshAddCompanionBtnState();
    }

    function collectCompanionNames() {
        return Array.prototype.slice.call(companionsList.querySelectorAll('.gb-companion-input'))
            .map(function (el) { return el.value.trim(); })
            .filter(function (v) { return v !== ''; });
    }

    // Shows/hides and seeds the additional-guest-names field for the room
    // currently selected, based on its admin-configured capacity.
    function setupCompanionFields(capacity, guests) {
        companionMax = Math.max(0, capacity - 1);

        if (companionMax <= 0) {
            companionsField.setAttribute('hidden', '');
            companionsList.innerHTML = '';
            return;
        }

        companionsField.removeAttribute('hidden');
        companionsHint.textContent = 'This room fits up to ' + capacity + ' guest' +
            (capacity === 1 ? '' : 's') + ' — add up to ' + companionMax + ' more name' +
            (companionMax === 1 ? '' : 's') + ' below.';

        resetCompanionRows(Math.max(0, guests - 1));
    }

    /* ── room select → pill + capacity cap ───────────────────────── */
    function onRoomChange() {
        var opt = roomSel.options[roomSel.selectedIndex];
        if (!opt || !opt.value) {
            roomSummary.setAttribute('hidden', '');
            guestsIn.max = 50;
            tourBtn.setAttribute('hidden', '');
            delete tourBtn.dataset.tourFile;
            photoBtn.setAttribute('hidden', '');
            photoBtn.dataset.photos = '[]';
            companionsField.setAttribute('hidden', '');
            companionsList.innerHTML = '';
            updateSummary();
            return;
        }
        var cap   = parseInt(opt.dataset.capacity, 10) || 50;
        var price = opt.dataset.price || '0';
        var type  = opt.dataset.type  || '';
        var tour  = opt.dataset.tour  || '';
        var isPool = isPoolType(type);

        roomSummary.removeAttribute('hidden');
        roomSummaryName.textContent  = opt.text;
        roomSummaryDetail.textContent = type + ' · Up to ' + cap + ' guest' + (cap === 1 ? '' : 's');
        roomSummaryPrice.textContent = formatPeso(price) + (isPool ? '/person' : '/night');

        if (tour) {
            tourBtn.dataset.tourFile = tour;
            tourBtn.dataset.tourName = opt.text;
            tourBtn.removeAttribute('hidden');
        } else {
            delete tourBtn.dataset.tourFile;
            tourBtn.setAttribute('hidden', '');
        }

        // "View Photos" button — parses the room's photo list (cover +
        // gallery) embedded server-side on the <option>. Hidden entirely
        // when the room has no photos uploaded yet.
        var photos = [];
        try {
            photos = JSON.parse(opt.dataset.photos || '[]');
        } catch (e) {
            photos = [];
        }
        if (photos.length) {
            photoBtn.dataset.photos = JSON.stringify(photos);
            photoBtn.dataset.photoName = opt.text;
            photoBtnLabel.textContent = photos.length > 1 ? ('Photos (' + photos.length + ')') : 'Photo';
            photoBtn.removeAttribute('hidden');
        } else {
            photoBtn.dataset.photos = '[]';
            delete photoBtn.dataset.photoName;
            photoBtn.setAttribute('hidden', '');
        }

        // cap guests input to this room's capacity
        guestsIn.max = cap;
        if (parseInt(guestsIn.value, 10) > cap) guestsIn.value = cap;

        setupCompanionFields(cap, parseInt(guestsIn.value, 10) || 1);

        updateSummary();
    }

    /* ── live booking summary ──────────────────────────────────────── */
    function updateSummary() {
        var opt      = roomSel.options[roomSel.selectedIndex];
        var roomName = (opt && opt.value) ? opt.text : '';
        var cin      = checkinIn.value;
        var cout     = checkoutIn.value;
        var guests   = parseInt(guestsIn.value, 10) || 0;
        var nights   = (cin && cout) ? nightsBetween(cin, cout) : 0;
        var price    = (opt && opt.value) ? parseFloat(opt.dataset.price) || 0 : 0;
        var isPool   = (opt && opt.value) ? isPoolType(opt.dataset.type) : false;

        if (roomName && cin && cout && nights > 0) {
            sumSection.removeAttribute('hidden');
            sumRoom.textContent     = roomName;
            sumCheckin.textContent  = formatDate(cin);
            sumCheckout.textContent = formatDate(cout);
            sumNights.textContent   = nights + ' night' + (nights === 1 ? '' : 's');
            sumGuests.textContent   = guests + ' guest' + (guests === 1 ? '' : 's');
            sumTotal.textContent    = formatPeso(isPool ? (price * guests) : (price * nights));
        } else {
            sumSection.setAttribute('hidden', '');
        }
    }

    /* ── date linking ──────────────────────────────────────────────── */
    checkinIn.addEventListener('change', function () {
        if (checkinIn.value) {
            checkoutIn.min = checkinIn.value;
            if (checkoutIn.value && checkoutIn.value <= checkinIn.value) {
                // auto-advance checkout to next day
                var d = new Date(checkinIn.value);
                d.setDate(d.getDate() + 1);
                checkoutIn.value = d.toISOString().split('T')[0];
            }
        }
        updateSummary();
    });

    checkoutIn.addEventListener('change', updateSummary);
    guestsIn.addEventListener('input', updateSummary);
    guestsIn.addEventListener('change', function () {
        // Re-sync the additional-guest-name rows to the (possibly clamped)
        // guest count once the user commits a value — not on every
        // keystroke, so names already typed aren't wiped mid-edit.
        var opt = roomSel.options[roomSel.selectedIndex];
        if (!opt || !opt.value) return;
        var cap = parseInt(opt.dataset.capacity, 10) || 50;
        if (parseInt(guestsIn.value, 10) > cap) guestsIn.value = cap;
        if (companionMax > 0) {
            syncCompanionRowCount((parseInt(guestsIn.value, 10) || 1) - 1);
        }
    });
    roomSel.addEventListener('change', onRoomChange);

    // init on load if URL params pre-filled values
    onRoomChange();

    /* ── client-side validation ───────────────────────────────────── */
    function validate() {
        var ok = true;

        if (!roomSel.value) {
            fieldErr('gb_room_err', 'Please select an accommodation.');
            roomSel.classList.add('gb-input--error');
            ok = false;
        }

        var cin  = checkinIn.value;
        var cout = checkoutIn.value;

        if (!cin) {
            fieldErr('gb_checkin_err', 'Please choose a check-in date.');
            checkinIn.classList.add('gb-input--error');
            ok = false;
        }

        if (!cout) {
            fieldErr('gb_checkout_err', 'Please choose a check-out date.');
            checkoutIn.classList.add('gb-input--error');
            ok = false;
        }

        if (cin && cout && cout <= cin) {
            fieldErr('gb_checkout_err', 'Check-out must be after check-in.');
            checkoutIn.classList.add('gb-input--error');
            ok = false;
        }

        var guests = parseInt(guestsIn.value, 10);
        if (!guests || guests < 1) {
            fieldErr('gb_guests_err', 'Please enter the number of guests.');
            guestsIn.classList.add('gb-input--error');
            ok = false;
        }

        var maxGuests = parseInt(guestsIn.max, 10);
        if (guests > maxGuests) {
            fieldErr('gb_guests_err', 'Maximum capacity for this room is ' + maxGuests + ' guest' + (maxGuests === 1 ? '' : 's') + '.');
            guestsIn.classList.add('gb-input--error');
            ok = false;
        }

        var fname = document.getElementById('gb_fname').value.trim();
        if (!fname) {
            fieldErr('gb_fname_err', 'First name is required.');
            document.getElementById('gb_fname').classList.add('gb-input--error');
            ok = false;
        }

        var lname = document.getElementById('gb_lname').value.trim();
        if (!lname) {
            fieldErr('gb_lname_err', 'Last name is required.');
            document.getElementById('gb_lname').classList.add('gb-input--error');
            ok = false;
        }

        var email = document.getElementById('gb_email').value.trim();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            fieldErr('gb_email_err', 'Please enter a valid email address.');
            document.getElementById('gb_email').classList.add('gb-input--error');
            ok = false;
        }

        var phone = document.getElementById('gb_phone').value.trim();
        if (!phone || phone.length < 7) {
            fieldErr('gb_phone_err', 'Please enter a valid phone number.');
            document.getElementById('gb_phone').classList.add('gb-input--error');
            ok = false;
        }

        return ok;
    }

    /* ── form submit ──────────────────────────────────────────────── */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();

        if (!validate()) {
            // Scroll to first error
            var firstErr = form.querySelector('.gb-input--error');
            if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // Loading state
        submitBtn.disabled = true;
        btnLabel.hidden = true;
        btnSpinner.hidden = false;

        var data = new FormData(form);

        fetch('api/guest-book.php', {
            method: 'POST',
            body: data,
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success) {
                showSuccess(json);
            } else {
                // Server-side validation errors
                formErr.textContent = json.error || 'An error occurred. Please try again.';
                if (json.field_errors) {
                    Object.keys(json.field_errors).forEach(function (field) {
                        fieldErr(field + '_err', json.field_errors[field]);
                        var el = document.getElementById('gb_' + field);
                        if (el) el.classList.add('gb-input--error');
                    });
                    var firstErr = form.querySelector('.gb-input--error');
                    if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        })
        .catch(function () {
            formErr.textContent = 'Network error. Please check your connection and try again.';
        })
        .finally(function () {
            submitBtn.disabled = false;
            btnLabel.hidden = false;
            btnSpinner.hidden = true;
        });
    });

    /* ── success overlay ──────────────────────────────────────────── */
    function showSuccess(json) {
        successRef.textContent      = json.reference || '—';
        successRoom.textContent     = json.room_name  || '—';
        successCheckin.textContent  = formatDate(json.checkin)  || '—';
        successCheckout.textContent = formatDate(json.checkout) || '—';

        successOverlay.removeAttribute('aria-hidden');
        successOverlay.classList.add('open');
        successBackdrop.classList.add('open');
        document.body.classList.add('gb-modal-open');

        // Focus the dialog
        successOverlay.focus();
    }

    /* ── 360° tour overlay ────────────────────────────────────────── */
    function openTour() {
        var file = tourBtn.dataset.tourFile;
        if (!file) return;

        tourFrame.src = TOUR_BASE + encodeURIComponent(file);
        tourTitle.textContent = tourBtn.dataset.tourName
            ? (tourBtn.dataset.tourName + ' — 360° Room Tour')
            : '360° Room Tour';

        tourOverlay.removeAttribute('aria-hidden');
        tourOverlay.classList.add('open');
        tourBackdrop.classList.add('open');
        document.body.classList.add('gb-modal-open');
        tourOverlay.focus();
    }

    function closeTour() {
        tourOverlay.setAttribute('aria-hidden', 'true');
        tourOverlay.classList.remove('open');
        tourBackdrop.classList.remove('open');
        if (!successOverlay.classList.contains('open')) {
            document.body.classList.remove('gb-modal-open');
        }
        tourFrame.src = 'about:blank'; // stop the tour running in the background once closed
    }

    tourBtn.addEventListener('click', openTour);
    tourClose.addEventListener('click', closeTour);
    tourBackdrop.addEventListener('click', closeTour);

    /* ── photo gallery overlay ────────────────────────────────────── */
    function renderGalleryPhoto() {
        if (!galleryPhotos.length) return;
        photoImg.src = PHOTO_BASE + encodeURIComponent(galleryPhotos[galleryIndex]);
        photoImg.alt = galleryName || 'Room photo';

        var multi = galleryPhotos.length > 1;
        photoTitle.textContent   = galleryName ? (galleryName + ' — Photos') : 'Room Photos';
        photoCaption.textContent = multi ? ((galleryIndex + 1) + ' of ' + galleryPhotos.length) : '';
        photoPrevBtn.hidden = !multi;
        photoNextBtn.hidden = !multi;
    }

    function showPrevPhoto() {
        if (galleryPhotos.length < 2) return;
        galleryIndex = (galleryIndex - 1 + galleryPhotos.length) % galleryPhotos.length;
        renderGalleryPhoto();
    }

    function showNextPhoto() {
        if (galleryPhotos.length < 2) return;
        galleryIndex = (galleryIndex + 1) % galleryPhotos.length;
        renderGalleryPhoto();
    }

    function openPhotos() {
        var photos = [];
        try {
            photos = JSON.parse(photoBtn.dataset.photos || '[]');
        } catch (e) {
            photos = [];
        }
        if (!photos.length) return;

        galleryPhotos = photos;
        galleryIndex  = 0;
        galleryName   = photoBtn.dataset.photoName || '';
        renderGalleryPhoto();

        photoOverlay.removeAttribute('aria-hidden');
        photoOverlay.classList.add('open');
        photoBackdrop.classList.add('open');
        document.body.classList.add('gb-modal-open');
        photoOverlay.focus();
    }

    function closePhotos() {
        photoOverlay.setAttribute('aria-hidden', 'true');
        photoOverlay.classList.remove('open');
        photoBackdrop.classList.remove('open');
        if (!successOverlay.classList.contains('open') && !tourOverlay.classList.contains('open')) {
            document.body.classList.remove('gb-modal-open');
        }
        photoImg.src = '';
    }

    photoBtn.addEventListener('click', openPhotos);
    photoClose.addEventListener('click', closePhotos);
    photoBackdrop.addEventListener('click', closePhotos);
    photoPrevBtn.addEventListener('click', showPrevPhoto);
    photoNextBtn.addEventListener('click', showNextPhoto);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (photoOverlay.classList.contains('open')) closePhotos();
            else if (tourOverlay.classList.contains('open')) closeTour();
            return;
        }
        if (photoOverlay.classList.contains('open')) {
            if (e.key === 'ArrowLeft')  showPrevPhoto();
            if (e.key === 'ArrowRight') showNextPhoto();
        }
    });

}());
</script>
</body>
</html>