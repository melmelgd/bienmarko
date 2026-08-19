/* =========================================================
   Bien Marko's Pension House — interactivity
   =========================================================
   Changes from original:
   1. Featured Rooms section — fetches api/featured-rooms.php,
      renders skeleton loaders while waiting, then replaces them
      with real cards. Section stays hidden if no featured rooms
      are returned.
   2. Booking Option Modal — triggered by any [data-book-room]
      link (room hover "Book Now") or the featured card "Book Now".
      Lets guests choose: Log in, Create Account, or Continue as
      Guest. The selected room name is forwarded to book.php as
      a query-string parameter.
   ========================================================= */

document.addEventListener("DOMContentLoaded", () => {

    /* ---------- accommodation filter tabs ---------- */
    const filterButtons = document.querySelectorAll("[data-filter]");
    const cards = document.querySelectorAll("[data-category]");

    filterButtons.forEach((button) => {
        button.addEventListener("click", () => {
            const filter = button.dataset.filter;

            filterButtons.forEach((item) => item.classList.remove("active"));
            button.classList.add("active");

            cards.forEach((card) => {
                card.hidden = filter !== "all" && card.dataset.category !== filter;
            });
        });
    });

    /* ---------- directions modal ---------- */
    const modal = document.querySelector("[data-map-modal]");
    const frame = document.querySelector("[data-map-frame]");
    const openButtons = document.querySelectorAll("[data-open-map]");
    const closeButton = document.querySelector("[data-close-map]");
    const locationButton = document.querySelector("[data-use-location]");
    const destination = "Bien Marko's Pension House, Tabango, Leyte";
    let lastFocusedEl = null;

    function openMapModal() {
        if (!modal) return;
        lastFocusedEl = document.activeElement;
        modal.classList.add("open");
        modal.setAttribute("aria-hidden", "false");
        document.body.classList.add("modal-open");
        if (closeButton) closeButton.focus();
    }

    function closeMapModal() {
        if (!modal) return;
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("modal-open");
        if (lastFocusedEl) lastFocusedEl.focus();
    }

    openButtons.forEach((button) => {
        button.addEventListener("click", openMapModal);
    });

    if (closeButton) {
        closeButton.addEventListener("click", closeMapModal);
    }

    if (modal) {
        modal.addEventListener("click", (event) => {
            if (event.target === modal) closeMapModal();
        });
    }

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeMapModal();
            closeBookingOptionModal();
        }
    });

    if (locationButton && frame) {
        locationButton.addEventListener("click", () => {
            if (!navigator.geolocation) {
                frame.src = `https://www.google.com/maps?q=${encodeURIComponent(destination)}&output=embed`;
                return;
            }

            locationButton.textContent = "Finding Location...";
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const origin = `${position.coords.latitude},${position.coords.longitude}`;
                    frame.src = `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}&output=embed`;
                    locationButton.textContent = "Route Loaded";
                },
                () => {
                    frame.src = `https://www.google.com/maps?q=${encodeURIComponent(destination)}&output=embed`;
                    locationButton.textContent = "Location Unavailable";
                },
                { enableHighAccuracy: true, timeout: 8000 }
            );
        });
    }

    /* ---------- hamburger / mobile drawer ---------- */
    const hamburger = document.getElementById("hamburger");
    const mobileMenu = document.getElementById("mobileMenu");
    const siteHeader = document.getElementById("siteHeader");

    if (hamburger && mobileMenu) {
        const positionMenu = () => {
            if (siteHeader) mobileMenu.style.top = siteHeader.getBoundingClientRect().height + "px";
        };
        positionMenu();
        window.addEventListener("resize", positionMenu);

        const closeMenu = () => {
            mobileMenu.classList.remove("open");
            hamburger.classList.remove("open");
            hamburger.setAttribute("aria-expanded", "false");
            document.body.classList.remove("menu-open");
        };

        hamburger.addEventListener("click", () => {
            positionMenu();
            const isOpen = mobileMenu.classList.toggle("open");
            hamburger.classList.toggle("open", isOpen);
            hamburger.setAttribute("aria-expanded", isOpen ? "true" : "false");
            document.body.classList.toggle("menu-open", isOpen);
        });

        mobileMenu.querySelectorAll("a").forEach((link) => {
            link.addEventListener("click", closeMenu);
        });
    }

    /* ---------- header solid state on scroll ---------- */
    if (siteHeader) {
        const toggleSolid = () => {
            siteHeader.classList.toggle("solid", window.scrollY > 8);
        };
        window.addEventListener("scroll", toggleSolid, { passive: true });
        toggleSolid();
    }

    /* ---------- active nav link on scroll ---------- */
    const sections = document.querySelectorAll("main section[id]");
    const navLinks = document.querySelectorAll(".main-nav a, .mobile-menu a");

    const setActiveLink = () => {
        let currentId = sections[0] ? sections[0].id : "";
        const scrollPos = window.scrollY + 140;

        sections.forEach((section) => {
            if (section.offsetTop <= scrollPos) currentId = section.id;
        });

        navLinks.forEach((link) => {
            const href = link.getAttribute("href").replace("#", "");
            link.classList.toggle("active", href === currentId);
        });
    };

    window.addEventListener("scroll", setActiveLink, { passive: true });
    setActiveLink();

    /* ---------- check-in / check-out date linking ---------- */
    const checkin = document.getElementById("checkin");
    const checkout = document.getElementById("checkout");

    if (checkin && checkout) {
        checkin.addEventListener("change", () => {
            if (checkin.value) {
                checkout.min = checkin.value;
                if (checkout.value && checkout.value < checkin.value) {
                    checkout.value = checkin.value;
                }
            }
        });
    }

    /* ---------- booking strip submit (scrolls to rooms) ---------- */
    const bookingStrip = document.querySelector(".booking-strip");
    const roomsSection = document.getElementById("rooms");
    if (bookingStrip) {
        bookingStrip.addEventListener("submit", (event) => {
            event.preventDefault();
            if (roomsSection) roomsSection.scrollIntoView({ behavior: "smooth" });
        });
    }

    /* =========================================================
       BOOKING OPTION MODAL
       Opens when any [data-book-room] element is clicked.
       Passes the room name forward to book.php via query param.
       ========================================================= */
    const bookingModal   = document.getElementById("bookingOptionModal");
    const bookingBackdrop = document.getElementById("bookingOptionBackdrop");
    const bookingClose   = document.getElementById("bookingOptionClose");
    const bookingRoomName = document.getElementById("bookingOptionRoomName");
    const bookingLoginBtn   = document.getElementById("bookingLoginBtn");
    const bookingRegisterBtn = document.getElementById("bookingRegisterBtn");
    const bookingGuestBtn   = document.getElementById("bookingGuestBtn");

    let bookingModalLastFocus = null;

    function openBookingOptionModal(roomName) {
        if (!bookingModal) return;
        bookingModalLastFocus = document.activeElement;

        // Populate room name label
        if (bookingRoomName) {
            bookingRoomName.textContent = roomName ? "Selected: " + roomName : "";
        }

        // Build URLs — registered users go to user/book.php, guests to user/guest-book.php.
        // Both receive the room name, checkin, and checkout as query params so the
        // destination page can pre-fill those fields without extra clicks.
        const bookParams = [];
        if (roomName)     bookParams.push("room="    + encodeURIComponent(roomName));

        // Forward strip dates if they were filled before opening the modal
        const stripCheckin  = document.getElementById("checkin");
        const stripCheckout = document.getElementById("checkout");
        if (stripCheckin  && stripCheckin.value)  bookParams.push("checkin="  + encodeURIComponent(stripCheckin.value));
        if (stripCheckout && stripCheckout.value) bookParams.push("checkout=" + encodeURIComponent(stripCheckout.value));

        const qs       = bookParams.length ? "?" + bookParams.join("&") : "";
        const bookUrl  = "user/book.php"       + qs;  // for registered users
        const guestUrl = "user/guest-book.php" + qs;  // for guest users

        if (bookingLoginBtn)    bookingLoginBtn.href    = "user/login.php"    + (bookUrl ? "?next=" + encodeURIComponent(bookUrl) : "");
        if (bookingRegisterBtn) bookingRegisterBtn.href = "user/register.php" + (bookUrl ? "?next=" + encodeURIComponent(bookUrl) : "");
        if (bookingGuestBtn)    bookingGuestBtn.href    = guestUrl;

        bookingModal.classList.add("open");
        bookingModal.setAttribute("aria-hidden", "false");
        bookingBackdrop && bookingBackdrop.classList.add("open");
        document.body.classList.add("modal-open");

        // Focus close button
        setTimeout(() => {
            if (bookingClose) bookingClose.focus();
        }, 50);
    }

    function closeBookingOptionModal() {
        if (!bookingModal) return;
        bookingModal.classList.remove("open");
        bookingModal.setAttribute("aria-hidden", "true");
        bookingBackdrop && bookingBackdrop.classList.remove("open");
        document.body.classList.remove("modal-open");
        if (bookingModalLastFocus && typeof bookingModalLastFocus.focus === "function") {
            bookingModalLastFocus.focus();
        }
    }

    if (bookingClose) {
        bookingClose.addEventListener("click", closeBookingOptionModal);
    }

    if (bookingBackdrop) {
        bookingBackdrop.addEventListener("click", closeBookingOptionModal);
    }

    // Wire up static room card "Book Now" links
    function wireBookNowLinks(root) {
        (root || document).querySelectorAll("[data-book-room]").forEach((el) => {
            el.addEventListener("click", (e) => {
                e.preventDefault();
                openBookingOptionModal(el.dataset.bookRoom || "");
            });
        });
    }

    wireBookNowLinks(document);

    /* =========================================================
       FEATURED ROOMS
       1. Show 3 skeleton placeholders immediately while fetching.
       2. On success, replace skeletons with real cards.
       3. On failure or empty list, keep section hidden.
       ========================================================= */
    const featuredSection = document.getElementById("featured");
    const featuredGrid    = document.getElementById("featuredGrid");

    if (featuredSection && featuredGrid) {

        // Helper: PHP price string -> formatted peso
        function formatPrice(price) {
            const n = parseFloat(price);
            if (isNaN(n)) return "₱—";
            return "₱" + n.toLocaleString("en-PH", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        // Build one skeleton placeholder
        function makeSkeleton() {
            const el = document.createElement("div");
            el.className = "featured-skeleton";
            el.innerHTML =
                '<div class="featured-skeleton-media"></div>' +
                '<div class="featured-skeleton-body">' +
                '  <div class="featured-skeleton-line featured-skeleton-line--title"></div>' +
                '  <div class="featured-skeleton-line featured-skeleton-line--sub"></div>' +
                '  <div class="featured-skeleton-line featured-skeleton-line--desc"></div>' +
                '  <div class="featured-skeleton-line featured-skeleton-line--desc2"></div>' +
                '</div>';
            return el;
        }

        // Show 3 skeletons and reveal the section right away
        function showSkeletons() {
            featuredGrid.innerHTML = "";
            for (let i = 0; i < 3; i++) {
                featuredGrid.appendChild(makeSkeleton());
            }
            featuredSection.removeAttribute("hidden");
        }

        // Build one real featured card from API data
        function makeFeaturedCard(room, index) {
            const article = document.createElement("article");
            article.className = "featured-card";
            article.setAttribute("role", "listitem");
            article.dataset.reveal = "";
            article.style.setProperty("--i", index);

            // Media
            let mediaInner = "";
            if (room.image_url) {
                mediaInner += `<img src="${escHtml(room.image_url)}" alt="${escHtml(room.name)}" loading="lazy">`;
            } else {
                mediaInner +=
                    '<div class="featured-card-placeholder">' +
                    '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>' +
                    '</div>';
            }

            mediaInner +=
                '<span class="featured-card-badge">' +
                '<svg viewBox="0 0 24 24" aria-hidden="true" width="11" height="11"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="currentColor"/></svg>' +
                'Featured</span>';

            mediaInner += `<span class="featured-card-price">${formatPrice(room.price)}<small>/night</small></span>`;

            // Hover overlay
            mediaInner +=
                '<div class="featured-card-hover">' +
                '<span>From</span>' +
                `<strong>${formatPrice(room.price)}</strong>` +
                `<a href="#" data-book-room="${escHtml(room.name)}">Book Now</a>` +
                '</div>';

            // Body
            let bodyInner = `<h3>${escHtml(room.name)}</h3>`;

            bodyInner += '<div class="featured-card-meta">';
            bodyInner +=
                '<span>' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>' +
                escHtml(room.capacity + " guest" + (room.capacity === 1 ? "" : "s")) +
                "</span>";
            bodyInner +=
                '<span>' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>' +
                escHtml(room.type) +
                '</span>';
            bodyInner += '</div>';

            if (room.description) {
                bodyInner += `<p class="featured-card-desc">${escHtml(room.description)}</p>`;
            }

            if (room.amenities && room.amenities.length) {
                bodyInner += '<div class="featured-card-amenities">';
                room.amenities.slice(0, 3).forEach((a) => {
                    bodyInner += `<span class="featured-chip">${escHtml(a)}</span>`;
                });
                if (room.amenities.length > 3) {
                    bodyInner += `<span class="featured-chip">+${room.amenities.length - 3} more</span>`;
                }
                bodyInner += '</div>';
            }

            article.innerHTML =
                `<div class="featured-card-media">${mediaInner}</div>` +
                `<div class="featured-card-body">${bodyInner}</div>`;

            return article;
        }

        // Minimal HTML escaper — avoids innerHTML XSS from API data
        function escHtml(str) {
            return String(str)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        }

        // Kick off the fetch + show skeletons simultaneously
        showSkeletons();

        fetch("api/featured-rooms.php")
            .then((res) => {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then((data) => {
                const rooms = (data && data.success && Array.isArray(data.rooms)) ? data.rooms : [];

                featuredGrid.innerHTML = ""; // remove skeletons

                if (rooms.length === 0) {
                    // Nothing featured — hide the whole section cleanly
                    featuredSection.setAttribute("hidden", "");
                    return;
                }

                rooms.forEach((room, i) => {
                    const card = makeFeaturedCard(room, i);
                    featuredGrid.appendChild(card);
                });

                // Wire "Book Now" links inside the freshly rendered cards
                wireBookNowLinks(featuredGrid);

                // Plug newly added [data-reveal] elements into the existing observer
                // (if the observer was already set up). We re-query here rather than
                // re-running the full reveal block, so we don't double-observe anything.
                if (document.body.classList.contains("reveal-ready")) {
                    featuredGrid.querySelectorAll("[data-reveal]").forEach((el) => {
                        // Fire immediately if already in view (common on tall screens)
                        const rect = el.getBoundingClientRect();
                        if (rect.top < window.innerHeight - 60) {
                            el.classList.add("is-visible");
                        } else if (window.__featuredRevealObserver) {
                            window.__featuredRevealObserver.observe(el);
                        } else {
                            el.classList.add("is-visible");
                        }
                    });
                }
            })
            .catch(() => {
                // API unreachable or returned bad data — hide section silently
                featuredGrid.innerHTML = "";
                featuredSection.setAttribute("hidden", "");
            });
    }

    /* ---------- scroll reveal ---------- */
    const revealEls = document.querySelectorAll("[data-reveal]");
    const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (revealEls.length && !prefersReducedMotion && typeof window.IntersectionObserver === "function") {
        document.body.classList.add("reveal-ready");

        const revealAll = () => revealEls.forEach((el) => el.classList.add("is-visible"));
        const safetyNet = window.setTimeout(revealAll, 3000);

        try {
            const observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add("is-visible");
                            observer.unobserve(entry.target);
                        }
                    });
                },
                { threshold: 0.12, rootMargin: "0px 0px -60px 0px" }
            );

            revealEls.forEach((el) => observer.observe(el));

            // Expose observer so the featured-rooms fetch can plug in late cards
            window.__featuredRevealObserver = observer;
        } catch (err) {
            window.clearTimeout(safetyNet);
            revealAll();
        }
    }

});
