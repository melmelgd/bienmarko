<?php
/**
 * admin/api/payments.php
 * -----------------------------------------------------------------------
 * JSON API for admin payment/billing operations.
 *   POST { action: "save_payment", ... } — upsert a payment record
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');
header('Content-Type: application/json; charset=utf-8');

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
   ACTION: save_payment
   ================================================================ */
if ($action === 'save_payment') {
    $bookingId     = (int)   ($body['booking_id']      ?? 0);
    $roomFee       = (float) ($body['room_fee']         ?? 0);
    $extraGuestFee = (float) ($body['extra_guest_fee']  ?? 0);
    $otherCharges  = (float) ($body['other_charges']    ?? 0);
    $discount      = (float) ($body['discount']         ?? 0);
    $discountReason= trim($body['discount_reason']     ?? '');
    $totalPaid     = (float) ($body['total_paid']       ?? 0);
    $paymentMethod = in_array($body['payment_method'] ?? '', ['cash','gcash']) ? $body['payment_method'] : 'cash';
    $paymentStatus = in_array($body['payment_status'] ?? '', ['unpaid','partial','paid']) ? $body['payment_status'] : 'unpaid';
    $notes         = trim($body['notes']               ?? '');
    $totalAmount   = (float) ($body['total_amount']     ?? 0);

    if ($bookingId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid booking ID.']);
        exit;
    }

    /* Verify booking exists */
    $bkStmt = $pdo->prepare("SELECT id, total_amount FROM bookings WHERE id = :id");
    $bkStmt->execute(['id' => $bookingId]);
    $bk = $bkStmt->fetch();
    if (!$bk) {
        echo json_encode(['success' => false, 'error' => 'Booking not found.']);
        exit;
    }

    /* Compute final total */
    $finalTotal = max(0.0, $roomFee + $extraGuestFee + $otherCharges - $discount);

    $paidAt = ($paymentStatus === 'paid') ? date('Y-m-d H:i:s') : null;

    try {
        /* Upsert: update if payment record exists, else insert */
        $existing = $pdo->prepare("SELECT id FROM payments WHERE booking_id = :bid");
        $existing->execute(['bid' => $bookingId]);
        $payId = $existing->fetchColumn();

        if ($payId) {
            /* UPDATE */
            $upd = $pdo->prepare(
                "UPDATE payments SET
                    room_fee        = :rf,
                    extra_guest_fee = :eg,
                    other_charges   = :oc,
                    discount        = :disc,
                    discount_reason = :dr,
                    total_paid      = :tp,
                    payment_method  = :pm,
                    payment_status  = :ps,
                    notes           = :notes,
                    paid_at         = COALESCE(:paid_at, paid_at),
                    updated_at      = NOW()
                 WHERE id = :pid"
            );
            $upd->execute([
                'rf'      => $roomFee,
                'eg'      => $extraGuestFee,
                'oc'      => $otherCharges,
                'disc'    => $discount,
                'dr'      => $discountReason,
                'tp'      => $totalPaid,
                'pm'      => $paymentMethod,
                'ps'      => $paymentStatus,
                'notes'   => $notes,
                'paid_at' => $paidAt,
                'pid'     => (int) $payId,
            ]);
        } else {
            /* INSERT */
            $ins = $pdo->prepare(
                "INSERT INTO payments
                    (booking_id, room_fee, extra_guest_fee, other_charges,
                     discount, discount_reason, total_paid, payment_method,
                     payment_status, notes, paid_at)
                 VALUES
                    (:bid, :rf, :eg, :oc,
                     :disc, :dr, :tp, :pm,
                     :ps, :notes, :paid_at)"
            );
            $ins->execute([
                'bid'     => $bookingId,
                'rf'      => $roomFee,
                'eg'      => $extraGuestFee,
                'oc'      => $otherCharges,
                'disc'    => $discount,
                'dr'      => $discountReason,
                'tp'      => $totalPaid,
                'pm'      => $paymentMethod,
                'ps'      => $paymentStatus,
                'notes'   => $notes,
                'paid_at' => $paidAt,
            ]);
            $payId = (int) $pdo->lastInsertId();
        }

        /* Keep bookings.total_amount in sync with computed billing total */
        if ($finalTotal > 0) {
            $pdo->prepare("UPDATE bookings SET total_amount = :total WHERE id = :bid")
                ->execute(['total' => $finalTotal, 'bid' => $bookingId]);
        }

        /* If fully paid, mark booking as completed */
        if ($paymentStatus === 'paid') {
            $pdo->prepare(
                "UPDATE bookings SET status = 'completed' WHERE id = :bid AND status != 'cancelled'"
            )->execute(['bid' => $bookingId]);
        }

        /* Notification */
        try {
            $ref = $pdo->query("SELECT reference FROM bookings WHERE id = {$bookingId}")->fetchColumn();
            $pdo->prepare(
                "INSERT INTO admin_notifications (booking_id, type, message)
                 VALUES (:bid, 'payment', :msg)"
            )->execute([
                'bid' => $bookingId,
                'msg' => "Payment recorded for booking {$ref}: ₱" . number_format($totalPaid, 2) . " ({$paymentStatus}).",
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }

        echo json_encode(['success' => true, 'pay_id' => $payId]);

    } catch (\Throwable $e) {
        error_log('[payments api] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'A database error occurred. Please try again.']);
    }
    exit;
}

/* Unknown action */
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
