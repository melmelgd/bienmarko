<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accommodations | Bien Marko's Pension House</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Great+Vibes&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="site-header solid">
        <a class="brand" href="index.php"><span>Bien Marko's</span><strong>Pension House</strong></a>
        <nav class="main-nav">
            <a href="index.php">Home</a>
            <a class="active" href="accommodations.php">Accommodations</a>
            <a href="about.php">About</a>
            <a href="contact.php">Contact</a>
        </nav>
        <div class="header-actions"><a class="nav-link" href="#login">Log in</a><a class="gold-button small" href="#signup">Sign up</a></div>
    </header>

    <main>
        <section class="page-hero">
            <img src="images/hero.jpg" alt="Bien Marko's Pension House">
            <div class="shade"></div>
            <div>
                <p class="eyebrow">Accommodations</p>
                <h1>Explore Your Stay</h1>
            </div>
        </section>

        <section class="rooms-page">
            <div class="section-title">
                <span>Our</span>
                <h2>Accommodations</h2>
            </div>
            <div class="filter-tabs">
                <button class="active" type="button" data-filter="all">All</button>
                <button type="button" data-filter="rooms">Rooms</button>
                <button type="button" data-filter="events">Event Halls</button>
                <button type="button" data-filter="cottages">Cottages</button>
                <button type="button" data-filter="pool">Pool</button>
            </div>

            <div class="room-grid">
                <article class="room-card" data-category="rooms">
                    <img src="images/room1.jpg" alt="Family Room">
                    <div class="room-hover"><span>From</span><strong>PHP 1,800</strong><a href="room-single.php?room=family">View Details</a></div>
                    <h3>Family Room</h3><p>4 Guests - Air-conditioned</p>
                </article>
                <article class="room-card" data-category="rooms">
                    <img src="images/room4.jpg" alt="Standard Room">
                    <div class="room-hover"><span>From</span><strong>PHP 1,200</strong><a href="room-single.php?room=standard">View Details</a></div>
                    <h3>Standard Room</h3><p>2 Guests - Clean essentials</p>
                </article>
                <article class="room-card" data-category="rooms">
                    <img src="images/r6.jpg" alt="Deluxe Stay">
                    <div class="room-hover"><span>From</span><strong>PHP 1,500</strong><a href="room-single.php?room=deluxe">View Details</a></div>
                    <h3>Deluxe Stay</h3><p>2 Guests - Quiet ambiance</p>
                </article>
                <article class="room-card" data-category="events">
                    <img src="images/pavilion.jpg" alt="Pavilion Hall">
                    <div class="room-hover"><span>From</span><strong>PHP 6,000</strong><a href="room-single.php?room=pavilion">View Details</a></div>
                    <h3>Pavilion Hall</h3><p>Events - Family gatherings</p>
                </article>
                <article class="room-card" data-category="cottages">
                    <img src="images/cottage1.jpg" alt="Garden Cottage">
                    <div class="room-hover"><span>From</span><strong>PHP 900</strong><a href="room-single.php?room=cottage">View Details</a></div>
                    <h3>Garden Cottage</h3><p>Day use - Relaxed seating</p>
                </article>
                <article class="room-card" data-category="pool">
                    <img src="images/pool2.jpg" alt="Pool Access">
                    <div class="room-hover"><span>From</span><strong>PHP 250</strong><a href="room-single.php?room=pool">View Details</a></div>
                    <h3>Pool Access</h3><p>Day swim - Tropical setting</p>
                </article>
            </div>
        </section>
    </main>

    <footer class="site-footer"><div><strong>Bien Marko's Pension House</strong><p>Rooms, cottages, event halls, and pool access.</p></div><nav><a href="index.php">Home</a><a href="accommodations.php">Accommodations</a><a href="about.php">About</a><a href="contact.php">Contact</a></nav></footer>
    <script src="script.js"></script>
</body>
</html>
