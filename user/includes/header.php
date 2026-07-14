<?php
$initials = '';
if (!empty($currentUser['name'])) {
    $parts = preg_split('/\s+/', trim($currentUser['name']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
$notifCount = count($notifications ?? []);
?>
<header class="topbar">
    <a href="dashboard.php" class="topbar__brand">
        <span class="topbar__logo"><?php render_icon('house'); ?></span>
        <span class="topbar__brand-text">
            <span class="topbar__brand-name"><?php echo htmlspecialchars(SITE_NAME); ?></span>
            <span class="topbar__brand-tagline"><?php echo htmlspecialchars(SITE_TAGLINE); ?></span>
        </span>
    </a>

    <div class="topbar__actions">
        <div class="topbar__notif">
            <button type="button" class="topbar__icon-btn" id="notifToggle" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                <?php render_icon('bell'); ?>
                <?php if ($notifCount > 0): ?>
                    <span class="topbar__badge"><?php echo (int) $notifCount; ?></span>
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
                            <li>
                                <span><?php echo htmlspecialchars($notif['message']); ?></span>
                                <span class="topbar__notif-time"><?php echo htmlspecialchars($notif['time']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <a href="profile.php" class="topbar__user">
            <span class="topbar__avatar"><?php if ($initials): ?><?php echo htmlspecialchars($initials); ?><?php else: ?><?php render_icon('user'); ?><?php endif; ?></span>
            <span class="topbar__username"><?php echo htmlspecialchars($currentUser['name'] ?: 'Guest'); ?></span>
        </a>

        <a href="logout.php" class="topbar__icon-btn topbar__logout" id="logoutLink" aria-label="Log out">
            <?php render_icon('log-out'); ?>
        </a>
    </div>
</header>
