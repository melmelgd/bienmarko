<?php
/**
 * api/accommodations.php
 * -----------------------------------------------------------------------
 * Public, read-only JSON feed of every publicly-visible accommodation
 * (Room, Event Hall, Cottage, Swimming Pool, or a custom "Other" type)
 * for the landing page's full "Our Accommodations" grid (index.html
 * #rooms). This is what script.js's room-grid renderer fetches.
 *
 * Unlike api/featured-rooms.php (which only returns is_featured = 1
 * rooms for the homepage highlight strip), this returns EVERY
 * accommodation the admin has added. It reads through the exact same
 * data-access layer the admin dashboard uses
 * (admin/includes/accommodations-data.php) — same table, same status
 * list, same amenities/image helpers — so what a guest sees here can
 * never drift from what the admin actually configured.
 *
 * No session/auth required — this is a public, guest-facing feed.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../admin/includes/accommodations-data.php';

header('Content-Type: application/json');

/**
 * Statuses an admin can set that a guest browsing the landing page
 * should still see listed. "maintenance" and "unavailable" are the
 * admin's explicit "off the market" states, so those two are the only
 * ones excluded here. A "booked" accommodation is still shown (it may
 * be open on other dates — real, date-specific availability is checked
 * later on the actual booking form), just flagged with a small badge
 * so the listing stays honest about its current state.
 */
const BM_PUBLIC_VISIBLE_STATUSES = ['available', 'booked'];

/**
 * Maps an accommodation's `type` (Room, Event Hall, Cottage, Swimming
 * Pool, or a free-typed "Other" value — see ACC_ACCOMMODATION_TYPES in
 * accommodations-data.php) to the landing page's fixed filter-tab
 * buckets: all / rooms / venues / cottage / pool. The four preset types
 * match directly; a custom "Other" type falls back to keyword matching
 * so it still lands somewhere sensible, and only buckets to 'other'
 * (visible under "All" only — there's no dedicated tab for it) when
 * nothing matches at all.
 */
function bm_public_category(string $type): string
{
    $normalized = strtolower(trim($type));

    if ($normalized === 'room' || strpos($normalized, 'room') !== false) {
        return 'rooms';
    }
    if ($normalized === 'event hall' || strpos($normalized, 'event') !== false || strpos($normalized, 'hall') !== false) {
        return 'venues';
    }
    if ($normalized === 'cottage' || strpos($normalized, 'cottage') !== false || strpos($normalized, 'hut') !== false || strpos($normalized, 'nipa') !== false) {
        return 'cottage';
    }
    if ($normalized === 'swimming pool' || strpos($normalized, 'pool') !== false || strpos($normalized, 'swim') !== false) {
        return 'pool';
    }

    return 'other';
}

acc_ensure_table();

$pdo = bienmarko_db();

$placeholders = implode(',', array_fill(0, count(BM_PUBLIC_VISIBLE_STATUSES), '?'));
$stmt = $pdo->prepare(
    "SELECT `id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`
     FROM `accommodations`
     WHERE `status` IN ($placeholders)
     ORDER BY `name` ASC"
);
$stmt->execute(BM_PUBLIC_VISIBLE_STATUSES);
$rows = $stmt->fetchAll();

$rooms = array_map(static function (array $row): array {
    $imageFilename = $row['image_filename'];

    return [
        'id'          => (int) $row['id'],
        'name'        => $row['name'],
        'type'        => $row['type'],
        'category'    => bm_public_category($row['type']),
        'room_number' => $row['room_number'],
        'capacity'    => (int) $row['capacity'],
        'price'       => $row['price'],
        'status'      => $row['status'],
        'description' => $row['description'],
        'amenities'   => acc_decode_amenities($row['amenities']),
        // Built relative to the site root (where index.html lives), since
        // ACC_UPLOAD_URL_BASE itself is only relative to admin/*.php.
        'image_url'   => $imageFilename
            ? 'admin/' . ACC_UPLOAD_URL_BASE . '/' . rawurlencode($imageFilename)
            : null,
    ];
}, $rows);

echo json_encode(['success' => true, 'rooms' => $rooms]);
