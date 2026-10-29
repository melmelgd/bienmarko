<?php

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
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        <a href="../login.php">Log in</a>
        <a class="gb-btn gb-btn--gold gb-btn--sm" href="../register.php">Create Account</a>
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
                    <label class="gb-label" for="gb_checkinTrigger">Check-in Date <span class="gb-required" aria-hidden="true">*</span></label>
                    <!-- Dates already reserved for the selected accommodation
                         are fetched from ../api/availability.php and greyed
                         out here — see the "GUEST BOOK DATE PICKERS" block
                         in the script below. -->
                    <div class="gb-date-picker" id="gb_checkinPicker">
                        <button type="button" class="gb-input gb-date-picker-trigger" id="gb_checkinTrigger" aria-haspopup="dialog" aria-describedby="gb_checkin_err">
                            <span id="gb_checkinText" class="<?= $preCheckin === '' ? 'is-placeholder' : '' ?>"><?= $preCheckin !== '' ? gb_e(date('M j, Y', strtotime($preCheckin))) : 'Select date' ?></span>
                            <svg class="gb-date-picker-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>
                        </button>
                        <div class="gb-date-picker-pop" id="gb_checkinPop"></div>
                    </div>
                    <input type="hidden" id="gb_checkin" name="checkin" value="<?= gb_e($preCheckin) ?>" required aria-required="true">
                    <span class="gb-field-err" id="gb_checkin_err" role="alert"></span>
                </div>

                <!-- Check-out -->
                <div class="gb-field">
                    <label class="gb-label" for="gb_checkoutTrigger">Check-out Date <span class="gb-required" aria-hidden="true">*</span></label>
                    <div class="gb-date-picker" id="gb_checkoutPicker">
                        <button type="button" class="gb-input gb-date-picker-trigger" id="gb_checkoutTrigger" aria-haspopup="dialog" aria-describedby="gb_checkout_err">
                            <span id="gb_checkoutText" class="<?= $preCheckout === '' ? 'is-placeholder' : '' ?>"><?= $preCheckout !== '' ? gb_e(date('M j, Y', strtotime($preCheckout))) : 'Select date' ?></span>
                            <svg class="gb-date-picker-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>
                        </button>
                        <div class="gb-date-picker-pop" id="gb_checkoutPop"></div>
                    </div>
                    <input type="hidden" id="gb_checkout" name="checkout" value="<?= gb_e($preCheckout) ?>" required aria-required="true">
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
            <p class="gb-section-note">Payment is collected in cash or via GCash upon check-in or check-out.</p>

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
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"></rect><path d="M11 18h2"></path></svg>
                        <strong>GCash</strong>
                        <span>Pay via GCash at check-in or check-out</span>
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

<!-- ═══ CONFIRM OVERLAY ═════════════════════════════════════════════ -->
<!-- Shown after client-side validation passes; the booking is only sent
     to api/guest-book.php once the guest taps "Yes, Confirm Booking" here. -->
<div class="gb-success-overlay" id="gbConfirmOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="gbConfirmTitle" tabindex="-1">
    <div class="gb-success-dialog">
        <h2 id="gbConfirmTitle">Confirm Your Booking</h2>
        <p>Please review your reservation details before submitting.</p>
        <div class="gb-success-details">
            <div class="gb-success-detail-row">
                <span>Room</span>
                <strong id="gbConfirmRoom">—</strong>
            </div>
            <div class="gb-success-detail-row">
                <span>Check-in</span>
                <strong id="gbConfirmCheckin">—</strong>
            </div>
            <div class="gb-success-detail-row">
                <span>Check-out</span>
                <strong id="gbConfirmCheckout">—</strong>
            </div>
            <div class="gb-success-detail-row">
                <span>Guests</span>
                <strong id="gbConfirmGuests">—</strong>
            </div>
        </div>
        <p class="gb-success-note">Once submitted, this request will be sent to Bien Marko's Pension House for confirmation.</p>
        <div class="gb-confirm-actions">
            <button type="button" class="gb-btn gb-btn--outline" id="gbConfirmBackBtn">Go Back</button>
            <button type="button" class="gb-btn gb-btn--gold" id="gbConfirmYesBtn">
                <span id="gbConfirmYesLabel">Yes, Confirm Booking</span>
            </button>
        </div>
    </div>
</div>
<div class="gb-success-backdrop" id="gbConfirmBackdrop" aria-hidden="true"></div>

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
    // The visible date pickers — #gb_checkin/#gb_checkout above are now
    // hidden inputs (see "GUEST BOOK DATE PICKERS" below), so any error
    // styling meant to be seen has to land on these trigger buttons
    // instead of on checkinIn/checkoutIn directly.
    var checkinTrigger  = document.getElementById('gb_checkinTrigger');
    var checkoutTrigger = document.getElementById('gb_checkoutTrigger');

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

    // Confirm overlay (are-you-sure step shown before the booking is sent)
    var confirmOverlay   = document.getElementById('gbConfirmOverlay');
    var confirmBackdrop  = document.getElementById('gbConfirmBackdrop');
    var confirmRoom      = document.getElementById('gbConfirmRoom');
    var confirmCheckin   = document.getElementById('gbConfirmCheckin');
    var confirmCheckout  = document.getElementById('gbConfirmCheckout');
    var confirmGuests    = document.getElementById('gbConfirmGuests');
    var confirmYesBtn    = document.getElementById('gbConfirmYesBtn');
    var confirmYesLabel  = document.getElementById('gbConfirmYesLabel');
    var confirmBackBtn   = document.getElementById('gbConfirmBackBtn');

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

    /* ── GUEST BOOK DATE PICKERS ──────────────────────────────────────
       Turns #gb_checkin / #gb_checkout into a small calendar popover
       that greys out dates already reserved for whichever accommodation
       is selected, fetched from the public ../api/availability.php
       endpoint (the same one the landing page's booking strip uses —
       no login required here either). #gb_checkin / #gb_checkout stay
       as hidden inputs holding the real ISO value, so the date-linking
       block above, updateSummary(), and api/guest-book.php's own
       transaction-locked final conflict check all keep working
       unchanged.
       ------------------------------------------------------------- */
    var gbdpAvailability = null;   // { capacity, isPool, bookings: [...] } for the selected room, or null
    var gbdpOpenPicker   = null;   // 'checkin' | 'checkout' | null
    var gbdpViewMonth    = { checkin: null, checkout: null };

    function gbdpPad(n) { return n < 10 ? '0' + n : '' + n; }
    function gbdpISO(y, m, d) { return y + '-' + gbdpPad(m + 1) + '-' + gbdpPad(d); }
    function gbdpParseISO(s) {
        if (!s) return null;
        var p = s.split('-');
        return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    }

    function gbdpGuestsWanted() {
        var n = parseInt(guestsIn.value, 10);
        return (!n || n < 1) ? 1 : n;
    }

    // Map of ISO date -> guests already booked that day, built from
    // gbdpAvailability.bookings. A date is "covered" by a booking when
    // check_in <= date < check_out (checkout day itself is free — same
    // half-open range api/guest-book.php uses for its own conflict check).
    function gbdpBuildBookedMap() {
        var map = {};
        if (!gbdpAvailability) return map;
        gbdpAvailability.bookings.forEach(function (b) {
            var cur = gbdpParseISO(b.check_in);
            var end = gbdpParseISO(b.check_out);
            while (cur < end) {
                var iso = gbdpISO(cur.getFullYear(), cur.getMonth(), cur.getDate());
                map[iso] = (map[iso] || 0) + (gbdpAvailability.isPool ? b.guests_count : 1);
                cur.setDate(cur.getDate() + 1);
            }
        });
        return map;
    }

    // Whether `iso` is unavailable as a stay-night for `guestsWanted`.
    // Every accommodation type but the Swimming Pool is exclusive (any
    // existing booking blocks it); the pool is shared up to capacity —
    // mirrors gb_is_pool_type()/the conflict query in api/guest-book.php.
    function gbdpIsBlocked(iso, bookedMap, guestsWanted) {
        if (!gbdpAvailability) return false;
        var used = bookedMap[iso] || 0;
        if (gbdpAvailability.isPool) return (used + Math.max(1, guestsWanted)) > gbdpAvailability.capacity;
        return used > 0;
    }

    // Earliest blocked date strictly after `fromIso` — check-out can
    // never be pushed past this, or the stay would span an occupied night.
    function gbdpFirstBlockedAfter(fromIso, bookedMap, guestsWanted) {
        if (!gbdpAvailability || !fromIso) return null;
        var d = gbdpParseISO(fromIso);
        d.setDate(d.getDate() + 1);
        for (var i = 0; i < 730; i++) {
            var iso = gbdpISO(d.getFullYear(), d.getMonth(), d.getDate());
            if (gbdpIsBlocked(iso, bookedMap, guestsWanted)) return iso;
            d.setDate(d.getDate() + 1);
        }
        return null;
    }

    function gbdpRenderCalendar(which) {
        var pop      = document.getElementById(which === 'checkin' ? 'gb_checkinPop' : 'gb_checkoutPop');
        var otherIso = document.getElementById(which === 'checkin' ? 'gb_checkout' : 'gb_checkin').value;

        if (which === 'checkout' && !otherIso) {
            pop.innerHTML = '<div class="gb-date-picker-note">Please select a check-in date first.</div>';
            return;
        }
        if (!roomSel.value) {
            pop.innerHTML = '<div class="gb-date-picker-note">Please select an accommodation first.</div>';
            return;
        }

        var selectedIso  = document.getElementById(which === 'checkin' ? 'gb_checkin' : 'gb_checkout').value;
        var guestsWanted = gbdpGuestsWanted();
        var bookedMap    = gbdpBuildBookedMap();

        if (!gbdpViewMonth[which]) {
            var base = gbdpParseISO(selectedIso) || gbdpParseISO(otherIso) || new Date();
            gbdpViewMonth[which] = { y: base.getFullYear(), m: base.getMonth() };
        }
        var view = gbdpViewMonth[which];

        var today = new Date();
        today.setHours(0, 0, 0, 0);

        var minDate, maxIso = null;
        if (which === 'checkout') {
            minDate = gbdpParseISO(otherIso);
            minDate.setDate(minDate.getDate() + 1);
            maxIso = gbdpFirstBlockedAfter(otherIso, bookedMap, guestsWanted);
        } else {
            minDate = today;
        }

        var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var startWeekday = new Date(view.y, view.m, 1).getDay();
        var daysInMonth  = new Date(view.y, view.m + 1, 0).getDate();

        var html = '';
        html += '<div class="gb-date-picker-head">';
        html += '<button type="button" class="gb-date-picker-nav" data-gbdp-nav="prev" data-gbdp-which="' + which + '" aria-label="Previous month">&#8249;</button>';
        html += '<span class="gb-date-picker-month">' + monthNames[view.m] + ' ' + view.y + '</span>';
        html += '<button type="button" class="gb-date-picker-nav" data-gbdp-nav="next" data-gbdp-which="' + which + '" aria-label="Next month">&#8250;</button>';
        html += '</div>';
        html += '<div class="gb-date-picker-grid gb-date-picker-weekdays">';
        ['S','M','T','W','T','F','S'].forEach(function (w) { html += '<span>' + w + '</span>'; });
        html += '</div>';
        html += '<div class="gb-date-picker-grid">';
        for (var i = 0; i < startWeekday; i++) html += '<span class="gb-date-picker-cell is-empty"></span>';
        for (var day = 1; day <= daysInMonth; day++) {
            var iso          = gbdpISO(view.y, view.m, day);
            var cellDate     = new Date(view.y, view.m, day);
            var visualBooked = gbdpIsBlocked(iso, bookedMap, guestsWanted);
            var beforeMin    = cellDate < minDate;
            var afterMax     = maxIso ? (iso > maxIso) : false;

            // Check-in must land on a genuinely open night. Check-out
            // just needs to be after check-in and not stretch past the
            // next occupied night — the checkout day itself is fine even
            // if another booking starts that same day (same-day turnover).
            var disabled = which === 'checkin'
                ? (visualBooked || beforeMin)
                : (beforeMin || afterMax);

            var isSelected = (iso === selectedIso);
            var cls = 'gb-date-picker-cell';
            if (disabled)   cls += ' is-disabled';
            if (isSelected) cls += ' is-selected';
            if (visualBooked && !beforeMin) cls += ' is-booked';

            html += '<button type="button" class="' + cls + '"' +
                (disabled ? ' disabled' : (' data-gbdp-pick="' + iso + '" data-gbdp-which="' + which + '"')) +
                (visualBooked && !beforeMin ? ' title="Already booked"' : '') +
                '>' + day + '</button>';
        }
        html += '</div>';
        html += '<div class="gb-date-picker-legend"><span class="gb-dp-swatch"></span> Booked / unavailable</div>';

        pop.innerHTML = html;
    }

    function gbdpCloseDatePickers() {
        gbdpOpenPicker = null;
        document.getElementById('gb_checkinPicker').classList.remove('is-open');
        document.getElementById('gb_checkoutPicker').classList.remove('is-open');
    }

    function gbdpOpenDatePicker(which) {
        gbdpCloseDatePickers();
        gbdpOpenPicker = which;
        gbdpRenderCalendar(which);
        document.getElementById(which === 'checkin' ? 'gb_checkinPicker' : 'gb_checkoutPicker').classList.add('is-open');
    }

    document.getElementById('gb_checkinTrigger').addEventListener('click', function (e) {
        e.stopPropagation();
        if (gbdpOpenPicker === 'checkin') { gbdpCloseDatePickers(); return; }
        gbdpOpenDatePicker('checkin');
    });
    document.getElementById('gb_checkoutTrigger').addEventListener('click', function (e) {
        e.stopPropagation();
        if (gbdpOpenPicker === 'checkout') { gbdpCloseDatePickers(); return; }
        gbdpOpenDatePicker('checkout');
    });
    document.addEventListener('click', function (e) {
        if (gbdpOpenPicker && !e.target.closest('#gb_checkinPicker') && !e.target.closest('#gb_checkoutPicker')) {
            gbdpCloseDatePickers();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && gbdpOpenPicker) gbdpCloseDatePickers();
    });

    function gbdpSelectDate(which, iso) {
        var hidden = document.getElementById(which === 'checkin' ? 'gb_checkin' : 'gb_checkout');
        var text   = document.getElementById(which === 'checkin' ? 'gb_checkinText' : 'gb_checkoutText');
        hidden.value = iso;
        text.textContent = formatDate(iso);
        text.classList.remove('is-placeholder');

        checkinTrigger.classList.remove('gb-input--error');
        checkoutTrigger.classList.remove('gb-input--error');

        if (which === 'checkin') {
            // If the previously-picked check-out no longer fits (it's now
            // on/before the new check-in, or beyond the next occupied
            // night), clear it so the visitor re-picks one.
            var coEl = document.getElementById('gb_checkout');
            if (coEl.value) {
                var bookedMap    = gbdpBuildBookedMap();
                var guestsWanted = gbdpGuestsWanted();
                var maxIso       = gbdpFirstBlockedAfter(iso, bookedMap, guestsWanted);
                if (coEl.value <= iso || (maxIso && coEl.value > maxIso)) {
                    coEl.value = '';
                    var coText = document.getElementById('gb_checkoutText');
                    coText.textContent = 'Select date';
                    coText.classList.add('is-placeholder');
                }
            }
            gbdpViewMonth.checkout = null;
        }

        gbdpCloseDatePickers();

        // The date-linking listener declared above keeps working off
        // this 'change' event, same as before.
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    form.addEventListener('click', function (e) {
        var navBtn = e.target.closest('[data-gbdp-nav]');
        if (navBtn) {
            e.stopPropagation();
            var which = navBtn.getAttribute('data-gbdp-which');
            var dir   = navBtn.getAttribute('data-gbdp-nav') === 'next' ? 1 : -1;
            var view  = gbdpViewMonth[which];
            var m = view.m + dir, y = view.y;
            if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
            gbdpViewMonth[which] = { y: y, m: m };
            gbdpRenderCalendar(which);
            return;
        }
        var pickBtn = e.target.closest('[data-gbdp-pick]');
        if (pickBtn) {
            e.stopPropagation();
            gbdpSelectDate(pickBtn.getAttribute('data-gbdp-which'), pickBtn.getAttribute('data-gbdp-pick'));
        }
    });

    function gbdpResetDates() {
        document.getElementById('gb_checkin').value  = '';
        document.getElementById('gb_checkout').value = '';
        var ciText = document.getElementById('gb_checkinText');
        var coText = document.getElementById('gb_checkoutText');
        ciText.textContent = 'Select date'; ciText.classList.add('is-placeholder');
        coText.textContent = 'Select date'; coText.classList.add('is-placeholder');
        updateSummary();
    }

    // True when the currently-picked check-in/check-out range is still
    // fully open against the latest fetched availability. Read-only —
    // callers decide what to do when it comes back false.
    function gbdpSelectionStillValid() {
        var ci = document.getElementById('gb_checkin').value;
        var co = document.getElementById('gb_checkout').value;
        if (!ci || !co) return true;

        var bookedMap    = gbdpBuildBookedMap();
        var guestsWanted = gbdpGuestsWanted();
        var cur = gbdpParseISO(ci);
        var end = gbdpParseISO(co);
        while (cur < end) {
            var iso = gbdpISO(cur.getFullYear(), cur.getMonth(), cur.getDate());
            if (gbdpIsBlocked(iso, bookedMap, guestsWanted)) return false;
            cur.setDate(cur.getDate() + 1);
        }
        return true;
    }

    // Fetches fresh availability for `accommodationId`. When
    // `preserveDates` is true (page load, possibly pre-filled from the
    // landing page's booking strip), an existing check-in/check-out is
    // kept if it's still open and cleared with a note otherwise; when
    // false (the visitor changed the room, or the server just rejected a
    // conflicting submit), any previously-picked dates are always
    // cleared since they no longer apply.
    function gbdpLoadAvailability(accommodationId, preserveDates) {
        gbdpAvailability = null;
        gbdpViewMonth = { checkin: null, checkout: null };
        gbdpCloseDatePickers();

        if (!preserveDates) gbdpResetDates();

        if (!accommodationId) return;

        fetch('../api/availability.php?accommodation_id=' + encodeURIComponent(accommodationId))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    gbdpAvailability = {
                        capacity: res.accommodation.capacity,
                        isPool:   res.accommodation.is_pool,
                        bookings: res.bookings,
                    };
                    if (preserveDates && !gbdpSelectionStillValid()) {
                        gbdpResetDates();
                        formErr.textContent = 'Your pre-filled dates are no longer available for this room — please pick new ones.';
                    }
                }
                // Silent otherwise — the picker falls back to an
                // unrestricted calendar rather than breaking the page.
            })
            .catch(function () {});
    }

    roomSel.addEventListener('change', function () {
        gbdpLoadAvailability(roomSel.value, false);
    });

    // Pool bookings are shared by guest count, so changing the guest
    // count can invalidate an already-picked date range — re-check it.
    guestsIn.addEventListener('input', function () {
        if (!gbdpAvailability || !gbdpAvailability.isPool) return;
        if (!gbdpSelectionStillValid()) gbdpResetDates();
    });

    // Initial fetch: the room may already be selected via a `?room=`
    // query param forwarded from the landing page, possibly with
    // pre-filled dates from its own booking strip — keep those if
    // they're still open, per preserveDates=true above.
    gbdpLoadAvailability(roomSel.value, true);

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
            checkinTrigger.classList.add('gb-input--error');
            ok = false;
        }

        if (!cout) {
            fieldErr('gb_checkout_err', 'Please choose a check-out date.');
            checkoutTrigger.classList.add('gb-input--error');
            ok = false;
        }

        if (cin && cout && cout <= cin) {
            fieldErr('gb_checkout_err', 'Check-out must be after check-in.');
            checkoutTrigger.classList.add('gb-input--error');
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

    /* ── form submit: validate, then ask for confirmation ───────────── */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();

        if (!validate()) {
            // Scroll to first error
            var firstErr = form.querySelector('.gb-input--error');
            if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        openConfirm();
    });

    /* ── confirm overlay ─────────────────────────────────────────────
       Shown only after validation passes. The booking is not sent to
       the server until the guest taps "Yes, Confirm Booking" here. */
    function openConfirm() {
        var roomOpt = roomSel.options[roomSel.selectedIndex];
        confirmRoom.textContent     = roomOpt ? roomOpt.text : '—';
        confirmCheckin.textContent  = document.getElementById('gb_checkinText').textContent || '—';
        confirmCheckout.textContent = document.getElementById('gb_checkoutText').textContent || '—';
        var guestsVal = parseInt(guestsIn.value, 10) || 1;
        confirmGuests.textContent   = guestsVal + ' guest' + (guestsVal === 1 ? '' : 's');

        confirmOverlay.removeAttribute('aria-hidden');
        confirmOverlay.classList.add('open');
        confirmBackdrop.classList.add('open');
        document.body.classList.add('gb-modal-open');
        confirmOverlay.focus();
    }

    function closeConfirm() {
        confirmOverlay.setAttribute('aria-hidden', 'true');
        confirmOverlay.classList.remove('open');
        confirmBackdrop.classList.remove('open');
        if (!successOverlay.classList.contains('open') &&
            !tourOverlay.classList.contains('open') &&
            !photoOverlay.classList.contains('open')) {
            document.body.classList.remove('gb-modal-open');
        }
    }

    confirmBackBtn.addEventListener('click', closeConfirm);
    confirmBackdrop.addEventListener('click', closeConfirm);
    confirmYesBtn.addEventListener('click', performBooking);

    /* ── actual submission — only runs after the guest confirms ─────── */
    function performBooking() {
        // Loading state
        submitBtn.disabled = true;
        btnLabel.hidden = true;
        btnSpinner.hidden = false;
        confirmYesBtn.disabled = true;
        confirmBackBtn.disabled = true;
        confirmYesLabel.textContent = 'Submitting…';

        var data = new FormData(form);

        fetch('api/guest-book.php', {
            method: 'POST',
            body: data,
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success) {
                closeConfirm();
                showSuccess(json);
            } else {
                closeConfirm();
                // Server-side validation errors
                formErr.textContent = json.error || 'An error occurred. Please try again.';
                if (json.field_errors) {
                    Object.keys(json.field_errors).forEach(function (field) {
                        fieldErr(field + '_err', json.field_errors[field]);
                        // checkin/checkout error styling has to land on the
                        // visible trigger button, not the hidden input.
                        var el = (field === 'checkin' || field === 'checkout')
                            ? document.getElementById('gb_' + field + 'Trigger')
                            : document.getElementById('gb_' + field);
                        if (el) el.classList.add('gb-input--error');
                    });
                    var firstErr = form.querySelector('.gb-input--error');
                    if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });

                    // A date/room conflict here means the transaction-locked
                    // final check in api/guest-book.php rejected the
                    // booking (someone else took these dates in the
                    // meantime) — refresh availability so the calendar
                    // reflects reality immediately, same as the admin
                    // dashboard's New Booking picker does on conflict.
                    if (json.field_errors.checkin || json.field_errors.room) {
                        gbdpLoadAvailability(roomSel.value, false);
                    }
                }
            }
        })
        .catch(function () {
            closeConfirm();
            formErr.textContent = 'Network error. Please check your connection and try again.';
        })
        .finally(function () {
            submitBtn.disabled = false;
            btnLabel.hidden = false;
            btnSpinner.hidden = true;
            confirmYesBtn.disabled = false;
            confirmBackBtn.disabled = false;
            confirmYesLabel.textContent = 'Yes, Confirm Booking';
        });
    }

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
        if (!successOverlay.classList.contains('open') && !confirmOverlay.classList.contains('open')) {
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
        if (!successOverlay.classList.contains('open') && !tourOverlay.classList.contains('open') && !confirmOverlay.classList.contains('open')) {
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
            else if (confirmOverlay.classList.contains('open')) closeConfirm();
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