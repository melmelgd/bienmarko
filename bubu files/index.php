<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bien Marko's Pension House | Online Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Great+Vibes&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="Bien Marko's Pension House home">
            <span>Bien Marko's</span>
            <strong>Pension House</strong>
        </a>
        <nav class="main-nav" aria-label="Primary navigation">
            <a class="active" href="index.php">Home</a>
            <a href="accommodations.php">Accommodations</a>
            <a href="about.php">About</a>
            <a href="contact.php">Contact</a>
        </nav>
        <div class="header-actions">
            <a class="nav-link" href="#login">Log in</a>
            <a class="gold-button small" href="#signup">Sign up</a>
        </div>
    </header>

    <main>
        <section class="home-hero">
            <img src="images/veranda.jpg" alt="Bien Marko's Pension House covered lounge">
            <div class="shade"></div>
            <div class="hero-center">
                <p>Bien Marko's</p>
                <h1>Pension House</h1>
                <h2>Your Stay, Your Way</h2>
                <span>Book rooms, cottages, event spaces, and pool access in a few clear steps.</span>
                <a class="outline-light" href="accommodations.php">Book Now</a>
            </div>
        </section>

        <form class="booking-strip" aria-label="Check availability">
            <label>
                <span>Choose Date</span>
                <input type="text" value="Jun 12 - Jun 13" aria-label="Choose date">
            </label>
            <label>
                <span>Adult</span>
                <input type="number" value="1" min="1" aria-label="Adults">
            </label>
            <label>
                <span>Children</span>
                <input type="number" value="0" min="0" aria-label="Children">
            </label>
            <button type="submit">Check Availability</button>
        </form>

        <section class="intro-block">
            <div>
                <p class="eyebrow">Welcome to Bien Marko's</p>
                <h2>A calm private stay for rest, gatherings, and easy days by the pool.</h2>
            </div>
            <p>Bien Marko's Pension House brings together practical comfort and a quiet tropical setting. Guests can book clean rooms, reserve cottages, plan small events, or enjoy a simple pool day with family and friends.</p>
        </section>

        <section class="split-feature">
            <div class="split-copy">
                <p class="eyebrow">Designed for simple stays</p>
                <h2>Comfort, clean spaces, and warm local hospitality.</h2>
                <p>Choose the accommodation that fits your visit, review the details, then send your booking inquiry without losing your place on the site.</p>
                <a class="gold-button" href="about.php">Explore About</a>
            </div>
            <div class="image-stack">
                <img src="images/pool.jpg" alt="Swimming pool">
                <img src="images/bm2.jpg" alt="Bien Marko's Pension House exterior">
            </div>
        </section>

        <section class="accommodation-preview">
            <div class="section-title">
                <span>Our</span>
                <h2>Accommodations</h2>
            </div>
            <div class="room-grid compact">
                <article class="room-card">
                    <img src="images/room1.jpg" alt="Family room">
                    <div class="room-hover">
                        <span>From</span>
                        <strong>PHP 1,800</strong>
                        <a href="room-single.php?room=family">View Details</a>
                    </div>
                    <h3>Family Room</h3>
                    <p>4 Guests - Air-conditioned</p>
                </article>
                <article class="room-card">
                    <img src="images/pavilion.jpg" alt="Pavilion hall">
                    <div class="room-hover">
                        <span>From</span>
                        <strong>PHP 6,000</strong>
                        <a href="room-single.php?room=pavilion">View Details</a>
                    </div>
                    <h3>Pavilion Hall</h3>
                    <p>Events - Gatherings</p>
                </article>
                <article class="room-card">
                    <img src="images/pool2.jpg" alt="Pool access">
                    <div class="room-hover">
                        <span>From</span>
                        <strong>PHP 250</strong>
                        <a href="room-single.php?room=pool">View Details</a>
                    </div>
                    <h3>Pool Access</h3>
                    <p>Day swim - Tropical setting</p>
                </article>
            </div>
            <div class="center-action">
                <a class="outline-dark" href="accommodations.php">View All Accommodations</a>
            </div>
        </section>

        <section class="policies-section">
            <div class="section-title">
                <span>Policies</span>
                <h2>A Harmonious Stay</h2>
            </div>
            <div class="policy-list">
                <article>
                    <span></span>
                    <div>
                        <h3>No Loud Voices</h3>
                        <p>Please maintain a peaceful volume, especially in hallways and common areas.</p>
                    </div>
                </article>
                <article>
                    <span></span>
                    <div>
                        <h3>Keep the Area Clean</h3>
                        <p>Use trash bins and help keep rooms, cottages, and pool areas pleasant for everyone.</p>
                    </div>
                </article>
                <article>
                    <span></span>
                    <div>
                        <h3>Curfew</h3>
                        <p>The main gate is locked at 11:00 PM. Please coordinate with the front desk for late arrival.</p>
                    </div>
                </article>
            </div>
        </section>

        <section class="booking-cta">
            <img src="images/veranda.jpg" alt="Covered common area">
            <div class="shade"></div>
            <div>
                <h2>Your Stay, Your Way</h2>
                <h3>Book with Ease</h3>
                <p>Choose a room, venue, cottage, or pool visit and complete your inquiry in a few clear steps.</p>
                <a class="outline-light" href="accommodations.php">Book Now</a>
            </div>
        </section>

        <section class="location-section">
            <div>
                <p class="eyebrow">Location</p>
                <h2>Find Bien Marko's Pension House.</h2>
                <p>Open the map in a modal and request directions from your current location without leaving the page.</p>
            </div>
            <button class="gold-button" type="button" data-open-map>Get Directions</button>
        </section>

        <section class="feedback-section">
            <div class="section-title">
                <span>Testimonials</span>
                <h2>What Our Guest Say</h2>
            </div>
            <div class="feedback-grid">
                <article>
                    <span>5.0</span>
                    <p>"The place feels private and relaxing. The rooms were clean, and the pool area made the stay worth it."</p>
                    <strong>Mariel D.</strong>
                </article>
                <article>
                    <span>5.0</span>
                    <p>"Perfect for family bonding. Staff were accommodating and the surroundings were peaceful."</p>
                    <strong>Jonas R.</strong>
                </article>
                <article>
                    <span>5.0</span>
                    <p>"Simple, elegant, and comfortable. Booking was easy and the location was convenient for our group."</p>
                    <strong>Alyssa M.</strong>
                </article>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div>
            <strong>Bien Marko's Pension House</strong>
            <p>Rooms, cottages, event halls, and pool access in one elegant booking experience.</p>
        </div>
        <nav>
            <a href="index.php">Home</a>
            <a href="accommodations.php">Accommodations</a>
            <a href="about.php">About</a>
            <a href="contact.php">Contact</a>
        </nav>
    </footer>

    <div class="map-modal" data-map-modal aria-hidden="true">
        <div class="map-dialog" role="dialog" aria-modal="true" aria-labelledby="map-title">
            <button class="modal-close" type="button" data-close-map aria-label="Close map">x</button>
            <div class="modal-copy">
                <p class="eyebrow">Directions</p>
                <h2 id="map-title">Route to Bien Marko's</h2>
                <p>Use your current location to preview the route inside this page.</p>
                <button class="gold-button" type="button" data-use-location>Use My Current Location</button>
            </div>
            <iframe title="Bien Marko's Pension House map" data-map-frame src="https://www.google.com/maps?q=Bien%20Marko%27s%20Pension%20House&output=embed" loading="lazy"></iframe>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>
