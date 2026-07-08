<?php
/**
 * logout.php
 * -----------------------------------------------------------------------
 * Standalone placeholder. Deliberately does NOT include sidebar.php or
 * topbar.php — once a user logs out they shouldn't see the authenticated
 * shell. When real authentication is wired up, this is where the session
 * gets destroyed (session_destroy(), clearing cookies, etc.) before
 * redirecting to a login page.
 * -----------------------------------------------------------------------
 */
session_start();
session_unset();   
session_destroy(); 
header("Location: ../index.php");
exit();

$page_title = 'Logged Out';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> · Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <div style="min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;">
    <div class="panel panel-pad" style="max-width:380px; width:100%; text-align:center;">
      <span class="sidebar-logo" style="margin:0 auto 16px;"><?php require_once __DIR__ . '/includes/icons.php'; echo icon('palm'); ?></span>
      <h1 style="font-size:1.25rem; margin-bottom:8px;">You've been logged out</h1>
      <p style="color:var(--text-tertiary); font-size:0.9rem; margin-bottom:20px;">
        Thanks for using the admin dashboard. Sign back in any time to pick up where you left off.
      </p>
      <a href="index.php" class="btn btn-primary" style="justify-content:center;">Back to gengeng</a>
    </div>
  </div>
</body>
</html>
