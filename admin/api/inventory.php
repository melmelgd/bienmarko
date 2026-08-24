<?php
/**
 * admin/api/inventory.php
 * -----------------------------------------------------------------------
 * JSON API behind admin/inventory.php. Guarded the same way as
 * admin/api/payments.php — session + admin role + CSRF, checked before
 * anything submitted is looked at. Every request is POST with a JSON
 * body and an `action` field selecting what happens:
 *
 *   action=create - insert a new inventory item
 *   action=update - update an existing item by id
 *   action=delete - delete an item by id
 *
 * Stock status (In Stock / Low Stock / Out of Stock) is never stored —
 * it's derived from quantity vs. min_stock_level wherever it's shown.
 *
 * Every quantity change is written to `inventory_logs` so the Monthly
 * Usage stat on the page can be computed rather than hand-typed:
 *   - a positive delta (restock) is logged with reason 'restock'
 *   - a negative delta (stock drawn down) is logged with reason 'usage'
 *   - the initial quantity on create is logged with reason 'initial'
 *     (not counted as usage)
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo  = bienmarko_db();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

/* CSRF check — before anything submitted is looked at */
if (empty($body['csrf_token']) || !bienmarko_csrf_valid($body['csrf_token'])) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token.']);
    exit;
}

/* ------------------------------------------------------------------
   Ensure inventory tables exist — mirrors the guards in
   admin/inventory.php so this endpoint also works standalone.
------------------------------------------------------------------ */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `inventory_items` (
        `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name`            VARCHAR(150) NOT NULL,
        `category`        VARCHAR(60)  NOT NULL DEFAULT 'Others',
        `quantity`        INT UNSIGNED NOT NULL DEFAULT 0,
        `min_stock_level` INT UNSIGNED NOT NULL DEFAULT 0,
        `cost_per_unit`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `expiration_date` DATE NULL DEFAULT NULL,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `inventory_logs` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `item_id`    INT UNSIGNED NOT NULL,
        `change_qty` INT NOT NULL,
        `reason`     ENUM('initial','restock','usage','adjustment') NOT NULL DEFAULT 'adjustment',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_inv_log_item` (`item_id`),
        CONSTRAINT `fk_inv_log_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

const INV_ALLOWED_CATEGORIES = ['Linens', 'Toiletries', 'Cleaning Supplies', 'Kitchen & Dining', 'Beverages', 'Maintenance Supplies', 'Amenities', 'Others'];

function inv_fail(string $msg): never
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function inv_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

/**
 * Shared field validation for create + update. Fails the request out
 * (via inv_fail) on the first problem found; otherwise returns clean,
 * correctly-typed data ready to bind into a query.
 */
function inv_validate(array $body): array
{
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        inv_fail('Item name is required (max 150 characters).');
    }

    $category = trim((string) ($body['category'] ?? ''));
    if (!in_array($category, INV_ALLOWED_CATEGORIES, true)) {
        inv_fail('Please select a valid category.');
    }

    if (!isset($body['quantity']) || !is_numeric($body['quantity']) || (int) $body['quantity'] < 0) {
        inv_fail('Quantity must be zero or more.');
    }
    $quantity = (int) $body['quantity'];

    if (!isset($body['min_stock_level']) || !is_numeric($body['min_stock_level']) || (int) $body['min_stock_level'] < 0) {
        inv_fail('Minimum stock level must be zero or more.');
    }
    $minStock = (int) $body['min_stock_level'];

    if (!isset($body['cost_per_unit']) || !is_numeric($body['cost_per_unit']) || (float) $body['cost_per_unit'] < 0) {
        inv_fail('Cost per unit must be zero or more.');
    }
    $cost = round((float) $body['cost_per_unit'], 2);

    $expiration = trim((string) ($body['expiration_date'] ?? ''));
    if ($expiration === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration) || !strtotime($expiration)) {
        inv_fail('A valid expiration date is required.');
    }

    return [
        'name'            => $name,
        'category'        => $category,
        'quantity'        => $quantity,
        'min_stock_level' => $minStock,
        'cost_per_unit'   => $cost,
        'expiration_date' => $expiration,
    ];
}

$action = trim((string) ($body['action'] ?? ''));

/* ================================================================
   ACTION: create
   ================================================================ */
if ($action === 'create') {
    $data = inv_validate($body);

    try {
        $ins = $pdo->prepare(
            "INSERT INTO inventory_items
                (name, category, quantity, min_stock_level, cost_per_unit, expiration_date)
             VALUES
                (:name, :category, :qty, :min, :cost, :exp)"
        );
        $ins->execute([
            'name'     => $data['name'],
            'category' => $data['category'],
            'qty'      => $data['quantity'],
            'min'      => $data['min_stock_level'],
            'cost'     => $data['cost_per_unit'],
            'exp'      => $data['expiration_date'],
        ]);
        $itemId = (int) $pdo->lastInsertId();

        if ($data['quantity'] > 0) {
            $pdo->prepare(
                "INSERT INTO inventory_logs (item_id, change_qty, reason) VALUES (:id, :q, 'initial')"
            )->execute(['id' => $itemId, 'q' => $data['quantity']]);
        }

        inv_ok(['id' => $itemId]);

    } catch (\Throwable $e) {
        error_log('[inventory api create] ' . $e->getMessage());
        inv_fail('A database error occurred. Please try again.');
    }
}

/* ================================================================
   ACTION: update
   ================================================================ */
if ($action === 'update') {
    $itemId = (int) ($body['id'] ?? 0);
    if ($itemId <= 0) {
        inv_fail('Invalid item.');
    }

    $existingStmt = $pdo->prepare('SELECT quantity FROM inventory_items WHERE id = :id');
    $existingStmt->execute(['id' => $itemId]);
    $existing = $existingStmt->fetch();
    if (!$existing) {
        inv_fail('Item not found.');
    }

    $data = inv_validate($body);

    try {
        $upd = $pdo->prepare(
            "UPDATE inventory_items SET
                name             = :name,
                category         = :category,
                quantity         = :qty,
                min_stock_level  = :min,
                cost_per_unit    = :cost,
                expiration_date  = :exp
             WHERE id = :id"
        );
        $upd->execute([
            'name'     => $data['name'],
            'category' => $data['category'],
            'qty'      => $data['quantity'],
            'min'      => $data['min_stock_level'],
            'cost'     => $data['cost_per_unit'],
            'exp'      => $data['expiration_date'],
            'id'       => $itemId,
        ]);

        $diff = $data['quantity'] - (int) $existing['quantity'];
        if ($diff !== 0) {
            $reason = $diff > 0 ? 'restock' : 'usage';
            $pdo->prepare(
                "INSERT INTO inventory_logs (item_id, change_qty, reason) VALUES (:id, :q, :reason)"
            )->execute(['id' => $itemId, 'q' => $diff, 'reason' => $reason]);
        }

        inv_ok(['id' => $itemId]);

    } catch (\Throwable $e) {
        error_log('[inventory api update] ' . $e->getMessage());
        inv_fail('A database error occurred. Please try again.');
    }
}

/* ================================================================
   ACTION: delete
   ================================================================ */
if ($action === 'delete') {
    $itemId = (int) ($body['id'] ?? 0);
    if ($itemId <= 0) {
        inv_fail('Invalid item.');
    }

    try {
        $del = $pdo->prepare('DELETE FROM inventory_items WHERE id = :id');
        $del->execute(['id' => $itemId]);

        if ($del->rowCount() === 0) {
            inv_fail('Item not found.');
        }

        inv_ok();

    } catch (\Throwable $e) {
        error_log('[inventory api delete] ' . $e->getMessage());
        inv_fail('A database error occurred. Please try again.');
    }
}

/* Unknown action */
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
