<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'receipts';
$pageTitle   = 'Receipts';

$receipts = []; // e.g. [['reference' => 'BM-1001', 'date' => 'May 14, 2026', 'amount' => 4200]]

require __DIR__ . '/includes/head.php';
?>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('Receipts', 'Download and review your payment receipts'); ?>
    </div>

    <section class="panel">
        <?php render_empty_state(
            'file-text',
            'No receipts yet',
            'Once a payment is processed, your receipt will appear here for download.'
        ); ?>
    </section>

</main>
<?php require __DIR__ . '/includes/foot.php'; ?>
