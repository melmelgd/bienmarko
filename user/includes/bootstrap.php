<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'BienMarko');
define('SITE_TAGLINE', "Bien Marko's Pension House");

// TODO: Replace with real session-backed user data once accounts/auth exist.
$currentUser = [
    'name'  => null, // e.g. 'Shayra Walters'
    'email' => null,
];

// TODO: Replace with real notifications once there is something to notify about.
$notifications = [];

require_once __DIR__ . '/nav-items.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/ui.php';
