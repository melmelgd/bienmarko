<?php
$pageTitle = $pageTitle ?? SITE_NAME;

/*
 * Sidebar shell (black top bar + left sidebar) is now the DEFAULT layout for
 * every page that includes this file.
 *
 * A page can still opt back into the old top-navigation header by setting
 *     $useSidebarShell = false;
 * BEFORE requiring includes/head.php.
 */
$useSidebarShell = $useSidebarShell ?? true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars($pageTitle . ' · ' . SITE_NAME); ?></title>
<link rel="icon" href="../logo/palm-tree.png">
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<?php if (!empty($useSidebarShell)): ?>
<link rel="stylesheet" href="assets/css/shell-sidebar.css">
<?php endif; ?>
</head>
<body<?php echo !empty($useSidebarShell) ? ' class="has-sidebar"' : ''; ?>>
<?php require __DIR__ . '/' . (!empty($useSidebarShell) ? 'shell-sidebar.php' : 'header.php'); ?>