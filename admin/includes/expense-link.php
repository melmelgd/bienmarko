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

    $notes = sprintf(
        'Inventory purchase: %s (%d unit%s x P%s)',
        $itemName,
        $unitsAdded,
        $unitsAdded === 1 ? '' : 's',
        number_format($costPerUnit, 2)
    );
    if (mb_strlen($notes) > 255) {
        $notes = mb_substr($notes, 0, 252) . '...';
    }

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