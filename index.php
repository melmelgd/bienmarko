<?php

require_once __DIR__ . '/includes/auth-common.php';

bienmarko_start_session();

if (bienmarko_is_logged_in()) {
    header('Location: ' . bienmarko_dashboard_path($_SESSION['auth_role']));
} else {
    header('Location: login.php');
}
exit;