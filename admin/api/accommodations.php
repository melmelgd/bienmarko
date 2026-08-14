<?php
/**
 * admin/api/accommodations.php
 * -----------------------------------------------------------------------
 * JSON API behind admin/accommodations.php. Guarded by session + admin
 * role + CSRF, checked in that order, before any submitted data is read.
 *
 * Every request is POST, with an `action` field selecting what happens:
 *
 *   Room actions:
 *     action=create           - insert a new room (multipart; image optional)
 *     action=update           - update an existing room by id
 *     action=delete           - delete a room by id (and its photo file)
 *     action=set_status       - update only the status column (quick action)
 *
 *   Category actions:
 *     action=create_category  - insert a new category
 *     action=update_category  - update an existing category by id
 *     action=delete_category  - delete a category; sets category_id=NULL
 *                               on any rooms that belonged to it
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/accommodations-data.php';

header('Content-Type: application/json');

function acc_json_fail(int $httpCode, string $message): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function acc_json_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    acc_json_fail(405, 'Method not allowed.');
}

if (!bienmarko_csrf_valid($_POST['csrf_token'] ?? null)) {
    acc_json_fail(403, 'Your session has expired. Please refresh the page and try again.');
}

acc_ensure_table();
acc_ensure_categories_table();
acc_ensure_upload_dir();

$pdo = bienmarko_db();

// -------------------------------------------------------------------------
// Shared field validation for room create + update
// -------------------------------------------------------------------------
function acc_validate_fields(array $post): array
{
    $errors = [];

    $name = trim((string) ($post['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Room name is required (max 150 characters).';
    }

    $type = trim((string) ($post['type'] ?? ''));
    if ($type === 'other') {
        $type = trim((string) ($post['type_other'] ?? ''));
    }
    if ($type === '' || mb_strlen($type) > 100) {
        $errors[] = 'Room type is required (max 100 characters).';
    }

    $capacity = filter_var($post['capacity'] ?? null, FILTER_VALIDATE_INT);
    if ($capacity === false || $capacity < 1 || $capacity > 50) {
        $errors[] = 'Capacity must be a whole number between 1 and 50.';
    }

    $price = filter_var($post['price'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($price === false || $price < 0 || $price > 999999) {
        $errors[] = 'Price must be a valid amount (0 or more).';
    }

    $status = (string) ($post['status'] ?? 'available');
    if (!acc_valid_status($status)) {
        $errors[] = 'Please choose a valid status.';
    }

    $description = trim((string) ($post['description'] ?? ''));
    if (mb_strlen($description) > 2000) {
        $errors[] = 'Description is too long (max 2000 characters).';
    }

    $amenitiesRaw = trim((string) ($post['amenities'] ?? ''));
    if (mb_strlen($amenitiesRaw) > 500) {
        $errors[] = 'Amenities list is too long.';
    }

    // category_id — optional, must be a positive int or blank/null
    $categoryIdRaw = $post['category_id'] ?? '';
    $categoryId = null;
    if ($categoryIdRaw !== '' && $categoryIdRaw !== '0') {
        $categoryId = filter_var($categoryIdRaw, FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            $errors[] = 'Invalid category selected.';
            $categoryId = null;
        }
    }

    return [
        $errors,
        [
            'name'        => $name,
            'type'        => $type,
            'capacity'    => $capacity !== false ? (int) $capacity : 0,
            'price'       => $price !== false ? (float) $price : 0.0,
            'status'      => $status,
            'description' => $description,
            'amenities'   => acc_parse_amenities($amenitiesRaw),
            'category_id' => $categoryId,
        ],
    ];
}

// -------------------------------------------------------------------------
// Image upload helpers
// -------------------------------------------------------------------------
function acc_handle_image_upload(?array $file, array &$errors): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'Image is too large for this server\'s upload limit. Please choose a smaller file.';
        } else {
            $errors[] = 'Image upload failed. Please try a different file.';
        }
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Image upload failed. Please try again.';
        return null;
    }

    if ($file['size'] > ACC_MAX_IMAGE_BYTES) {
        $errors[] = 'Image is too large (max 5MB).';
        return null;
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ACC_ALLOWED_IMAGE_EXT, true)) {
        $errors[] = 'Image must be a JPG, PNG, or WEBP file.';
        return null;
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        $errors[] = 'That file does not appear to be a valid image.';
        return null;
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = ACC_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $errors[] = 'Could not save the uploaded image. Please try again.';
        return null;
    }

    return $filename;
}

function acc_delete_image_file(?string $filename): void
{
    if (!$filename) {
        return;
    }
    $path = ACC_UPLOAD_DIR . '/' . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

// -------------------------------------------------------------------------
// Room actions
// -------------------------------------------------------------------------
function acc_action_create(PDO $pdo): never
{
    [$errors, $data] = acc_validate_fields($_POST);
    $imageFilename = acc_handle_image_upload($_FILES['image'] ?? null, $errors);

    if ($errors) {
        acc_delete_image_file($imageFilename);
        acc_json_fail(400, implode(' ', $errors));
    }

    // Validate category exists if supplied
    if ($data['category_id'] !== null) {
        $chk = $pdo->prepare('SELECT `id` FROM `accommodation_categories` WHERE `id` = :id LIMIT 1');
        $chk->execute(['id' => $data['category_id']]);
        if ($chk->fetch() === false) {
            acc_delete_image_file($imageFilename);
            acc_json_fail(400, 'The selected category does not exist.');
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `accommodations`
            (`name`, `type`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `category_id`)
         VALUES
            (:name, :type, :capacity, :price, :status, :description, :amenities, :image_filename, :category_id)'
    );
    $stmt->execute([
        'name'           => $data['name'],
        'type'           => $data['type'],
        'capacity'       => $data['capacity'],
        'price'          => $data['price'],
        'status'         => $data['status'],
        'description'    => $data['description'] !== '' ? $data['description'] : null,
        'amenities'      => acc_encode_amenities($data['amenities']),
        'image_filename' => $imageFilename,
        'category_id'   => $data['category_id'],
    ]);

    acc_json_ok(['id' => (int) $pdo->lastInsertId()]);
}

function acc_action_update(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        acc_json_fail(400, 'Missing or invalid accommodation id.');
    }

    $existingStmt = $pdo->prepare('SELECT `image_filename` FROM `accommodations` WHERE `id` = :id LIMIT 1');
    $existingStmt->execute(['id' => $id]);
    $existing = $existingStmt->fetch();
    if ($existing === false) {
        acc_json_fail(404, 'Accommodation not found.');
    }

    [$errors, $data] = acc_validate_fields($_POST);
    $newImageFilename = acc_handle_image_upload($_FILES['image'] ?? null, $errors);

    if ($errors) {
        acc_delete_image_file($newImageFilename);
        acc_json_fail(400, implode(' ', $errors));
    }

    // Validate category exists if supplied
    if ($data['category_id'] !== null) {
        $chk = $pdo->prepare('SELECT `id` FROM `accommodation_categories` WHERE `id` = :id LIMIT 1');
        $chk->execute(['id' => $data['category_id']]);
        if ($chk->fetch() === false) {
            acc_delete_image_file($newImageFilename);
            acc_json_fail(400, 'The selected category does not exist.');
        }
    }

    $removeImage = !empty($_POST['remove_image']);
    $finalImageFilename = $existing['image_filename'];

    if ($newImageFilename !== null) {
        acc_delete_image_file($existing['image_filename']);
        $finalImageFilename = $newImageFilename;
    } elseif ($removeImage) {
        acc_delete_image_file($existing['image_filename']);
        $finalImageFilename = null;
    }

    $stmt = $pdo->prepare(
        'UPDATE `accommodations` SET
            `name` = :name, `type` = :type, `capacity` = :capacity, `price` = :price,
            `status` = :status, `description` = :description, `amenities` = :amenities,
            `image_filename` = :image_filename, `category_id` = :category_id
         WHERE `id` = :id'
    );
    $stmt->execute([
        'name'           => $data['name'],
        'type'           => $data['type'],
        'capacity'       => $data['capacity'],
        'price'          => $data['price'],
        'status'         => $data['status'],
        'description'    => $data['description'] !== '' ? $data['description'] : null,
        'amenities'      => acc_encode_amenities($data['amenities']),
        'image_filename' => $finalImageFilename,
        'category_id'   => $data['category_id'],
        'id'             => $id,
    ]);

    acc_json_ok();
}

function acc_action_delete(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        acc_json_fail(400, 'Missing or invalid accommodation id.');
    }

    $existingStmt = $pdo->prepare('SELECT `image_filename` FROM `accommodations` WHERE `id` = :id LIMIT 1');
    $existingStmt->execute(['id' => $id]);
    $existing = $existingStmt->fetch();
    if ($existing === false) {
        acc_json_fail(404, 'Accommodation not found.');
    }

    $del = $pdo->prepare('DELETE FROM `accommodations` WHERE `id` = :id');
    $del->execute(['id' => $id]);

    acc_delete_image_file($existing['image_filename']);

    acc_json_ok();
}

function acc_action_set_status(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $status = (string) ($_POST['status'] ?? '');

    if (!$id) {
        acc_json_fail(400, 'Missing or invalid accommodation id.');
    }
    if (!acc_valid_status($status)) {
        acc_json_fail(400, 'Please choose a valid status.');
    }

    $existsStmt = $pdo->prepare('SELECT `id` FROM `accommodations` WHERE `id` = :id LIMIT 1');
    $existsStmt->execute(['id' => $id]);
    if ($existsStmt->fetch() === false) {
        acc_json_fail(404, 'Accommodation not found.');
    }

    $stmt = $pdo->prepare('UPDATE `accommodations` SET `status` = :status WHERE `id` = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);

    acc_json_ok(['status' => $status]);
}

// -------------------------------------------------------------------------
// Category actions
// -------------------------------------------------------------------------
function acc_action_create_category(PDO $pdo): never
{
    $name = trim((string) ($_POST['cat_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        acc_json_fail(400, 'Category name is required (max 100 characters).');
    }

    $description = trim((string) ($_POST['cat_description'] ?? ''));
    if (mb_strlen($description) > 500) {
        acc_json_fail(400, 'Category description is too long (max 500 characters).');
    }

    // Duplicate check
    $chk = $pdo->prepare('SELECT `id` FROM `accommodation_categories` WHERE `name` = :name LIMIT 1');
    $chk->execute(['name' => $name]);
    if ($chk->fetch()) {
        acc_json_fail(409, 'A category with that name already exists.');
    }

    // MySQL does not allow a subquery that references the same table being
    // inserted into (Error 1093). Fetch MAX(sort_order) first, then INSERT.
    $maxRow = $pdo->query('SELECT COALESCE(MAX(`sort_order`), 0) + 1 AS `next_order` FROM `accommodation_categories`')->fetch();
    $nextOrder = (int) ($maxRow['next_order'] ?? 1);

    $stmt = $pdo->prepare(
        'INSERT INTO `accommodation_categories` (`name`, `description`, `sort_order`)
         VALUES (:name, :description, :sort_order)'
    );
    $stmt->execute([
        'name'        => $name,
        'description' => $description !== '' ? $description : null,
        'sort_order'  => $nextOrder,
    ]);

    acc_json_ok(['id' => (int) $pdo->lastInsertId(), 'name' => $name]);
}

function acc_action_update_category(PDO $pdo): never
{
    $id = filter_var($_POST['cat_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        acc_json_fail(400, 'Missing or invalid category id.');
    }

    $name = trim((string) ($_POST['cat_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        acc_json_fail(400, 'Category name is required (max 100 characters).');
    }

    $description = trim((string) ($_POST['cat_description'] ?? ''));
    if (mb_strlen($description) > 500) {
        acc_json_fail(400, 'Category description is too long (max 500 characters).');
    }

    // Existence check
    $existsStmt = $pdo->prepare('SELECT `id` FROM `accommodation_categories` WHERE `id` = :id LIMIT 1');
    $existsStmt->execute(['id' => $id]);
    if ($existsStmt->fetch() === false) {
        acc_json_fail(404, 'Category not found.');
    }

    // Duplicate name check (excluding self)
    $chk = $pdo->prepare('SELECT `id` FROM `accommodation_categories` WHERE `name` = :name AND `id` != :id LIMIT 1');
    $chk->execute(['name' => $name, 'id' => $id]);
    if ($chk->fetch()) {
        acc_json_fail(409, 'Another category with that name already exists.');
    }

    $stmt = $pdo->prepare(
        'UPDATE `accommodation_categories` SET `name` = :name, `description` = :description WHERE `id` = :id'
    );
    $stmt->execute([
        'name'        => $name,
        'description' => $description !== '' ? $description : null,
        'id'          => $id,
    ]);

    acc_json_ok(['name' => $name]);
}

function acc_action_delete_category(PDO $pdo): never
{
    $id = filter_var($_POST['cat_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        acc_json_fail(400, 'Missing or invalid category id.');
    }

    $existsStmt = $pdo->prepare('SELECT `id` FROM `accommodation_categories` WHERE `id` = :id LIMIT 1');
    $existsStmt->execute(['id' => $id]);
    if ($existsStmt->fetch() === false) {
        acc_json_fail(404, 'Category not found.');
    }

    // Detach rooms instead of deleting them
    $detach = $pdo->prepare('UPDATE `accommodations` SET `category_id` = NULL WHERE `category_id` = :id');
    $detach->execute(['id' => $id]);

    $del = $pdo->prepare('DELETE FROM `accommodation_categories` WHERE `id` = :id');
    $del->execute(['id' => $id]);

    acc_json_ok();
}

// -------------------------------------------------------------------------
// Dispatch
// -------------------------------------------------------------------------
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'create':
        acc_action_create($pdo);
        break;
    case 'update':
        acc_action_update($pdo);
        break;
    case 'delete':
        acc_action_delete($pdo);
        break;
    case 'set_status':
        acc_action_set_status($pdo);
        break;
    case 'create_category':
        acc_action_create_category($pdo);
        break;
    case 'update_category':
        acc_action_update_category($pdo);
        break;
    case 'delete_category':
        acc_action_delete_category($pdo);
        break;
    default:
        acc_json_fail(400, 'Unknown action.');
}
