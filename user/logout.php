<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

// TODO: once real authentication exists, invalidate the real session/user here.
$_SESSION = [];
session_destroy();

$pageTitle = 'Logged Out';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars($pageTitle . ' · ' . SITE_NAME); ?></title>
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="standalone">
    <div class="standalone__card">
        <span class="standalone__icon"><?php render_icon('log-out'); ?></span>
        <h1>You've been logged out</h1>
        <p>Thanks for visiting <?php echo htmlspecialchars(SITE_NAME); ?>. See you again soon!</p>
        <a href="../index.php" class="btn btn--primary btn--block">Back to You</a>
    </div>
</body>
</html>
