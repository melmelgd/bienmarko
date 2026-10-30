<?php
/**
 * api/site-settings.php
 * -----------------------------------------------------------------------
 * PUBLIC, read-only. Returns only the guest-facing details saved on
 * Admin > Settings > General so the static landing page (index.html) can
 * show the current property name, phone, email and address.
 * Nothing private (admin account, notification flags) is exposed.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/site-settings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$s     = bienmarko_site_settings();
$brand = bienmarko_site_brand();

echo json_encode([
    'success'  => true,
    'settings' => [
        'name'       => $s['name'],
        'short_name' => $brand['short'],
        'rest_name'  => $brand['rest'],
        'email'      => $s['email'],
        'phone'      => $s['phone'],
        'phone_href' => bienmarko_site_tel_href($s['phone']),
        'address'    => $s['address'],
    ],
], JSON_UNESCAPED_UNICODE);
