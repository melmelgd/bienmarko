<?php
/**
 * Shared bottom navigation. This is the "sidebar", repositioned as a fixed
 * app-style tab bar and included identically on every app page. It reads
 * $navItems from nav-items.php (never redeclares it) and $currentPage from
 * the calling page to mark the active tab — that is the only thing that
 * differs between pages, and it is normalized out when diff-checking.
 */
$current = $currentPage ?? '';
?>
<nav class="bottom-nav" aria-label="Primary">
    <?php foreach ($navItems as $item):
        $isActive    = ($current === $item['key']);
        $activeClass = $isActive ? ' is-active' : '';
        $ariaCurrent = $isActive ? ' aria-current="page"' : '';
    ?>
        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="bottom-nav__item<?php echo $activeClass; ?>"<?php echo $ariaCurrent; ?>>
            <span class="bottom-nav__icon"><?php render_icon($item['icon']); ?></span>
            <span class="bottom-nav__label"><?php echo htmlspecialchars($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
