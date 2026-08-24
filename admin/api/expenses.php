<?php
/**
 * admin/api/expenses.php
 * -----------------------------------------------------------------------
 * JSON API behind admin/expenses.php. Guarded the same way as
 * admin/api/inventory.php — session + admin role + CSRF, checked before
 * anything submitted is looked at. Every request is POST with a JSON
 * body and an `action` field selecting what happens:
 *
 *   action=create - insert a new expense record
 *   action=update - update an existing expense by id
 *   action=delete - delete an expense by id
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
   Ensure the expenses table exists — mirrors the guard in
   admin/expenses.php so this endpoint also works standalone.
------------------------------------------------------------------ */
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

const EXP_ALLOWED_CATEGORIES = ['Utilities', 'Maintenance', 'Supplies', 'Salaries', 'Others'];

function exp_fail(string $msg): never
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function exp_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

/**
 * Shared field validation for create + update. Fails the request out
 * (via exp_fail) on the first problem found; otherwise returns clean,
 * correctly-typed data ready to bind into a query.
 */
function exp_validate(array $body): array
{
    $date = trim((string) ($body['expense_date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        exp_fail('A valid date is required.');
    }

    $category = trim((string) ($body['category'] ?? ''));
    if (!in_array($category, EXP_ALLOWED_CATEGORIES, true)) {
        exp_fail('Please select a valid category.');
    }

    if (!isset($body['amount']) || !is_numeric($body['amount']) || (float) $body['amount'] <= 0) {
        exp_fail('Amount must be greater than zero.');
    }
    $amount = round((float) $body['amount'], 2);

    $notes = trim((string) ($body['notes'] ?? ''));
    if ($notes === '' || mb_strlen($notes) > 255) {
        exp_fail('Notes are required (max 255 characters).');
    }

    return [
        'expense_date' => $date,
        'category'     => $category,
        'amount'       => $amount,
        'notes'        => $notes,
    ];
}

$action = trim((string) ($body['action'] ?? ''));

/* ================================================================
   ACTION: create
   ================================================================ */
if ($action === 'create') {
    $data = exp_validate($body);

    try {
        $ins = $pdo->prepare(
            "INSERT INTO expenses (expense_date, category, amount, notes)
             VALUES (:date, :category, :amount, :notes)"
        );
        $ins->execute([
            'date'     => $data['expense_date'],
            'category' => $data['category'],
            'amount'   => $data['amount'],
            'notes'    => $data['notes'],
        ]);

        exp_ok(['id' => (int) $pdo->lastInsertId()]);

    } catch (\Throwable $e) {
        error_log('[expenses api create] ' . $e->getMessage());
        exp_fail('A database error occurred. Please try again.');
    }
}

/* ================================================================
   ACTION: update
   ================================================================ */
if ($action === 'update') {
    $expenseId = (int) ($body['id'] ?? 0);
    if ($expenseId <= 0) {
        exp_fail('Invalid expense record.');
    }

    $existingStmt = $pdo->prepare('SELECT id FROM expenses WHERE id = :id');
    $existingStmt->execute(['id' => $expenseId]);
    if (!$existingStmt->fetch()) {
        exp_fail('Expense record not found.');
    }

    $data = exp_validate($body);

    try {
        $upd = $pdo->prepare(
            "UPDATE expenses SET
                expense_date = :date,
                category     = :category,
                amount       = :amount,
                notes        = :notes
             WHERE id = :id"
        );
        $upd->execute([
            'date'     => $data['expense_date'],
            'category' => $data['category'],
            'amount'   => $data['amount'],
            'notes'    => $data['notes'],
            'id'       => $expenseId,
        ]);

        exp_ok(['id' => $expenseId]);

    } catch (\Throwable $e) {
        error_log('[expenses api update] ' . $e->getMessage());
        exp_fail('A database error occurred. Please try again.');
    }
}

/* ================================================================
   ACTION: delete
   ================================================================ */
if ($action === 'delete') {
    $expenseId = (int) ($body['id'] ?? 0);
    if ($expenseId <= 0) {
        exp_fail('Invalid expense record.');
    }

    try {
        $del = $pdo->prepare('DELETE FROM expenses WHERE id = :id');
        $del->execute(['id' => $expenseId]);

        if ($del->rowCount() === 0) {
            exp_fail('Expense record not found.');
        }

        exp_ok();

    } catch (\Throwable $e) {
        error_log('[expenses api delete] ' . $e->getMessage());
        exp_fail('A database error occurred. Please try again.');
    }
}

/* Unknown action */
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
