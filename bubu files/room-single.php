<?php
$rooms = [
    'family' => ['title' => 'Family Room', 'image' => 'images/room1.jpg', 'price' => 'PHP 1,800', 'meta' => '4 Guests - Air-conditioned', 'copy' => 'A spacious room prepared for families or small groups, with clean beds, fresh linens, and a quiet setting for restful evenings.'],
    'standard' => ['title' => 'Standard Room', 'image' => 'images/room4.jpg', 'price' => 'PHP 1,200', 'meta' => '2 Guests - Clean essentials', 'copy' => 'A simple and comfortable private room for short stays, business visits, or quiet overnight rest.'],
    'deluxe' => ['title' => 'Deluxe Stay', 'image' => 'images/r6.jpg', 'price' => 'PHP 1,500', 'meta' => '2 Guests - Quiet ambiance', 'copy' => 'A relaxed room option with extra comfort and a calm finish for couples or solo guests.'],
    'pavilion' => ['title' => 'Pavilion Hall', 'image' => 'images/pavilion.jpg', 'price' => 'PHP 6,000', 'meta' => 'Events - Family gatherings', 'copy' => 'A covered event space suited for family occasions, small celebrations, and private gatherings.'],
    'cottage' => ['title' => 'Garden Cottage', 'image' => 'images/cottage1.jpg', 'price' => 'PHP 900', 'meta' => 'Day use - Relaxed seating', 'copy' => 'A comfortable cottage option for day use, pool visits, and casual group bonding.'],
    'pool' => ['title' => 'Pool Access', 'image' => 'images/pool2.jpg', 'price' => 'PHP 250', 'meta' => 'Day swim - Tropical setting', 'copy' => 'Enjoy a refreshing pool day in a peaceful tropical setting with access to shared facilities.'],
];
$key = $_GET['room'] ?? 'family';
$room = $rooms[$key] ?? $rooms['family'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($room['title']); ?> | Bien Marko's Pension House</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Great+Vibes&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="site-header solid">
        <a class="brand" href="index.php"><span>Bien Marko's</span><strong>Pension House</strong></a>
        <nav class="main-nav"><a href="index.php">Home</a><a class="active" href="accommodations.php">Accommodations</a><a href="about.php">About</a><a href="contact.php">Contact</a></nav>
        <div class="header-actions"><a class="nav-link" href="#login">Log in</a><a class="gold-button small" href="#signup">Sign up</a></div>
    </header>
    <main>
        <section class="page-hero compact-hero">
            <img src="<?php echo htmlspecialchars($room['image']); ?>" alt="<?php echo htmlspecialchars($room['title']); ?>">
            <div class="shade"></div>
            <div><p class="eyebrow">Accommodation Details</p><h1><?php echo htmlspecialchars($room['title']); ?></h1></div>
        </section>
        <section class="room-detail">
            <div class="detail-gallery">
                <img src="<?php echo htmlspecialchars($room['image']); ?>" alt="<?php echo htmlspecialchars($room['title']); ?>">
                <img src="images/pool.jpg" alt="Pool area">
                <img src="images/veranda.jpg" alt="Veranda">
            </div>
            <aside class="detail-panel">
                <span>From</span>
                <strong><?php echo htmlspecialchars($room['price']); ?></strong>
                <p><?php echo htmlspecialchars($room['meta']); ?></p>
                <a class="gold-button" href="contact.php">Send Inquiry</a>
            </aside>
            <div class="detail-copy">
                <h2>About This Stay</h2>
                <p><?php echo htmlspecialchars($room['copy']); ?></p>
                <div class="amenity-grid">
                    <span>Clean linens</span><span>Shared facilities</span><span>Guest assistance</span><span>Peaceful setting</span>
                </div>
            </div>
        </section>
    </main>
    <footer class="site-footer"><div><strong>Bien Marko's Pension House</strong><p>Rooms, cottages, event halls, and pool access.</p></div><nav><a href="index.php">Home</a><a href="accommodations.php">Accommodations</a><a href="about.php">About</a><a href="contact.php">Contact</a></nav></footer>
</body>
</html>
