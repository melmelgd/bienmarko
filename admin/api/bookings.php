<?php
/**
 * admin/api/bookings.php
 * -----------------------------------------------------------------------
 * JSON API for admin booking operations.
 *   POST { action: "create", ... }         — create a new walk-in booking
 *   POST { action: "check_in",  id }       — admin confirms the guest has
 *                                            arrived (Upcoming → Checked-in)
 *   POST { action: "check_out", id }       — admin records departure
 *                                            (Checked-in → Completed)
 *   POST { action: "update_status", ... }  — cancel / restore a booking
 *   POST { action: "update", ... }         — edit an existing booking
 *
 * Check-in and check-out are ALWAYS a manual admin action. Nothing here
 * (or anywhere else) changes them based on a date or time. The stage
 * rules live in includes/booking-stay.php.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';
require_once __DIR__ . '/../../includes/booking-emails.php';
require_once __DIR__ . '/../includes/booking-stay.php';

bienmarko_start_session();
bienmarko_require_role('admin');
header('Content-Type: application/json; charset=utf-8');

$pdo  = bienmarko_db();

// Defensive migration: companion_names may not exist yet if this
// endpoint is hit before the user-facing booking APIs have run their
// migration. Additive, information_schema-guarded, safe to repeat.
try {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS companion_names TEXT NULL DEFAULT NULL");
} catch (\Throwable $e) {
    error_log('[bookings api] companion_names migration warning: ' . $e->getMessage());
}

// Adds bookings.checked_in_at / checked_out_at if they don't exist yet.
bk_stay_migrate($pdo);

// Adds bookings.pool_session ('day' | 'night') for Swimming Pool bookings.
bk_pool_session_migrate($pdo);

/* ================================================================
   GET ?action=availability — read-only lookup used by the New/Edit
   Booking date pickers to grey out already-reserved dates before the
   admin ever submits the form. Session + admin role are already
   enforced above; no CSRF token is required since this performs no
   writes. Every other action on this endpoint remains POST+CSRF, so
   this is handled and exited before that flow ever runs.
   ================================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (($_GET['action'] ?? '') !== 'availability') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
    }

    $accommodationId = (int) ($_GET['accommodation_id'] ?? 0);
    $excludeId       = (int) ($_GET['exclude_id'] ?? 0);

    if ($accommodationId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid accommodation.']);
        exit;
    }

    $room = $pdo->prepare("SELECT id, type, capacity, status FROM accommodations WHERE id = :id");
    $room->execute(['id' => $accommodationId]);
    $room = $room->fetch();

    if (!$room) {
        echo json_encode(['success' => false, 'error' => 'Accommodation not found.']);
        exit;
    }

    // Every confirmed/pending booking for this accommodation (minus the
    // booking currently being edited, if any) — the client turns this
    // into a per-date "blocked" map. Cancelled bookings free up their
    // dates and are intentionally excluded.
    $sql    = "SELECT check_in, check_out, guests_count, pool_session FROM bookings
               WHERE accommodation_id = :aid
                 AND status IN ('confirmed','pending')";
    $params = ['aid' => $accommodationId];
    if ($excludeId > 0) {
        $sql .= " AND id != :xid";
        $params['xid'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'success'       => true,
        'accommodation' => [
            'id'       => (int) $room['id'],
            'capacity' => (int) $room['capacity'],
            'is_pool'  => strcasecmp(trim((string) $room['type']), 'Swimming Pool') === 0,
            'status'   => $room['status'],
        ],
        'bookings' => array_map(function ($r) {
            return [
                'check_in'     => $r['check_in'],
                'check_out'    => $r['check_out'],
                'guests_count' => (int) $r['guests_count'],
                // 'day' | 'night' | null (null = old pool booking, blocks both sessions)
                'pool_session' => $r['pool_session'] ?: null,
            ];
        }, $rows),
    ]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

/* CSRF check */
if (empty($body['csrf_token']) || !bienmarko_csrf_valid($body['csrf_token'])) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token.']);
    exit;
}

$action = trim($body['action'] ?? '');

/**
 * Parse a newline/comma-separated companion-names textarea into a
 * JSON array string ready for storage, or null when empty. Shared by
 * the create and update actions so both stay in sync.
 */
function bm_parse_companion_names(string $raw): ?string
{
    if (trim($raw) === '') {
        return null;
    }
    $companions = [];
    foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $companions[] = mb_substr($line, 0, 100);
        if (count($companions) >= 50) {
            break;
        }
    }
    return $companions ? json_encode(array_values($companions), JSON_UNESCAPED_UNICODE) : null;
}

/* ================================================================
   ACTION: check_in  (Upcoming → Checked-in)
   -----------------------------------------------------------------
   Only the admin can do this, only from the Upcoming stage, and only by
   explicitly calling this action when the guest is physically at the
   front desk. The scheduled check-in date is NOT consulted — an early
   arrival is the admin's call, and a late one is never auto-completed.
   The UPDATE re-checks the stage in its WHERE clause so a double-click,
   or a second admin acting on a stale page, can't apply it twice.
   ================================================================ */
if ($action === 'check_in' || $action === 'check_out') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid booking ID.']);
        exit;
    }

    $isIn      = ($action === 'check_in');
    $fromStage = $isIn ? 'upcoming'   : 'checked_in';
    $column    = $isIn ? 'checked_in_at' : 'checked_out_at';

    /* Check-out is refused until the booking is fully paid. Enforced here
       (not just by the disabled button) so a stale page, a second admin or a
       hand-made request can't check a guest out with money still owed.
       Only checked when the booking really is Checked-in; any other stage
       falls through to the stage message below. */
    if (!$isIn) {
        $stageRow = $pdo->prepare("SELECT status, checked_in_at, checked_out_at FROM bookings WHERE id = :id");
        $stageRow->execute(['id' => $id]);
        $stageRow = $stageRow->fetch();

        if ($stageRow && bk_stage($stageRow) === 'checked_in') {
            $pay = bk_payment_state($pdo, $id);
            if (!$pay['fully_paid']) {
                $owed = number_format($pay['balance'], 2);
                echo json_encode([
                    'success' => false,
                    'refresh' => true,
                    'error'   => $pay['has_record']
                        ? "Can't check out yet — this booking still has an unpaid balance of ₱{$owed}. Record the payment in Payments first."
                        : "Can't check out yet — no payment has been recorded for this booking (₱{$owed} due). Record the payment in Payments first.",
                ]);
                exit;
            }
        }
    }

    $upd = $pdo->prepare(
        "UPDATE bookings SET {$column} = :now
         WHERE id = :id AND " . bk_stage_sql($fromStage, '')
    );
    $upd->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);

    if ($upd->rowCount() === 0) {
        // Nothing changed — work out why, so the admin gets a useful message.
        $cur = $pdo->prepare("SELECT status, checked_in_at, checked_out_at FROM bookings WHERE id = :id");
        $cur->execute(['id' => $id]);
        $cur = $cur->fetch();

        if (!$cur) {
            echo json_encode(['success' => false, 'error' => 'Booking not found.']);
            exit;
        }
        $labels = ['upcoming' => 'Upcoming', 'checked_in' => 'Checked-in', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        $stage  = bk_stage($cur);
        echo json_encode([
            'success' => false,
            'refresh' => true,
            'error'   => $isIn
                ? "This booking can't be checked in — it is currently {$labels[$stage]}."
                : "This booking can't be checked out — it is currently {$labels[$stage]}. Only Checked-in guests can be checked out.",
        ]);
        exit;
    }

    try {
        $ref = $pdo->prepare("SELECT reference FROM bookings WHERE id = :id");
        $ref->execute(['id' => $id]);
        $ref = (string) $ref->fetchColumn();
        $pdo->prepare(
            "INSERT INTO admin_notifications (booking_id, type, message)
             VALUES (:bid, 'booking_status', :msg)"
        )->execute([
            'bid' => $id,
            'msg' => $isIn
                ? "Booking {$ref}: guest checked in."
                : "Booking {$ref}: guest checked out — booking completed.",
        ]);
    } catch (\Throwable $e) {
        /* Non-fatal: the notification log must never block a front-desk action */
    }

    echo json_encode(['success' => true, 'stage' => $isIn ? 'checked_in' : 'completed']);
    exit;
}

/* ================================================================
   ACTION: update_status  (cancel / restore only)
   -----------------------------------------------------------------
   Check-in and check-out have their own actions above, so this endpoint
   no longer accepts 'completed' / 'pending' (which would let a booking
   skip the front-desk flow). Existing cancellation rules are kept:
     • only confirmed/pending bookings can be cancelled (as before);
     • new: a guest who is already Checked-in or Completed can't be
       cancelled — the stay has started;
     • only a Cancelled booking can be restored.
   ================================================================ */
if ($action === 'update_status') {
    $id     = (int) ($body['id'] ?? 0);
    $status = trim($body['status'] ?? '');

    if ($id <= 0 || !in_array($status, ['cancelled', 'confirmed'], true)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid booking ID or status. Use Check-in / Check-out to record arrivals and departures.',
        ]);
        exit;
    }

    $curStmt = $pdo->prepare("SELECT status, checked_in_at, checked_out_at FROM bookings WHERE id = :id");
    $curStmt->execute(['id' => $id]);
    $cur = $curStmt->fetch();

    if (!$cur) {
        echo json_encode(['success' => false, 'error' => 'Booking not found.']);
        exit;
    }
    $prevStatus = (string) $cur['status'];
    $stage      = bk_stage($cur);

    if ($status === 'cancelled') {
        if ($prevStatus === 'cancelled') {
            echo json_encode(['success' => false, 'refresh' => true, 'error' => 'This booking is already cancelled.']);
            exit;
        }
        if ($stage !== 'upcoming') {
            echo json_encode([
                'success' => false,
                'refresh' => true,
                'error'   => 'Only bookings that have not checked in yet can be cancelled.',
            ]);
            exit;
        }
        if (!in_array($prevStatus, ['confirmed', 'pending'], true)) {
            echo json_encode(['success' => false, 'error' => "A {$prevStatus} booking can't be cancelled."]);
            exit;
        }
    } else { // restore
        if ($prevStatus !== 'cancelled') {
            echo json_encode(['success' => false, 'refresh' => true, 'error' => 'Only cancelled bookings can be restored.']);
            exit;
        }
    }

    // "AND status = :prev" makes the write a no-op if someone else changed it first.
    $stmt = $pdo->prepare("UPDATE bookings SET status = :status WHERE id = :id AND status = :prev");
    $stmt->execute(['status' => $status, 'id' => $id, 'prev' => $prevStatus]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'refresh' => true, 'error' => 'This booking was just changed by someone else. Please review it again.']);
        exit;
    }

    /* Notify admin log */
    try {
        $ref = $pdo->query("SELECT reference FROM bookings WHERE id = {$id}")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO admin_notifications (booking_id, type, message)
             VALUES (:bid, 'booking_status', :msg)"
        )->execute([
            'bid' => $id,
            'msg' => "Booking {$ref} status changed to " . ucfirst($status) . ".",
        ]);
    } catch (\Throwable $e) {
        /* Non-fatal: notification table may not have this column yet */
    }

    if ($status === 'cancelled' && $prevStatus !== 'cancelled') {
        // Email the guest (sent after the response; never blocks the admin action).
        bienmarko_email_after_response(static function () use ($pdo, $id): void {
            bienmarko_email_send_booking($pdo, $id, 'cancelled');
        });
    }

    echo json_encode(['success' => true]);
    exit;
}

/* ================================================================
   ACTION: create (admin walk-in booking)
   ================================================================ */
if ($action === 'create') {
    /* --- Validate required fields --- */
    $guestName       = trim($body['guest_name']       ?? '');
    $accommodationId = (int)  ($body['accommodation_id'] ?? 0);
    $checkIn         = trim($body['check_in']         ?? '');
    $checkOut        = trim($body['check_out']        ?? '');
    $guestsCount     = max(1, (int) ($body['guests_count'] ?? 1));
    $phone           = trim($body['phone']            ?? '');
    $email           = trim($body['email']            ?? '');
    $address         = trim($body['address']          ?? '');
    $paymentMethod   = in_array($body['payment_method'] ?? '', ['cash', 'gcash']) ? $body['payment_method'] : 'cash';
    $notes           = trim($body['notes']            ?? '');

    // Companion / guest-mate names — submitted as a single newline-
    // separated textarea from the admin "New Booking" form (used for
    // Swimming Pool walk-ins so front desk can log who's in the group).
    // Stored as a JSON array, same column/format as the guest-facing
    // booking flows.
    $companionNamesJson = bm_parse_companion_names((string) ($body['companion_names'] ?? ''));

    if ($guestName === '') {
        echo json_encode(['success' => false, 'error' => 'Guest name is required.']);
        exit;
    }
    if ($accommodationId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Please select an accommodation.']);
        exit;
    }
    if ($checkIn === '' || $checkOut === '') {
        echo json_encode(['success' => false, 'error' => 'Check-in and check-out dates are required.']);
        exit;
    }
    if ($checkOut <= $checkIn) {
        echo json_encode(['success' => false, 'error' => 'Check-out must be after check-in.']);
        exit;
    }

    /* --- Fetch accommodation & compute total --- */
    $room = $pdo->prepare("SELECT id, type, price, status, capacity FROM accommodations WHERE id = :id");
    $room->execute(['id' => $accommodationId]);
    $room = $room->fetch();

    if (!$room) {
        echo json_encode(['success' => false, 'error' => 'Accommodation not found.']);
        exit;
    }
    if ($room['status'] !== 'available') {
        echo json_encode(['success' => false, 'error' => 'This accommodation is currently unavailable.']);
        exit;
    }
    if ($guestsCount > (int) $room['capacity']) {
        echo json_encode(['success' => false, 'error' => "This accommodation holds up to {$room['capacity']} guest(s)."]);
        exit;
    }

    // Swimming Pool: shared by multiple concurrent bookings up to its
    // capacity and billed per person, mirroring the guest/registered
    // booking flows so pricing and availability stay consistent
    // regardless of which portal created the booking.
    $isPool = strcasecmp(trim((string) $room['type']), 'Swimming Pool') === 0;

    // Pool bookings are for the DAY or the NIGHT session. Each session has
    // its own capacity, so a day booking never blocks a night booking.
    $poolSession = null;
    if ($isPool) {
        $poolSession = strtolower(trim((string) ($body['pool_session'] ?? '')));
        if (!in_array($poolSession, BK_POOL_SESSIONS, true)) {
            echo json_encode(['success' => false, 'error' => 'Please choose Day or Night for the swimming pool.']);
            exit;
        }
    }

    $nights = max(1, (int) ceil((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    // Pool = flat ₱1,000 for 1-10 guests; 11+ guests pay the per-person
    // price each (see bk_pool_fee), for each day booked.
    // Every other accommodation = price per night.
    $total  = $isPool
        ? bk_pool_fee((float) $room['price'], $guestsCount, $nights)
        : round((float) $room['price'] * $nights, 2);

    /* --- Conflict / capacity check (row-level lock) --- */
    $pdo->beginTransaction();
    try {
        if ($isPool) {
            $conflict = $pdo->prepare(
                "SELECT COALESCE(SUM(guests_count), 0) FROM bookings
                 WHERE accommodation_id = :aid
                   AND status IN ('confirmed','pending')
                   AND " . bk_pool_session_sql() . "
                   AND check_in  < :co
                   AND check_out > :ci
                 FOR UPDATE"
            );
            $conflict->execute(['aid' => $accommodationId, 'psess' => $poolSession, 'ci' => $checkIn, 'co' => $checkOut]);
            $bookedGuests = (int) $conflict->fetchColumn();

            if ($bookedGuests + $guestsCount > (int) $room['capacity']) {
                $pdo->rollBack();
                $remaining = max(0, (int) $room['capacity'] - $bookedGuests);
                echo json_encode([
                    'success' => false,
                    'error'   => $remaining > 0
                        ? "Only {$remaining} pool slot(s) left for the {$poolSession} session on the selected dates."
                        : "The swimming pool is fully booked for the {$poolSession} session on the selected dates.",
                ]);
                exit;
            }
        } else {
            $conflict = $pdo->prepare(
                "SELECT COUNT(*) FROM bookings
                 WHERE accommodation_id = :aid
                   AND status IN ('confirmed','pending')
                   AND check_in  < :co
                   AND check_out > :ci
                 FOR UPDATE"
            );
            $conflict->execute(['aid' => $accommodationId, 'ci' => $checkIn, 'co' => $checkOut]);
            if ((int) $conflict->fetchColumn() > 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Room is already booked for the selected dates.']);
                exit;
            }
        }

        /* --- Generate unique reference --- */
        do {
            $ref    = 'BM-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
            $chkRef = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE reference = :r");
            $chkRef->execute(['r' => $ref]);
            $exists = (int) $chkRef->fetchColumn();
        } while ($exists > 0);

        /* --- Insert booking --- */
        $ins = $pdo->prepare(
            "INSERT INTO bookings
                (accommodation_id, guest_id, reference, check_in, check_out,
                 guests_count, status, total_amount,
                 guest_name, email, phone, address, payment_method, booking_type, notes, companion_names,
                 pool_session)
             VALUES
                (:aid, NULL, :ref, :ci, :co,
                 :gc, 'confirmed', :total,
                 :gname, :email, :phone, :addr, :pm, 'guest', :notes, :companion_names,
                 :psess)"
        );
        $ins->execute([
            'psess'           => $poolSession,
            'aid'             => $accommodationId,
            'ref'             => $ref,
            'ci'              => $checkIn,
            'co'              => $checkOut,
            'gc'              => $guestsCount,
            'total'           => $total,
            'gname'           => $guestName,
            'email'           => $email,
            'phone'           => $phone,
            'addr'            => $address,
            'pm'              => $paymentMethod,
            'notes'           => $notes,
            'companion_names' => $companionNamesJson,
        ]);

        $bookingId = (int) $pdo->lastInsertId();

        /* --- Notification --- */
        try {
            $pdo->prepare(
                "INSERT INTO admin_notifications (booking_id, type, message)
                 VALUES (:bid, 'new_booking', :msg)"
            )->execute([
                'bid' => $bookingId,
                'msg' => "New walk-in booking {$ref} for {$guestName}.",
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }

        $pdo->commit();

        if ($email !== '') {
            // Email the guest (sent after the response; never blocks the admin action).
            bienmarko_email_after_response(static function () use ($pdo, $bookingId): void {
                bienmarko_email_send_booking($pdo, $bookingId, 'confirmation');
            });
        }

        echo json_encode(['success' => true, 'reference' => $ref, 'booking_id' => $bookingId]);

    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('[bookings api] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'A database error occurred. Please try again.']);
    }
    exit;
}

/* ================================================================
   ACTION: update (admin edits an existing booking)
   -----------------------------------------------------------------
   Admins never need to manually "confirm" a booking — every
   reservation, whether placed by a guest or a front-desk walk-in, is
   auto-approved on creation. This action instead lets the admin fix
   or adjust a booking that's already on the books (room, dates,
   guest count, payment method, notes, companions). Cancelling is
   handled separately via update_status.
   ================================================================ */
if ($action === 'update') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid booking ID.']);
        exit;
    }

    $existing = $pdo->prepare("SELECT * FROM bookings WHERE id = :id");
    $existing->execute(['id' => $id]);
    $existing = $existing->fetch();

    if (!$existing) {
        echo json_encode(['success' => false, 'error' => 'Booking not found.']);
        exit;
    }

    $accommodationId = (int) ($body['accommodation_id'] ?? $existing['accommodation_id']);
    $checkIn         = trim($body['check_in']  ?? $existing['check_in']);
    $checkOut        = trim($body['check_out'] ?? $existing['check_out']);
    $guestsCount     = max(1, (int) ($body['guests_count'] ?? $existing['guests_count']));
    $paymentMethod   = in_array($body['payment_method'] ?? '', ['cash', 'gcash'], true)
        ? $body['payment_method']
        : ($existing['payment_method'] ?? 'cash');
    $notes           = trim($body['notes'] ?? '');

    // Registered-user bookings keep the guest_name/email/phone/address
    // columns tied to the account (they're NULL/blank on that booking
    // row by design — bk_name()/bk_email()/bk_phone() pull from the
    // guests table instead). Only guest (no-account) bookings let the
    // admin edit contact details directly here.
    if ($existing['booking_type'] === 'guest') {
        $guestName = trim($body['guest_name'] ?? (string) $existing['guest_name']);
        $phone     = trim($body['phone']      ?? (string) $existing['phone']);
        $email     = trim($body['email']      ?? (string) $existing['email']);
        $address   = trim($body['address']    ?? (string) $existing['address']);

        if ($guestName === '') {
            echo json_encode(['success' => false, 'error' => 'Guest name is required.']);
            exit;
        }
    } else {
        $guestName = $existing['guest_name'];
        $phone     = $existing['phone'];
        $email     = $existing['email'];
        $address   = $existing['address'];
    }

    $companionNamesJson = bm_parse_companion_names((string) ($body['companion_names'] ?? ''));

    if ($checkIn === '' || $checkOut === '') {
        echo json_encode(['success' => false, 'error' => 'Check-in and check-out dates are required.']);
        exit;
    }
    if ($checkOut <= $checkIn) {
        echo json_encode(['success' => false, 'error' => 'Check-out must be after check-in.']);
        exit;
    }

    /* --- Fetch accommodation & recompute total --- */
    $room = $pdo->prepare("SELECT id, type, price, status, capacity FROM accommodations WHERE id = :id");
    $room->execute(['id' => $accommodationId]);
    $room = $room->fetch();

    if (!$room) {
        echo json_encode(['success' => false, 'error' => 'Accommodation not found.']);
        exit;
    }
    if ($guestsCount > (int) $room['capacity']) {
        echo json_encode(['success' => false, 'error' => "This accommodation holds up to {$room['capacity']} guest(s)."]);
        exit;
    }

    $isPool = strcasecmp(trim((string) $room['type']), 'Swimming Pool') === 0;

    // Pool bookings are for the DAY or the NIGHT session (see create).
    // An old pool booking with no session on file must be given one the
    // first time it is edited.
    $poolSession = null;
    if ($isPool) {
        $poolSession = strtolower(trim((string) ($body['pool_session'] ?? $existing['pool_session'] ?? '')));
        if (!in_array($poolSession, BK_POOL_SESSIONS, true)) {
            echo json_encode(['success' => false, 'error' => 'Please choose Day or Night for the swimming pool.']);
            exit;
        }
    }

    $nights = max(1, (int) ceil((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    // Pool = flat ₱1,000 for 1-10 guests; 11+ guests pay the per-person
    // price each (see bk_pool_fee), for each day booked. Others per night.
    $total  = $isPool
        ? bk_pool_fee((float) $room['price'], $guestsCount, $nights)
        : round((float) $room['price'] * $nights, 2);

    /* --- Conflict / capacity check, excluding this booking itself --- */
    $pdo->beginTransaction();
    try {
        if ($isPool) {
            $conflict = $pdo->prepare(
                "SELECT COALESCE(SUM(guests_count), 0) FROM bookings
                 WHERE accommodation_id = :aid
                   AND id != :id
                   AND status IN ('confirmed','pending')
                   AND " . bk_pool_session_sql() . "
                   AND check_in  < :co
                   AND check_out > :ci
                 FOR UPDATE"
            );
            $conflict->execute(['aid' => $accommodationId, 'id' => $id, 'psess' => $poolSession, 'ci' => $checkIn, 'co' => $checkOut]);
            $bookedGuests = (int) $conflict->fetchColumn();

            if ($bookedGuests + $guestsCount > (int) $room['capacity']) {
                $pdo->rollBack();
                $remaining = max(0, (int) $room['capacity'] - $bookedGuests);
                echo json_encode([
                    'success' => false,
                    'error'   => $remaining > 0
                        ? "Only {$remaining} pool slot(s) left for the {$poolSession} session on the selected dates."
                        : "The swimming pool is fully booked for the {$poolSession} session on the selected dates.",
                ]);
                exit;
            }
        } else {
            $conflict = $pdo->prepare(
                "SELECT COUNT(*) FROM bookings
                 WHERE accommodation_id = :aid
                   AND id != :id
                   AND status IN ('confirmed','pending')
                   AND check_in  < :co
                   AND check_out > :ci
                 FOR UPDATE"
            );
            $conflict->execute(['aid' => $accommodationId, 'id' => $id, 'ci' => $checkIn, 'co' => $checkOut]);
            if ((int) $conflict->fetchColumn() > 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Room is already booked for the selected dates.']);
                exit;
            }
        }

        $upd = $pdo->prepare(
            "UPDATE bookings SET
                accommodation_id = :aid,
                check_in         = :ci,
                check_out        = :co,
                guests_count     = :gc,
                total_amount     = :total,
                guest_name       = :gname,
                email            = :email,
                phone            = :phone,
                address          = :addr,
                payment_method   = :pm,
                notes            = :notes,
                companion_names  = :companion_names,
                pool_session     = :psess
             WHERE id = :id"
        );
        $upd->execute([
            'psess'           => $poolSession,
            'aid'             => $accommodationId,
            'ci'              => $checkIn,
            'co'              => $checkOut,
            'gc'              => $guestsCount,
            'total'           => $total,
            'gname'           => $guestName,
            'email'           => $email,
            'phone'           => $phone,
            'addr'            => $address,
            'pm'              => $paymentMethod,
            'notes'           => $notes,
            'companion_names' => $companionNamesJson,
            'id'              => $id,
        ]);

        /* --- Notification --- */
        try {
            $pdo->prepare(
                "INSERT INTO admin_notifications (booking_id, type, message)
                 VALUES (:bid, 'booking_updated', :msg)"
            )->execute([
                'bid' => $id,
                'msg' => "Booking {$existing['reference']} was updated by an admin.",
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }

        $pdo->commit();

        if (($existing['status'] ?? '') !== 'cancelled') {
            // Email the guest the updated details (sent after the response).
            bienmarko_email_after_response(static function () use ($pdo, $id): void {
                bienmarko_email_send_booking($pdo, $id, 'modified');
            });
        }

        echo json_encode(['success' => true, 'reference' => $existing['reference'], 'booking_id' => $id]);

    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('[bookings api] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'A database error occurred. Please try again.']);
    }
    exit;
}

/* Unknown action */
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);