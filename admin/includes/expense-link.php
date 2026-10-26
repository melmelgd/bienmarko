<?php
/**
 * admin/includes/expense-link.php
 * -----------------------------------------------------------------------
 * Connects Inventory and Expenses.
 *
 * Whenever stock is bought (a new item with a starting quantity, or an
 * edit that raises the quantity), the inventory API records a matching
 * row in `expenses`:
 *
 *     category = 'Supplies'
 *     amount   = units added x cost_per_unit
 *     source   = 'inventory'
 *
 * The expense row remembers which inventory item and which inventory
 * log entry created it, so the Expenses page can show where it came
 * from and keep it read-only (editing it there would put the two
 * pages out of sync).
 *
 * Staying in sync: when an inventory item is edited (its cost per unit
 * or its name), expense_link_sync_item() re-prices every expense that
 * item created: amount = units bought in that purchase x the current
 * cost per unit. The Expenses page and the Restock Spend card then
 * always agree with Inventory.
 *
 * Additive and self-guarding: adds the link columns to `expenses` the
 * first time it runs, and does nothing on later runs.
 * -----------------------------------------------------------------------
 */

/** Adds source / inventory_item_id / inventory_log_id to `expenses` if missing. */
function expense_link_ensure_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `expenses`')->fetchAll() as $col) {
        $existing[$col['Field']] = true;
    }

    if (!isset($existing['source'])) {
        $pdo->exec("ALTER TABLE `expenses` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'manual'");
    }
    if (!isset($existing['inventory_item_id'])) {
        $pdo->exec("ALTER TABLE `expenses` ADD COLUMN `inventory_item_id` INT UNSIGNED NULL DEFAULT NULL");
    }
    if (!isset($existing['inventory_log_id'])) {
        $pdo->exec("ALTER TABLE `expenses` ADD COLUMN `inventory_log_id` INT UNSIGNED NULL DEFAULT NULL");
    }

    $done = true;
}

/** The note text stored on an inventory-created expense. */
function expense_link_notes(string $itemName, int $units, float $costPerUnit): string
{
    $notes = sprintf(
        'Inventory purchase: %s (%d unit%s x P%s)',
        $itemName,
        $units,
        $units === 1 ? '' : 's',
        number_format($costPerUnit, 2)
    );
    if (mb_strlen($notes) > 255) {
        $notes = mb_substr($notes, 0, 252) . '...';
    }
    return $notes;
}

/**
 * Records the expense for a stock purchase. Call this inside the same
 * transaction as the inventory change so both succeed or both fail.
 * Returns the new expense id, or 0 when there is nothing to record
 * (no units added, or a zero cost).
 */
function expense_link_record_purchase(
    PDO $pdo,
    int $itemId,
    int $logId,
    string $itemName,
    int $unitsAdded,
    float $costPerUnit
): int {
    $amount = round($unitsAdded * $costPerUnit, 2);
    if ($unitsAdded <= 0 || $amount <= 0) {
        return 0;
    }

    $notes = expense_link_notes($itemName, $unitsAdded, $costPerUnit);

    $pdo->prepare(
        "INSERT INTO expenses
            (expense_date, category, amount, notes, source, inventory_item_id, inventory_log_id)
         VALUES
            (:d, 'Supplies', :amount, :notes, 'inventory', :item, :log)"
    )->execute([
        'd'      => date('Y-m-d'),
        'amount' => $amount,
        'notes'  => $notes,
        'item'   => $itemId,
        'log'    => $logId,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Re-prices every expense that this inventory item created, so editing the
 * item's cost per unit (or name) is reflected on the Expenses page.
 *
 * Each linked expense belongs to one purchase (an inventory log entry with
 * a positive quantity). Its amount is recalculated as
 *     units in that purchase x the item's current cost per unit
 * and its note text is rebuilt. The expense date is left alone. Rows that
 * would not change are skipped. Call inside the same transaction as the
 * inventory update. Returns how many expense rows were changed.
 */
function expense_link_sync_item(PDO $pdo, int $itemId, string $itemName, float $costPerUnit): int
{
    $stmt = $pdo->prepare(
        "SELECT e.id, e.amount, e.notes, l.change_qty
           FROM expenses e
           JOIN inventory_logs l ON l.id = e.inventory_log_id
          WHERE e.source = 'inventory'
            AND e.inventory_item_id = :item
            AND l.change_qty > 0"
    );
    $stmt->execute(['item' => $itemId]);

    $upd     = $pdo->prepare('UPDATE expenses SET amount = :a, notes = :n WHERE id = :id');
    $changed = 0;

    foreach ($stmt->fetchAll() as $row) {
        $units  = (int) $row['change_qty'];
        $amount = round($units * $costPerUnit, 2);
        $notes  = expense_link_notes($itemName, $units, $costPerUnit);

        if (abs($amount - (float) $row['amount']) < 0.005 && $notes === (string) $row['notes']) {
            continue;
        }
        $upd->execute(['a' => $amount, 'n' => $notes, 'id' => (int) $row['id']]);
        $changed++;
    }

    return $changed;
}