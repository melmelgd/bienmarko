<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'BienMarko');
define('SITE_TAGLINE', "Bien Marko's Pension House");


$currentUser = [
    'name'  => null, // e.g. 'Shayra Walters'
    'email' => null,
];


$notifications = [];

require_once __DIR__ . '/nav-items.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/ui.php';
