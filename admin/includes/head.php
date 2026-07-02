<?php
/**
 * head.php
 * -----------------------------------------------------------------------
 * Opens the HTML document: <head> plus the start of <body> and the outer
 * .app-shell wrapper. Every page sets $page_title before requiring this
 * file. Paired with foot.php, which closes everything opened here.
 * -----------------------------------------------------------------------
 */
$page_title = $page_title ?? 'Admin Dashboard';
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
<div class="app-shell">
