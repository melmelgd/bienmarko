<?php
/**
 * Shared bootstrap. Every page requires this file first, and only this file.
 *
 * It starts the session, defines site-wide constants, sets up placeholder
 * "current user" data, and pulls in the shared nav config, icon library,
 * and UI helper functions so pages don't need four separate requires.
 *
 * PROJECT STAGE: no database or authentication exists yet. Everything below
 * marked "placeholder" is safe to replace once real data is wired in — the
 * templates already branch on empty vs. populated state, so real data will
 * render correctly the moment these values stop being null/empty.
 */

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
