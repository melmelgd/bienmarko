<?php
/**
 * index.php
 * -----------------------------------------------------------------------
 * Root entry point. Same job as user/index.php and admin/index.php — send
 * the visitor straight where they belong — except this one has a session
 * to check first: signed in guests and staff skip the login page entirely.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/auth-common.php';

bienmarko_start_session();

if (bienmarko_is_logged_in()) {
    header('Location: ' . bienmarko_dashboard_path($_SESSION['auth_role']));
} else {
    header('Location: login.php');
}
exit;
