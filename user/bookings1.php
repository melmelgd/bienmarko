<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'bookings';
$pageTitle   = 'Book a Room';

// Pull guest profile for Step 3 pre-fill
$guestProfile = ['first_name' => '', 'last_name' => '', 'phone' => ''];
try {
    $pdo     = bienmarko_db();
    $guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
    $gStmt   = $pdo->prepare(
        "SELECT first_name, last_name, phone FROM guests WHERE id = :id LIMIT 1"
    );
    $gStmt->execute(['id' => $guestId]);
    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($gRow) {
        $guestProfile = $gRow;
    }
} catch (Throwable $e) {
   
}

$prefillName  = trim($guestProfile['first_name'] . ' ' . $guestProfile['last_name']);
$prefillPhone = $guestProfile['phone'] ?? '';

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
                b.created_at, a.name AS room_name, a.type AS room_type
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

// Fetch all rooms for the room list panel
$allRooms = [];
try {
    $pdo = bienmarko_db();
    $roomStmt = $pdo->query(
        "SELECT id, name, type, capacity, price, status, description, amenities, image_filename
         FROM accommodations
         ORDER BY name ASC"
    );
    $allRooms = $roomStmt->fetchAll();
} catch (Throwable $e) {
    $allRooms = [];
}

$csrfToken = bienmarko_csrf_token();
$today     = date('Y-m-d');

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

/* ---------- All Rooms panel ---------- */
.all-rooms-section { display: flex; flex-direction: column; gap: 16px; }
.all-rooms-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 8px;
}
.all-rooms-header h2 { font-size: 18px; }
.all-rooms-subtitle { font-size: 13px; color: var(--paper-dim); margin-top: 2px; }

.all-rooms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}

/* ---------- Room thumb card (in Step 1) ---------- */
.rthumb {
  background: var(--surface);
  border: 2px solid var(--line);
  border-radius: var(--radius-lg);
  overflow: hidden;
  display: flex; flex-direction: column;
  cursor: pointer;
  transition: border-color .2s, transform .15s, box-shadow .2s;
  position: relative;
}
.rthumb:hover {
  border-color: var(--ember);
  transform: translateY(-2px);
  box-shadow: 0 8px 24px -8px rgba(232,163,61,.25);
}
.rthumb.is-selected {
  border-color: var(--ember);
  box-shadow: 0 0 0 3px rgba(232,163,61,.2);
}
.rthumb__media {
  position: relative;
  aspect-ratio: 16/9;
  background: var(--surface-raised);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.rthumb__media img {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.rthumb__media-icon { width: 38px; height: 38px; color: var(--paper-faint); }
.rthumb__status-badge {
  position: absolute; left: 8px; top: 8px;
  border-radius: var(--radius-full);
  font-size: 10px; font-weight: 700;
  padding: 3px 8px;
  display: flex; align-items: center; gap: 4px;
  pointer-events: none;
}
.rthumb__status-badge .dot {
  width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0;
}
.rstatus--available   { background: rgba(40,160,90,.9);  color: #fff; }
.rstatus--available .dot { background: #7fffa8; }
.rstatus--booked      { background: rgba(220,100,50,.9); color: #fff; }
.rstatus--booked .dot { background: #ffd080; }
.rstatus--unavailable { background: rgba(80,80,100,.9);  color: #ddd; }
.rstatus--unavailable .dot { background: #aaa; }
.rstatus--maintenance { background: rgba(160,120,20,.9); color: #fff; }
.rstatus--maintenance .dot { background: #ffe97a; }

.rthumb__cal-btn {
  position: absolute; right: 8px; top: 8px;
  background: rgba(11,13,15,.82);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: var(--radius-full);
  color: var(--paper-dim);
  font-size: 11px; font-weight: 700;
  padding: 4px 10px;
  display: flex; align-items: center; gap: 5px;
  cursor: pointer;
  transition: color .15s, border-color .15s;
  pointer-events: all;
}
.rthumb__cal-btn:hover { color: var(--ember); border-color: var(--ember); }
.rthumb__cal-btn svg { width: 11px; height: 11px; flex-shrink: 0; }

.rthumb__price-badge {
  position: absolute; right: 8px; bottom: 8px;
  background: rgba(11,13,15,.85);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: var(--radius-full);
  font-family: var(--font-display); font-weight: 700;
  font-size: 13px; color: var(--paper);
  padding: 4px 10px;
}
.rthumb__price-badge small {
  font-family: var(--font-body); font-size: 10px;
  color: var(--paper-dim); font-weight: 400;
}

.rthumb__body {
  padding: 12px 14px;
  display: flex; flex-direction: column; gap: 8px;
  flex: 1;
}
.rthumb__name { font-size: 15px; font-weight: 700; }
.rthumb__type { font-size: 12px; color: var(--paper-dim); margin-top: -4px; }
.rthumb__meta {
  display: flex; align-items: center; gap: 5px;
  font-size: 12px; color: var(--paper-dim);
}
.rthumb__amenities { display: flex; flex-wrap: wrap; gap: 4px; }
.rthumb__tag {
  font-size: 10px; font-weight: 600;
  background: var(--surface-raised); border: 1px solid var(--line);
  border-radius: var(--radius-full); padding: 2px 7px;
  color: var(--paper-dim);
}
.rthumb__tag--more { color: var(--ember); border-color: rgba(232,163,61,.4); }

.rthumb__footer {
  padding: 10px 14px;
  border-top: 1px solid var(--line);
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px;
}
.rthumb__select-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 7px 13px;
  background: var(--ember); color: var(--ember-ink);
  border-radius: var(--radius-full);
  font-weight: 700; font-size: 12px;
  border: none; cursor: pointer;
  transition: background .15s;
}
.rthumb__select-btn:hover { background: var(--ember-strong); }
.rthumb__unavail-label {
  font-size: 11px; color: var(--paper-faint); font-weight: 600;
  padding: 7px 0;
}

/* ---------- Room grid in Step 2 ---------- */
.rooms-section { display: flex; flex-direction: column; gap: 20px; }
.rooms-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
}
.rooms-header h2 { font-size: 19px; }
.rooms-meta { font-size: 13.5px; color: var(--paper-dim); }
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
.guest-info-field .field-hint {
  font-size: 12px; color: var(--paper-faint);
}

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
  box-shadow: 0 24px 60px -20px rgba(0,0,0,.7);
  overflow: hidden;
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
.cal-legend-dot--maintenance { background: rgba(220,160,20,.7); }
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

/* ---------- Responsive ---------- */
@media (max-width: 600px) {
  .date-row { grid-template-columns: 1fr; }
  .booking-step__label { display: none; }
  .bk-item { flex-direction: column; gap: 8px; }
  .bk-item__right { flex-direction: row; align-items: center; }
  .all-rooms-grid { grid-template-columns: 1fr; }
  .cal-modal { width: 96vw; }
  .guest-info-grid { grid-template-columns: 1fr; }
}
</style>

<main class="page" id="main-content">

    <!-- Page header -->
    <div class="page-head">
        <div class="page-head__text">
            <h1 class="page-head__title">Book a Room</h1>
            <p class="page-head__subtitle">Choose your dates to see real-time availability.</p>
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
            <span class="booking-step__label">Choose Room</span>
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
        </section>

        <!-- ===== ALL ROOMS panel (always visible in Step 1) ===== -->
        <section class="panel" id="allRoomsPanel">
            <div class="all-rooms-section">
                <div class="all-rooms-header">
                    <div>
                        <h2>All Rooms</h2>
                        <p class="all-rooms-subtitle">Click <strong>View Calendar</strong> on any room to see its booking schedule. Click <strong>Select &amp; Check</strong> then pick your dates to book.</p>
                    </div>
                    <span style="font-size:13px;color:var(--paper-dim);font-weight:600">
                        <?php echo count($allRooms); ?> room<?php echo count($allRooms) !== 1 ? 's' : ''; ?>
                    </span>
                </div>

                <?php if (empty($allRooms)): ?>
                    <?php render_empty_state(
                        'bed-double',
                        'No rooms yet',
                        'No accommodations have been added yet. Check back soon.'
                    ); ?>
                <?php else: ?>
                    <div class="all-rooms-grid" id="allRoomsGrid">
                        <?php foreach ($allRooms as $room):
                            $amenities = [];
                            if ($room['amenities']) {
                                $dec = json_decode($room['amenities'], true);
                                if (is_array($dec)) $amenities = $dec;
                            }
                            $imgBase = '../admin/assets/uploads/accommodations/';

                            $statusKey   = $room['status'];
                            $statusLabel = match ($statusKey) {
                                'available'   => 'Available',
                                'booked'      => 'Booked',
                                'unavailable' => 'Unavailable',
                                'maintenance' => 'Under Maintenance',
                                default       => ucfirst($statusKey),
                            };
                            $statusClass = 'rstatus--' . ($statusKey === 'booked' ? 'booked' : $statusKey);
                            $canBook     = ($statusKey === 'available' || $statusKey === 'booked');
                        ?>
                        <div class="rthumb" data-room-id="<?php echo (int)$room['id']; ?>"
                             data-room-name="<?php echo htmlspecialchars($room['name']); ?>"
                             data-room-status="<?php echo htmlspecialchars($statusKey); ?>">
                            <div class="rthumb__media">
                                <?php if (!empty($room['image_filename'])): ?>
                                    <img src="<?php echo htmlspecialchars($imgBase . $room['image_filename']); ?>"
                                         alt="<?php echo htmlspecialchars($room['name']); ?>"
                                         loading="lazy">
                                <?php else: ?>
                                    <span class="rthumb__media-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:100%;height:100%"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>
                                    </span>
                                <?php endif; ?>

                                <span class="rthumb__status-badge <?php echo $statusClass; ?>">
                                    <span class="dot"></span>
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>

                                <button type="button" class="rthumb__cal-btn"
                                        data-cal-room-id="<?php echo (int)$room['id']; ?>"
                                        data-cal-room-name="<?php echo htmlspecialchars($room['name']); ?>"
                                        data-cal-room-type="<?php echo htmlspecialchars($room['type']); ?>"
                                        aria-label="View booking calendar for <?php echo htmlspecialchars($room['name']); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    View Calendar
                                </button>

                                <span class="rthumb__price-badge">
                                    ₱<?php echo number_format((float)$room['price'], 0); ?><small>/night</small>
                                </span>
                            </div>

                            <div class="rthumb__body">
                                <div class="rthumb__name"><?php echo htmlspecialchars($room['name']); ?></div>
                                <div class="rthumb__type"><?php echo htmlspecialchars($room['type']); ?></div>
                                <div class="rthumb__meta">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;flex-shrink:0"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    Up to <?php echo (int)$room['capacity']; ?> guest<?php echo (int)$room['capacity'] !== 1 ? 's' : ''; ?>
                                </div>
                                <?php if (!empty($amenities)): ?>
                                <div class="rthumb__amenities">
                                    <?php
                                    $shown = array_slice($amenities, 0, 3);
                                    $more  = count($amenities) - 3;
                                    foreach ($shown as $a): ?>
                                        <span class="rthumb__tag"><?php echo htmlspecialchars($a); ?></span>
                                    <?php endforeach;
                                    if ($more > 0): ?>
                                        <span class="rthumb__tag rthumb__tag--more">+<?php echo $more; ?> more</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="rthumb__footer">
                                <span style="font-size:13px;color:var(--paper-dim)">
                                    ₱<?php echo number_format((float)$room['price'], 2); ?>/night
                                </span>
                                <?php if ($canBook): ?>
                                    <button type="button" class="rthumb__select-btn"
                                            data-select-room-id="<?php echo (int)$room['id']; ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:11px;height:11px"><polyline points="20 6 9 17 4 12"/></svg>
                                        Select &amp; Check
                                    </button>
                                <?php else: ?>
                                    <span class="rthumb__unavail-label">Not available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ===== STEP 2: Room results ===== -->
        <section class="panel" id="step2Panel" style="display:none">
            <div class="rooms-section">
                <div class="rooms-header">
                    <h2>Available Rooms</h2>
                    <span class="rooms-meta" id="roomsMeta"></span>
                </div>

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
                    <h3>No rooms available</h3>
                    <p>All our rooms are either occupied or unavailable for those dates. Try different dates.</p>
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
                    <div class="guest-info-field" style="grid-column:1/-1">
                        <label for="guestFullName">Full Name</label>
                        <input type="text" id="guestFullName"
                               value="<?php echo htmlspecialchars($prefillName); ?>"
                               placeholder="Your full name"
                               autocomplete="name">
                        <span class="field-hint">Pre-filled from your account — update if needed.</span>
                    </div>
                    <div class="guest-info-field">
                        <label for="guestPhone">Phone Number</label>
                        <input type="tel" id="guestPhone"
                               value="<?php echo htmlspecialchars($prefillPhone); ?>"
                               placeholder="+63 9XX XXX XXXX"
                               autocomplete="tel">
                        <span class="field-hint">For booking confirmation contact.</span>
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
                        $checkIn   = date('M j, Y', strtotime($bk['check_in']));
                        $checkOut  = date('M j, Y', strtotime($bk['check_out']));
                        $nights    = (int) round((strtotime($bk['check_out']) - strtotime($bk['check_in'])) / 86400);
                        ?>
                        <article class="bk-item">
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
                <span class="bk-detail-row__label">Phone</span>
                <span class="bk-detail-row__value" id="bkDetailPhone">—</span>
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

        <div class="bk-modal__foot">
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
            Payment is settled upon check-in or check-out (cash or GCash).
        </p>
        <div class="bk-success__actions">
            <button type="button" class="btn btn--primary" id="bkSuccessDone">
                <?php render_icon('circle-check'); ?> Done
            </button>
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

<!-- Hidden tokens for JS -->
<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
<input type="hidden" id="imgBase" value="../admin/assets/uploads/accommodations/">

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
  var allRoomsPanel = document.getElementById('allRoomsPanel');

  var elSkeleton   = document.getElementById('roomsSkeleton');
  var elGrid       = document.getElementById('roomsGrid');
  var elMeta       = document.getElementById('roomsMeta');
  var elError      = document.getElementById('roomsError');
  var elErrText    = document.getElementById('roomsErrorText');
  var elEmpty      = document.getElementById('roomsEmpty');

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

  // Step 3
  var elStep3Error    = document.getElementById('step3Error');
  var elStep3ErrorTxt = document.getElementById('step3ErrorText');
  var elGuestName     = document.getElementById('guestFullName');
  var elGuestPhone    = document.getElementById('guestPhone');

  var csrf    = (document.getElementById('csrfToken') || {}).value || '';
  var imgBase = (document.getElementById('imgBase') || {}).value || '';

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

  /* ---- "Select & Check" on room thumbnails ---- */
  document.getElementById('allRoomsGrid') && document.getElementById('allRoomsGrid').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-select-room-id]');
    if (!btn) return;
    step1Panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (!currentCheckIn || !currentCheckOut) {
      // Flash the date triggers
      [triggerCI, triggerCO].forEach(function (el) {
        el.style.transition = 'box-shadow .3s';
        el.style.boxShadow  = '0 0 0 3px rgba(232,163,61,.5)';
        setTimeout(function () { el.style.boxShadow = ''; }, 1400);
      });
      openPickCalendar('checkin');
    }
  });

  /* ---- Step management ---- */
  function setStep(n) {
    step1Panel.style.display    = n === 1 ? '' : 'none';
    step2Panel.style.display    = n === 2 ? '' : 'none';
    step3Panel.style.display    = n === 3 ? '' : 'none';
    allRoomsPanel.style.display = n === 1 ? '' : 'none';

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
  }
  function hideRoomsLoading() { elSkeleton.style.display = 'none'; }

  function buildRoomCard(room) {
    var card = document.createElement('div');
    card.className = 'room-card' +
      (room.availability === 'occupied'    ? ' is-occupied'    : '') +
      (room.availability === 'unavailable' ? ' is-unavailable' : '');

    var availClass = 'avail--' + room.availability;

    var mediaHtml = room.image_filename
      ? '<img src="' + imgBase + encodeURIComponent(room.image_filename) +
        '" alt="' + escHtml(room.name) + '" loading="lazy">'
      : '<span class="room-card__media-icon">' + bedIcon() + '</span>';

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
        sendIcon() + ' Book This Room</button>'
      : '<span style="font-size:12px;color:var(--paper-faint);font-weight:600">' +
        escHtml(room.availability_label) + '</span>';

    card.innerHTML =
      '<div class="room-card__media">' +
        mediaHtml +
        '<span class="room-card__price-badge">' + escHtml(room.price_formatted) +
          '<small>/night</small></span>' +
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
          '<span>Up to ' + room.capacity + ' guest' + (room.capacity === 1 ? '' : 's') + '</span>' +
        '</div>' +
        amenHtml + descHtml +
      '</div>' +
      '<div class="room-card__footer">' +
        '<div class="room-card__total">' + escHtml(room.total_formatted) +
          '<br><small>' + room.nights + ' night' + (room.nights === 1 ? '' : 's') + '</small>' +
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
        action:     'availability',
        check_in:   currentCheckIn,
        check_out:  currentCheckOut,
        csrf_token: csrf,
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

      var rooms  = data.rooms || [];
      var nights = data.nights || 0;

      elMeta.innerHTML =
        '<strong>' + rooms.length + '</strong> room' + (rooms.length === 1 ? '' : 's') +
        ' · <strong>' + nights + '</strong> night' + (nights === 1 ? '' : 's') +
        ' · ' + formatDate(currentCheckIn) + ' → ' + formatDate(currentCheckOut);

      elGrid.innerHTML = '';

      if (rooms.length === 0) {
        elEmpty.style.display = 'block';
        return;
      }

      elGrid.style.display = 'grid';

      rooms.sort(function (a, b) {
        var order = { available: 0, occupied: 1, unavailable: 2 };
        return (order[a.availability] || 0) - (order[b.availability] || 0);
      });

      rooms.forEach(function (room) { elGrid.appendChild(buildRoomCard(room)); });

      var availCount = rooms.filter(function (r) { return r.availability === 'available'; }).length;
      if (availCount === 0) {
        var notice = document.createElement('div');
        notice.className = 'api-error';
        notice.style.gridColumn = '1/-1';
        notice.innerHTML = bedIcon() + '<span>No rooms are available for these dates. Try different dates.</span>';
        elGrid.prepend(notice);
      }
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

    // Clear step 3 error
    elStep3Error.style.display = 'none';

    setStep(3);
  }

  elBackToRooms && elBackToRooms.addEventListener('click', function () { setStep(2); });

  /* ---- Step 3 → Confirm modal ---- */
  elOpenModal && elOpenModal.addEventListener('click', function () {
    if (!pendingBooking) return;

    var fullName = elGuestName.value.trim();
    var phone    = elGuestPhone.value.trim();

    elStep3Error.style.display = 'none';

    if (!fullName) {
      elStep3ErrorTxt.textContent = 'Please enter your full name.';
      elStep3Error.style.display  = 'flex';
      elGuestName.focus();
      return;
    }
    if (!phone) {
      elStep3ErrorTxt.textContent = 'Please enter your phone number.';
      elStep3Error.style.display  = 'flex';
      elGuestPhone.focus();
      return;
    }

    openBookingModal(fullName, phone);
  });

  /* ---- Booking confirmation modal ---- */
  function openBookingModal(fullName, phone) {
    var room = pendingBooking.room;

    document.getElementById('bkModalRoomName').textContent  = room.name;
    document.getElementById('bkModalRoomType').textContent  = room.type;
    document.getElementById('bkDetailGuest').textContent    = fullName;
    document.getElementById('bkDetailPhone').textContent    = phone;
    document.getElementById('bkDetailCheckIn').textContent  = formatDate(currentCheckIn);
    document.getElementById('bkDetailCheckOut').textContent = formatDate(currentCheckOut);
    document.getElementById('bkDetailNights').textContent   = pendingBooking.nights + ' night' + (pendingBooking.nights === 1 ? '' : 's');
    document.getElementById('bkDetailGuests').textContent   = currentGuests + ' guest' + (currentGuests === 1 ? '' : 's');
    document.getElementById('bkDetailRate').textContent     = room.price_formatted + '/night';
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

    document.getElementById('bkNotes').value = '';
    elMError.textContent = '';
    elConfLbl.textContent = 'Confirm Booking';
    elMConfirm.disabled = false;
    elMContent.style.display = '';
    elSuccess.classList.remove('is-visible');

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

  /* ---- Confirm booking ---- */
  elMConfirm.addEventListener('click', function () {
    if (!pendingBooking || isSubmitting) return;

    elMError.textContent = '';
    isSubmitting = true;
    elMConfirm.disabled = true;
    elConfLbl.textContent = 'Booking…';

    var notes = (document.getElementById('bkNotes').value || '').trim();

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
        csrf_token:       csrf,
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      isSubmitting = false;
      if (!data.success) {
        elMError.textContent = data.error || 'Booking failed. Please try again.';
        elMConfirm.disabled = false;
        elConfLbl.textContent = 'Confirm Booking';
        return;
      }

      elMContent.style.display = 'none';
      elSuccess.classList.add('is-visible');
      elSuccRef.textContent = data.reference;

      var det = formatDate(data.check_in) + ' → ' + formatDate(data.check_out) +
                ' · ' + data.room_name + ' · ' + data.total_formatted;
      elSuccDet.textContent = det;

      injectNewBooking(data);
    })
    .catch(function () {
      isSubmitting = false;
      elMError.textContent = 'Network error. Please try again.';
      elMConfirm.disabled = false;
      elConfLbl.textContent = 'Confirm Booking';
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
    refreshDateTriggers();
    setStep(1);
    document.querySelector('.bk-list') &&
      document.querySelector('.bk-list').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* ---- Inject new booking row into My Bookings ---- */
  function injectNewBooking(data) {
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

    var art = document.createElement('article');
    art.className = 'bk-item';
    art.innerHTML =
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

})();
</script>