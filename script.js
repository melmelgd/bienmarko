/* =========================================================
   Bien Marko's Pension House — interactivity
   Filter + map-modal logic kept as supplied in the reference
   script.js; hamburger, header state, date linking, and
   scroll-reveal added to match the rest of the page.
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
        if (event.key === "Escape") closeMapModal();
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

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closeMenu();
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

    /* ---------- booking strip submit (no backend yet) ---------- */
    const bookingStrip = document.querySelector(".booking-strip");
    const roomsSection = document.getElementById("rooms");
    if (bookingStrip) {
        bookingStrip.addEventListener("submit", (event) => {
            event.preventDefault();
            if (roomsSection) roomsSection.scrollIntoView({ behavior: "smooth" });
        });
    }

    /* ---------- scroll reveal ---------- */
    const revealEls = document.querySelectorAll("[data-reveal]");
    const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (revealEls.length && !prefersReducedMotion && typeof window.IntersectionObserver === "function") {
        // Only now does CSS start hiding [data-reveal] elements —
        // see the body.reveal-ready gate in style.css. Everything
        // below is defensive: if observer setup throws for any
        // reason, the unconditional safety-net timeout (registered
        // first, before the risky part) still force-reveals
        // everything rather than leaving content stuck invisible.
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
        } catch (err) {
            window.clearTimeout(safetyNet);
            revealAll();
        }
    }

});