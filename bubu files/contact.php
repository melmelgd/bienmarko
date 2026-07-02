<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact | Bien Marko's Pension House</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Great+Vibes&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="site-header solid">
        <a class="brand" href="index.php"><span>Bien Marko's</span><strong>Pension House</strong></a>
        <nav class="main-nav"><a href="index.php">Home</a><a href="accommodations.php">Accommodations</a><a href="about.php">About</a><a class="active" href="contact.php">Contact</a></nav>
        <div class="header-actions"><a class="nav-link" href="#login">Log in</a><a class="gold-button small" href="#signup">Sign up</a></div>
    </header>
    <main>
        <section class="page-hero compact-hero">
            <img src="images/bm3.jpg" alt="Bien Marko's Pension House">
            <div class="shade"></div>
            <div><p class="eyebrow">Contact</p><h1>Start Your Booking</h1></div>
        </section>
        <section class="contact-page">
            <div class="contact-info">
                <p class="eyebrow">Get in touch</p>
                <h2>Send your inquiry and preferred dates.</h2>
                <p>For rooms, cottages, event halls, and pool access, share your details and the team can confirm availability.</p>
                <button class="gold-button" type="button" data-open-map>Get Directions</button>
            </div>
            <form class="contact-form">
                <label><span>Name</span><input type="text" placeholder="Your name"></label>
                <label><span>Email</span><input type="email" placeholder="you@example.com"></label>
                <label><span>Accommodation</span><select><option>Room</option><option>Event Hall</option><option>Cottage</option><option>Pool Access</option></select></label>
                <label><span>Message</span><textarea placeholder="Preferred date, number of guests, and notes"></textarea></label>
                <button class="gold-button" type="submit">Send Inquiry</button>
            </form>
        </section>
    </main>
    <footer class="site-footer"><div><strong>Bien Marko's Pension House</strong><p>Rooms, cottages, event halls, and pool access.</p></div><nav><a href="index.php">Home</a><a href="accommodations.php">Accommodations</a><a href="about.php">About</a><a href="contact.php">Contact</a></nav></footer>
    <div class="map-modal" data-map-modal aria-hidden="true"><div class="map-dialog" role="dialog" aria-modal="true" aria-labelledby="map-title"><button class="modal-close" type="button" data-close-map aria-label="Close map">x</button><div class="modal-copy"><p class="eyebrow">Directions</p><h2 id="map-title">Route to Bien Marko's</h2><p>Use your current location to preview the route inside this page.</p><button class="gold-button" type="button" data-use-location>Use My Current Location</button></div><iframe title="Bien Marko's Pension House map" data-map-frame src="https://www.google.com/maps?q=Bien%20Marko%27s%20Pension%20House&output=embed" loading="lazy"></iframe></div></div>
    <script src="script.js"></script>
</body>
</html>
