<?php
/**
 * api/featured-rooms.php
 * -----------------------------------------------------------------------
 * Public (no auth required) JSON endpoint. Returns accommodations that
 * have is_featured = 1, ordered by name. Used by the landing page
 * (index.html / script.js) to populate the Featured Rooms section
 * without any server-side rendering.
 *
 * Output shape (200 OK):
 * {
 *   "success": true,
 *   "rooms": [
 *     {
 *       "id": 1,
 *       "name": "Barkada Room",
 *       "type": "Family Room",
 *       "capacity": 4,
 *       "price": "2000.00",
 *       "status": "available",
 *       "description": "...",
 *       "amenities": ["Wi-Fi", "Air Conditioning"],
 *       "image_url": "admin/assets/uploads/accommodations/abc123.jpg"
 *     },
 *     ...
 *   ]
 * }
 *
 * On any error, returns { "success": false, "rooms": [] } so the
 * landing page can degrade silently (hide the section) rather than
 * showing a broken state.
 *
 * CORS: same origin only (no Access-Control-Allow-Origin header), since
 * the landing page is served from the same host as this script.
 * -----------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
// Prevent search engines from indexing raw API output
header('X-Robots-Tag: noindex');

// Cache for 60 seconds on the client — featured list changes rarely,
// and a short TTL keeps the page fast without serving stale data long.
header('Cache-Control: public, max-age=60');

/**
 * Fail helper — always returns a valid JSON envelope so the JS side
 * can safely read json.rooms without extra null guards.
 */
function feat_fail(): never
{
    echo json_encode(['success' => false, 'rooms' => []]);
    exit;
}

try {
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    feat_fail();
}

try {
    $pdo = bienmarko_db();

    // Confirm the column exists before querying it — defensive against
    // deployments where the migration hasn't run yet. Uses the same
    // information_schema approach as acc_ensure_table().
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $colExists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db
           AND TABLE_NAME   = 'accommodations'
           AND COLUMN_NAME  = 'is_featured'"
    );
    $colExists->execute(['db' => $dbName]);

    if ((int) $colExists->fetchColumn() === 0) {
        // Table or column missing — return empty featured list silently
        echo json_encode(['success' => true, 'rooms' => []]);
        exit;
    }

    $stmt = $pdo->query(
        "SELECT
            `id`,
            `name`,
            `type`,
            `capacity`,
            `price`,
            `status`,
            `description`,
            `amenities`,
            `image_filename`
         FROM `accommodations`
         WHERE `is_featured` = 1
         ORDER BY `name` ASC
         LIMIT 12"
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rooms = [];

    foreach ($rows as $row) {
        // Decode amenities JSON array stored as text
        $amenities = [];
        if (!empty($row['amenities'])) {
            $decoded = json_decode($row['amenities'], true);
            if (is_array($decoded)) {
                $amenities = $decoded;
            }
        }

        // Build the image URL relative to the project root.
        // The landing page (index.html) lives at the project root, so
        // "admin/assets/uploads/accommodations/filename.jpg" is correct.
        $imageUrl = null;
        if (!empty($row['image_filename'])) {
            $imageUrl = 'admin/assets/uploads/accommodations/' . rawurlencode($row['image_filename']);
        }

        $rooms[] = [
            'id'          => (int) $row['id'],
            'name'        => $row['name'],
            'type'        => $row['type'],
            'capacity'    => (int) $row['capacity'],
            'price'       => $row['price'],           // keep as string, JS formats it
            'status'      => $row['status'],
            'description' => $row['description'] ?? '',
            'amenities'   => $amenities,
            'image_url'   => $imageUrl,
        ];
    }

    echo json_encode(['success' => true, 'rooms' => $rooms], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    feat_fail();
}
