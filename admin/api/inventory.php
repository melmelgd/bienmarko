<?php
/**
 * admin/api/inventory.php
 * -----------------------------------------------------------------------
 * JSON API behind admin/inventory.php. Guarded the same way as
 * admin/api/payments.php — session + admin role + CSRF, checked before
 * anything submitted is looked at. Every request is POST with a JSON
 * body and an `action` field selecting what happens:
 *
 *   action=create - insert a new inventory item (starting `quantity`)
 *   action=update - update an existing item by id. Stock is changed with
 *                   `add_quantity` (units newly received) and
 *                   `used_quantity` (units used / taken out); the new
 *                   remaining stock is worked out here from what is in the
 *                   database: remaining + add - used.
 *
 * Deleting inventory items is intentionally not supported (stock history
 * and the linked expense records must stay intact).
 *
 * Connected to Expenses: when stock is added (initial quantity on create,
 * or an add_quantity on update) and the request has record_expense=true,
 * a 'Supplies' expense (units added x cost per unit) is written to the
 * `expenses` table in the same transaction. On update, every expense this
 * item already created is also re-priced to the item's current cost per
 * unit (and name), so Inventory and Expenses never disagree.
 * See includes/expense-link.php.
 *
 * Total vs remaining: `quantity` is the REMAINING stock (it goes down when
 * stock is used). `total_quantity` is every unit ever received (starting
 * quantity + restocks) and is never lowered by usage, so the page can show
 * a Total Cost (total_quantity x cost_per_unit) that stays put.
 *
 * Stock status (In Stock / Low Stock / Out of Stock) is never stored —
 * it's derived from quantity vs. min_stock_level wherever it's shown.
 *
 * Every quantity change is written to `inventory_logs` so the Monthly
 * Usage stat on the page can be computed rather than hand-typed:
 *   - add_quantity (restock) is logged with reason 'restock'
 *   - used_quantity (stock drawn down) is logged with reason 'usage'
 *   - the initial quantity on create is logged with reason 'initial'
 *     (not counted as usage)
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/expense-link.php';

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

/* Total (original) quantity — every unit ever received: the starting quantity
   plus all restocks. Using stock never lowers it, so "Total Cost" stays fixed
   while "Remaining" goes down. Added once; existing items are filled in from
   their stock log (positive entries), and never below their current quantity. */
if (!$pdo->query("SHOW COLUMNS FROM `inventory_items` LIKE 'total_quantity'")->fetch()) {
    $pdo->exec("ALTER TABLE `inventory_items` ADD COLUMN `total_quantity` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `quantity`");
    $pdo->exec(
        "UPDATE `inventory_items` i
            SET i.total_quantity = GREATEST(
                i.quantity,
                COALESCE((SELECT SUM(l.change_qty) FROM `inventory_logs` l
                           WHERE l.item_id = i.id AND l.change_qty > 0), 0)
            )"
    );
}

/* Expenses table + link columns, so purchases can be recorded as expenses */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `expenses` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `expense_date`  DATE NOT NULL,
        `category`      VARCHAR(40) NOT NULL DEFAULT 'Others',
        `amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `notes`         VARCHAR(255) NOT NULL DEFAULT '',
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
expense_link_ensure_columns($pdo);

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
 * A blank / missing value counts as 0. Anything else must be a whole
 * number, zero or more.
 */
function inv_whole_number(mixed $value, string $label): int
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (!is_numeric($value) || (float) $value < 0 || (float) $value != floor((float) $value)) {
        inv_fail($label . ' must be a whole number, zero or more.');
    }
    return (int) $value;
}

/**
 * Shared field validation for create + update. Fails the request out
 * (via inv_fail) on the first problem found; otherwise returns clean,
 * correctly-typed data ready to bind into a query.
 */
function inv_validate(array $body, bool $withQuantity = true): array
{
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        inv_fail('Item name is required (max 150 characters).');
    }

    $category = trim((string) ($body['category'] ?? ''));
    if (!in_array($category, INV_ALLOWED_CATEGORIES, true)) {
        inv_fail('Please select a valid category.');
    }

    /* On update the quantity is not sent — stock moves through add_quantity /
       used_quantity instead (see inv_whole_number + the update action). */
    $quantity = 0;
    if ($withQuantity) {
        if (!isset($body['quantity']) || !is_numeric($body['quantity']) || (int) $body['quantity'] < 0) {
            inv_fail('Quantity must be zero or more.');
        }
        $quantity = (int) $body['quantity'];
    }

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

    $recordExpense = !empty($body['record_expense']);

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare(
            "INSERT INTO inventory_items
                (name, category, quantity, total_quantity, min_stock_level, cost_per_unit, expiration_date)
             VALUES
                (:name, :category, :qty, :tqty, :min, :cost, :exp)"
        );
        $ins->execute([
            'name'     => $data['name'],
            'category' => $data['category'],
            'qty'      => $data['quantity'],
            'tqty'     => $data['quantity'],   // starting quantity = total received so far
            'min'      => $data['min_stock_level'],
            'cost'     => $data['cost_per_unit'],
            'exp'      => $data['expiration_date'],
        ]);
        $itemId    = (int) $pdo->lastInsertId();
        $expenseId = 0;

        if ($data['quantity'] > 0) {
            $pdo->prepare(
                "INSERT INTO inventory_logs (item_id, change_qty, reason) VALUES (:id, :q, 'initial')"
            )->execute(['id' => $itemId, 'q' => $data['quantity']]);
            $logId = (int) $pdo->lastInsertId();

            if ($recordExpense) {
                $expenseId = expense_link_record_purchase(
                    $pdo, $itemId, $logId, $data['name'], $data['quantity'], $data['cost_per_unit']
                );
            }
        }

        $pdo->commit();
        inv_ok(['id' => $itemId, 'expense_id' => $expenseId]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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

    $existsStmt = $pdo->prepare('SELECT id FROM inventory_items WHERE id = :id');
    $existsStmt->execute(['id' => $itemId]);
    if (!$existsStmt->fetch()) {
        inv_fail('Item not found.');
    }

    $data    = inv_validate($body, false);
    $addQty  = inv_whole_number($body['add_quantity']  ?? 0, 'Add quantity');
    $usedQty = inv_whole_number($body['used_quantity'] ?? 0, 'Used quantity');

    $recordExpense = !empty($body['record_expense']);

    try {
        $pdo->beginTransaction();

        /* Lock the row and read the CURRENT stock, so the new remaining is
           worked out from the database, not from a page that may be stale. */
        $lock = $pdo->prepare('SELECT quantity, total_quantity FROM inventory_items WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $itemId]);
        $current = $lock->fetch();
        if (!$current) {
            $pdo->rollBack();
            inv_fail('Item not found.');
        }

        $currentQty = (int) $current['quantity'];
        $available  = $currentQty + $addQty;
        if ($usedQty > $available) {
            $pdo->rollBack();
            inv_fail('Used quantity is more than the stock available (' . $available . ').');
        }

        /* Remaining = what is on hand + newly added - used. The total received
           only ever goes up (by the added units) and can never be smaller than
           what is on hand. */
        $newQty   = $available - $usedQty;
        $newTotal = max((int) $current['total_quantity'] + $addQty, $newQty);

        $upd = $pdo->prepare(
            "UPDATE inventory_items SET
                name             = :name,
                category         = :category,
                quantity         = :qty,
                total_quantity   = :tqty,
                min_stock_level  = :min,
                cost_per_unit    = :cost,
                expiration_date  = :exp
             WHERE id = :id"
        );
        $upd->execute([
            'name'     => $data['name'],
            'category' => $data['category'],
            'qty'      => $newQty,
            'tqty'     => $newTotal,
            'min'      => $data['min_stock_level'],
            'cost'     => $data['cost_per_unit'],
            'exp'      => $data['expiration_date'],
            'id'       => $itemId,
        ]);

        /* Keep the expenses this item already created in step with its
           (possibly new) cost per unit and name — before a new restock
           expense is added below, which is already at the new price. */
        $expensesUpdated = expense_link_sync_item(
            $pdo, $itemId, $data['name'], $data['cost_per_unit']
        );

        $expenseId = 0;

        /* Units added = a restock (and a purchase, if the box is ticked). */
        if ($addQty > 0) {
            $pdo->prepare(
                "INSERT INTO inventory_logs (item_id, change_qty, reason) VALUES (:id, :q, 'restock')"
            )->execute(['id' => $itemId, 'q' => $addQty]);
            $logId = (int) $pdo->lastInsertId();

            if ($recordExpense) {
                $expenseId = expense_link_record_purchase(
                    $pdo, $itemId, $logId, $data['name'], $addQty, $data['cost_per_unit']
                );
            }
        }

        /* Units used = stock drawn down; usage is not an expense. */
        if ($usedQty > 0) {
            $pdo->prepare(
                "INSERT INTO inventory_logs (item_id, change_qty, reason) VALUES (:id, :q, 'usage')"
            )->execute(['id' => $itemId, 'q' => -$usedQty]);
        }

        $pdo->commit();
        inv_ok([
            'id'               => $itemId,
            'quantity'         => $newQty,
            'expense_id'       => $expenseId,
            'expenses_updated' => $expensesUpdated,
        ]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[inventory api update] ' . $e->getMessage());
        inv_fail('A database error occurred. Please try again.');
    }
}

/* ================================================================
   ACTION: delete — disabled. Inventory items can no longer be deleted.
   ================================================================ */
if ($action === 'delete') {
    http_response_code(405);
    inv_fail('Deleting inventory items is not allowed.');
}

/* Unknown action */
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);