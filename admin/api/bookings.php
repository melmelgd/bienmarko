<?php
/**
 * admin/api/bookings.php
 * -----------------------------------------------------------------------
 * JSON API for admin booking operations.
 *   POST { action: "create", ... }         — create a new walk-in booking
 *   POST { action: "update_status", ... }  — update booking status
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
header('Content-Type: application/json; charset=utf-8');

/* Only admins may call this endpoint */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$pdo  = bienmarko_db();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

/* CSRF check */
if (empty($body['csrf_token']) || !bienmarko_csrf_valid($body['csrf_token'])) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token.']);
    exit;
}

$action = trim($body['action'] ?? '');

/* ================================================================
   ACTION: update_status
   ================================================================ */
if ($action === 'update_status') {
    $id     = (int) ($body['id'] ?? 0);
    $status = trim($body['status'] ?? '');

    $allowed = ['confirmed', 'pending', 'cancelled', 'completed'];
    if ($id <= 0 || !in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid booking ID or status.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE bookings SET status = :status WHERE id = :id");
    $stmt->execute(['status' => $status, 'id' => $id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Booking not found.']);
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
    $room = $pdo->prepare("SELECT id, price, status FROM accommodations WHERE id = :id");
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

    $nights = max(1, (int) ceil((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    $total  = round((float) $room['price'] * $nights, 2);

    /* --- Conflict check (row-level lock) --- */
    $pdo->beginTransaction();
    try {
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

        /* --- Generate unique reference --- */
        do {
            $ref = 'BM-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
            $exists = (int) $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE reference = :r")->execute(['r' => $ref]);
        } while ($exists > 0);

        /* --- Insert booking --- */
        $ins = $pdo->prepare(
            "INSERT INTO bookings
                (accommodation_id, guest_id, reference, check_in, check_out,
                 guests_count, status, total_amount,
                 guest_name, email, phone, address, payment_method, booking_type, notes)
             VALUES
                (:aid, NULL, :ref, :ci, :co,
                 :gc, 'confirmed', :total,
                 :gname, :email, :phone, :addr, :pm, 'guest', :notes)"
        );
        $ins->execute([
            'aid'   => $accommodationId,
            'ref'   => $ref,
            'ci'    => $checkIn,
            'co'    => $checkOut,
            'gc'    => $guestsCount,
            'total' => $total,
            'gname' => $guestName,
            'email' => $email,
            'phone' => $phone,
            'addr'  => $address,
            'pm'    => $paymentMethod,
            'notes' => $notes,
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

        echo json_encode(['success' => true, 'reference' => $ref, 'booking_id' => $bookingId]);

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
