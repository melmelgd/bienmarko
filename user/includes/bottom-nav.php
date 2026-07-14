<?php
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
