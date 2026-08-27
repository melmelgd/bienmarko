<?php
/**
 * api/availability.php
 * -----------------------------------------------------------------------
 * Public (no auth required) JSON endpoint.
 * Given an accommodation_id, returns that accommodation's capacity/pool
 * flag plus every confirmed/pending booking's date range, so the
 * landing page's booking-strip date pickers can grey out dates that
 * are already reserved — the same availability data the admin's own
 * "New Booking" date pickers use (see admin/api/bookings.php,
 * action=availability), just exposed without a session since this is
 * a guest-facing, pre-login step.
 *
 * Request:
 *   GET api/availability.php?accommodation_id=3
 *
 * Output shape (200 OK):
 * {
 *   "success": true,
 *   "accommodation": { "id": 3, "capacity": 4, "is_pool": false },
 *   "bookings": [
 *     { "check_in": "2026-09-01", "check_out": "2026-09-03", "guests_count": 2 },
 *     ...
 *   ]
 * }
 *
 * Only check_in / check_out / guests_count are exposed per booking — no
 * guest names, contact details, or booking IDs — so this is safe to
 * serve without authentication, same principle as the other read-only
 * api/*.php feeds (accommodations.php, featured-rooms.php, etc.).
 *
 * On any error, returns { "success": false, "error": "..." } so the
 * front end can fail gracefully (calendar just shows nothing blocked
 * rather than breaking the page).
 *
 * CORS: same-origin only — no Access-Control-Allow-Origin header.
 * -----------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
// No caching — unlike the room-list feeds, this must always reflect the
// live booking state, or the picker could show a just-taken date as free.
header('Cache-Control: no-store');

function avail_fail(string $message = 'Unable to load availability.'): never
{
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

try {
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    avail_fail();
}

try {
    $pdo = bienmarko_db();

    $accommodationId = (int) ($_GET['accommodation_id'] ?? 0);
    if ($accommodationId <= 0) {
        avail_fail('Invalid accommodation.');
    }

    $room = $pdo->prepare("SELECT id, type, capacity, status FROM accommodations WHERE id = :id");
    $room->execute(['id' => $accommodationId]);
    $room = $room->fetch();

    if (!$room) {
        avail_fail('Accommodation not found.');
    }

    // Every confirmed/pending booking for this accommodation — cancelled
    // bookings free up their dates and are intentionally excluded, same
    // filter the admin endpoint and the booking-creation conflict check
    // both use.
    $stmt = $pdo->prepare(
        "SELECT check_in, check_out, guests_count FROM bookings
         WHERE accommodation_id = :aid
           AND status IN ('confirmed','pending')"
    );
    $stmt->execute(['aid' => $accommodationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'       => true,
        'accommodation' => [
            'id'       => (int) $room['id'],
            'capacity' => (int) $room['capacity'],
            'is_pool'  => strcasecmp(trim((string) $room['type']), 'Swimming Pool') === 0,
        ],
        'bookings' => array_map(static function (array $r): array {
            return [
                'check_in'     => $r['check_in'],
                'check_out'    => $r['check_out'],
                'guests_count' => (int) $r['guests_count'],
            ];
        }, $rows),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    avail_fail();
}
