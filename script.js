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
   4. Updated design — hero background slider and in-hero
      navigation. Rooms and event-hall categories now open
      separate live listing pages.
   ========================================================= */

document.addEventListener("DOMContentLoaded", () => {
    window.bienMarkoAccommodations = [];
    window.bienMarkoInventoryLoaded = false;
    window.bienMarkoInventoryFailed = false;

    /* ---------- accommodation filter tabs ---------- */
    // Cards are now rendered asynchronously (see the "ALL ACCOMMODATIONS
    // GRID" block below), so this re-queries [data-category] fresh on
    // every click instead of caching a NodeList at load time — otherwise
    // cards added after the initial fetch would never be found.
    const filterButtons = document.querySelectorAll("[data-filter]");
    const roomGridForFilters = document.getElementById("roomGrid");
    let activeAccommodationFilter = "all";

    function applyAccommodationFilter(filter) {
        if (!roomGridForFilters) return;
        roomGridForFilters.querySelectorAll("[data-category]").forEach((card) => {
            card.hidden = filter !== "all" && card.dataset.category !== filter;
        });
    }

    filterButtons.forEach((button) => {
        button.addEventListener("click", () => {
            activeAccommodationFilter = button.dataset.filter;

            filterButtons.forEach((item) => item.classList.remove("active"));
            button.classList.add("active");

            applyAccommodationFilter(activeAccommodationFilter);
        });
    });

    // Keep this optional filter behavior for any page that still includes
    // filter tabs. Home-page category cards now link to dedicated pages.
    document.querySelectorAll("[data-filter-link]").forEach((link) => {
        link.addEventListener("click", () => {
            const wanted = link.dataset.filterLink || "all";
            const tab = document.querySelector('[data-filter="' + wanted + '"]');
            if (tab) tab.click();
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

    /* ---------- hero background slider ---------- */
    const heroSlides = document.querySelectorAll(".hero-slide");
    const heroDots = document.querySelectorAll(".slider-dots .dot");
    const prevSlideBtn = document.getElementById("prevSlide");
    const nextSlideBtn = document.getElementById("nextSlide");
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let currentSlide = 0;
    let sliderTimer = null;

    function showSlide(index) {
        if (!heroSlides.length) return;
        currentSlide = (index + heroSlides.length) % heroSlides.length;
        heroSlides.forEach((slide, i) => slide.classList.toggle("active", i === currentSlide));
        heroDots.forEach((dot, i) => dot.classList.toggle("active", i === currentSlide));
    }

    function startSlider() {
        clearInterval(sliderTimer);
        if (reduceMotion || heroSlides.length < 2) return;
        sliderTimer = setInterval(() => showSlide(currentSlide + 1), 6000);
    }

    if (heroSlides.length) {
        if (nextSlideBtn) nextSlideBtn.addEventListener("click", () => { showSlide(currentSlide + 1); startSlider(); });
        if (prevSlideBtn) prevSlideBtn.addEventListener("click", () => { showSlide(currentSlide - 1); startSlider(); });
        heroDots.forEach((dot, index) => {
            dot.addEventListener("click", () => { showSlide(index); startSlider(); });
        });
        startSlider();
    }

    /* ---------- mobile menu (nav inside the hero) ---------- */
    const menuBtn = document.getElementById("menuBtn");
    const navLinksWrap = document.getElementById("navLinks");

    if (menuBtn && navLinksWrap) {
        const closeMenu = () => {
            navLinksWrap.classList.remove("mobile-open");
            menuBtn.classList.remove("open");
            menuBtn.setAttribute("aria-expanded", "false");
            menuBtn.setAttribute("aria-label", "Open menu");
        };

        menuBtn.addEventListener("click", () => {
            const isOpen = navLinksWrap.classList.toggle("mobile-open");
            menuBtn.classList.toggle("open", isOpen);
            menuBtn.setAttribute("aria-expanded", isOpen ? "true" : "false");
            menuBtn.setAttribute("aria-label", isOpen ? "Close menu" : "Open menu");
        });

        navLinksWrap.querySelectorAll("a").forEach((link) => {
            link.addEventListener("click", closeMenu);
        });

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closeMenu();
        });

        // leaving the mobile breakpoint resets the menu state
        window.addEventListener("resize", () => {
            if (window.innerWidth > 800) closeMenu();
        });
    }

    /* ---------- active nav link on scroll ---------- */
    const sections = document.querySelectorAll("header#home, main section[id]");
    const navLinks = document.querySelectorAll(".nav-links > a");

    // Sections that live "inside" a nav item
    const navGroup = { featured: "accommodations", rooms: "accommodations" };

    const setActiveLink = () => {
        let currentId = "home";
        const scrollPos = window.scrollY + 140;

        sections.forEach((section) => {
            // hidden sections (display:none) report offsetTop 0 — skip them
            if (section.offsetParent === null && section.id !== "home") return;
            if (section.offsetTop <= scrollPos) currentId = section.id;
        });

        // at the very bottom of the page, highlight the last nav item
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
            currentId = "contact";
        }

        currentId = navGroup[currentId] || currentId;

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

    /* =========================================================
       BOOKING STRIP DATE PICKERS
       Turns #checkin / #checkout into calendar popovers. Since the
       accommodation selector now chooses a category, exact availability
       is checked for each matching item after search. The hidden inputs
       retain ISO dates for the search and booking links.
       ========================================================= */
    (() => {
        const accomSelect = document.getElementById("bookingAccommodationSelect");
        const guestSelect = document.getElementById("bookingGuestSelect");
        const ciHidden = document.getElementById("checkin");
        const coHidden = document.getElementById("checkout");
        if (!accomSelect || !ciHidden || !coHidden) return;

        let bsAvailability = null;   // { capacity, isPool, bookings: [...] } for the selected room, or null
        let bsOpenPicker = null;     // "checkin" | "checkout" | null
        let bsViewMonth = { checkin: null, checkout: null };

        function bsPad(n) { return n < 10 ? "0" + n : "" + n; }
        function bsISO(y, m, d) { return y + "-" + bsPad(m + 1) + "-" + bsPad(d); }
        function bsParseISO(s) {
            if (!s) return null;
            const p = s.split("-");
            return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        }
        function bsFormatDisplay(iso) {
            const d = bsParseISO(iso);
            return d ? d.toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "";
        }

        // Lower bound of the selected guest range ("3-4" -> 3), defaulting
        // to 1 — the strip only offers ranges, not an exact headcount, so
        // this is a conservative stand-in for pool capacity blocking.
        function bsGuestsWanted() {
            if (!guestSelect || !guestSelect.value) return 1;
            const n = parseInt(guestSelect.value, 10);
            return isNaN(n) || n < 1 ? 1 : n;
        }

        // Map of ISO date -> guests already booked that day, built from
        // bsAvailability.bookings. A date is "covered" by a booking when
        // check_in <= date < check_out (checkout day itself is free —
        // same half-open range the booking APIs use for conflict checks).
        function bsBuildBookedMap() {
            const map = {};
            if (!bsAvailability) return map;
            bsAvailability.bookings.forEach((b) => {
                const cur = bsParseISO(b.check_in);
                const end = bsParseISO(b.check_out);
                while (cur < end) {
                    const iso = bsISO(cur.getFullYear(), cur.getMonth(), cur.getDate());
                    map[iso] = (map[iso] || 0) + (bsAvailability.isPool ? b.guests_count : 1);
                    cur.setDate(cur.getDate() + 1);
                }
            });
            return map;
        }

        // Whether `iso` is unavailable as a stay-night for `guestsWanted`.
        // Every accommodation type but the Swimming Pool is exclusive (any
        // existing booking blocks it); the pool is shared up to capacity.
        function bsIsBlocked(iso, bookedMap, guestsWanted) {
            if (!bsAvailability) return false;
            const used = bookedMap[iso] || 0;
            if (bsAvailability.isPool) return (used + Math.max(1, guestsWanted)) > bsAvailability.capacity;
            return used > 0;
        }

        // Earliest blocked date strictly after `fromIso` — check-out can
        // never be pushed past this, or the stay would span an occupied night.
        function bsFirstBlockedAfter(fromIso, bookedMap, guestsWanted) {
            if (!bsAvailability || !fromIso) return null;
            const d = bsParseISO(fromIso);
            d.setDate(d.getDate() + 1);
            for (let i = 0; i < 730; i++) {
                const iso = bsISO(d.getFullYear(), d.getMonth(), d.getDate());
                if (bsIsBlocked(iso, bookedMap, guestsWanted)) return iso;
                d.setDate(d.getDate() + 1);
            }
            return null;
        }

        function bsRenderCalendar(which) {
            const pop = document.getElementById(which === "checkin" ? "checkinPop" : "checkoutPop");
            const otherIso = document.getElementById(which === "checkin" ? "checkout" : "checkin").value;

            if (which === "checkout" && !otherIso) {
                pop.innerHTML = '<div class="date-picker-note">Please select a check-in date first.</div>';
                return;
            }
            if (!accomSelect.value) {
                pop.innerHTML = '<div class="date-picker-note">Please select Rooms or Event Halls first.</div>';
                return;
            }

            const selectedIso = document.getElementById(which === "checkin" ? "checkin" : "checkout").value;
            const guestsWanted = bsGuestsWanted();
            const bookedMap = bsBuildBookedMap();

            if (!bsViewMonth[which]) {
                const base = bsParseISO(selectedIso) || bsParseISO(otherIso) || new Date();
                bsViewMonth[which] = { y: base.getFullYear(), m: base.getMonth() };
            }
            const view = bsViewMonth[which];

            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let minDate, maxIso = null;
            if (which === "checkout") {
                minDate = bsParseISO(otherIso);
                minDate.setDate(minDate.getDate() + 1);
                maxIso = bsFirstBlockedAfter(otherIso, bookedMap, guestsWanted);
            } else {
                minDate = today;
            }

            const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
            const startWeekday = new Date(view.y, view.m, 1).getDay();
            const daysInMonth  = new Date(view.y, view.m + 1, 0).getDate();

            let html = "";
            html += '<div class="date-picker-head">';
            html += '<button type="button" class="date-picker-nav" data-bs-nav="prev" data-bs-which="' + which + '" aria-label="Previous month">&#8249;</button>';
            html += '<span class="date-picker-month">' + monthNames[view.m] + " " + view.y + "</span>";
            html += '<button type="button" class="date-picker-nav" data-bs-nav="next" data-bs-which="' + which + '" aria-label="Next month">&#8250;</button>';
            html += "</div>";
            html += '<div class="date-picker-grid date-picker-weekdays">';
            ["S","M","T","W","T","F","S"].forEach((w) => { html += "<span>" + w + "</span>"; });
            html += "</div>";
            html += '<div class="date-picker-grid">';
            for (let i = 0; i < startWeekday; i++) html += '<span class="date-picker-cell is-empty"></span>';
            for (let day = 1; day <= daysInMonth; day++) {
                const iso = bsISO(view.y, view.m, day);
                const cellDate = new Date(view.y, view.m, day);
                const visualBooked = bsIsBlocked(iso, bookedMap, guestsWanted);
                const beforeMin = cellDate < minDate;
                const afterMax  = maxIso ? (iso > maxIso) : false;

                // Check-in must land on a genuinely open night. Check-out
                // just needs to be after check-in and not stretch past the
                // next occupied night — the checkout day itself is fine
                // even if another booking starts that same day.
                const disabled = which === "checkin"
                    ? (visualBooked || beforeMin)
                    : (beforeMin || afterMax);

                const isSelected = iso === selectedIso;
                let cls = "date-picker-cell";
                if (disabled) cls += " is-disabled";
                if (isSelected) cls += " is-selected";
                if (visualBooked && !beforeMin) cls += " is-booked";

                html += '<button type="button" class="' + cls + '"' +
                    (disabled ? " disabled" : (' data-bs-pick="' + iso + '" data-bs-which="' + which + '"')) +
                    (visualBooked && !beforeMin ? ' title="Already booked"' : "") +
                    ">" + day + "</button>";
            }
            html += "</div>";
            html += '<div class="date-picker-legend">Exact availability is checked for every matching item after search.</div>';

            pop.innerHTML = html;
        }

        function bsCloseDatePickers() {
            bsOpenPicker = null;
            document.getElementById("checkinPicker").classList.remove("is-open");
            document.getElementById("checkoutPicker").classList.remove("is-open");
        }

        function bsOpenDatePicker(which) {
            bsCloseDatePickers();
            bsOpenPicker = which;
            bsRenderCalendar(which);
            document.getElementById(which === "checkin" ? "checkinPicker" : "checkoutPicker").classList.add("is-open");
        }

        document.getElementById("checkinTrigger").addEventListener("click", (e) => {
            e.stopPropagation();
            if (bsOpenPicker === "checkin") { bsCloseDatePickers(); return; }
            bsOpenDatePicker("checkin");
        });
        document.getElementById("checkoutTrigger").addEventListener("click", (e) => {
            e.stopPropagation();
            if (bsOpenPicker === "checkout") { bsCloseDatePickers(); return; }
            bsOpenDatePicker("checkout");
        });
        document.addEventListener("click", (e) => {
            if (bsOpenPicker && !e.target.closest("#checkinPicker") && !e.target.closest("#checkoutPicker")) {
                bsCloseDatePickers();
            }
        });
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && bsOpenPicker) bsCloseDatePickers();
        });

        function bsSelectDate(which, iso) {
            const hidden = document.getElementById(which === "checkin" ? "checkin" : "checkout");
            const text = document.getElementById(which === "checkin" ? "checkinText" : "checkoutText");
            hidden.value = iso;
            text.textContent = bsFormatDisplay(iso);
            text.classList.remove("is-placeholder");

            if (which === "checkin") {
                // If the previously-picked check-out no longer fits (it's
                // now on/before the new check-in, or beyond the next
                // occupied night), clear it so the visitor re-picks one.
                const coEl = document.getElementById("checkout");
                if (coEl.value) {
                    const bookedMap = bsBuildBookedMap();
                    const guestsWanted = bsGuestsWanted();
                    const maxIso = bsFirstBlockedAfter(iso, bookedMap, guestsWanted);
                    if (coEl.value <= iso || (maxIso && coEl.value > maxIso)) {
                        coEl.value = "";
                        const coText = document.getElementById("checkoutText");
                        coText.textContent = "Select date";
                        coText.classList.add("is-placeholder");
                    }
                }
                bsViewMonth.checkout = null;
            }

            bsCloseDatePickers();

            // The check-in/check-out linking listener declared just above
            // this block keeps working off this 'change' event unchanged.
            hidden.dispatchEvent(new Event("change", { bubbles: true }));
        }

        const stripEl = document.querySelector(".booking-strip");
        if (stripEl) {
            stripEl.addEventListener("click", (e) => {
                const navBtn = e.target.closest("[data-bs-nav]");
                if (navBtn) {
                    e.stopPropagation();
                    const which = navBtn.getAttribute("data-bs-which");
                    const dir = navBtn.getAttribute("data-bs-nav") === "next" ? 1 : -1;
                    const view = bsViewMonth[which];
                    let m = view.m + dir, y = view.y;
                    if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
                    bsViewMonth[which] = { y, m };
                    bsRenderCalendar(which);
                    return;
                }
                const pickBtn = e.target.closest("[data-bs-pick]");
                if (pickBtn) {
                    e.stopPropagation();
                    bsSelectDate(pickBtn.getAttribute("data-bs-which"), pickBtn.getAttribute("data-bs-pick"));
                }
            });
        }

        function bsResetDates() {
            ciHidden.value = "";
            coHidden.value = "";
            const ciText = document.getElementById("checkinText");
            const coText = document.getElementById("checkoutText");
            ciText.textContent = "Select date"; ciText.classList.add("is-placeholder");
            coText.textContent = "Select date"; coText.classList.add("is-placeholder");
        }

        // The selector now represents a category rather than one specific
        // accommodation, so exact dates are checked for every matching item
        // when the guest submits the availability search.
        function bsLoadAvailability() {
            bsAvailability = null;
            bsViewMonth = { checkin: null, checkout: null };
            bsCloseDatePickers();
            bsResetDates();
        }

        accomSelect.addEventListener("change", () => {
            bsLoadAvailability();
        });

        // Pool bookings are shared by guest count, so changing the guest
        // range can invalidate an already-picked date range — re-check it.
        if (guestSelect) {
            guestSelect.addEventListener("change", () => {
                if (!bsAvailability || !bsAvailability.isPool) return;
                const ci = ciHidden.value, co = coHidden.value;
                if (!ci || !co) return;

                const bookedMap = bsBuildBookedMap();
                const guestsWanted = bsGuestsWanted();
                let cur = bsParseISO(ci);
                const end = bsParseISO(co);
                let stillOk = true;
                while (cur < end) {
                    const iso = bsISO(cur.getFullYear(), cur.getMonth(), cur.getDate());
                    if (bsIsBlocked(iso, bookedMap, guestsWanted)) { stillOk = false; break; }
                    cur.setDate(cur.getDate() + 1);
                }
                if (!stillOk) bsResetDates();
            });
        }
    })();

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
    window.bienMarkoOpenBookingOption = openBookingOptionModal;

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

    /* =========================================================
       ACCOMMODATIONS DATA
       One shared fetch of api/accommodations.php feeds the listing
       grid, when present, and the category-based availability search:
       1. The optional accommodation grid (#roomGrid), when present.
       2. The Rooms/Event Halls search results.
       ========================================================= */
    const roomGrid = document.getElementById("roomGrid");
    const bookingAccommodationSelect = document.getElementById("bookingAccommodationSelect");

    if (roomGrid || bookingAccommodationSelect) {

        // Helper: PHP price string -> "₱2,000" (no suffix)
        function roomFormatPeso(price) {
            const n = parseFloat(price);
            if (isNaN(n)) return "₱—";
            return "₱" + n.toLocaleString("en-PH", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        // Helper: PHP price string -> "PHP 2,000" (matches the room-hover style)
        function roomFormatPhp(price) {
            const n = parseFloat(price);
            if (isNaN(n)) return "PHP —";
            return "PHP " + n.toLocaleString("en-PH", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        // Rooms bill per night, pool access bills per person, everything
        // else (venues, cottages, custom types) bills per day.
        function roomPriceSuffix(category) {
            if (category === "rooms") return "/night";
            if (category === "pool") return "/person";
            return "/day";
        }

        // Builds the short line under the room name, e.g.
        // "4 Guests · Room 101" or "6 Guests · Wi-Fi, Air Conditioning"
        function roomSubtitle(room) {
            const capacity = Number(room.capacity) || 0;
            const parts = [capacity + " Guest" + (capacity === 1 ? "" : "s")];

            if (room.category === "rooms" && room.room_number) {
                parts.push("Room " + room.room_number);
            } else if (room.amenities && room.amenities.length) {
                parts.push(room.amenities.slice(0, 2).join(", "));
            } else if (room.type) {
                parts.push(room.type);
            }

            return parts.join(" · ");
        }

        // Minimal HTML escaper — avoids innerHTML XSS from API data
        function roomEscHtml(str) {
            return String(str)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        }

        function makeRoomSkeleton() {
            const el = document.createElement("div");
            el.className = "room-skeleton";
            el.innerHTML =
                '<div class="room-skeleton-media"></div>' +
                '<div class="room-skeleton-line room-skeleton-line--title"></div>' +
                '<div class="room-skeleton-line room-skeleton-line--sub"></div>';
            return el;
        }

        function showRoomSkeletons() {
            roomGrid.innerHTML = "";
            for (let i = 0; i < 6; i++) {
                roomGrid.appendChild(makeRoomSkeleton());
            }
        }

        function showRoomGridMessage(message) {
            roomGrid.innerHTML = "";
            const p = document.createElement("p");
            p.className = "room-grid-empty";
            p.textContent = message;
            roomGrid.appendChild(p);
        }

        // Build one real accommodation card from API data. Same markup
        // and classes as the original hand-authored cards (room-card,
        // room-media, price-tag, room-hover, data-category,
        // data-book-room) so the existing filter tabs, hover reveal,
        // and Book Now wiring all keep working unchanged.
        function makeRoomCard(room, index) {
            const article = document.createElement("article");
            article.className = "room-card";
            article.dataset.category = room.category || "other";
            article.dataset.reveal = "";
            article.style.setProperty("--i", index % 4);

            let mediaInner = "";
            if (room.image_url) {
                mediaInner += `<img src="${roomEscHtml(room.image_url)}" alt="${roomEscHtml(room.name)}" loading="lazy">`;
            } else {
                mediaInner +=
                    '<div class="room-card-placeholder">' +
                    '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>' +
                    '</div>';
            }

            mediaInner += `<span class="price-tag">${roomFormatPeso(room.price)}${roomPriceSuffix(room.category)}</span>`;

            if (room.status === "booked") {
                mediaInner += '<span class="room-status-tag">Currently Booked</span>';
            }

            mediaInner +=
                '<div class="room-hover">' +
                '<span>From</span>' +
                `<strong>${roomFormatPhp(room.price)}</strong>` +
                `<a href="#" data-book-room="${roomEscHtml(room.name)}">Book Now</a>` +
                '</div>';

            article.innerHTML =
                `<div class="room-media">${mediaInner}</div>` +
                `<h3>${roomEscHtml(room.name)}</h3>` +
                `<p>${roomEscHtml(roomSubtitle(room))}</p>`;

            return article;
        }

        if (roomGrid) showRoomSkeletons();

        fetch("api/accommodations.php")
            .then((res) => {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then((data) => {
                const rooms = (data && data.success && Array.isArray(data.rooms)) ? data.rooms : [];

                window.bienMarkoAccommodations = rooms;
                window.bienMarkoInventoryLoaded = true;
                window.bienMarkoInventoryFailed = false;
                window.dispatchEvent(new CustomEvent("bienmarko:inventory-loaded", { detail: rooms }));

                if (!roomGrid) return;

                if (rooms.length === 0) {
                    showRoomGridMessage("No accommodations are available to view right now. Please check back soon.");
                    return;
                }

                roomGrid.innerHTML = "";
                rooms.forEach((room, i) => {
                    roomGrid.appendChild(makeRoomCard(room, i));
                });

                // Wire "Book Now" links inside the freshly rendered cards
                wireBookNowLinks(roomGrid);

                // Respect whichever filter tab is already active (in case
                // the visitor clicked one before the fetch resolved)
                applyAccommodationFilter(activeAccommodationFilter);

                // Plug newly added [data-reveal] elements into the existing
                // scroll-reveal observer, same approach as the featured cards.
                if (document.body.classList.contains("reveal-ready")) {
                    roomGrid.querySelectorAll("[data-reveal]").forEach((el) => {
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
                window.bienMarkoAccommodations = [];
                window.bienMarkoInventoryLoaded = true;
                window.bienMarkoInventoryFailed = true;
                window.dispatchEvent(new CustomEvent("bienmarko:inventory-loaded", { detail: [] }));
                if (roomGrid) {
                    showRoomGridMessage("We couldn't load accommodations right now. Please refresh the page or check back shortly.");
                }
            });
    }

    /* =========================================================
       TESTIMONIALS
       Populated from api/testimonials.php — guest feedback an admin
       has explicitly published from admin/feedback.php. Same
       skeleton-then-swap approach as Featured Rooms above; the
       section stays hidden if nothing has been published yet.
       ========================================================= */
    const testimonialsSection = document.getElementById("testimonials");
    const feedbackGrid        = document.getElementById("feedbackGrid");

    if (testimonialsSection && feedbackGrid) {

        // Minimal HTML escaper — avoids innerHTML XSS from API data
        function escHtmlTestimonial(str) {
            return String(str)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        }

        function makeFeedbackSkeleton() {
            const el = document.createElement("div");
            el.className = "feedback-skeleton";
            el.innerHTML =
                '<div class="feedback-skeleton-line feedback-skeleton-line--rating"></div>' +
                '<div class="feedback-skeleton-line feedback-skeleton-line--text1"></div>' +
                '<div class="feedback-skeleton-line feedback-skeleton-line--text2"></div>' +
                '<div class="feedback-skeleton-line feedback-skeleton-line--text3"></div>' +
                '<div class="feedback-skeleton-line feedback-skeleton-line--name"></div>' +
                '<div class="feedback-skeleton-line feedback-skeleton-line--ctx"></div>';
            return el;
        }

        function showFeedbackSkeletons() {
            feedbackGrid.innerHTML = "";
            for (let i = 0; i < 3; i++) {
                feedbackGrid.appendChild(makeFeedbackSkeleton());
            }
            testimonialsSection.removeAttribute("hidden");
        }

        function makeTestimonialCard(item, index) {
            const article = document.createElement("article");
            article.dataset.reveal = "";
            article.style.setProperty("--i", index);

            const ratingNum = Math.max(0, Math.min(5, Number(item.rating) || 0));
            const rating = ratingNum.toFixed(1);
            const filled = Math.round(ratingNum);
            const starsHtml =
                "&#9733;".repeat(filled) +
                (filled < 5 ? '<span class="off">' + "&#9733;".repeat(5 - filled) + "</span>" : "");

            article.innerHTML =
                '<span class="quote-mark" aria-hidden="true">&rdquo;</span>' +
                `<span class="stars" role="img" aria-label="${rating} out of 5">${starsHtml}</span>` +
                `<p>"${escHtmlTestimonial(item.message)}"</p>` +
                `<strong>${escHtmlTestimonial(item.name)}</strong>` +
                `<em>${escHtmlTestimonial(item.context)}</em>`;

            return article;
        }

        showFeedbackSkeletons();

        fetch("api/testimonials.php")
            .then((res) => {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then((data) => {
                const items = (data && data.success && Array.isArray(data.testimonials)) ? data.testimonials : [];

                feedbackGrid.innerHTML = ""; // remove skeletons

                if (items.length === 0) {
                    // Nothing published yet — hide the whole section cleanly
                    testimonialsSection.setAttribute("hidden", "");
                    return;
                }

                items.forEach((item, i) => {
                    feedbackGrid.appendChild(makeTestimonialCard(item, i));
                });

                // Plug newly added [data-reveal] elements into the existing observer
                // (if it's already set up) — same hand-off used by Featured Rooms.
                if (document.body.classList.contains("reveal-ready")) {
                    feedbackGrid.querySelectorAll("[data-reveal]").forEach((el) => {
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
                feedbackGrid.innerHTML = "";
                testimonialsSection.setAttribute("hidden", "");
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