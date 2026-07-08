<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = 'guide';
$pageTitle   = 'User Guide';

$faqs = [
    [
        'q' => 'How do I book a stay?',
        'a' => 'Open the Bookings tab and fill out the "Request a Booking" form with your preferred dates, guest count, and any special requests. Our team will follow up to confirm availability.',
    ],
    [
        'q' => 'How do I view my receipts?',
        'a' => 'Open the Receipts tab. Once a payment has been processed, it will appear there and can be downloaded.',
    ],
    [
        'q' => 'How do I leave feedback about my stay?',
        'a' => 'Open the Feedback tab, choose a star rating and category, then share your comments. Your submission history is listed on the same page.',
    ],
    [
        'q' => 'How do I update my profile details?',
        'a' => 'Select your name in the top-right corner, or use "Edit Profile" under Quick Actions on the Dashboard, to update your contact information.',
    ],
    [
        'q' => 'How do I get in touch with the resort?',
        'a' => 'Use "Contact Support" under Quick Actions on the Dashboard for our email and phone number, or send a message through the Feedback tab.',
    ],
];

require __DIR__ . '/includes/head.php';
?>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('User Guide', 'Answers to common questions about your dashboard'); ?>
    </div>

    <section class="panel">
        <div class="faq-list">
            <?php foreach ($faqs as $faq): ?>
                <details class="faq-item">
                    <summary>
                        <span><?php echo htmlspecialchars($faq['q']); ?></span>
                        <span class="faq-item__chevron" aria-hidden="true"><?php render_icon('chevron-down'); ?></span>
                    </summary>
                    <p><?php echo htmlspecialchars($faq['a']); ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

</main>
<?php require __DIR__ . '/includes/foot.php'; ?>
