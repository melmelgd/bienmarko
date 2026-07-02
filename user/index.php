<?php
// Entry point — sends visitors straight to the dashboard.
// TODO: once authentication exists, redirect to a login page when signed out.
header('Location: dashboard.php');
exit;
