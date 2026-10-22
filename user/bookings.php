<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'bookings';
$pageTitle   = 'Book a Stay';

// Accommodation-type filter (Step 1 chips / Step 2 chips). Same categories the
// public listing pages use. ?type=rooms|cottage|venues|pool pre-selects one.
$typeOptions = [
    'all'     => 'All',
    'rooms'   => 'Rooms',
    'cottage' => 'Cottages',
    'venues'  => 'Event Halls',
    'pool'    => 'Pool',
];
$initialType = (string) ($_GET['type'] ?? 'all');
if (!isset($typeOptions[$initialType])) {
    $initialType = 'all';
}

// Pull guest profile for Step 3 pre-fill
$guestProfile = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
$prefillAddress = '';
try {
    $pdo     = bienmarko_db();
    $guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
    $gStmt   = $pdo->prepare(
        "SELECT first_name, last_name, email, phone FROM guests WHERE id = :id LIMIT 1"
    );
    $gStmt->execute(['id' => $guestId]);
    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($gRow) {
        $guestProfile = array_merge($guestProfile, $gRow);
    }
} catch (Throwable $e) {
   
}

// Re-use the address from this guest's most recent booking, if they gave one.
try {
    $aStmt = $pdo->prepare(
        "SELECT address FROM bookings
         WHERE guest_id = :id AND address IS NOT NULL AND address <> ''
         ORDER BY id DESC LIMIT 1"
    );
    $aStmt->execute(['id' => $guestId]);
    $prefillAddress = (string) ($aStmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    // bookings.address may not exist yet on a fresh install — ignore.
}

$prefillName  = trim($guestProfile['first_name'] . ' ' . $guestProfile['last_name']);
$prefillPhone = $guestProfile['phone'] ?? '';
$prefillEmail = $guestProfile['email'] ?? '';

$myBookings = [];
try {
    $pdo = bienmarko_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `bookings` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference`        VARCHAR(20)  NOT NULL UNIQUE,
            `guest_id`         INT UNSIGNED NOT NULL,
            `accommodation_id` INT UNSIGNED NOT NULL,
            `check_in`         DATE         NOT NULL,
            `check_out`        DATE         NOT NULL,
            `guests_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `notes`            TEXT         NULL,
            `status`           ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed',
            `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_guest`         (`guest_id`),
            KEY `idx_accommodation` (`accommodation_id`),
            KEY `idx_dates`         (`check_in`, `check_out`),
            KEY `idx_status`        (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT b.id, b.reference, b.check_in, b.check_out,
                b.guests_count, b.status, b.total_amount, b.notes,
                b.created_at, b.accommodation_id, b.companion_names,
                b.guest_name, b.email AS contact_email, b.phone AS contact_phone,
                b.address AS contact_address,
                a.name AS room_name, a.type AS room_type,
                a.capacity AS room_capacity, a.price AS room_price,
                a.image_filename
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.guest_id = :guest_id
         ORDER BY b.created_at DESC
         LIMIT 10"
    );
    $stmt->execute(['guest_id' => $guestId]);
    $myBookings = $stmt->fetchAll();
} catch (Throwable $e) {
    $myBookings = [];
}

$csrfToken = bienmarko_csrf_token();
$today     = date('Y-m-d');
$imgBase   = '../admin/assets/uploads/accommodations/';

// Status badge colors
function booking_status_class(string $status): string
{
    return match ($status) {
        'confirmed'  => 'badge--green',
        'pending'    => 'badge--amber',
        'cancelled'  => 'badge--red',
        'completed'  => 'badge--blue',
        default      => 'badge--grey',
    };
}

require __DIR__ . '/includes/head.php';
?>

<style>
/* ============================================================
   bookings.php — page-scoped styles
   ============================================================ */

/* ---------- Step indicator ---------- */
.booking-steps {
  display: flex;
  align-items: center;
  gap: 0;
  margin-bottom: 28px;
  overflow-x: auto;
  padding-bottom: 4px;
}
.booking-step {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.booking-step__num {
  width: 30px; height: 30px;
  border-radius: var(--radius-full);
  border: 2px solid var(--line);
  background: var(--surface-raised);
  color: var(--paper-faint);
  font-weight: 700; font-size: 13px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: background .2s, border-color .2s, color .2s;
}
.booking-step__label {
  font-size: 13px; font-weight: 600;
  color: var(--paper-faint);
  transition: color .2s;
}
.booking-step.is-active .booking-step__num {
  background: var(--ember); border-color: var(--ember);
  color: var(--ember-ink);
}
.booking-step.is-active .booking-step__label { color: var(--paper); }
.booking-step.is-done .booking-step__num {
  background: rgba(232,163,61,.18); border-color: var(--ember);
  color: var(--ember);
}
.booking-step.is-done .booking-step__label { color: var(--ember); }
.booking-step__connector {
  flex: 1; min-width: 28px; height: 2px;
  background: var(--line); border-radius: 2px;
  margin: 0 8px;
}

/* ---------- Date trigger buttons (replaces <input type="date">) ---------- */
.date-panel { display: flex; flex-direction: column; gap: 20px; }

.date-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.date-field { display: flex; flex-direction: column; gap: 8px; }
.date-field label {
  font-size: 13px; font-weight: 600; color: var(--paper-dim);
}

.date-trigger {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--surface-raised);
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  color: var(--paper);
  padding: 12px 14px;
  width: 100%;
  font-size: 15px;
  font-family: var(--font-body);
  cursor: pointer;
  text-align: left;
  transition: border-color .15s;
  min-height: 50px;
}
.date-trigger:hover { border-color: var(--ember); }
.date-trigger.is-filled { border-color: rgba(232,163,61,.5); }
.date-trigger svg { flex-shrink: 0; opacity: .6; }
.date-trigger__placeholder { color: var(--paper-faint); }
.date-trigger__value { color: var(--paper); }

.guests-row {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.guests-row label {
  font-size: 13px; font-weight: 600; color: var(--paper-dim);
  flex-shrink: 0;
}
.guests-stepper {
  display: flex; align-items: center; gap: 0;
  border: 1px solid var(--line); border-radius: var(--radius-sm);
  background: var(--surface-raised); overflow: hidden;
}
.guests-stepper button {
  width: 40px; height: 44px;
  background: none; border: none;
  color: var(--paper-dim); font-size: 20px;
  display: flex; align-items: center; justify-content: center;
  transition: color .15s, background .15s;
}
.guests-stepper button:hover { color: var(--paper); background: rgba(255,255,255,.05); }
.guests-stepper .guests-count {
  width: 52px; height: 44px;
  background: none; border: none;
  color: var(--paper); font-size: 15px; font-weight: 600;
  text-align: center; font-family: var(--font-body);
  display: flex; align-items: center; justify-content: center;
}

.night-summary {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px;
  background: rgba(232,163,61,.1);
  border: 1px solid rgba(232,163,61,.3);
  border-radius: var(--radius-sm);
  font-size: 14px; color: var(--ember-strong);
  font-weight: 600;
}
.night-summary .icon { width: 16px; height: 16px; }

/* ---------- Room grid in Step 2 ---------- */
.rooms-section { display: flex; flex-direction: column; gap: 20px; }
.rooms-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
}
.rooms-header h2 { font-size: 19px; }
.rooms-subtitle { font-size: 13px; color: var(--paper-dim); margin-top: 4px; max-width: 520px; }
.rooms-meta { font-size: 13.5px; color: var(--paper-dim); white-space: nowrap; }
.rooms-meta strong { color: var(--paper); }

.rooms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 18px;
}

.room-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  overflow: hidden;
  display: flex; flex-direction: column;
  transition: border-color .2s, transform .15s;
  position: relative;
}
.room-card:hover { border-color: var(--ember-strong); transform: translateY(-2px); }
.room-card.is-occupied { opacity: 0.6; pointer-events: none; }
.room-card.is-unavailable { opacity: 0.5; pointer-events: none; }

.room-card__media {
  position: relative;
  aspect-ratio: 16/10;
  background: var(--surface-raised);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.room-card__media img {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.room-card__media-icon { width: 44px; height: 44px; color: var(--paper-faint); }
.room-card__price-badge {
  position: absolute; right: 10px; bottom: 10px;
  background: rgba(11,13,15,.85);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: var(--radius-full);
  font-family: var(--font-display); font-weight: 700;
  font-size: 14px; color: var(--paper);
  padding: 5px 12px;
}
.room-card__price-badge small {
  font-family: var(--font-body); font-size: 11px;
  color: var(--paper-dim); font-weight: 400;
}

.room-card__avail-badge {
  position: absolute; left: 10px; top: 10px;
  border-radius: var(--radius-full);
  font-size: 11px; font-weight: 700;
  padding: 4px 10px;
  display: flex; align-items: center; gap: 5px;
}
.room-card__avail-badge .dot {
  width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
}
.avail--available   { background: rgba(40,160,90,.9);  color: #fff; }
.avail--available .dot { background: #7fffa8; }
.avail--occupied    { background: rgba(220,100,50,.9); color: #fff; }
.avail--occupied .dot { background: #ffd080; }
.avail--unavailable { background: rgba(80,80,100,.9);  color: #ddd; }
.avail--unavailable .dot { background: #aaa; }

/* "View Calendar" button on each Step 2 room card */
.room-card__cal-btn {
  position: absolute; right: 10px; top: 10px;
  background: rgba(11,13,15,.82);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: var(--radius-full);
  color: var(--paper-dim);
  font-size: 11px; font-weight: 700;
  padding: 5px 11px;
  display: flex; align-items: center; gap: 5px;
  cursor: pointer; z-index: 2;
  transition: color .15s, border-color .15s;
}
.room-card__cal-btn:hover { color: var(--ember); border-color: var(--ember); }
.room-card__cal-btn svg { width: 11px; height: 11px; flex-shrink: 0; }

/* "360° Tour" button on each Step 2 room card — stacks below View Calendar */
.room-card__tour-btn {
  position: absolute; right: 10px; top: 42px;
  background: rgba(11,13,15,.82);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: var(--radius-full);
  color: var(--paper-dim);
  font-size: 11px; font-weight: 700;
  padding: 5px 11px;
  display: flex; align-items: center; gap: 5px;
  cursor: pointer; z-index: 2;
  transition: color .15s, border-color .15s;
}
.room-card__tour-btn:hover { color: var(--ember); border-color: var(--ember); }
.room-card__tour-btn svg { width: 11px; height: 11px; flex-shrink: 0; }

/* Click-to-enlarge room photo */
.room-card__media--zoomable { cursor: zoom-in; }
.room-card__zoom-hint {
  position: absolute; left: 10px; bottom: 10px;
  display: flex; align-items: center; gap: 5px;
  background: rgba(11,13,15,.78);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: var(--radius-full);
  font-size: 11px; font-weight: 600; color: var(--paper-dim);
  padding: 4px 10px;
  opacity: 0; transform: translateY(4px);
  transition: opacity .15s, transform .15s;
  pointer-events: none;
}
.room-card__media--zoomable:hover .room-card__zoom-hint { opacity: 1; transform: translateY(0); }
.room-card__zoom-hint svg { width: 12px; height: 12px; flex-shrink: 0; }

.room-card__body { padding: 16px; display: flex; flex-direction: column; gap: 12px; flex: 1; }
.room-card__head { display: flex; flex-direction: column; gap: 3px; }
.room-card__name { font-size: 16px; font-weight: 700; line-height: 1.3; }
.room-card__type { font-size: 12px; color: var(--paper-dim); }

.room-card__meta {
  display: flex; align-items: center; gap: 6px;
  font-size: 13px; color: var(--paper-dim);
}
.room-card__meta .icon { width: 14px; height: 14px; }

.room-card__amenities { display: flex; flex-wrap: wrap; gap: 5px; }
.room-tag {
  font-size: 11px; font-weight: 600;
  background: var(--surface-raised); border: 1px solid var(--line);
  border-radius: var(--radius-full); padding: 3px 9px;
  color: var(--paper-dim);
}
.room-tag--more { color: var(--ember); border-color: rgba(232,163,61,.4); }

.room-card__desc {
  font-size: 13px; color: var(--paper-dim); line-height: 1.5;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
  overflow: hidden;
}

.room-card__footer {
  padding: 12px 16px;
  border-top: 1px solid var(--line);
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px;
}
.room-card__total {
  font-family: var(--font-display); font-weight: 700; font-size: 17px;
  color: var(--paper);
}
.room-card__total small {
  font-family: var(--font-body); font-size: 11px;
  color: var(--paper-dim); font-weight: 400;
}

.btn-book {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 16px;
  background: var(--ember); color: var(--ember-ink);
  border-radius: var(--radius-full);
  font-weight: 700; font-size: 13.5px;
  border: none;
  transition: background .15s, transform .1s;
  cursor: pointer;
}
.btn-book:hover { background: var(--ember-strong); }
.btn-book:active { transform: scale(.97); }
.btn-book .icon { width: 14px; height: 14px; }

/* ---------- Skeleton loader ---------- */
.rooms-skeleton { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 18px; }
.skeleton-card {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--radius-lg); overflow: hidden;
}
.skeleton-media {
  aspect-ratio: 16/10;
  background: linear-gradient(90deg, var(--surface-raised) 25%, var(--line) 50%, var(--surface-raised) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s infinite;
}
.skeleton-body { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.skeleton-line {
  height: 14px; border-radius: 6px;
  background: linear-gradient(90deg, var(--surface-raised) 25%, var(--line) 50%, var(--surface-raised) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s infinite;
}
.skeleton-line--short { width: 55%; }
.skeleton-line--medium { width: 75%; }
@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ---------- No-results ---------- */
.rooms-empty {
  text-align: center; padding: 48px 24px; color: var(--paper-dim);
}
.rooms-empty__icon {
  width: 56px; height: 56px; border-radius: var(--radius-full);
  background: rgba(232,163,61,.1); border: 1px solid rgba(232,163,61,.3);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.rooms-empty__icon .icon { width: 26px; height: 26px; }
.rooms-empty h3 { font-size: 17px; margin-bottom: 6px; }
.rooms-empty p  { font-size: 14px; max-width: 340px; margin: 0 auto; }

/* ---------- Step 3: Guest info ---------- */
.guest-info-panel { display: flex; flex-direction: column; gap: 20px; }
.guest-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.guest-info-field { display: flex; flex-direction: column; gap: 7px; }
.guest-info-field label {
  font-size: 13px; font-weight: 600; color: var(--paper-dim);
}
.guest-info-field input {
  background: var(--surface-raised);
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  color: var(--paper);
  padding: 12px 14px;
  width: 100%;
  font-size: 15px;
  font-family: var(--font-body);
  transition: border-color .15s;
}
.guest-info-field input:focus { border-color: var(--ember); outline: none; }
.guest-info-field select {
  background: var(--surface-raised);
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  color: var(--paper);
  padding: 12px 14px;
  width: 100%;
  font-size: 15px;
  font-family: var(--font-body);
  transition: border-color .15s;
  cursor: pointer;
}
.guest-info-field select:focus { border-color: var(--ember); outline: none; }
.guest-info-field .req { color: var(--ember); font-weight: 700; }
.pay-method {
  display: flex; flex-direction: column; gap: 2px;
  background: var(--surface-raised); border: 1px solid var(--ember);
  border-radius: var(--radius-sm); padding: 12px 14px; font-size: 13px; color: var(--paper-dim);
}
.pay-method strong { font-size: 15px; color: var(--paper); }
.guest-info-field select option,
.guest-info-field select optgroup { background: var(--surface-raised); color: var(--paper); }
.guest-info-field select option:disabled { color: var(--paper-faint); }
.guest-info-field .field-hint {
  font-size: 12px; color: var(--paper-faint);
}

/* Additional guest names (capacity-based) */
.companion-list { display: flex; flex-direction: column; gap: 8px; }
.companion-row { display: flex; gap: 8px; }
.companion-row input {
  flex: 1;
  background: var(--surface-raised);
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  color: var(--paper);
  padding: 10px 12px;
  font-size: 14px;
  font-family: var(--font-body);
  transition: border-color .15s;
}
.companion-row input:focus { border-color: var(--ember); outline: none; }
.companion-row__remove {
  flex-shrink: 0;
  width: 38px;
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  background: var(--surface-raised);
  color: var(--paper-dim);
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  transition: border-color .15s, color .15s;
}
.companion-row__remove:hover { border-color: var(--ember); color: var(--ember); }
#s3AddCompanionBtn:disabled { opacity: .5; cursor: not-allowed; }
#s3AddCompanionBtn:disabled:hover { background: transparent; }
#s3CompanionsField[hidden] { display: none !important; }

.booking-summary-box {
  background: var(--surface-raised);
  border: 1px solid var(--line);
  border-radius: var(--radius-md);
  padding: 16px 18px;
}
.booking-summary-box__title {
  font-size: 12px; font-weight: 700; color: var(--paper-dim);
  text-transform: uppercase; letter-spacing: .06em;
  margin-bottom: 12px;
}
.booking-summary-row {
  display: flex; justify-content: space-between; align-items: baseline;
  font-size: 14px; color: var(--paper-dim);
  padding: 4px 0;
}
.booking-summary-row strong { color: var(--paper); font-weight: 600; }
.booking-summary-row.total {
  border-top: 1px solid var(--line);
  margin-top: 8px; padding-top: 12px;
  font-size: 15px; font-weight: 700;
  color: var(--ember-strong);
}
.booking-summary-row.total strong { color: var(--ember-strong); font-size: 17px; font-family: var(--font-display); }

.step3-actions {
  display: flex; gap: 12px; flex-wrap: wrap;
}

/* ---------- Booking confirm modal ---------- */
.bk-modal {
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  background: var(--surface-raised);
  color: var(--paper);
  padding: 0;
  width: min(94vw, 460px);
  max-height: calc(100vh - 24px);          /* fallback for older browsers */
  max-height: calc(100dvh - 24px);         /* never taller than the screen */
  box-shadow: 0 24px 60px -20px rgba(0,0,0,.7);
  overflow: hidden;
}
/* The inner wrapper scrolls when the content is taller than the screen,
   so the header stays reachable and the action buttons are always visible. */
.bk-modal > div[id$="ModalContent"] {
  max-height: calc(100vh - 28px);
  max-height: calc(100dvh - 28px);
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: var(--line) transparent;
}
.bk-modal::backdrop { background: rgba(0,0,0,.65); backdrop-filter: blur(3px); }
.bk-modal__head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 20px 0;
}
.bk-modal__head h2 { font-size: 19px; }
.bk-modal__close {
  width: 32px; height: 32px; border-radius: var(--radius-full);
  background: var(--surface); border: 1px solid var(--line);
  color: var(--paper-dim); display: flex; align-items: center; justify-content: center;
  cursor: pointer;
}
.bk-modal__close .icon { width: 15px; height: 15px; }
.bk-modal__close:hover { color: var(--paper); border-color: var(--ember); }

.bk-modal__room {
  display: flex; align-items: center; gap: 14px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--line);
}
.bk-modal__room-img {
  width: 72px; height: 56px; border-radius: var(--radius-sm);
  background: var(--surface); border: 1px solid var(--line);
  object-fit: cover; flex-shrink: 0; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
.bk-modal__room-img img { width: 100%; height: 100%; object-fit: cover; }
.bk-modal__room-img .icon { width: 26px; height: 26px; color: var(--paper-faint); }
.bk-modal__room-name { font-size: 16px; font-weight: 700; }
.bk-modal__room-type { font-size: 12px; color: var(--paper-dim); margin-top: 2px; }

.bk-modal__details { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }
.bk-detail-row {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 12px; font-size: 14px;
}
.bk-detail-row__label { color: var(--paper-dim); flex-shrink: 0; }
.bk-detail-row__value { font-weight: 600; text-align: right; }
.bk-detail-divider { height: 1px; background: var(--line); margin: 4px 0; }
.bk-detail-row--total .bk-detail-row__label { font-weight: 700; color: var(--paper); }
.bk-detail-row--total .bk-detail-row__value {
  font-family: var(--font-display); font-size: 18px; color: var(--ember-strong);
}

.bk-modal__notes-field { padding: 0 20px; }
.bk-modal__notes-field label { font-size: 13px; font-weight: 600; color: var(--paper-dim); display: block; margin-bottom: 6px; }
.bk-modal__notes-field textarea {
  width: 100%; resize: vertical; min-height: 70px;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--radius-sm); color: var(--paper);
  padding: 10px 12px; font-family: var(--font-body); font-size: 14px;
  transition: border-color .15s;
}
.bk-modal__notes-field textarea:focus { border-color: var(--ember); outline: none; }
.bk-modal__notes-field textarea::placeholder { color: var(--paper-faint); }

.bk-modal__foot {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 16px 20px;
  border-top: 1px solid var(--line);
  /* stay pinned to the bottom so the buttons never scroll out of view */
  position: sticky; bottom: 0; z-index: 2;
  background: var(--surface-raised);
}
.bk-modal__error {
  font-size: 13px; color: var(--danger);
  min-height: 1px; flex: 1;
}
.bk-modal__foot-actions { display: flex; gap: 10px; flex-shrink: 0; }

.btn--cancel {
  padding: 10px 16px;
  background: transparent; border: 1px solid var(--line);
  border-radius: var(--radius-full); color: var(--paper-dim);
  font-weight: 600; font-size: 14px; cursor: pointer;
  transition: border-color .15s, color .15s;
}
.btn--cancel:hover { border-color: var(--ember); color: var(--paper); }

.bk-modal__confirm-step {
  display: none; flex-direction: column; gap: 14px;
  padding: 16px 20px;
  border-top: 1px solid var(--line);
  /* pinned so "Yes, Confirm Booking" is always on screen */
  position: sticky; bottom: 0; z-index: 2;
  background: var(--surface-raised);
}
.bk-modal__confirm-step.is-visible { display: flex; }
.bk-modal__confirm-text {
  margin: 0; font-size: 13.5px; line-height: 1.5; color: var(--paper-dim);
}
.bk-modal__confirm-actions { display: flex; justify-content: flex-end; gap: 10px; }

@media (max-height: 760px) {
  .bk-modal__head { padding-top: 14px; }
  .bk-modal__room { padding: 10px 20px; }
  .bk-modal__details { padding: 10px 20px; gap: 7px; }
  .bk-modal__foot,
  .bk-modal__confirm-step { padding-top: 12px; padding-bottom: 12px; }
}

/* ---------- Success screen ---------- */
.bk-success {
  display: none; flex-direction: column; align-items: center;
  text-align: center; padding: 36px 24px;
}
.bk-success.is-visible { display: flex; }
.bk-success__icon {
  width: 64px; height: 64px; border-radius: var(--radius-full);
  background: rgba(40,180,100,.18); border: 2px solid rgba(40,180,100,.4);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 16px;
}
.bk-success__icon .icon { width: 30px; height: 30px; }
.bk-success h2 { font-size: 22px; margin-bottom: 8px; }
.bk-success p { font-size: 14px; color: var(--paper-dim); line-height: 1.6; max-width: 320px; margin: 0 auto 6px; }
.bk-success__ref {
  display: inline-block; margin: 12px 0;
  background: rgba(232,163,61,.15); border: 1px solid rgba(232,163,61,.35);
  border-radius: var(--radius-sm); padding: 8px 18px;
  font-family: var(--font-display); font-size: 18px; font-weight: 700;
  color: var(--ember-strong);
  letter-spacing: .05em;
}
.bk-success__actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; justify-content: center; }

/* ---------- My Bookings list ---------- */
.bk-list { display: flex; flex-direction: column; gap: 12px; }
.bk-item {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 14px 16px;
  background: var(--surface-raised); border: 1px solid var(--line);
  border-radius: var(--radius-md);
}
.bk-item__img {
  width: 84px; height: 84px;
  border-radius: var(--radius-sm);
  background: var(--surface); border: 1px solid var(--line);
  flex-shrink: 0; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
.bk-item__img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.bk-item__img .icon { width: 26px; height: 26px; color: var(--paper-faint); }
.bk-item__ref {
  font-family: var(--font-display); font-size: 13px; font-weight: 700;
  color: var(--ember); letter-spacing: .04em; flex-shrink: 0;
  min-width: 120px;
}
.bk-item__body { flex: 1; min-width: 0; }
.bk-item__room { font-weight: 700; font-size: 14.5px; margin-bottom: 2px; }
.bk-item__dates { font-size: 13px; color: var(--paper-dim); }
.bk-item__meta { font-size: 12px; color: var(--paper-faint); margin-top: 2px; }
.bk-item__right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.bk-item__amount { font-family: var(--font-display); font-size: 15px; font-weight: 700; }
.bk-item__actions { display: flex; gap: 6px; margin-top: 2px; flex-wrap: wrap; justify-content: flex-end; }
.bk-action-btn {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 5px 10px;
  background: transparent; border: 1px solid var(--line);
  border-radius: var(--radius-full);
  color: var(--paper-dim); font-size: 11.5px; font-weight: 600;
  font-family: var(--font-body); cursor: pointer; white-space: nowrap;
  transition: border-color .15s, color .15s;
}
.bk-action-btn .icon { width: 12px; height: 12px; }
.bk-action-btn--edit:hover { border-color: var(--ember); color: var(--paper); }
.bk-action-btn--cancel:hover { border-color: var(--danger); color: var(--danger); }
.bk-item__note { font-size: 11px; color: var(--paper-faint); text-align: right; max-width: 150px; margin-top: 2px; }
.bk-item--flash { animation: bkItemFlash 1.6s ease; }
@keyframes bkItemFlash {
  0%   { background: rgba(232,163,61,.18); }
  100% { background: var(--surface-raised); }
}
.badge {
  display: inline-flex; align-items: center;
  padding: 3px 9px; border-radius: var(--radius-full);
  font-size: 11px; font-weight: 700; letter-spacing: .03em;
}
.badge--green  { background: rgba(40,160,90,.2);  color: #4fd882; }
.badge--amber  { background: rgba(220,160,40,.2); color: var(--ember-strong); }
.badge--red    { background: rgba(220,80,60,.2);  color: var(--danger); }
.badge--blue   { background: rgba(80,140,220,.2); color: #86b8e8; }
.badge--grey   { background: rgba(140,140,150,.2); color: var(--paper-dim); }

/* ---------- Error state ---------- */
.api-error {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 16px;
  background: rgba(220,80,60,.1); border: 1px solid rgba(220,80,60,.3);
  border-radius: var(--radius-sm); color: var(--danger); font-size: 14px;
}
.api-error .icon { width: 18px; height: 18px; flex-shrink: 0; }

/* ============================================================
   CALENDAR MODAL  — extended for range-picking mode
   ============================================================ */
.cal-modal {
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  background: var(--surface-raised);
  color: var(--paper);
  padding: 0;
  width: min(96vw, 520px);
  box-shadow: 0 24px 60px -20px rgba(0,0,0,.75);
  overflow: hidden;
}
.cal-modal::backdrop { background: rgba(0,0,0,.7); backdrop-filter: blur(4px); }

.cal-modal__head {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 18px 20px 14px;
  border-bottom: 1px solid var(--line);
  gap: 12px;
}
.cal-modal__title { font-size: 17px; font-weight: 700; }
.cal-modal__subtitle { font-size: 12px; color: var(--paper-dim); margin-top: 2px; }
.cal-modal__close {
  width: 30px; height: 30px; flex-shrink: 0;
  border-radius: var(--radius-full);
  background: var(--surface); border: 1px solid var(--line);
  color: var(--paper-dim);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; margin-top: 2px;
}
.cal-modal__close:hover { color: var(--paper); border-color: var(--ember); }
.cal-modal__close svg { width: 14px; height: 14px; }

/* Legend */
.cal-legend {
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  padding: 10px 20px;
  border-bottom: 1px solid var(--line);
  background: var(--surface);
}
.cal-legend-item {
  display: flex; align-items: center; gap: 5px;
  font-size: 11px; color: var(--paper-dim); font-weight: 600;
}
.cal-legend-dot {
  width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0;
}
.cal-legend-dot--available   { background: rgba(40,160,90,.7); }
.cal-legend-dot--booked      { background: rgba(220,80,60,.7); }
.cal-legend-dot--maintenance { background: rgba(5, 9, 205, 0.7); }
.cal-legend-dot--unavailable { background: rgba(100,100,120,.7); }
.cal-legend-dot--selected    { background: var(--ember); }

/* Month nav */
.cal-body { padding: 16px 20px 20px; }
.cal-nav {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px;
}
.cal-nav__btn {
  width: 32px; height: 32px;
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--radius-full); color: var(--paper-dim);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: color .15s, border-color .15s;
}
.cal-nav__btn:hover { color: var(--ember); border-color: var(--ember); }
.cal-nav__btn svg { width: 14px; height: 14px; }
.cal-nav__btn:disabled { opacity: .35; cursor: not-allowed; pointer-events: none; }
.cal-nav__label { font-size: 15px; font-weight: 700; }

/* Grid */
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 3px;
}
.cal-day-header {
  text-align: center;
  font-size: 10px; font-weight: 700; letter-spacing: .06em;
  color: var(--paper-faint);
  padding-bottom: 6px;
  text-transform: uppercase;
}
.cal-day {
  aspect-ratio: 1;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--radius-sm);
  font-size: 12px; font-weight: 600;
  position: relative;
  transition: background .12s;
}
.cal-day--empty { background: transparent; }
.cal-day--today {
  color: var(--ember);
  font-weight: 800;
}
.cal-day--today::after {
  content: '';
  position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%);
  width: 4px; height: 4px; border-radius: 50%;
  background: var(--ember);
}
.cal-day--past {
  color: var(--paper-faint);
  background: transparent;
  cursor: not-allowed;
}
.cal-day--available {
  background: rgba(40,160,90,.15);
  color: #5de89a;
}
/* Range-pick mode: clickable available days */
.cal-pick-mode .cal-day--available {
  cursor: pointer;
}
.cal-pick-mode .cal-day--available:hover {
  background: rgba(232,163,61,.25);
  color: var(--ember-strong);
}
.cal-day--booked {
  background: rgba(220,80,60,.2);
  color: #f08080;
  text-decoration: line-through;
  cursor: not-allowed;
}
.cal-day--maintenance {
  background: rgba(220,160,20,.2);
  color: #e8c23d;
  cursor: not-allowed;
}
.cal-day--unavailable {
  background: rgba(100,100,120,.18);
  color: var(--paper-faint);
  cursor: not-allowed;
}
/* Selected range styles */
.cal-day--sel-start,
.cal-day--sel-end {
  background: var(--ember) !important;
  color: var(--ember-ink) !important;
  font-weight: 800;
}
.cal-day--in-range {
  background: rgba(232,163,61,.18) !important;
  color: var(--ember-strong) !important;
  border-radius: 0;
}
.cal-day--in-range.cal-day--sel-start { border-radius: var(--radius-sm) 0 0 var(--radius-sm); }
.cal-day--in-range.cal-day--sel-end   { border-radius: 0 var(--radius-sm) var(--radius-sm) 0; }

/* Range-pick hint bar */
.cal-pick-hint {
  font-size: 12px; color: var(--paper-dim);
  text-align: center; margin-top: 12px;
  min-height: 18px;
}
.cal-pick-hint strong { color: var(--ember-strong); }

/* Range-pick footer */
.cal-pick-footer {
  display: none;
  align-items: center; justify-content: flex-end; gap: 10px;
  padding: 14px 20px;
  border-top: 1px solid var(--line);
}
.cal-pick-footer.is-visible { display: flex; }

/* Loading spinner in calendar */
.cal-loading {
  display: flex; align-items: center; justify-content: center;
  gap: 10px; padding: 32px 0;
  color: var(--paper-dim); font-size: 14px;
}
.cal-spinner {
  width: 20px; height: 20px;
  border: 2px solid var(--line);
  border-top-color: var(--ember);
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ============================================================
   PHOTO MODAL — enlarged view of a room photo (Step 2)
   ============================================================ */
.photo-modal {
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  background: var(--surface-raised);
  color: var(--paper);
  padding: 0;
  width: min(94vw, 720px);
  box-shadow: 0 24px 60px -20px rgba(0,0,0,.75);
  overflow: hidden;
}
.photo-modal::backdrop { background: rgba(0,0,0,.78); backdrop-filter: blur(4px); }
.photo-modal__close {
  position: absolute; right: 12px; top: 12px; z-index: 2;
  width: 32px; height: 32px; border-radius: var(--radius-full);
  background: rgba(11,13,15,.75); border: 1px solid rgba(255,255,255,.18);
  color: var(--paper); display: flex; align-items: center; justify-content: center;
  cursor: pointer;
}
.photo-modal__close:hover { color: var(--ember); border-color: var(--ember); }
.photo-modal__close svg { width: 15px; height: 15px; }
.photo-modal__img-wrap {
  position: relative;
  width: 100%; max-height: 78vh;
  background: var(--surface);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.photo-modal__img-wrap img {
  width: 100%; max-height: 78vh; object-fit: contain; display: block;
}
.photo-modal__nav {
  position: absolute; top: 50%; transform: translateY(-50%); z-index: 2;
  width: 38px; height: 38px; border-radius: var(--radius-full);
  background: rgba(11,13,15,.72); border: 1px solid rgba(255,255,255,.18);
  color: var(--paper); display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background .15s, border-color .15s;
}
.photo-modal__nav:hover { background: var(--ember); border-color: var(--ember); color: #1a1200; }
.photo-modal__nav[hidden] { display: none; }
.photo-modal__nav--prev { left: 12px; }
.photo-modal__nav--next { right: 12px; }
.photo-modal__caption {
  padding: 14px 20px;
  border-top: 1px solid var(--line);
  font-size: 14.5px; font-weight: 700; color: var(--paper);
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.photo-modal__counter {
  font-size: 12px; font-weight: 600; color: var(--paper-dim); white-space: nowrap;
}

/* ============================================================
   TOUR MODAL — 360° virtual tour viewer (Step 2)
   ============================================================ */
.tour-modal {
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  background: var(--surface-raised);
  color: var(--paper);
  padding: 0;
  width: min(94vw, 960px);
  box-shadow: 0 24px 60px -20px rgba(0,0,0,.75);
  overflow: hidden;
}
.tour-modal::backdrop { background: rgba(0,0,0,.82); backdrop-filter: blur(4px); }
.tour-modal__close {
  position: absolute; right: 12px; top: 12px; z-index: 2;
  width: 32px; height: 32px; border-radius: var(--radius-full);
  background: rgba(11,13,15,.75); border: 1px solid rgba(255,255,255,.18);
  color: var(--paper); display: flex; align-items: center; justify-content: center;
  cursor: pointer;
}
.tour-modal__close:hover { color: var(--ember); border-color: var(--ember); }
.tour-modal__close svg { width: 15px; height: 15px; }
.tour-modal__frame-wrap {
  position: relative;
  width: 100%; height: min(70vh, 560px);
  background: #000;
}
.tour-modal__frame-wrap iframe {
  width: 100%; height: 100%; border: 0; display: block;
}
.tour-modal__caption {
  padding: 14px 20px;
  border-top: 1px solid var(--line);
  font-size: 14.5px; font-weight: 700; color: var(--paper);
}

/* ---------- Responsive ---------- */
/* ---------- Accommodation catalog (Step 1, shown before dates are picked) ---------- */
.catalog { margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: 16px; }
.catalog__head h3 { font-size: 18px; }
.catalog__head p { font-size: 13px; color: var(--paper-dim); margin-top: 4px; max-width: 560px; }
.catalog__loading, .catalog__empty { font-size: 13.5px; color: var(--paper-dim); padding: 12px 0; }
.catalog-card .room-card__desc { display: block; -webkit-line-clamp: unset; overflow: visible; }
.catalog-card .room-card__amenities { gap: 6px; }
.catalog-card__label {
  font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  color: var(--paper-faint); margin-bottom: -4px;
}
.catalog-card__status {
  position: absolute; left: 10px; top: 10px; z-index: 1;
  border-radius: var(--radius-full); font-size: 11px; font-weight: 700;
  padding: 4px 10px; background: rgba(80,80,100,.9); color: #ddd;
}
.catalog-card__hint { font-size: 12px; color: var(--paper-faint); font-weight: 600; }
.catalog-card__pick {
  font-family: var(--font-body); font-size: 12px; font-weight: 700; cursor: pointer;
  background: transparent; color: var(--ember); border: 1px solid var(--ember);
  border-radius: var(--radius-full); padding: 7px 14px;
}
.catalog-card__pick:hover { background: var(--ember); color: var(--ember-ink); }

/* ---------- Accommodation type chips (Step 1 + Step 2) ---------- */
.type-field { display: flex; flex-direction: column; gap: 8px; }
.type-field > label { font-size: 13px; font-weight: 600; color: var(--paper-dim); }
.type-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.type-chip {
  display: inline-flex; align-items: center; justify-content: center;
  min-height: 40px; padding: 0 16px;
  border: 1px solid var(--line); border-radius: var(--radius-full);
  background: var(--surface-raised); color: var(--paper-dim);
  font-family: var(--font-body); font-size: 13px; font-weight: 600;
  cursor: pointer; transition: background .15s, border-color .15s, color .15s;
}
.type-chip:hover { border-color: var(--ember); color: var(--paper); }
.type-chip[aria-pressed="true"] {
  background: var(--ember); border-color: var(--ember); color: var(--ember-ink);
}
.type-chip__count { margin-left: 6px; font-weight: 500; opacity: .75; }
.rooms-section > .type-chips { margin-top: -6px; }
/* The results line is longer now ("5 accommodations · 2 nights · …"); let it wrap on phones. */
.rooms-header > div, .rooms-meta { min-width: 0; }
@media (max-width: 600px) { .rooms-meta { white-space: normal; } }

@media (max-width: 600px) {
  .date-row { grid-template-columns: 1fr; }
  .booking-step__label { display: none; }
  .bk-item { flex-direction: column; gap: 8px; }
  .bk-item__img { width: 100%; height: 140px; }
  .bk-item__right { flex-direction: row; align-items: center; flex-wrap: wrap; }
  .bk-item__actions { justify-content: flex-start; }
  .cal-modal { width: 96vw; }
  .photo-modal { width: 96vw; }
  .photo-modal__nav { width: 32px; height: 32px; }
  .tour-modal { width: 96vw; }
  .tour-modal__frame-wrap { height: 60vh; }
  .guest-info-grid { grid-template-columns: 1fr; }
}
</style>

<main class="page" id="main-content">

    <!-- Page header -->
    <div class="page-head">
        <div class="page-head__text">
            <h1 class="page-head__title">Book a Stay</h1>
            <p class="page-head__subtitle">Pick a type of accommodation and your dates to see real-time availability.</p>
        </div>
    </div>

    <!-- Step indicator -->
    <div class="booking-steps" id="bookingSteps" aria-label="Booking steps">
        <div class="booking-step is-active" id="step1Indicator">
            <span class="booking-step__num">1</span>
            <span class="booking-step__label">Select Dates</span>
        </div>
        <div class="booking-step__connector"></div>
        <div class="booking-step" id="step2Indicator">
            <span class="booking-step__num">2</span>
            <span class="booking-step__label">Choose Stay</span>
        </div>
        <div class="booking-step__connector"></div>
        <div class="booking-step" id="step3Indicator">
            <span class="booking-step__num">3</span>
            <span class="booking-step__label">Confirm</span>
        </div>
    </div>

    <div class="content-grid content-grid--stack">

        <!-- ===== STEP 1: Date + Guest picker ===== -->
        <section class="panel" id="step1Panel">
            <div class="panel__head">
                <h2>When would you like to stay?</h2>
                <p class="panel__subtitle">Click a date field to open the calendar and pick your check-in and check-out dates.</p>
            </div>

            <div class="date-panel">
                <div class="type-field">
                    <label id="typeLabel">What would you like to book?</label>
                    <div class="type-chips" id="typeChipsStep1" role="group" aria-labelledby="typeLabel">
                        <?php foreach ($typeOptions as $typeKey => $typeLabel): ?>
                        <button type="button" class="type-chip" data-type="<?php echo htmlspecialchars($typeKey); ?>"
                                aria-pressed="<?php echo $typeKey === $initialType ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($typeLabel); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="date-row">
                    <div class="date-field">
                        <label>Check-in date</label>
                        <button type="button" class="date-trigger" id="triggerCheckIn"
                                aria-label="Select check-in date" aria-haspopup="dialog">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span id="displayCheckIn" class="date-trigger__placeholder">Select check-in date</span>
                        </button>
                    </div>
                    <div class="date-field">
                        <label>Check-out date</label>
                        <button type="button" class="date-trigger" id="triggerCheckOut"
                                aria-label="Select check-out date" aria-haspopup="dialog">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span id="displayCheckOut" class="date-trigger__placeholder">Select check-out date</span>
                        </button>
                    </div>
                </div>

                <div class="guests-row">
                    <label>Number of guests</label>
                    <div class="guests-stepper">
                        <button type="button" id="guestsMinus" aria-label="Decrease guests">−</button>
                        <span class="guests-count" id="guestsDisplay" aria-live="polite">1</span>
                        <button type="button" id="guestsPlus" aria-label="Increase guests">+</button>
                    </div>
                </div>

                <div id="nightSummary" style="display:none" class="night-summary">
                    <?php render_icon('calendar-check'); ?>
                    <span id="nightSummaryText"></span>
                </div>

                <div id="dateError" class="api-error" style="display:none">
                    <?php render_icon('circle-help'); ?>
                    <span id="dateErrorText"></span>
                </div>

                <button type="button" class="btn btn--primary" id="checkAvailBtn" style="align-self:flex-start">
                    <?php render_icon('bed-double'); ?> Check Availability
                </button>
            </div>

            <!-- What's inside each accommodation — visible before any dates are chosen -->
            <div class="catalog" id="catalogSection">
                <div class="catalog__head">
                    <h3 id="catalogTitle">What's inside each accommodation</h3>
                    <p>Browse photos, inclusions and rates below. Pick your dates above to see what's free.</p>
                </div>
                <div class="catalog__loading" id="catalogLoading">Loading accommodations…</div>
                <div class="rooms-grid" id="catalogGrid" style="display:none"></div>
                <div class="catalog__empty" id="catalogEmpty" style="display:none"></div>
            </div>
        </section>

        <!-- ===== STEP 2: Room results ===== -->
        <section class="panel" id="step2Panel" style="display:none">
            <div class="rooms-section">
                <div class="rooms-header">
                    <div>
                        <h2 id="roomsTitle">Available Accommodations</h2>
                        <p class="rooms-subtitle">Switch type below, click <strong>View Calendar</strong> to check a schedule, or click a photo to view it larger.</p>
                    </div>
                    <span class="rooms-meta" id="roomsMeta"></span>
                </div>

                <div class="type-chips" id="typeChipsStep2" role="group" aria-label="Filter by accommodation type" style="display:none"></div>

                <div class="rooms-skeleton" id="roomsSkeleton" style="display:none">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="skeleton-card">
                        <div class="skeleton-media"></div>
                        <div class="skeleton-body">
                            <div class="skeleton-line skeleton-line--medium"></div>
                            <div class="skeleton-line skeleton-line--short"></div>
                            <div class="skeleton-line skeleton-line--medium"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <div class="api-error" id="roomsError" style="display:none">
                    <?php render_icon('circle-help'); ?>
                    <span id="roomsErrorText">Could not load rooms. Please try again.</span>
                </div>

                <div class="rooms-grid" id="roomsGrid" style="display:none"></div>

                <div class="rooms-empty" id="roomsEmpty" style="display:none">
                    <div class="rooms-empty__icon"><?php render_icon('bed-double'); ?></div>
                    <h3 id="roomsEmptyTitle">No accommodations available</h3>
                    <p id="roomsEmptyText">Everything is either occupied or unavailable for those dates. Try different dates.</p>
                </div>

                <button type="button" class="btn btn--ghost" id="changeDatesBtn" style="align-self:flex-start; margin-top:8px">
                    ← Change dates
                </button>
            </div>
        </section>

        <!-- ===== STEP 3: Guest info + summary ===== -->
        <section class="panel" id="step3Panel" style="display:none">
            <div class="panel__head">
                <h2>Your Details</h2>
                <p class="panel__subtitle">Confirm your information before finalizing the booking.</p>
            </div>

            <div class="guest-info-panel">

                <div id="step3Error" class="api-error" style="display:none">
                    <?php render_icon('circle-help'); ?>
                    <span id="step3ErrorText"></span>
                </div>

                <div class="guest-info-grid">
                    <div class="guest-info-field">
                        <label for="guestFirstName">First Name <span class="req">*</span></label>
                        <input type="text" id="guestFirstName" maxlength="80" required
                               value="<?php echo htmlspecialchars((string) $guestProfile['first_name']); ?>"
                               placeholder="Juan" autocomplete="given-name">
                    </div>
                    <div class="guest-info-field">
                        <label for="guestLastName">Last Name <span class="req">*</span></label>
                        <input type="text" id="guestLastName" maxlength="80" required
                               value="<?php echo htmlspecialchars((string) $guestProfile['last_name']); ?>"
                               placeholder="Dela Cruz" autocomplete="family-name">
                    </div>
                    <div class="guest-info-field">
                        <label for="guestEmail">Email Address <span class="req">*</span></label>
                        <input type="email" id="guestEmail" maxlength="200" required inputmode="email"
                               value="<?php echo htmlspecialchars((string) $prefillEmail); ?>"
                               placeholder="juan@example.com" autocomplete="email">
                        <span class="field-hint">Your confirmation is sent here.</span>
                    </div>
                    <div class="guest-info-field">
                        <label for="guestPhone">Phone Number <span class="req">*</span></label>
                        <input type="tel" id="guestPhone" maxlength="30" required
                               value="<?php echo htmlspecialchars($prefillPhone); ?>"
                               placeholder="+63 9XX XXX XXXX"
                               autocomplete="tel">
                        <span class="field-hint">For booking confirmation contact.</span>
                    </div>
                    <div class="guest-info-field" style="grid-column:1/-1">
                        <label for="guestAddress">Home Address <span style="font-weight:400;color:var(--paper-faint)">(optional)</span></label>
                        <input type="text" id="guestAddress" maxlength="255"
                               value="<?php echo htmlspecialchars($prefillAddress); ?>"
                               placeholder="Street, Barangay, City / Municipality, Province"
                               autocomplete="street-address">
                        <span class="field-hint">Pre-filled from your account and last booking — update if needed.</span>
                    </div>

                    <div class="guest-info-field" style="grid-column:1/-1">
                        <label>Payment Method</label>
                        <div class="pay-method">
                            <strong>Cash</strong>
                            <span>Pay in person at check-in or check-out.</span>
                        </div>
                    </div>

                    <!-- Additional guest names — shown for any accommodation whose
                         admin-configured capacity allows more than one guest. -->
                    <div class="guest-info-field" id="s3CompanionsField" style="grid-column:1/-1" hidden>
                        <label>Additional Guest Names <span style="font-weight:400;color:var(--paper-faint)">(optional)</span></label>
                        <div class="companion-list" id="s3CompanionsList"></div>
                        <button type="button" class="btn btn--ghost" id="s3AddCompanionBtn" style="align-self:flex-start;padding:8px 16px;font-size:12.5px;">
                            + Add guest name
                        </button>
                        <span class="field-hint" id="s3CompanionsHint"></span>
                    </div>
                </div>

                <!-- Booking summary -->
                <div class="booking-summary-box">
                    <div class="booking-summary-box__title">Booking Summary</div>
                    <div class="booking-summary-row"><span>Room</span><strong id="s3Room">—</strong></div>
                    <div class="booking-summary-row"><span>Type</span><strong id="s3Type">—</strong></div>
                    <div class="booking-summary-row"><span>Check-in</span><strong id="s3CheckIn">—</strong></div>
                    <div class="booking-summary-row"><span>Check-out</span><strong id="s3CheckOut">—</strong></div>
                    <div class="booking-summary-row"><span>Duration</span><strong id="s3Nights">—</strong></div>
                    <div class="booking-summary-row"><span>Guests</span><strong id="s3Guests">—</strong></div>
                    <div class="booking-summary-row total"><span>Total (estimated)</span><strong id="s3Total">—</strong></div>
                </div>

                <div class="step3-actions">
                    <button type="button" class="btn btn--ghost" id="backToRoomsBtn">← Back to Rooms</button>
                    <button type="button" class="btn btn--primary" id="openModalBtn">
                        <?php render_icon('send'); ?> Review &amp; Confirm
                    </button>
                </div>
            </div>
        </section>

        <!-- ===== My Bookings ===== -->
        <section class="panel">
            <div class="panel__head">
                <h2>My Bookings</h2>
                <p class="panel__subtitle">Your reservation history.</p>
            </div>

            <?php if (empty($myBookings)): ?>
                <?php render_empty_state(
                    'calendar-check',
                    'No bookings yet',
                    'Select your dates above and book your first stay with us.',
                ); ?>
            <?php else: ?>
                <div class="bk-list">
                    <?php foreach ($myBookings as $bk): ?>
                        <?php
                        $checkIn    = date('M j, Y', strtotime($bk['check_in']));
                        $checkOut   = date('M j, Y', strtotime($bk['check_out']));
                        $nights     = (int) round((strtotime($bk['check_out']) - strtotime($bk['check_in'])) / 86400);
                        $isPool     = strcasecmp(trim((string) $bk['room_type']), 'Swimming Pool') === 0;
                        $isActive   = in_array($bk['status'], ['pending', 'confirmed'], true);
                        $canModify  = $isActive && strtotime($bk['check_in']) >= strtotime($today);

                        // Contact details saved with the booking; older bookings fall back to the profile.
                        if (!empty($bk['guest_name'])) {
                            $nameParts = preg_split('/\s+/', trim((string) $bk['guest_name']));
                            $rowLast   = count($nameParts) > 1 ? array_pop($nameParts) : '';
                            $rowFirst  = implode(' ', $nameParts);
                        } else {
                            $rowFirst = (string) $guestProfile['first_name'];
                            $rowLast  = (string) $guestProfile['last_name'];
                        }
                        $rowEmail   = !empty($bk['contact_email']) ? (string) $bk['contact_email'] : (string) $prefillEmail;
                        $rowPhone   = !empty($bk['contact_phone']) ? (string) $bk['contact_phone'] : (string) $prefillPhone;
                        $rowAddress = (string) ($bk['contact_address'] ?? '');

                        $companionNamesArr = [];
                        if (!empty($bk['companion_names'])) {
                            $decodedNames = json_decode((string) $bk['companion_names'], true);
                            if (is_array($decodedNames)) {
                                $companionNamesArr = $decodedNames;
                            }
                        }
                        ?>
                        <article class="bk-item"
                                 data-booking-id="<?php echo (int) $bk['id']; ?>"
                                 data-reference="<?php echo htmlspecialchars($bk['reference']); ?>"
                                 data-accommodation-id="<?php echo (int) $bk['accommodation_id']; ?>"
                                 data-room-name="<?php echo htmlspecialchars($bk['room_name']); ?>"
                                 data-room-type="<?php echo htmlspecialchars($bk['room_type']); ?>"
                                 data-is-pool="<?php echo $isPool ? '1' : '0'; ?>"
                                 data-capacity="<?php echo (int) $bk['room_capacity']; ?>"
                                 data-price="<?php echo (float) $bk['room_price']; ?>"
                                 data-check-in="<?php echo htmlspecialchars($bk['check_in']); ?>"
                                 data-check-out="<?php echo htmlspecialchars($bk['check_out']); ?>"
                                 data-guests="<?php echo (int) $bk['guests_count']; ?>"
                                 data-notes="<?php echo htmlspecialchars((string) $bk['notes']); ?>"
                                 data-companion-names="<?php echo htmlspecialchars(json_encode($companionNamesArr, JSON_UNESCAPED_UNICODE)); ?>"
                                 data-first-name="<?php echo htmlspecialchars($rowFirst); ?>"
                                 data-last-name="<?php echo htmlspecialchars($rowLast); ?>"
                                 data-email="<?php echo htmlspecialchars($rowEmail); ?>"
                                 data-phone="<?php echo htmlspecialchars($rowPhone); ?>"
                                 data-address="<?php echo htmlspecialchars($rowAddress); ?>"
                                 data-status="<?php echo htmlspecialchars($bk['status']); ?>"
                                 data-image="<?php echo htmlspecialchars((string) $bk['image_filename']); ?>">
                            <div class="bk-item__img">
                                <?php if (!empty($bk['image_filename'])): ?>
                                    <img src="<?php echo htmlspecialchars($imgBase . $bk['image_filename']); ?>"
                                         alt="<?php echo htmlspecialchars($bk['room_name']); ?>"
                                         loading="lazy">
                                <?php else: ?>
                                    <?php render_icon('bed-double'); ?>
                                <?php endif; ?>
                            </div>
                            <div class="bk-item__ref"><?php echo htmlspecialchars($bk['reference']); ?></div>
                            <div class="bk-item__body">
                                <div class="bk-item__room"><?php echo htmlspecialchars($bk['room_name']); ?></div>
                                <div class="bk-item__dates">
                                    <?php echo htmlspecialchars($checkIn); ?> → <?php echo htmlspecialchars($checkOut); ?>
                                </div>
                                <div class="bk-item__meta">
                                    <?php echo $nights; ?> night<?php echo $nights !== 1 ? 's' : ''; ?> ·
                                    <?php echo (int) $bk['guests_count']; ?> guest<?php echo (int) $bk['guests_count'] !== 1 ? 's' : ''; ?>
                                </div>
                            </div>
                            <div class="bk-item__right">
                                <span class="bk-item__amount">₱<?php echo number_format((float) $bk['total_amount'], 2); ?></span>
                                <span class="badge <?php echo booking_status_class($bk['status']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($bk['status'])); ?>
                                </span>
                                <?php if ($isActive): ?>
                                    <?php if ($canModify): ?>
                                        <div class="bk-item__actions">
                                            <button type="button" class="bk-action-btn bk-action-btn--edit" data-bk-edit>
                                                <?php render_icon('pencil'); ?> Edit
                                            </button>
                                            <button type="button" class="bk-action-btn bk-action-btn--cancel" data-bk-cancel>
                                                <?php render_icon('x'); ?> Cancel
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="bk-item__note">Contact us to modify</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div><!-- .content-grid -->

</main>

<!-- ===== Booking confirmation modal ===== -->
<dialog class="bk-modal" id="bkModal" aria-labelledby="bkModalTitle" aria-modal="true">

    <div id="bkModalContent">
        <div class="bk-modal__head">
            <h2 id="bkModalTitle">Confirm Booking</h2>
            <button type="button" class="bk-modal__close" id="bkModalClose" aria-label="Close">
                <?php render_icon('x'); ?>
            </button>
        </div>

        <div class="bk-modal__room">
            <div class="bk-modal__room-img" id="bkModalImg">
                <?php render_icon('bed-double'); ?>
            </div>
            <div>
                <div class="bk-modal__room-name" id="bkModalRoomName">Room</div>
                <div class="bk-modal__room-type" id="bkModalRoomType"></div>
            </div>
        </div>

        <div class="bk-modal__details">
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Guest</span>
                <span class="bk-detail-row__value" id="bkDetailGuest">—</span>
            </div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Email</span>
                <span class="bk-detail-row__value" id="bkDetailEmail">—</span>
            </div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Phone</span>
                <span class="bk-detail-row__value" id="bkDetailPhone">—</span>
            </div>
            <div class="bk-detail-row" id="bkDetailAddressRow">
                <span class="bk-detail-row__label">Address</span>
                <span class="bk-detail-row__value" id="bkDetailAddress">—</span>
            </div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Payment</span>
                <span class="bk-detail-row__value">Cash on arrival</span>
            </div>
            <div class="bk-detail-divider"></div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Check-in</span>
                <span class="bk-detail-row__value" id="bkDetailCheckIn">—</span>
            </div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Check-out</span>
                <span class="bk-detail-row__value" id="bkDetailCheckOut">—</span>
            </div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Duration</span>
                <span class="bk-detail-row__value" id="bkDetailNights">—</span>
            </div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Guests</span>
                <span class="bk-detail-row__value" id="bkDetailGuests">—</span>
            </div>
            <div class="bk-detail-row" id="bkDetailCompanionsRow" style="display:none">
                <span class="bk-detail-row__label">Additional Guests</span>
                <span class="bk-detail-row__value" id="bkDetailCompanions">—</span>
            </div>
            <div class="bk-detail-row">
                <span class="bk-detail-row__label">Rate</span>
                <span class="bk-detail-row__value" id="bkDetailRate">—</span>
            </div>
            <div class="bk-detail-divider"></div>
            <div class="bk-detail-row bk-detail-row--total">
                <span class="bk-detail-row__label">Total (estimated)</span>
                <span class="bk-detail-row__value" id="bkDetailTotal">—</span>
            </div>
        </div>

        <div class="bk-modal__notes-field">
            <label for="bkNotes">Notes <span style="font-weight:400;color:var(--paper-faint)">(optional)</span></label>
            <textarea id="bkNotes" maxlength="1000"
                      placeholder="Special requests, arrival time, accessibility needs…"></textarea>
        </div>

        <!-- Are-you-sure step: shown after "Confirm Booking" is first clicked;
             the actual booking is only submitted once the user taps
             "Yes, Confirm Booking" here. -->
        <div class="bk-modal__confirm-step" id="bkConfirmStep">
            <p class="bk-modal__confirm-text">Are you sure you want to submit this booking request? Please double-check the details above.</p>
            <div class="bk-modal__confirm-actions">
                <button type="button" class="btn--cancel" id="bkConfirmNoBtn">No, Go Back</button>
                <button type="button" class="btn-book" id="bkConfirmYesBtn">
                    <?php render_icon('send'); ?> <span id="bkConfirmYesLabel">Yes, Confirm Booking</span>
                </button>
            </div>
        </div>

        <div class="bk-modal__foot" id="bkModalFoot">
            <span class="bk-modal__error" id="bkModalError" role="alert"></span>
            <div class="bk-modal__foot-actions">
                <button type="button" class="btn--cancel" id="bkModalCancelBtn">Cancel</button>
                <button type="button" class="btn-book" id="bkModalConfirmBtn">
                    <?php render_icon('send'); ?> <span id="bkConfirmLabel">Confirm Booking</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Success view -->
    <div class="bk-success" id="bkSuccessView">
        <div class="bk-success__icon"><?php render_icon('circle-check'); ?></div>
        <h2>Booking Confirmed!</h2>
        <p>Your reservation has been placed. Please save your reference number below.</p>
        <div class="bk-success__ref" id="bkSuccessRef">BM-XXXXXXXX-XXXX</div>
        <p id="bkSuccessDetails" style="margin-top:4px"></p>
        <p style="font-size:13px;color:var(--paper-faint);margin-top:8px">
            Payment is settled in cash upon check-in or check-out.
        </p>
        <div class="bk-success__actions">
            <button type="button" class="btn btn--primary" id="bkSuccessDone">
                <?php render_icon('circle-check'); ?> Done
            </button>
        </div>
    </div>

</dialog>

<!-- ===== Edit booking modal ===== -->
<dialog class="bk-modal" id="editBkModal" aria-labelledby="editBkModalTitle" aria-modal="true">

    <div id="editBkModalContent">
        <div class="bk-modal__head">
            <h2 id="editBkModalTitle">Edit Booking</h2>
            <button type="button" class="bk-modal__close" id="editBkModalClose" aria-label="Close">
                <?php render_icon('x'); ?>
            </button>
        </div>

        <div class="bk-modal__room">
            <div class="bk-modal__room-img" id="editRoomImg"><?php render_icon('bed-double'); ?></div>
            <div>
                <div class="bk-modal__room-name" id="editRoomName">Room</div>
                <div class="bk-modal__room-type" id="editRoomType"></div>
            </div>
        </div>

        <div class="guest-info-panel" style="padding:0 20px">
            <!-- Change the booked accommodation (the server re-checks availability + re-prices) -->
            <div class="guest-info-field">
                <label for="editRoomSelect">Accommodation</label>
                <select id="editRoomSelect" aria-describedby="editRoomHint"></select>
                <span class="field-hint" id="editRoomHint"></span>
            </div>

            <div class="guest-info-grid">
                <div class="guest-info-field">
                    <label for="editFirstName">First Name <span class="req">*</span></label>
                    <input type="text" id="editFirstName" maxlength="80" required autocomplete="given-name">
                </div>
                <div class="guest-info-field">
                    <label for="editLastName">Last Name <span class="req">*</span></label>
                    <input type="text" id="editLastName" maxlength="80" required autocomplete="family-name">
                </div>
                <div class="guest-info-field">
                    <label for="editEmail">Email Address <span class="req">*</span></label>
                    <input type="email" id="editEmail" maxlength="200" required inputmode="email" autocomplete="email">
                </div>
                <div class="guest-info-field">
                    <label for="editPhone">Phone Number <span class="req">*</span></label>
                    <input type="tel" id="editPhone" maxlength="30" required autocomplete="tel">
                </div>
                <div class="guest-info-field" style="grid-column:1/-1">
                    <label for="editAddress">Home Address <span style="font-weight:400;color:var(--paper-faint)">(optional)</span></label>
                    <input type="text" id="editAddress" maxlength="255" autocomplete="street-address">
                </div>
            </div>

            <div class="guest-info-grid">
                <div class="guest-info-field">
                    <label for="editCheckIn">Check-in date</label>
                    <input type="date" id="editCheckIn">
                </div>
                <div class="guest-info-field">
                    <label for="editCheckOut">Check-out date</label>
                    <input type="date" id="editCheckOut">
                </div>
            </div>

            <div class="guests-row">
                <label>Number of guests</label>
                <div class="guests-stepper">
                    <button type="button" id="editGuestsMinus" aria-label="Decrease guests">−</button>
                    <span class="guests-count" id="editGuestsDisplay" aria-live="polite">1</span>
                    <button type="button" id="editGuestsPlus" aria-label="Increase guests">+</button>
                </div>
            </div>

            <!-- Shown only for accommodations whose capacity allows more than one guest -->
            <div class="guest-info-field" id="editCompanionsField" hidden>
                <label>Additional Guest Names <span style="font-weight:400;color:var(--paper-faint)">(optional)</span></label>
                <div class="companion-list" id="editCompanionsList"></div>
                <button type="button" class="btn btn--ghost" id="editAddCompanionBtn" style="align-self:flex-start;padding:8px 16px;font-size:12.5px;">
                    + Add guest name
                </button>
                <span class="field-hint" id="editCompanionsHint"></span>
            </div>

            <div class="bk-modal__notes-field" style="padding:0">
                <label for="editNotes">Notes <span style="font-weight:400;color:var(--paper-faint)">(optional)</span></label>
                <textarea id="editNotes" maxlength="1000"
                          placeholder="Special requests, arrival time, accessibility needs…"></textarea>
            </div>

            <div class="bk-detail-row bk-detail-row--total" style="padding-top:10px;border-top:1px solid var(--line)">
                <span class="bk-detail-row__label">New total (estimated)</span>
                <span class="bk-detail-row__value" id="editTotalPreview">—</span>
            </div>
        </div>

        <div class="bk-modal__foot">
            <span class="bk-modal__error" id="editModalError" role="alert"></span>
            <div class="bk-modal__foot-actions">
                <button type="button" class="btn--cancel" id="editModalCancelBtn">Close</button>
                <button type="button" class="btn-book" id="editModalSaveBtn">
                    <?php render_icon('send'); ?> <span id="editSaveLabel">Save Changes</span>
                </button>
            </div>
        </div>
    </div>

</dialog>

<!-- ===== Calendar modal — shared for view-only AND range-picking ===== -->
<dialog class="cal-modal" id="calModal" aria-labelledby="calModalTitle" aria-modal="true">
    <div class="cal-modal__head">
        <div>
            <div class="cal-modal__title" id="calModalTitle">Room Calendar</div>
            <div class="cal-modal__subtitle" id="calModalSubtitle">Loading…</div>
        </div>
        <button type="button" class="cal-modal__close" id="calModalClose" aria-label="Close calendar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>

    <div class="cal-legend">
        <span class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--available"></span>Available</span>
        <span class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--booked"></span>Booked</span>
        <span class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--maintenance"></span>Maintenance</span>
        <span class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--unavailable"></span>Unavailable</span>
        <span class="cal-legend-item" id="calLegendSelected" style="display:none"><span class="cal-legend-dot cal-legend-dot--selected"></span>Selected</span>
    </div>

    <div class="cal-body">
        <div class="cal-loading" id="calLoading">
            <div class="cal-spinner"></div>
            <span>Loading calendar…</span>
        </div>

        <div id="calContent" style="display:none">
            <div class="cal-nav">
                <button type="button" class="cal-nav__btn" id="calPrevBtn" aria-label="Previous month">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <span class="cal-nav__label" id="calMonthLabel"></span>
                <button type="button" class="cal-nav__btn" id="calNextBtn" aria-label="Next month">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </div>
            <div class="cal-grid" id="calGrid"></div>
            <!-- Range-pick hint (visible in pick mode) -->
            <div class="cal-pick-hint" id="calPickHint" style="display:none"></div>
        </div>
    </div>

    <!-- Footer shown only in range-pick mode -->
    <div class="cal-pick-footer" id="calPickFooter">
        <button type="button" class="btn--cancel" id="calClearBtn">Clear</button>
        <button type="button" class="btn btn--primary" id="calApplyBtn" disabled>Apply Dates</button>
    </div>
</dialog>

<!-- ===== Photo modal — enlarged view of the selected room's photo(s) ===== -->
<dialog class="photo-modal" id="photoModal" aria-labelledby="photoModalCaption" aria-modal="true">
    <button type="button" class="photo-modal__close" id="photoModalClose" aria-label="Close photo">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <div class="photo-modal__img-wrap">
        <img id="photoModalImg" src="" alt="">
        <button type="button" class="photo-modal__nav photo-modal__nav--prev" id="photoModalPrev" aria-label="Previous photo" hidden>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <button type="button" class="photo-modal__nav photo-modal__nav--next" id="photoModalNext" aria-label="Next photo" hidden>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>
    <div class="photo-modal__caption">
        <span id="photoModalCaption"></span>
        <span class="photo-modal__counter" id="photoModalCounter"></span>
    </div>
</dialog>

<dialog class="tour-modal" id="tourModal" aria-labelledby="tourModalCaption" aria-modal="true">
    <button type="button" class="tour-modal__close" id="tourModalClose" aria-label="Close 360&deg; tour">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
    </button>
    <div class="tour-modal__frame-wrap">
        <iframe id="tourModalFrame" src="about:blank" title="360 degree room tour" loading="lazy" allow="accelerometer; gyroscope; fullscreen" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
    </div>
    <div class="tour-modal__caption" id="tourModalCaption"></div>
</dialog>

<!-- Hidden tokens for JS -->
<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
<input type="hidden" id="imgBase" value="../admin/assets/uploads/accommodations/">
<input type="hidden" id="tourBase" value="../admin/assets/uploads/tours/">
<input type="hidden" id="initialType" value="<?php echo htmlspecialchars($initialType); ?>">

<?php require __DIR__ . '/includes/support-modal.php'; ?>
<?php require __DIR__ . '/includes/foot.php'; ?>

<script>
/* =============================================================
   bookings.php — inline booking JS
   ============================================================= */

(function () {
  'use strict';

  /* ---- Cached DOM refs ---- */
  var elCheckBtn   = document.getElementById('checkAvailBtn');
  var elNightSum   = document.getElementById('nightSummary');
  var elNightTxt   = document.getElementById('nightSummaryText');
  var elDateErr    = document.getElementById('dateError');
  var elDateErrT   = document.getElementById('dateErrorText');

  var triggerCI    = document.getElementById('triggerCheckIn');
  var triggerCO    = document.getElementById('triggerCheckOut');
  var dispCI       = document.getElementById('displayCheckIn');
  var dispCO       = document.getElementById('displayCheckOut');

  var step1Panel    = document.getElementById('step1Panel');
  var step2Panel    = document.getElementById('step2Panel');
  var step3Panel    = document.getElementById('step3Panel');

  var elSkeleton   = document.getElementById('roomsSkeleton');
  var elGrid       = document.getElementById('roomsGrid');
  var elMeta       = document.getElementById('roomsMeta');
  var elError      = document.getElementById('roomsError');
  var elErrText    = document.getElementById('roomsErrorText');
  var elEmpty      = document.getElementById('roomsEmpty');
  var elRoomsTitle = document.getElementById('roomsTitle');
  var elEmptyTitle = document.getElementById('roomsEmptyTitle');
  var elEmptyText  = document.getElementById('roomsEmptyText');
  var elChips1     = document.getElementById('typeChipsStep1');
  var elChips2     = document.getElementById('typeChipsStep2');

  var elChangeDates  = document.getElementById('changeDatesBtn');
  var elBackToRooms  = document.getElementById('backToRoomsBtn');
  var elOpenModal    = document.getElementById('openModalBtn');

  var sI1 = document.getElementById('step1Indicator');
  var sI2 = document.getElementById('step2Indicator');
  var sI3 = document.getElementById('step3Indicator');

  // Booking modal
  var modal      = document.getElementById('bkModal');
  var elMClose   = document.getElementById('bkModalClose');
  var elMCancel  = document.getElementById('bkModalCancelBtn');
  var elMConfirm = document.getElementById('bkModalConfirmBtn');
  var elMError   = document.getElementById('bkModalError');
  var elMContent = document.getElementById('bkModalContent');
  var elSuccess  = document.getElementById('bkSuccessView');
  var elSuccRef  = document.getElementById('bkSuccessRef');
  var elSuccDet  = document.getElementById('bkSuccessDetails');
  var elSuccDone = document.getElementById('bkSuccessDone');
  var elConfLbl  = document.getElementById('bkConfirmLabel');
  var elModalFoot     = document.getElementById('bkModalFoot');
  var elConfirmStep   = document.getElementById('bkConfirmStep');
  var elConfirmYesBtn = document.getElementById('bkConfirmYesBtn');
  var elConfirmYesLbl = document.getElementById('bkConfirmYesLabel');
  var elConfirmNoBtn  = document.getElementById('bkConfirmNoBtn');

  // Calendar modal
  var calModal        = document.getElementById('calModal');
  var calClose        = document.getElementById('calModalClose');
  var calTitle        = document.getElementById('calModalTitle');
  var calSubtitle     = document.getElementById('calModalSubtitle');
  var calLoading      = document.getElementById('calLoading');
  var calContent      = document.getElementById('calContent');
  var calGrid         = document.getElementById('calGrid');
  var calMonthLbl     = document.getElementById('calMonthLabel');
  var calPrevBtn      = document.getElementById('calPrevBtn');
  var calNextBtn      = document.getElementById('calNextBtn');
  var calPickHint     = document.getElementById('calPickHint');
  var calPickFooter   = document.getElementById('calPickFooter');
  var calClearBtn     = document.getElementById('calClearBtn');
  var calApplyBtn     = document.getElementById('calApplyBtn');
  var calLegendSel    = document.getElementById('calLegendSelected');

  // Photo modal
  var photoModal        = document.getElementById('photoModal');
  var photoModalClose   = document.getElementById('photoModalClose');
  var photoModalImg     = document.getElementById('photoModalImg');
  var photoModalCaption = document.getElementById('photoModalCaption');
  var photoModalCounter = document.getElementById('photoModalCounter');
  var photoModalPrev    = document.getElementById('photoModalPrev');
  var photoModalNext    = document.getElementById('photoModalNext');
  var galleryPhotos      = []; // full image URLs for the room currently open in the lightbox
  var galleryIndex       = 0;
  var galleryRoomName    = '';

  // 360° Tour modal
  var tourModal        = document.getElementById('tourModal');
  var tourModalClose   = document.getElementById('tourModalClose');
  var tourModalFrame   = document.getElementById('tourModalFrame');
  var tourModalCaption = document.getElementById('tourModalCaption');

  // Step 3
  var elStep3Error    = document.getElementById('step3Error');
  var elStep3ErrorTxt = document.getElementById('step3ErrorText');
  var elGuestFirst    = document.getElementById('guestFirstName');
  var elGuestLast     = document.getElementById('guestLastName');
  var elGuestEmail    = document.getElementById('guestEmail');
  var elGuestPhone    = document.getElementById('guestPhone');
  var elGuestAddress  = document.getElementById('guestAddress');

  // Additional guest names (Step 3) — capacity-based, any accommodation type
  var elCompanionsField = document.getElementById('s3CompanionsField');
  var elCompanionsList  = document.getElementById('s3CompanionsList');
  var elAddCompanionBtn = document.getElementById('s3AddCompanionBtn');
  var elCompanionsHint  = document.getElementById('s3CompanionsHint');
  var companionMax      = 0; // max additional guest names allowed for the currently selected room (capacity - 1)

  var csrf     = (document.getElementById('csrfToken') || {}).value || '';
  var imgBase  = (document.getElementById('imgBase') || {}).value || '';
  var tourBase = (document.getElementById('tourBase') || {}).value || '';

  /* ---- Global state ---- */
  var currentCheckIn  = '';
  var currentCheckOut = '';
  var currentGuests   = 1;
  var pendingBooking  = null;
  var isLoadingRooms  = false;
  var isSubmitting    = false;

  // Calendar shared state
  var calRoomId       = null;
  var calRoomStatus   = 'available';
  var calBookedRanges = [];
  var calDisplayYear  = new Date().getFullYear();
  var calDisplayMonth = new Date().getMonth();

  // Calendar mode: 'view' (read-only) | 'pick' (date-range selection)
  var calMode         = 'view';
  // Temp range picks (in pick mode)
  var calPickStart    = null;   // 'YYYY-MM-DD'
  var calPickEnd      = null;

  var MONTHS      = ['January','February','March','April','May','June',
                     'July','August','September','October','November','December'];
  var DAY_HEADERS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

  /* ---- Date helpers ---- */
  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' +
      String(d.getMonth() + 1).padStart(2, '0') + '-' +
      String(d.getDate()).padStart(2, '0');
  }

  function formatDate(ymd) {
    if (!ymd) return '';
    var p = ymd.split('-');
    if (p.length !== 3) return ymd;
    var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return m[parseInt(p[1], 10) - 1] + ' ' + parseInt(p[2], 10) + ', ' + p[0];
  }

  function calcNights(ci, co) {
    if (!ci || !co) return 0;
    return Math.round((new Date(co) - new Date(ci)) / 86400000);
  }

  function showDateError(msg) {
    elDateErrT.textContent = msg;
    elDateErr.style.display = 'flex';
  }
  function hideDateError() { elDateErr.style.display = 'none'; }

  function updateNightSummary() {
    if (!currentCheckIn || !currentCheckOut) { elNightSum.style.display = 'none'; return; }
    var n = calcNights(currentCheckIn, currentCheckOut);
    if (n <= 0) { elNightSum.style.display = 'none'; return; }
    elNightTxt.textContent = n + ' night' + (n === 1 ? '' : 's') +
      ' · ' + formatDate(currentCheckIn) + ' → ' + formatDate(currentCheckOut);
    elNightSum.style.display = 'flex';
  }

  /* ---- Update date trigger button displays ---- */
  function refreshDateTriggers() {
    if (currentCheckIn) {
      dispCI.textContent = formatDate(currentCheckIn);
      dispCI.className   = 'date-trigger__value';
      triggerCI.classList.add('is-filled');
    } else {
      dispCI.textContent = 'Select check-in date';
      dispCI.className   = 'date-trigger__placeholder';
      triggerCI.classList.remove('is-filled');
    }
    if (currentCheckOut) {
      dispCO.textContent = formatDate(currentCheckOut);
      dispCO.className   = 'date-trigger__value';
      triggerCO.classList.add('is-filled');
    } else {
      dispCO.textContent = 'Select check-out date';
      dispCO.className   = 'date-trigger__placeholder';
      triggerCO.classList.remove('is-filled');
    }
    updateNightSummary();
  }

  function validateDates() {
    hideDateError();
    if (!currentCheckIn || !currentCheckOut) return false;
    if (currentCheckIn < todayStr())       { showDateError('Check-in date cannot be in the past.'); return false; }
    if (currentCheckOut <= currentCheckIn) { showDateError('Check-out must be after check-in.'); return false; }
    if (calcNights(currentCheckIn, currentCheckOut) > 90) { showDateError('Maximum stay is 90 nights.'); return false; }
    return true;
  }

  /* ---- Guests stepper ---- */
  document.getElementById('guestsMinus').addEventListener('click', function () {
    if (currentGuests > 1) { currentGuests--; document.getElementById('guestsDisplay').textContent = currentGuests; }
  });
  document.getElementById('guestsPlus').addEventListener('click', function () {
    if (currentGuests < 20) { currentGuests++; document.getElementById('guestsDisplay').textContent = currentGuests; }
  });

  /* ---- Date trigger clicks → open calendar in pick mode ---- */
  triggerCI.addEventListener('click', function () { openPickCalendar('checkin'); });
  triggerCO.addEventListener('click', function () { openPickCalendar('checkout'); });

  /* ============================================================
     CALENDAR MODAL — shared for view-only AND range-pick
     ============================================================ */

  /* Open in VIEW mode (from "View Calendar" buttons) */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cal-room-id]');
    if (!btn) return;
    e.stopPropagation();

    var roomId   = parseInt(btn.getAttribute('data-cal-room-id'), 10);
    var roomName = btn.getAttribute('data-cal-room-name') || 'Room';
    var roomType = btn.getAttribute('data-cal-room-type') || '';

    var card = btn.closest('[data-room-status]');
    calRoomStatus = card ? card.getAttribute('data-room-status') : 'available';

    openViewCalendar(roomId, roomName, roomType);
  });

  function openViewCalendar(roomId, roomName, roomType) {
    calMode   = 'view';
    calRoomId = roomId;

    calTitle.textContent    = roomName;
    calSubtitle.textContent = roomType ? roomType + ' · Booking Calendar' : 'Booking Calendar';

    calGrid.classList.remove('cal-pick-mode');
    calPickHint.style.display  = 'none';
    calPickFooter.classList.remove('is-visible');
    calLegendSel.style.display = 'none';

    var now = new Date();
    calDisplayYear  = now.getFullYear();
    calDisplayMonth = now.getMonth();

    calLoading.style.display = 'flex';
    calContent.style.display = 'none';

    calModal.showModal ? calModal.showModal() : calModal.setAttribute('open', '');

    fetchCalendarData(roomId);
  }

  /* Open in PICK mode (from date trigger buttons) */
  function openPickCalendar(trigger) {
    calMode   = 'pick';
    calRoomId = null;    // no specific room — show generic available days

    // Seed the temp picks from already-selected dates
    calPickStart = currentCheckIn  || null;
    calPickEnd   = currentCheckOut || null;

    calTitle.textContent    = trigger === 'checkin' ? 'Select Check-in & Check-out' : 'Select Check-out Date';
    calSubtitle.textContent = 'Click a start date, then an end date.';

    calGrid.classList.add('cal-pick-mode');
    calPickHint.style.display  = 'block';
    calPickFooter.classList.add('is-visible');
    calLegendSel.style.display = 'inline-flex';

    // Reset to current month
    var now = new Date();
    calDisplayYear  = now.getFullYear();
    calDisplayMonth = now.getMonth();

    // In pick mode: no room-specific bookings — all future days are selectable
    calBookedRanges = [];
    calRoomStatus   = 'available';

    calLoading.style.display = 'none';
    calContent.style.display = 'block';

    calModal.showModal ? calModal.showModal() : calModal.setAttribute('open', '');

    renderCalendar();
    updatePickHint();
    updateApplyBtn();
  }

  /* Fetch calendar data from API (view mode) */
  function fetchCalendarData(roomId) {
    fetch('api/bookings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'room_calendar', room_id: roomId, csrf_token: csrf }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      calLoading.style.display = 'none';
      if (!data.success) {
        calContent.style.display = 'block';
        calGrid.innerHTML = '<p style="color:var(--danger);text-align:center;padding:20px 0">' +
          escHtml(data.error || 'Could not load calendar.') + '</p>';
        calMonthLbl.textContent = '';
        return;
      }
      calBookedRanges = data.bookings || [];
      calRoomStatus   = data.room_status || calRoomStatus;
      calContent.style.display = 'block';
      renderCalendar();
    })
    .catch(function () {
      calLoading.style.display = 'none';
      calContent.style.display = 'block';
      calGrid.innerHTML = '<p style="color:var(--danger);text-align:center;padding:20px 0">Network error. Please try again.</p>';
      calMonthLbl.textContent = '';
    });
  }

  /* Render the calendar grid */
  function renderCalendar() {
    var year  = calDisplayYear;
    var month = calDisplayMonth;

    calMonthLbl.textContent = MONTHS[month] + ' ' + year;

    var todayIso = todayStr();
    var now      = new Date();
    var isPastOrCurrent = (year < now.getFullYear()) ||
      (year === now.getFullYear() && month <= now.getMonth());
    calPrevBtn.disabled = isPastOrCurrent;

    calGrid.innerHTML = '';

    DAY_HEADERS.forEach(function (d) {
      var el = document.createElement('div');
      el.className = 'cal-day-header';
      el.textContent = d;
      calGrid.appendChild(el);
    });

    var firstDay    = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();

    for (var i = 0; i < firstDay; i++) {
      var empty = document.createElement('div');
      empty.className = 'cal-day cal-day--empty';
      calGrid.appendChild(empty);
    }

    for (var day = 1; day <= daysInMonth; day++) {
      var isoDate = year + '-' +
        String(month + 1).padStart(2, '0') + '-' +
        String(day).padStart(2, '0');

      var el = document.createElement('div');
      el.className = 'cal-day';
      el.textContent = day;

      var isPast  = isoDate < todayIso;
      var isToday = isoDate === todayIso;

      if (isToday) el.classList.add('cal-day--today');

      if (isPast) {
        el.classList.add('cal-day--past');
      } else {
        var dayStatus = getDayStatus(isoDate);
        el.classList.add('cal-day--' + dayStatus);
        el.title = getDayTitle(dayStatus, isoDate);

        // In pick mode: attach click handler to available days
        if (calMode === 'pick' && dayStatus === 'available') {
          (function (iso) {
            el.addEventListener('click', function () { handlePickClick(iso); });
          })(isoDate);
        }
      }

      // Range selection highlight (pick mode)
      if (calMode === 'pick') {
        var isSelStart = (isoDate === calPickStart);
        var isSelEnd   = (isoDate === calPickEnd);
        var isInRange  = calPickStart && calPickEnd &&
                         isoDate > calPickStart && isoDate < calPickEnd;

        if (isSelStart) el.classList.add('cal-day--sel-start');
        if (isSelEnd)   el.classList.add('cal-day--sel-end');
        if (isInRange || isSelStart || isSelEnd) {
          if (isInRange) el.classList.add('cal-day--in-range');
        }
      }

      calGrid.appendChild(el);
    }
  }

  /* Handle a day click in pick mode */
  function handlePickClick(isoDate) {
    if (!calPickStart || (calPickStart && calPickEnd)) {
      // Start fresh — first click sets start
      calPickStart = isoDate;
      calPickEnd   = null;
    } else {
      // Second click
      if (isoDate <= calPickStart) {
        // Clicked same or earlier — reset start
        calPickStart = isoDate;
        calPickEnd   = null;
      } else {
        calPickEnd = isoDate;
      }
    }
    renderCalendar();
    updatePickHint();
    updateApplyBtn();
  }

  function updatePickHint() {
    if (!calPickStart) {
      calPickHint.innerHTML = 'Click a day to set your <strong>check-in</strong> date.';
    } else if (!calPickEnd) {
      calPickHint.innerHTML = 'Check-in: <strong>' + formatDate(calPickStart) +
        '</strong> — now click your <strong>check-out</strong> date.';
    } else {
      var n = calcNights(calPickStart, calPickEnd);
      calPickHint.innerHTML = '<strong>' + formatDate(calPickStart) + '</strong> → <strong>' +
        formatDate(calPickEnd) + '</strong> · ' + n + ' night' + (n === 1 ? '' : 's');
    }
  }

  function updateApplyBtn() {
    calApplyBtn.disabled = !(calPickStart && calPickEnd && calPickEnd > calPickStart);
  }

  calClearBtn.addEventListener('click', function () {
    calPickStart = null;
    calPickEnd   = null;
    renderCalendar();
    updatePickHint();
    updateApplyBtn();
  });

  calApplyBtn.addEventListener('click', function () {
    if (!calPickStart || !calPickEnd) return;
    currentCheckIn  = calPickStart;
    currentCheckOut = calPickEnd;
    refreshDateTriggers();
    closeCalModal();
  });

  function getDayStatus(isoDate) {
    if (calRoomStatus === 'maintenance') return 'maintenance';
    if (calRoomStatus === 'unavailable') return 'unavailable';
    for (var i = 0; i < calBookedRanges.length; i++) {
      var b = calBookedRanges[i];
      if (isoDate >= b.check_in && isoDate < b.check_out) return 'booked';
    }
    return 'available';
  }

  function getDayTitle(status, isoDate) {
    switch (status) {
      case 'booked':      return 'Booked — ' + isoDate;
      case 'maintenance': return 'Under maintenance';
      case 'unavailable': return 'Not available';
      default:            return 'Available — ' + isoDate;
    }
  }

  /* Month navigation */
  calPrevBtn.addEventListener('click', function () {
    calDisplayMonth--;
    if (calDisplayMonth < 0) { calDisplayMonth = 11; calDisplayYear--; }
    renderCalendar();
  });
  calNextBtn.addEventListener('click', function () {
    calDisplayMonth++;
    if (calDisplayMonth > 11) { calDisplayMonth = 0; calDisplayYear++; }
    renderCalendar();
  });

  /* Close calendar */
  calClose.addEventListener('click', closeCalModal);
  calModal.addEventListener('click', function (e) {
    var rect = calModal.getBoundingClientRect();
    if (e.clientY < rect.top || e.clientY > rect.bottom ||
        e.clientX < rect.left || e.clientX > rect.right) { closeCalModal(); }
  });
  calModal.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCalModal();
  });

  function closeCalModal() {
    if (calModal.close) calModal.close();
    else calModal.removeAttribute('open');
    if (calMode === 'view') {
      calBookedRanges = [];
      calRoomId = null;
    }
  }

  /* ============================================================
     PHOTO MODAL — enlarged view of a room's photo(s) (Step 2 cards)
     ============================================================ */
  function renderGalleryPhoto() {
    if (!galleryPhotos.length) return;
    photoModalImg.src = galleryPhotos[galleryIndex];
    photoModalImg.alt = galleryRoomName || 'Room photo';

    var multi = galleryPhotos.length > 1;
    photoModalCaption.textContent = galleryRoomName || '';
    photoModalCounter.textContent = multi ? ((galleryIndex + 1) + ' of ' + galleryPhotos.length) : '';
    photoModalPrev.hidden = !multi;
    photoModalNext.hidden = !multi;
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

  function openPhotoModal(urls, startIndex, name) {
    if (!urls || !urls.length) return;
    galleryPhotos   = urls;
    galleryIndex    = Math.max(0, Math.min(startIndex || 0, urls.length - 1));
    galleryRoomName = name || '';
    renderGalleryPhoto();
    photoModal.showModal ? photoModal.showModal() : photoModal.setAttribute('open', '');
  }

  function closePhotoModal() {
    if (photoModal.close) photoModal.close();
    else photoModal.removeAttribute('open');
    photoModalImg.src = '';
    galleryPhotos = [];
  }

  photoModalClose.addEventListener('click', closePhotoModal);
  photoModalPrev.addEventListener('click', function (e) { e.stopPropagation(); showPrevPhoto(); });
  photoModalNext.addEventListener('click', function (e) { e.stopPropagation(); showNextPhoto(); });
  photoModal.addEventListener('click', function (e) {
    var rect = photoModal.getBoundingClientRect();
    if (e.clientY < rect.top || e.clientY > rect.bottom ||
        e.clientX < rect.left || e.clientX > rect.right) { closePhotoModal(); }
  });
  photoModal.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePhotoModal();
    if (e.key === 'ArrowLeft')  showPrevPhoto();
    if (e.key === 'ArrowRight') showNextPhoto();
  });

  /* Click on a room-card's photo (Step 2) opens the lightbox, starting
     at the cover photo and letting the visitor page through the rest
     of that room's gallery. The "View Calendar" and "360° Tour" buttons
     live inside the same media block, so both are excluded here to
     avoid double-triggering. */
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-cal-room-id]')) return;
    if (e.target.closest('[data-tour-file]')) return;
    var media = e.target.closest('[data-photo-urls]');
    if (!media) return;
    var urls = [];
    try {
      urls = JSON.parse(media.getAttribute('data-photo-urls') || '[]');
    } catch (err) {
      urls = [];
    }
    openPhotoModal(urls, 0, media.getAttribute('data-photo-name'));
  });

  /* ============================================================
     TOUR MODAL — 360° virtual tour viewer (Step 2 cards)
     ============================================================ */
  function openTourModal(filename, name) {
    if (!filename) return;
    tourModalFrame.src = tourBase + encodeURIComponent(filename);
    tourModalCaption.textContent = name ? (name + ' — 360° Tour') : '360° Tour';
    tourModal.showModal ? tourModal.showModal() : tourModal.setAttribute('open', '');
  }

  function closeTourModal() {
    if (tourModal.close) tourModal.close();
    else tourModal.removeAttribute('open');
    tourModalFrame.src = 'about:blank'; // stop the tour running in the background once closed
  }

  tourModalClose.addEventListener('click', closeTourModal);
  tourModal.addEventListener('click', function (e) {
    var rect = tourModal.getBoundingClientRect();
    if (e.clientY < rect.top || e.clientY > rect.bottom ||
        e.clientX < rect.left || e.clientX > rect.right) { closeTourModal(); }
  });
  tourModal.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeTourModal();
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-tour-file]');
    if (!btn) return;
    e.stopPropagation();
    openTourModal(btn.getAttribute('data-tour-file'), btn.getAttribute('data-tour-name'));
  });

  /* ---- Step management ---- */
  function setStep(n) {
    step1Panel.style.display = n === 1 ? '' : 'none';
    step2Panel.style.display = n === 2 ? '' : 'none';
    step3Panel.style.display = n === 3 ? '' : 'none';

    sI1.className = 'booking-step' + (n === 1 ? ' is-active' : (n > 1 ? ' is-done' : ''));
    sI2.className = 'booking-step' + (n === 2 ? ' is-active' : (n > 2 ? ' is-done' : ''));
    sI3.className = 'booking-step' + (n === 3 ? ' is-active' : '');

    if (n <= 1) {
      sI1.querySelector('.booking-step__num').textContent = '1';
      sI2.querySelector('.booking-step__num').textContent = '2';
      sI3.querySelector('.booking-step__num').textContent = '3';
    } else if (n === 2) {
      sI1.querySelector('.booking-step__num').textContent = '✓';
      sI2.querySelector('.booking-step__num').textContent = '2';
      sI3.querySelector('.booking-step__num').textContent = '3';
    } else if (n === 3) {
      sI1.querySelector('.booking-step__num').textContent = '✓';
      sI2.querySelector('.booking-step__num').textContent = '✓';
      sI3.querySelector('.booking-step__num').textContent = '3';
    }
  }

  /* ---- Availability check ---- */
  function showRoomsLoading() {
    elSkeleton.style.display = 'grid';
    elGrid.style.display     = 'none';
    elError.style.display    = 'none';
    elEmpty.style.display    = 'none';
    elChips2.style.display   = 'none';   // chips come back with the fresh results
    allRooms = [];
  }
  function hideRoomsLoading() { elSkeleton.style.display = 'none'; }

  function buildRoomCard(room) {
    var card = document.createElement('div');
    card.className = 'room-card' +
      (room.availability === 'occupied'    ? ' is-occupied'    : '') +
      (room.availability === 'unavailable' ? ' is-unavailable' : '');

    var availClass  = 'avail--' + room.availability;
    var photoFiles  = (room.photos && room.photos.length)
      ? room.photos
      : (room.image_filename ? [room.image_filename] : []);
    var photoUrls   = photoFiles.map(function (f) { return imgBase + encodeURIComponent(f); });
    var hasPhoto    = photoUrls.length > 0;
    var photoSrc    = hasPhoto ? photoUrls[0] : '';
    var zoomLabel   = photoUrls.length > 1 ? ('View ' + photoUrls.length + ' photos') : 'View photo';

    var mediaHtml = hasPhoto
      ? '<img src="' + photoSrc + '" alt="' + escHtml(room.name) + '" loading="lazy">' +
        '<span class="room-card__zoom-hint" aria-hidden="true">' + zoomIcon() + escHtml(zoomLabel) + '</span>'
      : '<span class="room-card__media-icon">' + bedIcon() + '</span>';

    var calBtnHtml =
      '<button type="button" class="room-card__cal-btn" ' +
        'data-cal-room-id="' + room.id + '" ' +
        'data-cal-room-name="' + escHtml(room.name) + '" ' +
        'data-cal-room-type="' + escHtml(room.type) + '" ' +
        'aria-label="View booking calendar for ' + escHtml(room.name) + '">' +
        calIcon() + 'View Calendar' +
      '</button>';

    var tourBtnHtml = room.tour_filename
      ? '<button type="button" class="room-card__tour-btn" ' +
          'data-tour-file="' + escHtml(room.tour_filename) + '" ' +
          'data-tour-name="' + escHtml(room.name) + '" ' +
          'aria-label="View 360 degree tour of ' + escHtml(room.name) + '">' +
          globeIcon() + '360&deg; Tour' +
        '</button>'
      : '';

    var amenHtml = '';
    if (room.amenities && room.amenities.length) {
      var shown = room.amenities.slice(0, 4);
      var more  = room.amenities.length - 4;
      amenHtml  = shown.map(function (a) {
        return '<span class="room-tag">' + escHtml(a) + '</span>';
      }).join('');
      if (more > 0) amenHtml += '<span class="room-tag room-tag--more">+' + more + ' more</span>';
      amenHtml = '<div class="room-card__amenities">' + amenHtml + '</div>';
    }

    var descHtml = room.description
      ? '<p class="room-card__desc">' + escHtml(room.description) + '</p>'
      : '';

    var actionHtml = room.availability === 'available'
      ? '<button type="button" class="btn-book" data-room-id="' + room.id + '">' +
        sendIcon() + ' Book This Stay</button>'
      : '<span style="font-size:12px;color:var(--paper-faint);font-weight:600">' +
        escHtml(room.availability_label) + '</span>';

    card.innerHTML =
      '<div class="room-card__media' + (hasPhoto ? ' room-card__media--zoomable' : '') + '"' +
        (hasPhoto ? ' data-photo-urls="' + escHtml(JSON.stringify(photoUrls)) + '" data-photo-name="' + escHtml(room.name) + '"' : '') +
        '>' +
        mediaHtml +
        calBtnHtml +
        tourBtnHtml +
        '<span class="room-card__price-badge">' + escHtml(room.price_formatted) +
          '<small>/' + (room.is_pool ? 'person' : 'night') + '</small></span>' +
        '<span class="room-card__avail-badge ' + availClass + '">' +
          '<span class="dot"></span>' + escHtml(room.availability_label) +
        '</span>' +
      '</div>' +
      '<div class="room-card__body">' +
        '<div class="room-card__head">' +
          '<div class="room-card__name">' + escHtml(room.name) + '</div>' +
          '<div class="room-card__type">' + escHtml(room.type) + '</div>' +
        '</div>' +
        '<div class="room-card__meta">' +
          usersIcon() +
          '<span>' + (room.is_pool && room.remaining_capacity !== null
            ? room.remaining_capacity + ' of ' + room.capacity + ' slot' + (room.capacity === 1 ? '' : 's') + ' available for these dates'
            : 'Up to ' + room.capacity + ' guest' + (room.capacity === 1 ? '' : 's')) + '</span>' +
        '</div>' +
        amenHtml + descHtml +
      '</div>' +
      '<div class="room-card__footer">' +
        '<div class="room-card__total">' + escHtml(room.total_formatted) +
          '<br><small>' + (room.is_pool
            ? currentGuests + ' guest' + (currentGuests === 1 ? '' : 's')
            : room.nights + ' night' + (room.nights === 1 ? '' : 's')) + '</small>' +
        '</div>' +
        actionHtml +
      '</div>';

    if (room.availability === 'available') {
      card.querySelector('.btn-book').addEventListener('click', function () {
        goToStep3(room);
      });
    }

    return card;
  }

  /* ---- Accommodation type filter ----
     Categories mirror the public listing pages (rooms / cottage / venues /
     pool); the availability API already returns every accommodation, so the
     filter is purely client-side. */
  var TYPE_LABELS   = { rooms: 'Rooms', cottage: 'Cottages', venues: 'Event Halls', pool: 'Pool', other: 'Other' };
  var TYPE_SINGULAR = { rooms: 'room', cottage: 'cottage', venues: 'event hall', pool: 'pool option', other: 'accommodation' };
  var TYPE_ORDER    = ['rooms', 'cottage', 'venues', 'pool', 'other'];
  var selectedType  = (document.getElementById('initialType') || {}).value || 'all';
  var allRooms      = [];   // last availability response (sorted), all types
  var lastNights    = 0;

  function categoryOf(type) {
    var n = String(type || '').toLowerCase().trim();
    if (n.indexOf('room') !== -1) return 'rooms';
    if (n.indexOf('event') !== -1 || n.indexOf('hall') !== -1) return 'venues';
    if (n.indexOf('cottage') !== -1 || n.indexOf('hut') !== -1 || n.indexOf('nipa') !== -1) return 'cottage';
    if (n.indexOf('pool') !== -1 || n.indexOf('swim') !== -1) return 'pool';
    return 'other';
  }

  function plural(noun, n) { return noun + (n === 1 ? '' : 's'); }

  function syncTypeChips() {
    Array.prototype.forEach.call(document.querySelectorAll('.type-chip'), function (btn) {
      btn.setAttribute('aria-pressed', btn.getAttribute('data-type') === selectedType ? 'true' : 'false');
    });
  }

  // Step 2 chips carry live counts, so they are rebuilt from the loaded data.
  function buildStep2Chips() {
    var counts = { all: allRooms.length };
    allRooms.forEach(function (r) {
      var c = categoryOf(r.type);
      counts[c] = (counts[c] || 0) + 1;
    });
    var html = '<button type="button" class="type-chip" data-type="all" aria-pressed="false">All' +
               '<span class="type-chip__count">' + counts.all + '</span></button>';
    TYPE_ORDER.forEach(function (key) {
      if (counts[key] || key === selectedType) {
        html += '<button type="button" class="type-chip" data-type="' + key + '" aria-pressed="false">' +
                escHtml(TYPE_LABELS[key]) + '<span class="type-chip__count">' + (counts[key] || 0) + '</span></button>';
      }
    });
    elChips2.innerHTML = html;
    elChips2.style.display = 'flex';
    syncTypeChips();
  }

  function renderRooms() {
    var visible = selectedType === 'all'
      ? allRooms
      : allRooms.filter(function (r) { return categoryOf(r.type) === selectedType; });

    var noun = selectedType === 'all' ? 'accommodation' : TYPE_SINGULAR[selectedType];

    buildStep2Chips();
    elRoomsTitle.textContent = selectedType === 'all'
      ? 'Available Accommodations'
      : 'Available ' + TYPE_LABELS[selectedType];

    elMeta.innerHTML =
      '<strong>' + visible.length + '</strong> ' + plural(noun, visible.length) +
      ' · <strong>' + lastNights + '</strong> night' + (lastNights === 1 ? '' : 's') +
      ' · ' + formatDate(currentCheckIn) + ' → ' + formatDate(currentCheckOut);

    elGrid.innerHTML = '';
    elGrid.style.display  = 'none';
    elEmpty.style.display = 'none';

    if (visible.length === 0) {
      elEmptyTitle.textContent = 'No ' + plural(noun, 0) + ' found';
      elEmptyText.textContent  = selectedType === 'all'
        ? 'There are no accommodations to show for those dates. Try different dates.'
        : 'We have no ' + plural(noun, 0) + ' listed right now. Try another type or different dates.';
      elEmpty.style.display = 'block';
      return;
    }

    elGrid.style.display = 'grid';
    visible.forEach(function (room) { elGrid.appendChild(buildRoomCard(room)); });

    var availCount = visible.filter(function (r) { return r.availability === 'available'; }).length;
    if (availCount === 0) {
      var notice = document.createElement('div');
      notice.className = 'api-error';
      notice.style.gridColumn = '1/-1';
      // Note: intentionally NOT bedIcon() here — that helper hardcodes
      // width:100%/height:100% so it can fill the 44×44px room-card
      // thumbnail it was designed for, and that inline style beats the
      // ".api-error .icon" (18px) rule below, blowing the icon up to
      // fill this notice instead. This is a plain class="icon" SVG with
      // no inline sizing so the existing CSS rule sizes it correctly.
      notice.innerHTML =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>' +
        '<span>No ' + plural(noun, 0) + ' are available for these dates. Try different dates' +
        (selectedType === 'all' ? '.' : ' or another type.') + '</span>';
      elGrid.prepend(notice);
    }
  }

  function onTypeChipClick(e) {
    var btn = e.target.closest ? e.target.closest('.type-chip') : null;
    if (!btn) return;
    selectedType = btn.getAttribute('data-type') || 'all';
    syncTypeChips();
    renderCatalog();                       // Step 1 catalog follows the chosen type
    if (allRooms.length) renderRooms();   // Step 2 already loaded → re-filter in place
  }
  elChips1.addEventListener('click', onTypeChipClick);
  elChips2.addEventListener('click', onTypeChipClick);

  /* ============================================================
     CATALOG — "what's inside" cards on Step 1, no dates needed.
     Loaded once from api/bookings.php (action: catalog) and
     filtered client-side by the same type chips as Step 2.
     ============================================================ */
  var elCatalogGrid    = document.getElementById('catalogGrid');
  var elCatalogLoading = document.getElementById('catalogLoading');
  var elCatalogEmpty   = document.getElementById('catalogEmpty');
  var elCatalogTitle   = document.getElementById('catalogTitle');
  var catalogRooms     = [];

  function buildCatalogCard(room) {
    var card = document.createElement('div');
    card.className = 'room-card catalog-card';
    card.setAttribute('data-room-status', room.status || 'available');

    var photoFiles = (room.photos && room.photos.length)
      ? room.photos
      : (room.image_filename ? [room.image_filename] : []);
    var photoUrls = photoFiles.map(function (f) { return imgBase + encodeURIComponent(f); });
    var hasPhoto  = photoUrls.length > 0;
    var zoomLabel = photoUrls.length > 1 ? ('View ' + photoUrls.length + ' photos') : 'View photo';

    var mediaHtml = hasPhoto
      ? '<img src="' + photoUrls[0] + '" alt="' + escHtml(room.name) + '" loading="lazy">' +
        '<span class="room-card__zoom-hint" aria-hidden="true">' + zoomIcon() + escHtml(zoomLabel) + '</span>'
      : '<span class="room-card__media-icon">' + bedIcon() + '</span>';

    var calBtnHtml =
      '<button type="button" class="room-card__cal-btn" ' +
        'data-cal-room-id="' + room.id + '" ' +
        'data-cal-room-name="' + escHtml(room.name) + '" ' +
        'data-cal-room-type="' + escHtml(room.type) + '" ' +
        'aria-label="View booking calendar for ' + escHtml(room.name) + '">' +
        calIcon() + 'View Calendar' +
      '</button>';

    var tourBtnHtml = room.tour_filename
      ? '<button type="button" class="room-card__tour-btn" ' +
          'data-tour-file="' + escHtml(room.tour_filename) + '" ' +
          'data-tour-name="' + escHtml(room.name) + '" ' +
          'aria-label="View 360 degree tour of ' + escHtml(room.name) + '">' +
          globeIcon() + '360&deg; Tour' +
        '</button>'
      : '';

    // Every amenity is listed (Step 2 cards truncate to 4 + "more").
    var amenHtml = '';
    if (room.amenities && room.amenities.length) {
      amenHtml =
        '<div class="catalog-card__label">What\'s included</div>' +
        '<div class="room-card__amenities">' +
        room.amenities.map(function (a) {
          return '<span class="room-tag">' + escHtml(a) + '</span>';
        }).join('') +
        '</div>';
    }

    var descHtml = room.description
      ? '<p class="room-card__desc">' + escHtml(room.description) + '</p>'
      : '';

    var statusHtml = room.status_label
      ? '<span class="catalog-card__status">' + escHtml(room.status_label) + '</span>'
      : '';

    var capacityText = room.is_pool
      ? 'Up to ' + room.capacity + ' guest' + (room.capacity === 1 ? '' : 's') + ' per day'
      : 'Up to ' + room.capacity + ' guest' + (room.capacity === 1 ? '' : 's');

    var footerAction = room.status_label
      ? '<span class="catalog-card__hint">' + escHtml(room.status_label) + '</span>'
      : '<button type="button" class="catalog-card__pick" data-catalog-pick>Select dates</button>';

    card.innerHTML =
      '<div class="room-card__media' + (hasPhoto ? ' room-card__media--zoomable' : '') + '"' +
        (hasPhoto ? ' data-photo-urls="' + escHtml(JSON.stringify(photoUrls)) + '" data-photo-name="' + escHtml(room.name) + '"' : '') +
        '>' +
        mediaHtml + calBtnHtml + tourBtnHtml + statusHtml +
        '<span class="room-card__price-badge">' + escHtml(room.price_formatted) +
          '<small>/' + (room.is_pool ? 'person' : 'night') + '</small></span>' +
      '</div>' +
      '<div class="room-card__body">' +
        '<div class="room-card__head">' +
          '<div class="room-card__name">' + escHtml(room.name) + '</div>' +
          '<div class="room-card__type">' + escHtml(room.type) + '</div>' +
        '</div>' +
        '<div class="room-card__meta">' + usersIcon() + '<span>' + capacityText + '</span></div>' +
        descHtml + amenHtml +
      '</div>' +
      '<div class="room-card__footer">' +
        '<div class="room-card__total">' + escHtml(room.price_formatted) +
          '<br><small>per ' + (room.is_pool ? 'person' : 'night') + '</small></div>' +
        footerAction +
      '</div>';

    return card;
  }

  function renderCatalog() {
    if (!elCatalogGrid) return;
    var visible = selectedType === 'all'
      ? catalogRooms
      : catalogRooms.filter(function (r) { return categoryOf(r.type) === selectedType; });

    elCatalogTitle.textContent = selectedType === 'all'
      ? 'What\'s inside each accommodation'
      : 'What\'s inside our ' + TYPE_LABELS[selectedType];

    elCatalogGrid.innerHTML = '';
    elCatalogGrid.style.display = 'none';
    elCatalogEmpty.style.display = 'none';

    if (!visible.length) {
      elCatalogEmpty.textContent = catalogRooms.length
        ? 'Nothing listed under this type right now. Try another type.'
        : 'No accommodations are listed yet.';
      elCatalogEmpty.style.display = 'block';
      return;
    }

    visible.forEach(function (room) { elCatalogGrid.appendChild(buildCatalogCard(room)); });
    elCatalogGrid.style.display = 'grid';
  }

  // "Select dates" on a catalog card → scroll to the date pickers and open check-in.
  if (elCatalogGrid) {
    elCatalogGrid.addEventListener('click', function (e) {
      if (!e.target.closest('[data-catalog-pick]')) return;
      var dateRow = triggerCI.closest('.date-panel') || triggerCI;
      dateRow.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(function () { triggerCI.click(); }, 350);
    });
  }

  function loadCatalog() {
    if (!elCatalogGrid) return;
    fetch('api/bookings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'catalog', csrf_token: csrf }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      elCatalogLoading.style.display = 'none';
      if (!data.success) {
        elCatalogEmpty.textContent = data.error || 'Could not load accommodations.';
        elCatalogEmpty.style.display = 'block';
        return;
      }
      catalogRooms = data.rooms || [];
      renderCatalog();
    })
    .catch(function () {
      elCatalogLoading.style.display = 'none';
      elCatalogEmpty.textContent = 'Could not load accommodations. Please refresh the page.';
      elCatalogEmpty.style.display = 'block';
    });
  }
  loadCatalog();

  function checkAvailability() {
    if (isLoadingRooms) return;
    hideDateError();

    if (!currentCheckIn || !currentCheckOut) {
      showDateError('Please select your check-in and check-out dates by clicking the date fields above.');
      return;
    }
    if (!validateDates()) return;

    setStep(2);
    showRoomsLoading();
    isLoadingRooms = true;
    elCheckBtn.disabled = true;

    fetch('api/bookings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:       'availability',
        check_in:     currentCheckIn,
        check_out:    currentCheckOut,
        guests_count: currentGuests,
        csrf_token:   csrf,
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      isLoadingRooms = false;
      elCheckBtn.disabled = false;
      hideRoomsLoading();

      if (!data.success) {
        elErrText.textContent = data.error || 'Could not load rooms.';
        elError.style.display = 'flex';
        return;
      }

      allRooms = data.rooms || [];
      lastNights = data.nights || 0;

      allRooms.sort(function (a, b) {
        var order = { available: 0, occupied: 1, unavailable: 2 };
        return (order[a.availability] || 0) - (order[b.availability] || 0);
      });

      renderRooms();
    })
    .catch(function () {
      isLoadingRooms = false;
      elCheckBtn.disabled = false;
      hideRoomsLoading();
      elErrText.textContent = 'Network error. Please check your connection and try again.';
      elError.style.display = 'flex';
    });
  }

  elCheckBtn.addEventListener('click', checkAvailability);

  elChangeDates.addEventListener('click', function () { setStep(1); });

  /* ---- Step 2 → Step 3 ---- */
  function goToStep3(room) {
    // Clamp the chosen guest count to this room's admin-set capacity — the
    // stepper in Step 1 doesn't know which room will be picked, so this is
    // the first point a mismatch can be caught and corrected.
    var capacity = parseInt(room.capacity, 10) || 1;
    var capacityAdjusted = false;
    if (currentGuests > capacity) {
      currentGuests = capacity;
      document.getElementById('guestsDisplay').textContent = currentGuests;
      capacityAdjusted = true;
    }

    pendingBooking = {
      room:     room,
      checkIn:  currentCheckIn,
      checkOut: currentCheckOut,
      guests:   currentGuests,
      nights:   room.nights,
    };

    // Populate summary in step 3
    document.getElementById('s3Room').textContent    = room.name;
    document.getElementById('s3Type').textContent    = room.type;
    document.getElementById('s3CheckIn').textContent = formatDate(currentCheckIn);
    document.getElementById('s3CheckOut').textContent= formatDate(currentCheckOut);
    document.getElementById('s3Nights').textContent  = room.nights + ' night' + (room.nights === 1 ? '' : 's');
    document.getElementById('s3Guests').textContent  = currentGuests + ' guest' + (currentGuests === 1 ? '' : 's');
    document.getElementById('s3Total').textContent   = room.total_formatted;

    // Clear / set step 3 notice
    if (capacityAdjusted) {
      elStep3ErrorTxt.textContent = 'Guest count adjusted to ' + currentGuests + ' — the maximum this room allows.';
      elStep3Error.style.display  = 'flex';
    } else {
      elStep3Error.style.display = 'none';
    }

    setupCompanionFields(capacity, currentGuests);

    setStep(3);
  }

  elBackToRooms && elBackToRooms.addEventListener('click', function () { setStep(2); });

  /* ---- Step 3 → Confirm modal ---- */
  elOpenModal && elOpenModal.addEventListener('click', function () {
    if (!pendingBooking) return;

    var contact = {
      first_name: elGuestFirst.value.trim(),
      last_name:  elGuestLast.value.trim(),
      email:      elGuestEmail.value.trim(),
      phone:      elGuestPhone.value.trim(),
      address:    elGuestAddress.value.trim(),
    };

    elStep3Error.style.display = 'none';

    var problem = validateContact(contact);
    if (problem) {
      elStep3ErrorTxt.textContent = problem.message;
      elStep3Error.style.display  = 'flex';
      var focusEl = { first_name: elGuestFirst, last_name: elGuestLast, email: elGuestEmail, phone: elGuestPhone }[problem.field];
      if (focusEl) focusEl.focus();
      return;
    }

    pendingBooking.contact = contact;
    openBookingModal(contact);
  });

  // Same rules the server applies (mirrors the walk-in guest-booking form).
  // Returns null when valid, else { field, message }.
  function validateContact(c) {
    if (!c.first_name || c.first_name.length > 80)
      return { field: 'first_name', message: 'Please enter your first name.' };
    if (!c.last_name || c.last_name.length > 80)
      return { field: 'last_name', message: 'Please enter your last name.' };
    if (!c.email || c.email.length > 200 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(c.email))
      return { field: 'email', message: 'Please enter a valid email address.' };
    if (!c.phone || c.phone.length < 7 || c.phone.length > 30)
      return { field: 'phone', message: 'Please enter a valid phone number.' };
    return null;
  }

  /* ---- Additional guest names — any accommodation, capped by capacity ---- */
  function addCompanionRow(value) {
    if (elCompanionsList.children.length >= companionMax) return;

    var row = document.createElement('div');
    row.className = 'companion-row';

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'bk-companion-input';
    input.maxLength = 100;
    input.placeholder = 'Guest name';
    input.value = value || '';

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'companion-row__remove';
    removeBtn.textContent = '−';
    removeBtn.setAttribute('aria-label', 'Remove name');
    removeBtn.addEventListener('click', function () {
      row.remove();
      refreshAddCompanionBtnState();
    });

    row.appendChild(input);
    row.appendChild(removeBtn);
    elCompanionsList.appendChild(row);
    refreshAddCompanionBtnState();
  }

  function refreshAddCompanionBtnState() {
    var atCap = elCompanionsList.children.length >= companionMax;
    elAddCompanionBtn.disabled = atCap;
    elAddCompanionBtn.textContent = atCap ? 'Maximum guests reached' : '+ Add guest name';
  }

  elAddCompanionBtn && elAddCompanionBtn.addEventListener('click', function () {
    addCompanionRow('');
  });

  function resetCompanionRows(seedCount) {
    elCompanionsList.innerHTML = '';
    // Pre-seed one blank row per additional guest (the primary guest's own
    // name is already captured above, so this covers the rest of the
    // party), never exceeding what the room's capacity allows.
    var seed = Math.max(0, Math.min(seedCount, companionMax));
    for (var i = 0; i < seed; i++) addCompanionRow('');
    refreshAddCompanionBtnState();
  }

  function collectCompanionNames() {
    return Array.prototype.slice.call(elCompanionsList.querySelectorAll('.bk-companion-input'))
      .map(function (el) { return el.value.trim(); })
      .filter(function (v) { return v !== ''; });
  }

  // Shows/hides and seeds the additional-guest-names field for the room
  // currently being booked, based on its admin-configured capacity.
  function setupCompanionFields(capacity, guests) {
    companionMax = Math.max(0, capacity - 1);

    if (companionMax <= 0) {
      elCompanionsField.hidden = true;
      elCompanionsList.innerHTML = '';
      return;
    }

    elCompanionsField.hidden = false;
    elCompanionsHint.textContent = 'This room fits up to ' + capacity + ' guest' +
      (capacity === 1 ? '' : 's') + ' — add up to ' + companionMax + ' more name' +
      (companionMax === 1 ? '' : 's') + ' below.';

    resetCompanionRows(Math.max(0, guests - 1));
  }

  /* ---- Booking confirmation modal ---- */
  function openBookingModal(contact) {
    var room = pendingBooking.room;

    document.getElementById('bkModalRoomName').textContent  = room.name;
    document.getElementById('bkModalRoomType').textContent  = room.type;
    document.getElementById('bkDetailGuest').textContent    = contact.first_name + ' ' + contact.last_name;
    document.getElementById('bkDetailEmail').textContent    = contact.email;
    document.getElementById('bkDetailPhone').textContent    = contact.phone;
    document.getElementById('bkDetailAddress').textContent  = contact.address;
    document.getElementById('bkDetailAddressRow').style.display = contact.address ? '' : 'none';
    document.getElementById('bkDetailCheckIn').textContent  = formatDate(currentCheckIn);
    document.getElementById('bkDetailCheckOut').textContent = formatDate(currentCheckOut);
    document.getElementById('bkDetailNights').textContent   = pendingBooking.nights + ' night' + (pendingBooking.nights === 1 ? '' : 's');
    document.getElementById('bkDetailGuests').textContent   = currentGuests + ' guest' + (currentGuests === 1 ? '' : 's');
    document.getElementById('bkDetailRate').textContent     = room.price_formatted + '/' + (room.is_pool ? 'person' : 'night');
    document.getElementById('bkDetailTotal').textContent    = room.total_formatted;

    var imgWrap = document.getElementById('bkModalImg');
    imgWrap.innerHTML = '';
    if (room.image_filename) {
      var img = document.createElement('img');
      img.src = imgBase + encodeURIComponent(room.image_filename);
      img.alt = room.name;
      imgWrap.appendChild(img);
    } else {
      imgWrap.innerHTML = bedIconLarge();
    }

    var companionNames = collectCompanionNames();
    var detailCompanionsRow = document.getElementById('bkDetailCompanionsRow');
    if (companionNames.length) {
      document.getElementById('bkDetailCompanions').textContent = companionNames.join(', ');
      detailCompanionsRow.style.display = '';
    } else {
      detailCompanionsRow.style.display = 'none';
    }

    document.getElementById('bkNotes').value = '';
    elMError.textContent = '';
    elConfLbl.textContent = 'Confirm Booking';
    elMConfirm.disabled = false;
    elMContent.style.display = '';
    elSuccess.classList.remove('is-visible');
    elConfirmStep.classList.remove('is-visible');
    elModalFoot.style.display = '';

    modal.showModal ? modal.showModal() : modal.setAttribute('open', '');
  }

  function closeModal() {
    if (modal.close) modal.close();
    else modal.removeAttribute('open');
  }

  elMClose.addEventListener('click', closeModal);
  elMCancel.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) {
    var rect = modal.getBoundingClientRect();
    if (e.clientY < rect.top || e.clientY > rect.bottom ||
        e.clientX < rect.left || e.clientX > rect.right) { closeModal(); }
  });
  modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  /* ---- Step 1: "Confirm Booking" reveals the are-you-sure step ---- */
  elMConfirm.addEventListener('click', function () {
    if (!pendingBooking || isSubmitting) return;
    elMError.textContent = '';
    elModalFoot.style.display = 'none';
    elConfirmStep.classList.add('is-visible');
  });

  elConfirmNoBtn.addEventListener('click', function () {
    elConfirmStep.classList.remove('is-visible');
    elModalFoot.style.display = '';
  });

  /* ---- Step 2: user confirmed — submit the booking for real ---- */
  elConfirmYesBtn.addEventListener('click', function () {
    if (!pendingBooking || isSubmitting) return;

    elMError.textContent = '';
    isSubmitting = true;
    elConfirmYesBtn.disabled = true;
    elConfirmNoBtn.disabled = true;
    elConfirmYesLbl.textContent = 'Booking…';

    var notes = (document.getElementById('bkNotes').value || '').trim();
    var companionNames = collectCompanionNames();

    fetch('api/bookings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:           'book',
        check_in:         pendingBooking.checkIn,
        check_out:        pendingBooking.checkOut,
        accommodation_id: pendingBooking.room.id,
        guests_count:     pendingBooking.guests,
        notes:            notes,
        companion_names:  companionNames,
        first_name:       pendingBooking.contact.first_name,
        last_name:        pendingBooking.contact.last_name,
        email:            pendingBooking.contact.email,
        phone:            pendingBooking.contact.phone,
        address:          pendingBooking.contact.address,
        payment_method:   'cash',
        csrf_token:       csrf,
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      isSubmitting = false;
      elConfirmYesBtn.disabled = false;
      elConfirmNoBtn.disabled = false;
      elConfirmYesLbl.textContent = 'Yes, Confirm Booking';

      if (!data.success) {
        elConfirmStep.classList.remove('is-visible');
        elModalFoot.style.display = '';
        elMError.textContent = data.error || 'Booking failed. Please try again.';
        return;
      }

      elMContent.style.display = 'none';
      elSuccess.classList.add('is-visible');
      elSuccRef.textContent = data.reference;

      var det = formatDate(data.check_in) + ' → ' + formatDate(data.check_out) +
                ' · ' + data.room_name + ' · ' + data.total_formatted;
      elSuccDet.textContent = det;

      injectNewBooking(data, notes, companionNames);
    })
    .catch(function () {
      isSubmitting = false;
      elConfirmYesBtn.disabled = false;
      elConfirmNoBtn.disabled = false;
      elConfirmYesLbl.textContent = 'Yes, Confirm Booking';
      elConfirmStep.classList.remove('is-visible');
      elModalFoot.style.display = '';
      elMError.textContent = 'Network error. Please try again.';
    });
  });

  elSuccDone.addEventListener('click', function () {
    closeModal();
    // Reset to step 1 for a fresh booking
    pendingBooking      = null;
    currentCheckIn      = '';
    currentCheckOut     = '';
    currentGuests       = 1;
    document.getElementById('guestsDisplay').textContent = '1';
    companionMax = 0;
    elCompanionsField.hidden = true;
    elCompanionsList.innerHTML = '';
    refreshDateTriggers();
    setStep(1);
    document.querySelector('.bk-list') &&
      document.querySelector('.bk-list').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* ---- Inject new booking row into My Bookings ---- */
  function injectNewBooking(data, notes, companionNames) {
    var container = document.querySelector('.bk-list');
    if (!container) {
      var panels = document.querySelectorAll('.panel');
      var panel  = panels[panels.length - 1];
      if (!panel) return;
      var emptyState = panel.querySelector('.empty-state');
      if (emptyState) emptyState.remove();
      container = document.createElement('div');
      container.className = 'bk-list';
      panel.appendChild(container);
    }

    var nights = data.nights || 1;
    var guests = data.guests_count || 1;
    var room   = pendingBooking ? pendingBooking.room : null;

    var art = document.createElement('article');
    art.className = 'bk-item';
    art.setAttribute('data-booking-id', data.booking_id);
    art.setAttribute('data-reference', data.reference);
    art.setAttribute('data-accommodation-id', room ? room.id : '');
    art.setAttribute('data-room-name', data.room_name);
    art.setAttribute('data-room-type', room ? room.type : '');
    art.setAttribute('data-is-pool', data.is_pool ? '1' : '0');
    art.setAttribute('data-capacity', room ? room.capacity : guests);
    art.setAttribute('data-price', room ? room.price : 0);
    art.setAttribute('data-check-in', data.check_in);
    art.setAttribute('data-check-out', data.check_out);
    art.setAttribute('data-guests', guests);
    art.setAttribute('data-notes', notes || '');
    art.setAttribute('data-companion-names', JSON.stringify(companionNames || []));
    var ct = (pendingBooking && pendingBooking.contact) || {};
    art.setAttribute('data-first-name', ct.first_name || '');
    art.setAttribute('data-last-name', ct.last_name || '');
    art.setAttribute('data-email', ct.email || '');
    art.setAttribute('data-phone', ct.phone || '');
    art.setAttribute('data-address', ct.address || '');
    art.setAttribute('data-status', 'confirmed');
    art.setAttribute('data-image', (room && room.image_filename) ? room.image_filename : '');

    var actionsHtml = bkCanModifyClient(data.check_in)
      ? '<div class="bk-item__actions">' +
          '<button type="button" class="bk-action-btn bk-action-btn--edit" data-bk-edit>' + pencilIconSmall() + ' Edit</button>' +
          '<button type="button" class="bk-action-btn bk-action-btn--cancel" data-bk-cancel>' + xIconSmall() + ' Cancel</button>' +
        '</div>'
      : '<span class="bk-item__note">Contact us to modify</span>';

    var imgHtml = (room && room.image_filename)
      ? '<img src="' + imgBase + encodeURIComponent(room.image_filename) + '" alt="' + escHtml(data.room_name) + '" loading="lazy">'
      : bedIconLarge();

    art.innerHTML =
      '<div class="bk-item__img">' + imgHtml + '</div>' +
      '<div class="bk-item__ref">' + escHtml(data.reference) + '</div>' +
      '<div class="bk-item__body">' +
        '<div class="bk-item__room">' + escHtml(data.room_name) + '</div>' +
        '<div class="bk-item__dates">' + formatDate(data.check_in) + ' → ' + formatDate(data.check_out) + '</div>' +
        '<div class="bk-item__meta">' + nights + ' night' + (nights === 1 ? '' : 's') + ' · ' +
          guests + ' guest' + (guests === 1 ? '' : 's') + '</div>' +
      '</div>' +
      '<div class="bk-item__right">' +
        '<span class="bk-item__amount">₱' + formatNumber(data.total_amount) + '</span>' +
        '<span class="badge badge--green">Confirmed</span>' +
        actionsHtml +
      '</div>';

    container.insertBefore(art, container.firstChild);
  }

  /* ---- Utility ---- */
  function escHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function formatNumber(n) {
    return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function bedIcon() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon" style="width:100%;height:100%;object-fit:contain"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>';
  }
  function bedIconLarge() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:26px;height:26px;color:var(--paper-faint)"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>';
  }
  function sendIcon() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:14px;height:14px;display:inline-block;vertical-align:middle"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
  }
  function usersIcon() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:14px;height:14px;flex-shrink:0"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
  }
  function zoomIcon() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:12px;height:12px;flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>';
  }
  function calIcon() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:11px;height:11px;flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
  }

  function globeIcon() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:11px;height:11px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/></svg>';
  }

  function pencilIconSmall() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:12px;height:12px;display:inline-block;vertical-align:middle"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>';
  }
  function xIconSmall() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:12px;height:12px;display:inline-block;vertical-align:middle"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
  }

  /* =============================================================
     Edit / Cancel existing bookings (My Bookings list)
     ============================================================= */

  var editModal            = document.getElementById('editBkModal');
  var editModalClose       = document.getElementById('editBkModalClose');
  var editModalCancelBtn   = document.getElementById('editModalCancelBtn');
  var editModalSaveBtn     = document.getElementById('editModalSaveBtn');
  var editSaveLabel        = document.getElementById('editSaveLabel');
  var editModalErrorEl     = document.getElementById('editModalError');
  var editRoomNameEl       = document.getElementById('editRoomName');
  var editRoomTypeEl       = document.getElementById('editRoomType');
  var editCheckInEl        = document.getElementById('editCheckIn');
  var editCheckOutEl       = document.getElementById('editCheckOut');
  var editGuestsDisplayEl  = document.getElementById('editGuestsDisplay');
  var editGuestsMinusBtn   = document.getElementById('editGuestsMinus');
  var editGuestsPlusBtn    = document.getElementById('editGuestsPlus');
  var editCompanionsField  = document.getElementById('editCompanionsField');
  var editCompanionsList   = document.getElementById('editCompanionsList');
  var editAddCompanionBtn  = document.getElementById('editAddCompanionBtn');
  var editCompanionsHint   = document.getElementById('editCompanionsHint');
  var editNotesEl          = document.getElementById('editNotes');
  var editTotalPreviewEl   = document.getElementById('editTotalPreview');
  var editRoomImgEl        = document.getElementById('editRoomImg');
  var editRoomSelectEl     = document.getElementById('editRoomSelect');
  var editRoomHintEl       = document.getElementById('editRoomHint');
  var editFirstNameEl      = document.getElementById('editFirstName');
  var editLastNameEl       = document.getElementById('editLastName');
  var editEmailEl          = document.getElementById('editEmail');
  var editPhoneEl          = document.getElementById('editPhone');
  var editAddressEl        = document.getElementById('editAddress');
  var editRoomsById        = {};   // id -> accommodation info, for the dropdown

  var editState = {
    bookingId:     null,
    roomId:        null,
    originalRoomId: null,
    isPool:        false,
    capacity:      1,
    price:         0,
    guests:        1,
    companionMax:  0,
    isSubmitting:  false,
  };

  /**
   * Client-side mirror of the server's bk_can_self_manage() — used only
   * to decide whether to show Edit/Cancel controls right after a booking
   * is created (no page reload yet); the server re-checks this on every
   * update/cancel request regardless.
   */
  function bkCanModifyClient(checkInStr) {
    var ci = new Date(checkInStr + 'T00:00:00');
    var t0 = new Date(todayStr() + 'T00:00:00');
    return ci >= t0;
  }

  function editOpenModal() {
    editModal.showModal ? editModal.showModal() : editModal.setAttribute('open', '');
  }
  function editCloseModal() {
    if (editModal.close) editModal.close();
    else editModal.removeAttribute('open');
  }

  editModalClose.addEventListener('click', editCloseModal);
  editModalCancelBtn.addEventListener('click', editCloseModal);
  editModal.addEventListener('click', function (e) {
    var rect = editModal.getBoundingClientRect();
    if (e.clientY < rect.top || e.clientY > rect.bottom ||
        e.clientX < rect.left || e.clientX > rect.right) { editCloseModal(); }
  });
  editModal.addEventListener('keydown', function (e) { if (e.key === 'Escape') editCloseModal(); });

  /* ---- Companion name rows (mirrors the Step 3 booking-flow pattern) ---- */
  function editAddCompanionRow(value) {
    if (editCompanionsList.children.length >= editState.companionMax) return;

    var row = document.createElement('div');
    row.className = 'companion-row';

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'bk-companion-input';
    input.maxLength = 100;
    input.placeholder = 'Guest full name';
    input.value = value || '';
    input.addEventListener('input', editRefreshTotalPreview);

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'companion-row__remove';
    removeBtn.setAttribute('aria-label', 'Remove guest name');
    removeBtn.textContent = '×';
    removeBtn.addEventListener('click', function () {
      row.remove();
      editRefreshAddCompanionBtnState();
    });

    row.appendChild(input);
    row.appendChild(removeBtn);
    editCompanionsList.appendChild(row);
    editRefreshAddCompanionBtnState();
  }

  function editRefreshAddCompanionBtnState() {
    var atCap = editCompanionsList.children.length >= editState.companionMax;
    editAddCompanionBtn.disabled = atCap;
    editAddCompanionBtn.textContent = atCap ? 'Maximum guests reached' : '+ Add guest name';
  }

  editAddCompanionBtn.addEventListener('click', function () { editAddCompanionRow(''); });

  function editCollectCompanionNames() {
    return Array.prototype.slice.call(editCompanionsList.querySelectorAll('.bk-companion-input'))
      .map(function (el) { return el.value.trim(); })
      .filter(function (v) { return v !== ''; });
  }

  function editSetupCompanionFields(seedNames) {
    editState.companionMax = Math.max(0, editState.capacity - 1);
    editCompanionsList.innerHTML = '';

    if (editState.companionMax <= 0) {
      editCompanionsField.hidden = true;
      return;
    }

    editCompanionsField.hidden = false;
    editCompanionsHint.textContent = 'This room fits up to ' + editState.capacity + ' guest' +
      (editState.capacity === 1 ? '' : 's') + ' — add up to ' + editState.companionMax + ' more name' +
      (editState.companionMax === 1 ? '' : 's') + ' below.';

    var names = Array.isArray(seedNames) ? seedNames.slice(0, editState.companionMax) : [];
    var seedCount = Math.min(Math.max(names.length, Math.max(0, editState.guests - 1)), editState.companionMax);
    for (var i = 0; i < seedCount; i++) {
      editAddCompanionRow(names[i] || '');
    }
    editRefreshAddCompanionBtnState();
  }

  /* ---- Guest stepper ---- */
  function editSetGuests(n) {
    n = Math.max(1, Math.min(editState.capacity, n));
    editState.guests = n;
    editGuestsDisplayEl.textContent = n;

    // Trim excess companion rows if guest count drops.
    while (editCompanionsList.children.length > Math.max(0, n - 1)) {
      editCompanionsList.removeChild(editCompanionsList.lastElementChild);
    }
    editRefreshAddCompanionBtnState();
    editRefreshTotalPreview();
  }

  editGuestsMinusBtn.addEventListener('click', function () { editSetGuests(editState.guests - 1); });
  editGuestsPlusBtn.addEventListener('click', function () { editSetGuests(editState.guests + 1); });

  /* ---- Live total preview (server recomputes authoritatively on save) ---- */
  function editRefreshTotalPreview() {
    var ci = editCheckInEl.value;
    var co = editCheckOutEl.value;

    if (!ci || !co || co <= ci) {
      editTotalPreviewEl.textContent = '—';
      return;
    }

    var nights = calcNights(ci, co);
    var total  = editState.isPool ? editState.price * editState.guests : editState.price * nights;

    editTotalPreviewEl.textContent = '₱' + formatNumber(total) +
      (editState.isPool ? '' : ' · ' + nights + ' night' + (nights === 1 ? '' : 's'));
  }

  editCheckInEl.addEventListener('change', function () {
    if (editCheckOutEl.value && editCheckOutEl.value <= editCheckInEl.value) {
      var next = new Date(editCheckInEl.value + 'T00:00:00');
      next.setDate(next.getDate() + 1);
      editCheckOutEl.value = next.toISOString().slice(0, 10);
    }
    editCheckOutEl.min = editCheckInEl.value;
    editRefreshTotalPreview();
  });
  editCheckOutEl.addEventListener('change', editRefreshTotalPreview);

  /* ---- Accommodation picker ---- */
  function editRoomOptionLabel(r) {
    return r.name + ' — ' + r.price_formatted + '/' + (r.is_pool ? 'person' : 'night') +
      ' · up to ' + r.capacity + ' guest' + (r.capacity === 1 ? '' : 's');
  }

  function editSetHeaderRoom(r) {
    editRoomNameEl.textContent = r.name || '';
    editRoomTypeEl.textContent = r.type || '';
    editRoomImgEl.innerHTML = r.image_filename
      ? '<img src="' + imgBase + encodeURIComponent(r.image_filename) + '" alt="' + escHtml(r.name) + '">'
      : bedIconLarge();
  }

  // (Re)builds the dropdown from the catalog list; always keeps the booking's own room selectable.
  function editBuildRoomSelect(currentRoom) {
    editRoomsById = {};
    var list = catalogRooms.slice();
    if (!list.some(function (r) { return r.id === currentRoom.id; })) list.unshift(currentRoom);

    var groups = {};
    list.forEach(function (r) {
      editRoomsById[r.id] = r;
      var c = categoryOf(r.type);
      (groups[c] = groups[c] || []).push(r);
    });

    var html = '';
    TYPE_ORDER.forEach(function (key) {
      if (!groups[key]) return;
      html += '<optgroup label="' + escHtml(TYPE_LABELS[key]) + '">';
      groups[key].forEach(function (r) {
        var blocked  = !!r.status_label && r.id !== currentRoom.id;
        var label    = editRoomOptionLabel(r) + (blocked ? ' (' + r.status_label + ')' : '');
        html += '<option value="' + r.id + '"' + (blocked ? ' disabled' : '') + '>' + escHtml(label) + '</option>';
      });
      html += '</optgroup>';
    });
    editRoomSelectEl.innerHTML = html;
    editRoomSelectEl.value = String(editState.roomId);
  }

  // Applies a newly chosen accommodation to the modal's state and fields.
  function editApplyRoom(r, announce) {
    var prevGuests = editState.guests;
    var names      = editCollectCompanionNames();

    editState.roomId   = r.id;
    editState.isPool   = !!r.is_pool;
    editState.capacity = parseInt(r.capacity, 10) || 1;
    editState.price    = parseFloat(r.price) || 0;
    editState.guests   = Math.min(editState.guests, editState.capacity);
    editGuestsDisplayEl.textContent = editState.guests;

    editSetHeaderRoom(r);
    editSetupCompanionFields(names);

    var notes = [];
    if (announce && r.id !== editState.originalRoomId) {
      notes.push('Moving to ' + r.name + '. Availability for your dates is confirmed when you save.');
    }
    if (editState.guests < prevGuests) {
      notes.push('Guest count lowered to ' + editState.guests + ' to fit this accommodation.');
    }
    editRoomHintEl.textContent = notes.join(' ');

    editRefreshTotalPreview();
  }

  editRoomSelectEl.addEventListener('change', function () {
    var r = editRoomsById[parseInt(editRoomSelectEl.value, 10)];
    if (r) editApplyRoom(r, true);
  });

  /* ---- Open the modal, pre-filled from a booking row's data-* ---- */
  function editOpenForBooking(item) {
    var d = item.dataset;

    editState.bookingId    = parseInt(d.bookingId, 10);
    editState.roomId       = parseInt(d.accommodationId, 10) || null;
    editState.originalRoomId = editState.roomId;
    editState.isPool       = d.isPool === '1';
    editState.capacity     = parseInt(d.capacity, 10) || 1;
    editState.price        = parseFloat(d.price) || 0;
    editState.guests       = parseInt(d.guests, 10) || 1;
    editState.isSubmitting = false;

    // The booking's own room, built from the row's data-* (always selectable).
    var currentRoom = {
      id:              editState.roomId,
      name:            d.roomName || '',
      type:            d.roomType || '',
      capacity:        editState.capacity,
      price:           editState.price,
      price_formatted: '₱' + formatNumber(editState.price),
      is_pool:         editState.isPool,
      image_filename:  d.image || null,
      status_label:    '',
    };
    editSetHeaderRoom(currentRoom);
    editRoomHintEl.textContent = '';
    editBuildRoomSelect(currentRoom);

    // If the accommodation list hasn't loaded yet, fetch it and refill the dropdown.
    if (!catalogRooms.length) {
      fetch('api/bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'catalog', csrf_token: csrf }),
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.success && data.rooms) {
          catalogRooms = data.rooms;
          renderCatalog();
          if (editState.bookingId === parseInt(d.bookingId, 10)) editBuildRoomSelect(currentRoom);
        }
      })
      .catch(function () { /* dropdown just keeps the current room */ });
    }

    editModalErrorEl.textContent = '';
    editModalSaveBtn.disabled = false;
    editSaveLabel.textContent = 'Save Changes';

    editCheckInEl.min   = todayStr();
    editCheckInEl.value = d.checkIn;
    editCheckOutEl.min  = d.checkIn;
    editCheckOutEl.value = d.checkOut;

    editGuestsDisplayEl.textContent = editState.guests;

    var seedNames = [];
    try { seedNames = JSON.parse(d.companionNames || '[]'); } catch (e) { seedNames = []; }
    editSetupCompanionFields(seedNames);

    editNotesEl.value = d.notes || '';

    editFirstNameEl.value = d.firstName || '';
    editLastNameEl.value  = d.lastName  || '';
    editEmailEl.value     = d.email     || '';
    editPhoneEl.value     = d.phone     || '';
    editAddressEl.value   = d.address   || '';

    editRefreshTotalPreview();
    editOpenModal();
  }

  /* ---- Save changes ---- */
  editModalSaveBtn.addEventListener('click', function () {
    if (editState.isSubmitting) return;

    var checkIn  = editCheckInEl.value;
    var checkOut = editCheckOutEl.value;

    editModalErrorEl.textContent = '';

    var editContact = {
      first_name: editFirstNameEl.value.trim(),
      last_name:  editLastNameEl.value.trim(),
      email:      editEmailEl.value.trim(),
      phone:      editPhoneEl.value.trim(),
      address:    editAddressEl.value.trim(),
    };
    var contactProblem = validateContact(editContact);
    if (contactProblem) {
      editModalErrorEl.textContent = contactProblem.message;
      return;
    }

    if (!checkIn || !checkOut) {
      editModalErrorEl.textContent = 'Please select both check-in and check-out dates.';
      return;
    }
    if (checkOut <= checkIn) {
      editModalErrorEl.textContent = 'Check-out must be after check-in.';
      return;
    }

    editState.isSubmitting = true;
    editModalSaveBtn.disabled = true;
    editSaveLabel.textContent = 'Saving…';

    fetch('api/bookings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:           'update',
        booking_id:       editState.bookingId,
        accommodation_id: editState.roomId,
        first_name:       editContact.first_name,
        last_name:        editContact.last_name,
        email:            editContact.email,
        phone:            editContact.phone,
        address:          editContact.address,
        check_in:         checkIn,
        check_out:        checkOut,
        guests_count:     editState.guests,
        notes:            editNotesEl.value.trim(),
        companion_names:  editCollectCompanionNames(),
        csrf_token:       csrf,
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      editState.isSubmitting = false;
      editModalSaveBtn.disabled = false;
      editSaveLabel.textContent = 'Save Changes';

      if (!data.success) {
        editModalErrorEl.textContent = data.error || 'Could not save changes. Please try again.';
        return;
      }

      applyBookingUpdate(data);
      editCloseModal();
    })
    .catch(function () {
      editState.isSubmitting = false;
      editModalSaveBtn.disabled = false;
      editSaveLabel.textContent = 'Save Changes';
      editModalErrorEl.textContent = 'Network error. Please try again.';
    });
  });

  /* ---- Reflect a successful edit back into the My Bookings row ---- */
  function applyBookingUpdate(data) {
    var item = document.querySelector('.bk-item[data-booking-id="' + data.booking_id + '"]');
    if (!item) return;

    var nights = data.nights || 1;

    // Accommodation may have changed — refresh everything the row (and the next edit) reads from it.
    if (data.accommodation_id) {
      item.setAttribute('data-accommodation-id', data.accommodation_id);
      item.setAttribute('data-room-name', data.room_name || '');
      item.setAttribute('data-room-type', data.room_type || '');
      item.setAttribute('data-is-pool', data.is_pool ? '1' : '0');
      item.setAttribute('data-capacity', data.room_capacity || data.guests_count);
      item.setAttribute('data-price', data.room_price || 0);
      item.setAttribute('data-image', data.room_image || '');

      var roomEl = item.querySelector('.bk-item__room');
      if (roomEl) roomEl.textContent = data.room_name || '';

      var imgBox = item.querySelector('.bk-item__img');
      if (imgBox) {
        imgBox.innerHTML = data.room_image
          ? '<img src="' + imgBase + encodeURIComponent(data.room_image) + '" alt="' + escHtml(data.room_name) + '" loading="lazy">'
          : bedIconLarge();
      }
    }

    if (data.contact) {
      var cparts = (data.contact.guest_name || '').split(' ');
      var clast  = cparts.length > 1 ? cparts.pop() : '';
      item.setAttribute('data-first-name', cparts.join(' '));
      item.setAttribute('data-last-name', clast);
      item.setAttribute('data-email', data.contact.email || '');
      item.setAttribute('data-phone', data.contact.phone || '');
      item.setAttribute('data-address', data.contact.address || '');
    }

    item.setAttribute('data-check-in', data.check_in);
    item.setAttribute('data-check-out', data.check_out);
    item.setAttribute('data-guests', data.guests_count);
    item.setAttribute('data-notes', data.notes || '');
    item.setAttribute('data-companion-names', JSON.stringify(data.companion_names || []));

    var datesEl = item.querySelector('.bk-item__dates');
    if (datesEl) datesEl.textContent = formatDate(data.check_in) + ' → ' + formatDate(data.check_out);

    var metaEl = item.querySelector('.bk-item__meta');
    if (metaEl) {
      metaEl.textContent = nights + ' night' + (nights === 1 ? '' : 's') + ' · ' +
        data.guests_count + ' guest' + (data.guests_count === 1 ? '' : 's');
    }

    var amountEl = item.querySelector('.bk-item__amount');
    if (amountEl) amountEl.textContent = '₱' + formatNumber(data.total_amount);

    // If the newly chosen check-in date now falls inside the no-modify
    // window, drop the actions so the guest isn't offered a re-edit that
    // the server would just reject.
    if (!data.can_modify) {
      var actions = item.querySelector('.bk-item__actions');
      if (actions) {
        var note = document.createElement('span');
        note.className = 'bk-item__note';
        note.textContent = 'Contact us to modify';
        actions.replaceWith(note);
      }
    }

    item.classList.add('bk-item--flash');
    setTimeout(function () { item.classList.remove('bk-item--flash'); }, 1600);
  }

  /* ---- Cancel a booking ---- */
  function cancelBooking(item) {
    var reference = item.dataset.reference || '';
    var confirmed = window.confirm('Cancel booking ' + reference + '? This cannot be undone.');
    if (!confirmed) return;

    var bookingId = parseInt(item.dataset.bookingId, 10);

    fetch('api/bookings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:     'cancel',
        booking_id: bookingId,
        csrf_token: csrf,
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success) {
        window.alert(data.error || 'Could not cancel this booking. Please try again.');
        return;
      }

      var badge = item.querySelector('.badge');
      if (badge) {
        badge.className = 'badge badge--red';
        badge.textContent = 'Cancelled';
      }
      var actions = item.querySelector('.bk-item__actions');
      if (actions) actions.remove();

      item.setAttribute('data-status', 'cancelled');
      item.classList.add('bk-item--flash');
      setTimeout(function () { item.classList.remove('bk-item--flash'); }, 1600);
    })
    .catch(function () {
      window.alert('Network error. Please try again.');
    });
  }

  /* ---- Delegate clicks from the My Bookings list (covers rows injected later too) ---- */
  document.addEventListener('click', function (e) {
    var editBtn = e.target.closest && e.target.closest('[data-bk-edit]');
    if (editBtn) {
      var editItem = editBtn.closest('.bk-item');
      if (editItem) editOpenForBooking(editItem);
      return;
    }
    var cancelBtn = e.target.closest && e.target.closest('[data-bk-cancel]');
    if (cancelBtn) {
      var cancelItem = cancelBtn.closest('.bk-item');
      if (cancelItem) cancelBooking(cancelItem);
    }
  });

})();
</script>