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
 *   action=list_photos   - list gallery photos for one room (Manage Photos modal)
 *   action=upload_photos - add one or more gallery photos (multipart; photos[])
 *   action=delete_photo  - remove one gallery photo
 *   action=set_cover_photo - promote a gallery photo to be the card/cover photo
 *   action=upload_tour   - upload/replace the 360° tour HTML file for a room
 *   action=delete_tour   - remove the 360° tour file from a room
 *
 * `type` is the accommodation CATEGORY (Room, Event Hall, Cottage,
 * Swimming Pool, or a custom "other" value). `room_number` is only
 * accepted/required when the raw <select> value posted for `type` is
 * exactly "Room" — see acc_validate_fields() below.
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
acc_ensure_photos_table();
acc_ensure_tour_upload_dir();

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
        $errors[] = 'Accommodation name is required (max 150 characters).';
    }

    // Keep the raw <select> value separate from the resolved $type — the
    // room-number gating below needs to know what the admin actually
    // *picked* in the dropdown, not the final free-text value (which, for
    // "other", comes from a different field entirely).
    $typeRaw = trim((string) ($post['type'] ?? ''));
    $type = $typeRaw;
    if ($type === 'other') {
        $type = trim((string) ($post['type_other'] ?? ''));
    }
    if ($type === '' || mb_strlen($type) > 100) {
        $errors[] = 'Accommodation type is required (max 100 characters).';
    }

    // Room number: only accepted (and required) when the admin selected
    // the "Room" category. For every other category it's cleared to NULL
    // regardless of what was posted, so stale values can't linger after
    // switching an accommodation's type away from "Room".
    $roomNumber = trim((string) ($post['room_number'] ?? ''));
    if (acc_type_requires_room_number($typeRaw)) {
        if ($roomNumber === '') {
            $errors[] = 'Room number is required when the accommodation type is Room.';
        } elseif (mb_strlen($roomNumber) > 20) {
            $errors[] = 'Room number is too long (max 20 characters).';
        }
    } else {
        $roomNumber = null;
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

    return [
        $errors,
        [
            'name'        => $name,
            'type'        => $type,
            'room_number' => $roomNumber,
            'capacity'    => $capacity !== false ? (int) $capacity : 0,
            'price'       => $price !== false ? (float) $price : 0.0,
            'status'      => $status,
            'description' => $description,
            'amenities'   => acc_parse_amenities($amenitiesRaw),
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

/**
 * Validates + stores ONE uploaded gallery photo. Same rules as
 * acc_handle_image_upload() (shared photo folder, same size/type limits) —
 * split out so acc_handle_gallery_uploads() can loop it over $_FILES['photos'].
 * Returns the generated filename, or null + an appended error on failure.
 */
function acc_store_one_gallery_photo(array $file, array &$errors): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        if (($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? null) === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'One of the photos is too large for this server\'s upload limit.';
        } else {
            $errors[] = 'One of the photos failed to upload. Please try a different file.';
        }
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Photo upload failed. Please try again.';
        return null;
    }

    if ($file['size'] > ACC_MAX_IMAGE_BYTES) {
        $errors[] = 'One of the photos is too large (max 5MB each).';
        return null;
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ACC_ALLOWED_IMAGE_EXT, true)) {
        $errors[] = 'Photos must be JPG, PNG, or WEBP files.';
        return null;
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        $errors[] = 'One of the files does not appear to be a valid image.';
        return null;
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = ACC_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $errors[] = 'Could not save one of the uploaded photos. Please try again.';
        return null;
    }

    return $filename;
}

/**
 * Normalises a multi-file <input name="photos[]" multiple> submission
 * (PHP's $_FILES shape for multi-file inputs is column-oriented, not
 * row-oriented — files[name][0], files[name][1], ... — so this reshapes
 * it into a plain list of single-file arrays) and stores each valid one
 * via acc_store_one_gallery_photo(). Returns the list of stored filenames.
 * A file that fails validation is skipped (its message lands in $errors)
 * rather than aborting the whole batch, since a 3-photo upload where 1
 * file is bad shouldn't cost the other 2.
 */
function acc_handle_gallery_uploads(?array $filesField, array &$errors): array
{
    if (!$filesField || empty($filesField['name']) || !is_array($filesField['name'])) {
        return [];
    }

    $count = count($filesField['name']);
    $stored = [];

    for ($i = 0; $i < $count; $i++) {
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // empty slot — browsers can send these, just skip
        }

        $single = [
            'name'     => $filesField['name'][$i]     ?? '',
            'type'     => $filesField['type'][$i]      ?? '',
            'tmp_name' => $filesField['tmp_name'][$i]  ?? '',
            'error'    => $filesField['error'][$i]     ?? UPLOAD_ERR_NO_FILE,
            'size'     => $filesField['size'][$i]      ?? 0,
        ];

        $filename = acc_store_one_gallery_photo($single, $errors);
        if ($filename !== null) {
            $stored[] = $filename;
        }
    }

    return $stored;
}

function acc_delete_gallery_photo_file(string $filename): void
{
    acc_delete_image_file($filename);
}

/**
 * Validates + stores an uploaded 360° tour file (a self-contained HTML
 * export, e.g. from Pannellum). Returns the generated filename, or null
 * when no file was submitted. Appends to $errors on any problem and never
 * partially writes a file when it does — same contract as
 * acc_handle_image_upload().
 */
function acc_handle_tour_upload(?array $file, array &$errors): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'That tour file is too large for this server\'s upload limit. Please choose a smaller file.';
        } else {
            $errors[] = '360° tour upload failed. Please try again.';
        }
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = '360° tour upload failed. Please try again.';
        return null;
    }

    if ($file['size'] > ACC_MAX_TOUR_BYTES) {
        $errors[] = '360° tour file is too large (max 30MB).';
        return null;
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ACC_ALLOWED_TOUR_EXT, true)) {
        $errors[] = 'The 360° tour must be an HTML file (.html).';
        return null;
    }

    // Light sanity check — read the first few KB and confirm it actually
    // looks like an HTML document rather than something mislabeled.
    $head = @file_get_contents($file['tmp_name'], false, null, 0, 4096);
    if ($head === false || stripos($head, '<html') === false) {
        $errors[] = 'That file does not appear to be a valid HTML tour export.';
        return null;
    }

    $filename = bin2hex(random_bytes(16)) . '.html';
    $dest = ACC_TOUR_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $errors[] = 'Could not save the uploaded tour file. Please try again.';
        return null;
    }

    return $filename;
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
            (`name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`)
         VALUES
            (:name, :type, :room_number, :capacity, :price, :status, :description, :amenities, :image_filename)'
    );
    $stmt->execute([
        'name'           => $data['name'],
        'type'           => $data['type'],
        'room_number'    => $data['room_number'],
        'capacity'       => $data['capacity'],
        'price'          => $data['price'],
        'status'         => 'available', // new accommodations always start as Available
        'description'    => $data['description'] !== '' ? $data['description'] : null,
        'amenities'      => acc_encode_amenities($data['amenities']),
        'image_filename' => $imageFilename,
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
            `name` = :name, `type` = :type, `room_number` = :room_number, `capacity` = :capacity, `price` = :price,
            `status` = :status, `description` = :description, `amenities` = :amenities,
            `image_filename` = :image_filename
         WHERE `id` = :id'
    );
    $stmt->execute([
        'name'           => $data['name'],
        'type'           => $data['type'],
        'room_number'    => $data['room_number'],
        'capacity'       => $data['capacity'],
        'price'          => $data['price'],
        'status'         => $data['status'],
        'description'    => $data['description'] !== '' ? $data['description'] : null,
        'amenities'      => acc_encode_amenities($data['amenities']),
        'image_filename' => $finalImageFilename,
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

    $existingStmt = $pdo->prepare('SELECT `image_filename`, `tour_filename` FROM `accommodations` WHERE `id` = :id LIMIT 1');
    $existingStmt->execute(['id' => $id]);
    $existing = $existingStmt->fetch();
    if ($existing === false) {
        acc_json_fail(404, 'Accommodation not found.');
    }

    // Gather gallery photo filenames before the row (and its FK-cascaded
    // photo rows) disappear, so we can still delete the physical files.
    $galleryPhotos = acc_get_photos($pdo, $id);

    $del = $pdo->prepare('DELETE FROM `accommodations` WHERE `id` = :id');
    $del->execute(['id' => $id]);

    acc_delete_image_file($existing['image_filename']);
    acc_delete_tour_file($existing['tour_filename']);
    foreach ($galleryPhotos as $photo) {
        acc_delete_gallery_photo_file($photo['filename']);
    }

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

/** Shared existence check, used by every photos/tour action below. */
function acc_require_accommodation(PDO $pdo, ?int $id): array
{
    if (!$id) {
        acc_json_fail(400, 'Missing or invalid accommodation id.');
    }
    $stmt = $pdo->prepare('SELECT `id`, `image_filename`, `tour_filename` FROM `accommodations` WHERE `id` = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        acc_json_fail(404, 'Accommodation not found.');
    }
    return $row;
}

/** action=list_photos — POST fields: id */
function acc_action_list_photos(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $acc = acc_require_accommodation($pdo, $id ?: null);

    acc_json_ok([
        'photos' => array_map(static function ($p) {
            return ['id' => (int) $p['id'], 'filename' => $p['filename']];
        }, acc_get_photos($pdo, (int) $acc['id'])),
        'cover' => $acc['image_filename'],
    ]);
}

/** action=upload_photos — POST fields: id, csrf_token; FILES: photos[] (multiple) */
function acc_action_upload_photos(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $acc = acc_require_accommodation($pdo, $id ?: null);
    $id = (int) $acc['id'];

    $existingCount = count(acc_get_photos($pdo, $id));
    $incoming = isset($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])
        ? count(array_filter($_FILES['photos']['name'], static function ($n) { return $n !== ''; }))
        : 0;

    if ($incoming === 0) {
        acc_json_fail(400, 'Please choose at least one photo to upload.');
    }
    if ($existingCount + $incoming > ACC_GALLERY_MAX_PHOTOS) {
        acc_json_fail(400, 'This room can have at most ' . ACC_GALLERY_MAX_PHOTOS . ' extra photos (' . $existingCount . ' already uploaded).');
    }

    $errors = [];
    $stored = acc_handle_gallery_uploads($_FILES['photos'] ?? null, $errors);

    if (!$stored) {
        acc_json_fail(400, $errors ? implode(' ', $errors) : 'No photos were uploaded. Please try again.');
    }

    // sort_order continues on from whatever's already there
    $nextOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(`sort_order`), -1) + 1 FROM `accommodation_photos` WHERE `accommodation_id` = :id');
    $nextOrderStmt->execute(['id' => $id]);
    $nextOrder = (int) $nextOrderStmt->fetchColumn();

    $insert = $pdo->prepare(
        'INSERT INTO `accommodation_photos` (`accommodation_id`, `filename`, `sort_order`) VALUES (:acc_id, :filename, :sort_order)'
    );
    foreach ($stored as $i => $filename) {
        $insert->execute(['acc_id' => $id, 'filename' => $filename, 'sort_order' => $nextOrder + $i]);
    }

    // If the room has no cover photo yet, promote the first newly
    // uploaded one — a freshly-created room shouldn't stay icon-only
    // just because photos were added through the gallery instead of
    // the main form.
    $newCover = $acc['image_filename'];
    if (!$newCover) {
        $newCover = $stored[0];
        $pdo->prepare('UPDATE `accommodations` SET `image_filename` = :f WHERE `id` = :id')
            ->execute(['f' => $newCover, 'id' => $id]);
    }

    acc_json_ok([
        'photos' => array_map(static function ($p) {
            return ['id' => (int) $p['id'], 'filename' => $p['filename']];
        }, acc_get_photos($pdo, $id)),
        'cover'   => $newCover,
        'warning' => $errors ? implode(' ', $errors) : null,
    ]);
}

/** action=delete_photo — POST fields: id, photo_id */
function acc_action_delete_photo(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $photoId = filter_var($_POST['photo_id'] ?? null, FILTER_VALIDATE_INT);
    $acc = acc_require_accommodation($pdo, $id ?: null);
    $id = (int) $acc['id'];

    if (!$photoId) {
        acc_json_fail(400, 'Missing or invalid photo id.');
    }

    $photoStmt = $pdo->prepare('SELECT `id`, `filename` FROM `accommodation_photos` WHERE `id` = :pid AND `accommodation_id` = :id LIMIT 1');
    $photoStmt->execute(['pid' => $photoId, 'id' => $id]);
    $photo = $photoStmt->fetch();
    if ($photo === false) {
        acc_json_fail(404, 'Photo not found.');
    }

    $pdo->prepare('DELETE FROM `accommodation_photos` WHERE `id` = :pid')->execute(['pid' => $photoId]);
    acc_delete_gallery_photo_file($photo['filename']);

    $newCover = $acc['image_filename'];
    if ($newCover === $photo['filename']) {
        // The deleted photo was the cover — clear it rather than silently
        // pointing at a file that no longer exists.
        $newCover = null;
        $pdo->prepare('UPDATE `accommodations` SET `image_filename` = NULL WHERE `id` = :id')->execute(['id' => $id]);
    }

    acc_json_ok([
        'photos' => array_map(static function ($p) {
            return ['id' => (int) $p['id'], 'filename' => $p['filename']];
        }, acc_get_photos($pdo, $id)),
        'cover' => $newCover,
    ]);
}

/** action=set_cover_photo — POST fields: id, photo_id */
function acc_action_set_cover_photo(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $photoId = filter_var($_POST['photo_id'] ?? null, FILTER_VALIDATE_INT);
    $acc = acc_require_accommodation($pdo, $id ?: null);
    $id = (int) $acc['id'];

    if (!$photoId) {
        acc_json_fail(400, 'Missing or invalid photo id.');
    }

    $photoStmt = $pdo->prepare('SELECT `filename` FROM `accommodation_photos` WHERE `id` = :pid AND `accommodation_id` = :id LIMIT 1');
    $photoStmt->execute(['pid' => $photoId, 'id' => $id]);
    $photo = $photoStmt->fetch();
    if ($photo === false) {
        acc_json_fail(404, 'Photo not found.');
    }

    $pdo->prepare('UPDATE `accommodations` SET `image_filename` = :f WHERE `id` = :id')
        ->execute(['f' => $photo['filename'], 'id' => $id]);

    acc_json_ok(['cover' => $photo['filename']]);
}

/** action=upload_tour — POST fields: id, csrf_token; FILES: tour_html */
function acc_action_upload_tour(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $acc = acc_require_accommodation($pdo, $id ?: null);
    $id = (int) $acc['id'];

    $errors = [];
    $newTourFilename = acc_handle_tour_upload($_FILES['tour_html'] ?? null, $errors);

    if ($errors || $newTourFilename === null) {
        acc_delete_tour_file($newTourFilename);
        acc_json_fail(400, $errors ? implode(' ', $errors) : 'Please choose a tour HTML file to upload.');
    }

    // Replacing an existing tour — remove the old file once the new one
    // is safely saved.
    acc_delete_tour_file($acc['tour_filename']);

    $pdo->prepare('UPDATE `accommodations` SET `tour_filename` = :f WHERE `id` = :id')
        ->execute(['f' => $newTourFilename, 'id' => $id]);

    acc_json_ok([
        'tour_filename' => $newTourFilename,
        'tour_url'      => ACC_TOUR_UPLOAD_URL_BASE . '/' . rawurlencode($newTourFilename),
    ]);
}

/** action=delete_tour — POST fields: id */
function acc_action_delete_tour(PDO $pdo): never
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $acc = acc_require_accommodation($pdo, $id ?: null);
    $id = (int) $acc['id'];

    acc_delete_tour_file($acc['tour_filename']);
    $pdo->prepare('UPDATE `accommodations` SET `tour_filename` = NULL WHERE `id` = :id')->execute(['id' => $id]);

    acc_json_ok();
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
        break;
    case 'list_photos':
        acc_action_list_photos($pdo);
        break;
    case 'upload_photos':
        acc_action_upload_photos($pdo);
        break;
    case 'delete_photo':
        acc_action_delete_photo($pdo);
        break;
    case 'set_cover_photo':
        acc_action_set_cover_photo($pdo);
        break;
    case 'upload_tour':
        acc_action_upload_tour($pdo);
        break;
    case 'delete_tour':
        acc_action_delete_tour($pdo);
        break;
    default:
        acc_json_fail(400, 'Unknown action.');
}