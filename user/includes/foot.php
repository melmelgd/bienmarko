<?php
/**
 * Live refresh: stamp the page with the data fingerprint it was rendered
 * with, then load assets/js/live-refresh.js (polls api/live.php). Wrapped in
 * try/catch — a live-refresh problem must never break a page.
 */
$__liveBase  = '';
$__liveNotif = '';
$__livePage  = basename((string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
$__liveGuest = (int) ($_SESSION['auth_user_id'] ?? 0);
if ($__liveGuest > 0) {
    try {
        require_once __DIR__ . '/live.php';
        $__livePdo   = bienmarko_db();
        $__liveBase  = bm_live_user_page_sig($__livePdo, $__liveGuest, $__livePage);
        $__liveNotif = bm_live_user_bell($__livePdo, $__liveGuest, false)['sig'];
    } catch (Throwable $e) {
        error_log('[user-live] ' . $e->getMessage());
    }
}
?>
<script src="assets/js/app.js"></script>
<?php if ($__liveGuest > 0): ?>
<script src="assets/js/live-refresh.js" defer
        data-live-endpoint="api/live.php"
        data-live-mode="user"
        data-live-page="<?php echo htmlspecialchars($__livePage, ENT_QUOTES, 'UTF-8'); ?>"
        data-live-base="<?php echo htmlspecialchars($__liveBase, ENT_QUOTES, 'UTF-8'); ?>"
        data-live-notif="<?php echo htmlspecialchars($__liveNotif, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
</body>
</html>
