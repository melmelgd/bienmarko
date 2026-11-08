<?php
/**
 * foot.php
 * -----------------------------------------------------------------------
 * Closes <main class="content"> and .app-main (opened by topbar.php) and
 * .app-shell (opened by head.php), then loads script.js. Every page ends
 * with `require __DIR__ . '/includes/foot.php';` as its very last line.
 *
 * Live refresh: stamps the page with the data fingerprint it was rendered
 * with, then loads assets/js/live-refresh.js, which polls api/live.php and
 * refreshes the page (and the notification bell) when the data changes.
 * Wrapped in try/catch — a live-refresh problem must never break a page.
 * -----------------------------------------------------------------------
 */
$__liveBase  = '';
$__liveNotif = '';
$__livePage  = basename((string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
try {
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/live.php';
    $__livePdo   = bienmarko_db();
    $__liveBase  = bm_live_admin_page_sig($__livePdo, $__livePage);
    $__liveNotif = bm_live_admin_bell($__livePdo, false)['sig'];
} catch (Throwable $e) {
    error_log('[admin-live] ' . $e->getMessage());
}
?>
  </main>
</div><!-- /.app-main -->
</div><!-- /.app-shell -->
<script src="assets/js/script.js" defer></script>
<script src="assets/js/live-refresh.js" defer
        data-live-endpoint="api/live.php"
        data-live-mode="admin"
        data-live-page="<?php echo htmlspecialchars($__livePage, ENT_QUOTES, 'UTF-8'); ?>"
        data-live-base="<?php echo htmlspecialchars($__liveBase, ENT_QUOTES, 'UTF-8'); ?>"
        data-live-notif="<?php echo htmlspecialchars($__liveNotif, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
