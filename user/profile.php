<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../includes/db.php';

require __DIR__ . '/includes/bootstrap.php';

$currentPage = ''; // Reached via the header avatar or Quick Actions, not a bottom-nav tab.
$pageTitle   = 'Edit Profile';

require __DIR__ . '/includes/head.php';
?>
<main class="page" id="main-content">

    <div class="page-head">
        <?php page_head('Edit Profile', 'Keep your contact information up to date'); ?>
    </div>

    <section class="panel panel--narrow">
        <form class="form" id="profileForm" novalidate>
            <div class="form__row">
                <div class="form__field">
                    <label for="firstName">First name</label>
                    <input type="text" id="firstName" name="firstName" placeholder="Enter your first name">
                </div>
                <div class="form__field">
                    <label for="lastName">Last name</label>
                    <input type="text" id="lastName" name="lastName" placeholder="Enter your last name">
                </div>
            </div>
            <div class="form__field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="you@example.com">
            </div>
            <div class="form__field">
                <label for="phone">Phone number</label>
                <input type="tel" id="phone" name="phone" placeholder="+63 900 000 0000">
            </div>
            <button type="submit" class="btn btn--primary">Save Changes</button>
            <p class="form__status" id="profileFormStatus" role="status"></p>
        </form>
    </section>

</main>
<?php require __DIR__ . '/includes/foot.php'; ?>
