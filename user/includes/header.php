<?php
$initials = '';
if (!empty($currentUser['name'])) {
    $parts = preg_split('/\s+/', trim($currentUser['name']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
$notifCount       = count($notifications ?? []);
$notifUnreadCount = $notifUnreadCount ?? 0;
$navCurrent       = $currentPage ?? '';
?>
<a class="skip-link" href="#main-content">Skip to content</a>
<header class="topbar" id="siteHeader">
    <div class="topbar__inner">

        <a href="dashboard.php" class="topbar__brand">
            <span class="topbar__logo"><?php render_icon('house'); ?></span>
            <span class="topbar__brand-text">
                <span class="topbar__brand-name"><?php echo htmlspecialchars(SITE_NAME); ?></span>
                <span class="topbar__brand-tagline"><?php echo htmlspecialchars(SITE_TAGLINE); ?></span>
            </span>
        </a>

        <!-- Primary navigation: inline links on desktop, dropdown menu on tablet/mobile -->
        <nav class="topbar__nav" id="primaryNav" aria-label="Primary">
            <?php foreach ($navItems as $item):
                $isActive    = ($navCurrent === $item['key']);
                $activeClass = $isActive ? ' is-active' : '';
                $ariaCurrent = $isActive ? ' aria-current="page"' : '';
            ?>
                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="topbar__link<?php echo $activeClass; ?>"<?php echo $ariaCurrent; ?>>
                    <span class="topbar__link-icon"><?php render_icon($item['icon']); ?></span>
                    <span class="topbar__link-label"><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
            <?php endforeach; ?>

            <div class="topbar__nav-extra">
                <a href="profile.php" class="topbar__link">
                    <span class="topbar__link-icon"><?php render_icon('user'); ?></span>
                    <span class="topbar__link-label">My Profile</span>
                </a>
                <a href="logout.php" class="topbar__link topbar__link--danger" data-logout>
                    <span class="topbar__link-icon"><?php render_icon('log-out'); ?></span>
                    <span class="topbar__link-label">Log out</span>
                </a>
            </div>
        </nav>

        <div class="topbar__actions">
            <div class="topbar__notif">
                <button type="button" class="topbar__icon-btn" id="notifToggle" data-csrf="<?php echo htmlspecialchars(bienmarko_csrf_token()); ?>" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <?php render_icon('bell'); ?>
                    <?php if ($notifUnreadCount > 0): ?>
                        <span class="topbar__badge"><?php echo (int) $notifUnreadCount; ?></span>
                    <?php endif; ?>
                </button>
                <div class="topbar__notif-panel" id="notifPanel" hidden>
                    <p class="topbar__notif-title">Notifications</p>
                    <?php if ($notifCount === 0): ?>
                        <div class="empty-state empty-state--compact">
                            <span class="empty-state__icon"><?php render_icon('inbox'); ?></span>
                            <p class="empty-state__message">No new notifications</p>
                        </div>
                    <?php else: ?>
                        <ul class="topbar__notif-list">
                            <?php foreach ($notifications as $notif): ?>
                                <li class="<?php echo empty($notif['is_read']) ? 'is-unread' : ''; ?>">
                                    <?php if (!empty($notif['href'])): ?>
                                        <a href="<?php echo htmlspecialchars($notif['href']); ?>" style="color:inherit;text-decoration:underline"><?php echo htmlspecialchars($notif['message']); ?></a>
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars($notif['message']); ?></span>
                                    <?php endif; ?>
                                    <span class="topbar__notif-time"><?php echo htmlspecialchars($notif['time']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <a href="profile.php" class="topbar__user">
                <span class="topbar__avatar">
                    <?php if (!empty($currentUser['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="">
                    <?php elseif ($initials): ?>
                        <?php echo htmlspecialchars($initials); ?>
                    <?php else: ?>
                        <?php render_icon('user'); ?>
                    <?php endif; ?>
                </span>
                <span class="topbar__username"><?php echo htmlspecialchars($currentUser['name'] ?: 'Guest'); ?></span>
            </a>

            <a href="logout.php" class="topbar__icon-btn topbar__logout" id="logoutLink" data-logout aria-label="Log out">
                <?php render_icon('log-out'); ?>
            </a>

            <button type="button" class="topbar__icon-btn topbar__burger" id="navToggle" aria-controls="primaryNav" aria-expanded="false" aria-label="Open menu">
                <span class="topbar__burger-bar" aria-hidden="true"></span>
            </button>
        </div>

    </div>
</header>