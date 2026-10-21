<?php
/**
 * admin/api/payments.php
 * -----------------------------------------------------------------------
 * JSON API for admin payment/billing operations.
 * The discount is received as a percentage (discount_percent, 0-100) plus
 * the number of guests in the room who get it (discount_guests). It applies
 * only to those guests' share of (room fee + extra guest fee), not to the
 * whole bill. The peso amount is calculated here and stored in `discount`,
 * so the client can't send a mismatched amount.
 *   POST { action: "save_payment", ... } — upsert a payment record
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';
require_once __DIR__ . '/../../includes/booking-emails.php';

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

/* Discount is now entered as a percentage of the subtotal. The peso amount
   stays in `discount` (so reports keep working); the percentage is kept in
   `discount_percent` so it can be shown and edited again. The number of
   guests that get the discount is kept in `discount_guests`. */
if (!$pdo->query("SHOW COLUMNS FROM `payments` LIKE 'discount_percent'")->fetch()) {
    $pdo->exec("ALTER TABLE `payments` ADD COLUMN `discount_percent` DECIMAL(5,2) NULL DEFAULT NULL AFTER `discount`");
}
if (!$pdo->query("SHOW COLUMNS FROM `payments` LIKE 'discount_guests'")->fetch()) {
    $pdo->exec("ALTER TABLE `payments` ADD COLUMN `discount_guests` INT UNSIGNED NULL DEFAULT NULL AFTER `discount_percent`");
}

/* ------------------------------------------------------------------
   Ensure guest_notifications table exists (additive, self-guarding —
   safe to run on every request, same pattern as admin_notifications).
   Scoped to a specific guest_id so the user portal's notification
   bell (user/includes/bootstrap.php) can show payment updates to the
   right account without surfacing every guest's notifications to
   every guest.
------------------------------------------------------------------ */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `guest_notifications` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `guest_id`   INT UNSIGNED NOT NULL,
        `booking_id` INT UNSIGNED NULL DEFAULT NULL,
        `type`       VARCHAR(60)  NOT NULL DEFAULT 'payment',
        `message`    VARCHAR(400) NOT NULL,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_guest`   (`guest_id`),
        KEY `idx_booking` (`booking_id`),
        KEY `idx_read`    (`is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

/**
 * Inserts one row into guest_notifications so the signed-in guest's
 * notification bell (user/includes/bootstrap.php) shows the payment
 * update the next time they load a page. Silently no-ops for walk-in
 * guest bookings (guest_id NULL) — those have no account/login to
 * deliver a notification to.
 */
function pay_notify_guest(PDO $pdo, ?int $guestId, int $bookingId, string $message): void
{
    if (!$guestId) {
        return; // guest (no-account) booking — nothing to notify
    }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO guest_notifications (guest_id, booking_id, type, message)
             VALUES (:gid, :bid, 'payment', :msg)"
        );
        $stmt->execute([
            'gid' => $guestId,
            'bid' => $bookingId,
            'msg' => $message,
        ]);
    } catch (\Throwable $e) {
        error_log('[bienmarko] guest notification insert failed: ' . $e->getMessage());
    }
}

/* ================================================================
   ACTION: save_payment
   ================================================================ */
if ($action === 'save_payment') {
    $bookingId     = (int)   ($body['booking_id']      ?? 0);
    $roomFee       = (float) ($body['room_fee']         ?? 0);
    $extraGuestFee = (float) ($body['extra_guest_fee']  ?? 0);
    $otherCharges  = (float) ($body['other_charges']    ?? 0);
    $discountPct   = min(100.0, max(0.0, round((float) ($body['discount_percent'] ?? 0), 2)));
    $discountGuests= max(0, (int) ($body['discount_guests'] ?? 0));
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
    $bkStmt = $pdo->prepare("SELECT id, total_amount, guests_count FROM bookings WHERE id = :id");
    $bkStmt->execute(['id' => $bookingId]);
    $bk = $bkStmt->fetch();
    if (!$bk) {
        echo json_encode(['success' => false, 'error' => 'Booking not found.']);
        exit;
    }

    /* Discount: an add-on for the guests who qualify, not the whole bill.
       Room fee + extra guest fee is split equally between the guests in the
       room; the percentage comes off only the discounted guests' share.
       Other charges are never discounted. */
    $guestsCount    = max(1, (int) ($bk['guests_count'] ?? 1));
    $discountGuests = min($discountGuests, $guestsCount);
    $perGuestShare  = ($roomFee + $extraGuestFee) / $guestsCount;
    $subtotal   = $roomFee + $extraGuestFee + $otherCharges;
    $discount   = round($perGuestShare * $discountGuests * $discountPct / 100, 2);

    /* Compute final total */
    $finalTotal = max(0.0, $subtotal - $discount);

    $paidAt = ($paymentStatus === 'paid') ? date('Y-m-d H:i:s') : null;

    try {
        /* Upsert: update if payment record exists, else insert */
        $existing = $pdo->prepare("SELECT id FROM payments WHERE booking_id = :bid");
        $existing->execute(['bid' => $bookingId]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
        $payId = $existingRow ? (int) $existingRow['id'] : null;

        if ($payId) {
            /* UPDATE */
            $upd = $pdo->prepare(
                "UPDATE payments SET
                    room_fee        = :rf,
                    extra_guest_fee = :eg,
                    other_charges   = :oc,
                    discount        = :disc,
                    discount_percent = :dp,
                    discount_guests = :dg,
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
                'dp'      => $discountPct,
                'dg'      => $discountGuests,
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
                     discount, discount_percent, discount_guests, discount_reason, total_paid, payment_method,
                     payment_status, notes, paid_at)
                 VALUES
                    (:bid, :rf, :eg, :oc,
                     :disc, :dp, :dg, :dr, :tp, :pm,
                     :ps, :notes, :paid_at)"
            );
            $ins->execute([
                'bid'     => $bookingId,
                'rf'      => $roomFee,
                'eg'      => $extraGuestFee,
                'oc'      => $otherCharges,
                'disc'    => $discount,
                'dp'      => $discountPct,
                'dg'      => $discountGuests,
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

        /* Notifications — admin bell + the guest's own account */
        try {
            $bkInfo = $pdo->prepare("SELECT reference, guest_id FROM bookings WHERE id = :id");
            $bkInfo->execute(['id' => $bookingId]);
            $bkRow    = $bkInfo->fetch();
            $ref      = $bkRow['reference'] ?? '';
            $refGuest = ($bkRow && $bkRow['guest_id'] !== null) ? (int) $bkRow['guest_id'] : null;

            $pdo->prepare(
                "INSERT INTO admin_notifications (booking_id, type, message)
                 VALUES (:bid, 'payment', :msg)"
            )->execute([
                'bid' => $bookingId,
                'msg' => "Payment recorded for booking {$ref}: ₱" . number_format($totalPaid, 2) . " ({$paymentStatus}).",
            ]);

            /* Push the same update to the guest's own account, if the
               booking belongs to a registered user and money was
               actually recorded (skip zero-amount billing edits). */
            if ($totalPaid > 0) {
                if ($paymentStatus === 'paid') {
                    $guestMsg = "Your payment of ₱" . number_format($totalPaid, 2)
                        . " for booking {$ref} has been received in full. Your receipt is ready to view.";
                } else {
                    $balance  = max(0.0, $finalTotal - $totalPaid);
                    $guestMsg = "A partial payment of ₱" . number_format($totalPaid, 2)
                        . " was recorded for booking {$ref}. Remaining balance: ₱" . number_format($balance, 2) . ".";
                }
                pay_notify_guest($pdo, $refGuest, $bookingId, $guestMsg);
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Retry paid receipts that were previously skipped or failed, but
        // never resend one already recorded as sent.
        if ($paymentStatus === 'paid') {
            $receiptAlreadySent = false;
            try {
                bienmarko_gmail_ensure_tables($pdo);
                $sentReceipt = $pdo->prepare(
                    "SELECT 1 FROM `email_log`
                     WHERE booking_id = :bid AND kind = 'receipt' AND status = 'sent'
                     LIMIT 1"
                );
                $sentReceipt->execute(['bid' => $bookingId]);
                $receiptAlreadySent = (bool) $sentReceipt->fetchColumn();
            } catch (\Throwable $e) {
                error_log('[payments api] receipt status check failed: ' . $e->getMessage());
            }

            if (!$receiptAlreadySent) {
                bienmarko_email_after_response(static function () use ($pdo, $bookingId): void {
                    bienmarko_email_send_booking($pdo, $bookingId, 'receipt');
                });
            }
        }

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