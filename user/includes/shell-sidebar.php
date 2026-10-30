<?php
/**
 * user/includes/shell-sidebar.php
 * -----------------------------------------------------------------------
 * Sidebar app shell: black top bar (brand, bell, avatar) + left sidebar.
 *
 * Opt in from any page by setting this BEFORE including includes/head.php:
 *
 *     $useSidebarShell = true;
 *
 * Pages that don't set it keep the original top-navigation header
 * (includes/header.php), so nothing else on the site changes.
 *
 * Nav keys / hrefs / icons still come from includes/nav-items.php (single
 * source of truth). Only the *labels* are overridden below to match the
 * longer sidebar wording ("My Bookings", "User Guide").
 *
 * Re-uses the element ids app.js already wires up:
 *   #notifToggle / #notifPanel  -> bell dropdown + mark-all-read
 *   [data-logout]               -> "Log out of BienMarko?" confirm
 * -----------------------------------------------------------------------
 */

if (!function_exists('bm_inline_icon')) {
    /**
     * Prints an icon from the icon library as inline SVG so it can take the
     * colour of its parent (currentColor). The <img> version used elsewhere
     * is locked to the file's hard-coded stroke colour.
     */
    function bm_inline_icon(string $name): void
    {
        global $iconLibrary;
        $rel  = $iconLibrary[$name] ?? ($iconLibrary['circle-help'] ?? '');
        $file = dirname(__DIR__) . '/' . $rel;
        $svg  = is_file($file) ? (string) file_get_contents($file) : '';
        if ($svg === '') {
            return;
        }
        $svg = str_ireplace('stroke="#F3F0E8"', 'stroke="currentColor"', $svg);
        $svg = preg_replace('/<svg\b/', '<svg class="shell-icon" aria-hidden="true" focusable="false"', $svg, 1);
        echo $svg;
    }
}

$shellLabels = [
    'bookings' => 'My Bookings',
    'guide'    => 'User Guide',
];

$shellInitials = '';
if (!empty($currentUser['name'])) {
    $shellParts    = preg_split('/\s+/', trim($currentUser['name']));
    $shellInitials = strtoupper(substr($shellParts[0], 0, 1) . (isset($shellParts[1]) ? substr($shellParts[1], 0, 1) : ''));
}
$shellNotifCount  = count($notifications ?? []);
$shellUnreadCount = (int) ($notifUnreadCount ?? 0);
$shellCurrent     = $currentPage ?? '';
?>
<a class="skip-link" href="#main-content">Skip to content</a>

<header class="shell-top" id="siteHeader">
    <div class="shell-top__left">
        <button type="button" class="shell-burger" id="shellMenuToggle"
                aria-controls="shellSidebar" aria-expanded="false" aria-label="Open menu">
            <span class="shell-burger__bar" aria-hidden="true"></span>
        </button>
        <a href="dashboard.php" class="shell-brand">
            <span class="shell-brand__logo"><?php bm_inline_icon('house'); ?></span>
            <span class="shell-brand__text">
                <span class="shell-brand__name"><?php echo htmlspecialchars(SITE_NAME); ?></span>
            </span>
        </a>
    </div>

    <div class="shell-top__right">
        <div class="topbar__notif">
            <button type="button" class="shell-bell" id="notifToggle"
                    data-csrf="<?php echo htmlspecialchars(bienmarko_csrf_token()); ?>"
                    aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                <?php bm_inline_icon('bell'); ?>
                <?php if ($shellUnreadCount > 0): ?>
                    <span class="topbar__badge" aria-label="<?php echo $shellUnreadCount; ?> unread"></span>
                <?php endif; ?>
            </button>
            <div class="topbar__notif-panel" id="notifPanel" hidden>
                <p class="topbar__notif-title">Notifications</p>
                <?php if ($shellNotifCount === 0): ?>
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

        <a href="profile.php" class="shell-user" title="My Profile">
            <span class="shell-user__avatar">
                <?php if (!empty($currentUser['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="">
                <?php elseif ($shellInitials): ?>
                    <?php echo htmlspecialchars($shellInitials); ?>
                <?php else: ?>
                    <?php bm_inline_icon('user'); ?>
                <?php endif; ?>
            </span>
            <span class="shell-user__name"><?php echo htmlspecialchars($currentUser['name'] ?: 'Guest'); ?></span>
        </a>
    </div>
</header>

<aside class="shell-side" id="shellSidebar" aria-label="Sidebar">
    <nav class="shell-side__nav" aria-label="Primary">
        <?php foreach ($navItems as $item):
            $isActive = ($shellCurrent === $item['key']);
            $label    = $shellLabels[$item['key']] ?? $item['label'];
        ?>
            <a href="<?php echo htmlspecialchars($item['href']); ?>"
               class="shell-side__link<?php echo $isActive ? ' is-active' : ''; ?>"
               <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
                <?php bm_inline_icon($item['icon']); ?>
                <span><?php echo htmlspecialchars($label); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="shell-side__foot">
        <a href="logout.php" class="shell-side__logout" data-logout>
            <?php bm_inline_icon('log-out'); ?>
            <span>Log Out</span>
        </a>
    </div>
</aside>
<div class="shell-scrim" id="shellScrim" hidden></div>

<script>
/* Sidebar drawer for tablet / phone (the sidebar is always visible on desktop). */
(function () {
  var btn   = document.getElementById('shellMenuToggle');
  var side  = document.getElementById('shellSidebar');
  var scrim = document.getElementById('shellScrim');
  if (!btn || !side || !scrim) return;

  function setOpen(open) {
    side.classList.toggle('is-open', open);
    scrim.hidden = !open;
    btn.setAttribute('aria-expanded', String(open));
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    document.documentElement.classList.toggle('shell-lock', open);
  }
  btn.addEventListener('click', function () { setOpen(!side.classList.contains('is-open')); });
  scrim.addEventListener('click', function () { setOpen(false); });
  side.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('a')) setOpen(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && side.classList.contains('is-open')) { setOpen(false); btn.focus(); }
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 900 && side.classList.contains('is-open')) setOpen(false);
  });
})();
</script>