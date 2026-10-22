<?php
$footerYear = date('Y');
?>
<footer class="site-footer">
    <div class="site-footer__inner">
        <div class="site-footer__brand">
            <a href="dashboard.php" class="topbar__brand">
                <span class="topbar__logo"><?php render_icon('house'); ?></span>
                <span class="topbar__brand-text">
                    <span class="topbar__brand-name"><?php echo htmlspecialchars(SITE_NAME); ?></span>
                    <span class="topbar__brand-tagline" style="display:block"><?php echo htmlspecialchars(SITE_TAGLINE); ?></span>
                </span>
            </a>
            <p class="site-footer__blurb">Browse rooms, manage your bookings, view receipts and share feedback about your stay, all in one place.</p>
        </div>

        <nav class="site-footer__col" aria-label="Footer">
            <h2 class="site-footer__heading">Explore</h2>
            <ul class="site-footer__list">
                <?php foreach ($navItems as $item): ?>
                    <li><a href="<?php echo htmlspecialchars($item['href']); ?>"><?php echo htmlspecialchars($item['label']); ?></a></li>
                <?php endforeach; ?>
                <li><a href="profile.php">My Profile</a></li>
            </ul>
        </nav>

        <div class="site-footer__col">
            <h2 class="site-footer__heading">Visit us</h2>
            <p class="site-footer__text">
                <?php echo htmlspecialchars(SITE_TAGLINE); ?><br>
                1st Ave Street, Poblacion,<br>
                Tabango, Leyte, Philippines
            </p>
        </div>
    </div>

    <div class="site-footer__bottom">
        <div class="site-footer__bottom-inner">
            <span>&copy; <?php echo $footerYear; ?> <?php echo htmlspecialchars(SITE_TAGLINE); ?>. All rights reserved.</span>
            <a href="#top" class="site-footer__top">Back to top &uarr;</a>
        </div>
    </div>
</footer>
<script src="assets/js/app.js"></script>
</body>
</html>