<?php
/**
 * admin/api/accommodations.php
 * -----------------------------------------------------------------------
 * JSON API behind admin/accommodations.php. Guarded the same way as
 * admin/api/create-admin.php — session + admin role + CSRF, checked in
 * that order, before anything submitted is looked at.
 *
 * Every request is POST, with an `action` field selecting what happens:
 *   action=create        - insert a new accommodation (multipart; image optional)
 *   action=update        - update an existing one by id
 *   action=delete        - delete one by id (and its photo file, if any)
 *   action=set_status    - update only the status column (quick action)
 *   action=set_featured  - toggle the is_featured flag (star button on card)
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
acc_ensure_upload_dir();

$pdo = bienmarko_db();

/**
 * Shared field validation for create + update.
 * Returns [errors[], cleanData[]]. cleanData is only meaningful when
 * errors is empty.
 */
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

    // is_featured: checkbox sends "1" when checked, absent when unchecked
    $isFeatured = !empty($post['is_featured']) ? 1 : 0;

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
            'is_featured' => $isFeatured,
        ],
    ];
}

/**
 * Validates + stores an uploaded image. Returns the generated filename,
 * or null when no file was submitted. Appends to $errors (by reference)
 * on any problem, and never partially writes a file when it does.
 */
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

function acc_action_create(PDO $pdo): never
{
    [$errors, $data] = acc_validate_fields($_POST);
    $imageFilename = acc_handle_image_upload($_FILES['image'] ?? null, $errors);

    if ($errors) {
        acc_delete_image_file($imageFilename);
        acc_json_fail(400, implode(' ', $errors));
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `accommodations`
            (`name`, `type`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `is_featured`)
         VALUES
            (:name, :type, :capacity, :price, :status, :description, :amenities, :image_filename, :is_featured)'
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
        'is_featured'    => $data['is_featured'],
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
            `image_filename` = :image_filename, `is_featured` = :is_featured
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
        'is_featured'    => $data['is_featured'],
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

/**
 * Toggles (or explicitly sets) the is_featured flag on one accommodation.
 * POST fields: id (int), featured (0|1)
 */
function acc_action_set_featured(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        acc_json_fail(400, 'Missing or invalid accommodation id.');
    }

    $featured = filter_var($_POST['featured'] ?? null, FILTER_VALIDATE_INT);
    if ($featured === false || ($featured !== 0 && $featured !== 1)) {
        acc_json_fail(400, 'featured must be 0 or 1.');
    }

    $existsStmt = $pdo->prepare('SELECT `id` FROM `accommodations` WHERE `id` = :id LIMIT 1');
    $existsStmt->execute(['id' => $id]);
    if ($existsStmt->fetch() === false) {
        acc_json_fail(404, 'Accommodation not found.');
    }

    $stmt = $pdo->prepare('UPDATE `accommodations` SET `is_featured` = :featured WHERE `id` = :id');
    $stmt->execute(['featured' => $featured, 'id' => $id]);

    acc_json_ok(['is_featured' => $featured]);
}

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
    case 'set_featured':
        acc_action_set_featured($pdo);
        break;
    default:
        acc_json_fail(400, 'Unknown action.');
}
