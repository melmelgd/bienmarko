/* Search accommodation categories (or all of them) for the selected dates and guests. */
document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("booking-strip");
    const typeSelect = document.getElementById("bookingAccommodationSelect");
    const checkinInput = document.getElementById("checkin");
    const checkoutInput = document.getElementById("checkout");
    const guestSelect = document.getElementById("bookingGuestSelect");
    const results = document.getElementById("availabilityResults");
    const resultsTitle = document.getElementById("availabilityResultsTitle");
    const summary = document.getElementById("availabilityResultsSummary");
    const grid = document.getElementById("availabilityResultsGrid");

    if (!form || !typeSelect || !checkinInput || !checkoutInput || !guestSelect || !results || !resultsTitle || !summary || !grid) return;

    let inventory = Array.isArray(window.bienMarkoAccommodations) ? window.bienMarkoAccommodations : [];
    let inventoryLoaded = Boolean(window.bienMarkoInventoryLoaded);
    let activeSearch = 0;

    const inventoryReady = inventoryLoaded
        ? Promise.resolve(inventory)
        : new Promise((resolve) => {
            const finish = (event) => {
                inventory = Array.isArray(event.detail) ? event.detail : [];
                inventoryLoaded = true;
                resolve(inventory);
            };
            window.addEventListener("bienmarko:inventory-loaded", finish, { once: true });
            window.setTimeout(() => {
                if (!inventoryLoaded) {
                    inventoryLoaded = true;
                    window.bienMarkoInventoryFailed = true;
                    resolve(Array.isArray(window.bienMarkoAccommodations) ? window.bienMarkoAccommodations : []);
                }
            }, 15000);
        });

    const escapeHtml = (value) => String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");

    const money = (value) => {
        const amount = Number.parseFloat(value);
        return Number.isFinite(amount)
            ? "₱" + amount.toLocaleString("en-PH", { maximumFractionDigits: 0 })
            : "₱—";
    };

    const categoryInfo = {
        all: { title: "Accommodations", label: "", singular: "accommodation", plural: "accommodations", unit: "accommodation", priceSuffix: "/day" },
        rooms: { title: "Rooms", label: "Room", singular: "room", plural: "rooms", unit: "room", priceSuffix: "/night" },
        venues: { title: "Event Halls", label: "Event Hall", singular: "event hall", plural: "event halls", unit: "event hall", priceSuffix: "/day" },
        cottage: { title: "Cottages", label: "Cottage", singular: "cottage", plural: "cottages", unit: "cottage", priceSuffix: "/day" },
        pool: { title: "Pool", label: "Pool", singular: "pool option", plural: "pool options", unit: "pool access", priceSuffix: "/person" }
    };

    // Order used when "All" is selected so results are grouped by type.
    const categoryOrder = ["rooms", "venues", "cottage", "pool"];

    // Falls back to generic wording for any custom category not listed above.
    const infoFor = (category) => categoryInfo[category] || {
        title: "Accommodations", label: "", singular: "accommodation", plural: "accommodations",
        unit: "accommodation", priceSuffix: "/day"
    };

    function showResults(message, showSkeletons = false) {
        results.hidden = false;
        summary.textContent = message;
        // Hide the summary line entirely when there is nothing to say.
        summary.hidden = !message;
        grid.innerHTML = "";
        if (showSkeletons) {
            for (let i = 0; i < 3; i++) {
                const skeleton = document.createElement("div");
                skeleton.className = "room-skeleton";
                skeleton.innerHTML =
                    '<div class="room-skeleton-media"></div>' +
                    '<div class="room-skeleton-line room-skeleton-line--title"></div>' +
                    '<div class="room-skeleton-line room-skeleton-line--sub"></div>';
                grid.appendChild(skeleton);
            }
        }
        results.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    /* Heading becomes "No Available <Category>" (e.g. "No Available Event Halls"),
       with an optional short hint underneath. */
    function showNoResults(details, message = "") {
        resultsTitle.textContent = "No Available " + details.title;
        showResults(message);
    }

    function makeResultCard(item) {
        const card = document.createElement("article");
        card.className = "room-card availability-room-card";
        // Each card uses its own category, so this also works for "All".
        const itemCategory = item.category;
        const categoryDetails = infoFor(itemCategory);

        const media = item.image_url
            ? `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}" loading="lazy">`
            : '<div class="room-card-placeholder" aria-label="No photo available"><span>No photo available</span></div>';
        const priceSuffix = categoryDetails.priceSuffix;
        const capacity = Number(item.capacity) || 0;
        const details = [];
        // Show the type (Room, Event Hall, ...) when searching across all categories.
        if (typeSelect.value === "all" && categoryDetails.label) details.push(categoryDetails.label);
        details.push(capacity + " guest" + (capacity === 1 ? "" : "s"));
        if (itemCategory === "rooms" && item.room_number) details.push("Room " + item.room_number);
        if (Array.isArray(item.amenities) && item.amenities.length) {
            details.push(item.amenities.slice(0, 2).join(", "));
        }

        card.innerHTML = `
            <div class="room-media">
                ${media}
                <span class="price-tag">${money(item.price)}${priceSuffix}</span>
            </div>
            <div class="availability-result-body">
                <h3>${escapeHtml(item.name || "Accommodation")}</h3>
                <p class="availability-result-details">${escapeHtml(details.join(" · "))}</p>
                ${item.description ? `<p class="availability-result-description">${escapeHtml(item.description)}</p>` : ""}
                <button class="availability-book-btn" type="button">Choose this ${escapeHtml(categoryDetails.unit)}</button>
            </div>`;

        const image = card.querySelector(".room-media img");
        if (image) {
            image.addEventListener("error", () => {
                const placeholder = document.createElement("div");
                placeholder.className = "room-card-placeholder";
                placeholder.textContent = "Photo unavailable";
                image.replaceWith(placeholder);
            }, { once: true });
        }

        card.querySelector(".availability-book-btn").addEventListener("click", () => {
            if (typeof window.bienMarkoOpenBookingOption === "function") {
                window.bienMarkoOpenBookingOption(item.name || "");
            } else {
                const params = new URLSearchParams({
                    room: item.name || "",
                    checkin: checkinInput.value,
                    checkout: checkoutInput.value
                });
                window.location.href = "user/guest-book.php?" + params.toString();
            }
        });

        return card;
    }

    function itemIsFreeForDates(data, checkin, checkout, guestsWanted) {
        const bookings = data.bookings;
        const isPool = Boolean(data.accommodation && data.accommodation.is_pool);

        if (!isPool) {
            return !bookings.some((booking) =>
                String(booking.check_in) < checkout && String(booking.check_out) > checkin
            );
        }

        // Pool bookings share capacity: only reject the requested stay if
        // existing guests plus this search's guest count exceed capacity
        // on any booked night. Checkout day is exclusive.
        const capacity = Number(data.accommodation.capacity) || 0;
        const day = new Date(checkin + "T00:00:00Z");
        const lastNight = new Date(checkout + "T00:00:00Z");
        while (day < lastNight) {
            const date = day.toISOString().slice(0, 10);
            const guestsAlreadyBooked = bookings
                .filter((booking) => String(booking.check_in) <= date && String(booking.check_out) > date)
                .reduce((total, booking) => total + (Number(booking.guests_count) || 1), 0);
            if (guestsAlreadyBooked + guestsWanted > capacity) return false;
            day.setUTCDate(day.getUTCDate() + 1);
        }
        return true;
    }

    // Sort key so "All" results are grouped: rooms, event halls, cottages, pool, then anything else.
    function categoryRank(item) {
        const index = categoryOrder.indexOf(item.category);
        return index === -1 ? categoryOrder.length : index;
    }

    form.addEventListener("change", () => {
        activeSearch++;
        if (!results.hidden) results.hidden = true;
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const searchId = ++activeSearch;
        if (!form.reportValidity()) return;

        const category = typeSelect.value;
        const checkin = checkinInput.value;
        const checkout = checkoutInput.value;
        const guestsWanted = Math.max(1, Number.parseInt(guestSelect.value, 10) || 1);

        if (!category) {
            showResults("Choose an accommodation type to search.");
            return;
        }
        if (!checkin || !checkout || checkout <= checkin) {
            showResults("Choose a valid check-in and check-out date to check availability.");
            return;
        }

        const details = categoryInfo[category];
        if (!details) {
            showResults("Choose All, Rooms, Event Halls, Cottages, or Pool.");
            return;
        }
        const isAll = category === "all";
        const label = details.plural;
        resultsTitle.textContent = "Available " + details.title;
        showResults(`Checking available ${label} for your selected dates…`, true);

        const allItems = await inventoryReady;
        if (searchId !== activeSearch) return;
        inventory = Array.isArray(allItems) ? allItems : [];
        if (window.bienMarkoInventoryFailed) {
            showResults("We couldn't load accommodation listings right now. Please try again shortly.");
            return;
        }
        const candidates = inventory
            .filter((item) =>
                (isAll || item.category === category) &&
                Number(item.capacity) >= guestsWanted &&
                !["maintenance", "unavailable"].includes(String(item.status || "").toLowerCase())
            )
            .sort((a, b) => categoryRank(a) - categoryRank(b));

        if (!candidates.length) {
            // Heading reads "No Available Event Halls"; no extra line underneath.
            showNoResults(details);
            return;
        }

        const checks = await Promise.all(candidates.map(async (item) => {
            try {
                const response = await fetch(
                    "api/availability.php?accommodation_id=" + encodeURIComponent(item.id),
                    { headers: { Accept: "application/json" }, cache: "no-store" }
                );
                if (!response.ok) throw new Error("Availability request failed.");
                const data = await response.json();
                if (!data || !data.success || !Array.isArray(data.bookings)) {
                    throw new Error("Availability data was not returned.");
                }
                return { item, available: itemIsFreeForDates(data, checkin, checkout, guestsWanted) };
            } catch {
                return { item, error: true };
            }
        }));
        if (searchId !== activeSearch) return;

        const availableItems = checks.filter((result) => result.available).map((result) => result.item);
        const failedChecks = checks.filter((result) => result.error).length;
        grid.innerHTML = "";

        if (availableItems.length) {
            const count = availableItems.length;
            summary.hidden = false;
            summary.textContent = `${count} ${count === 1 ? details.singular : label} available for ${checkin} to ${checkout}.`;
            if (failedChecks) {
                summary.textContent += ` Availability could not be verified for ${failedChecks} other listing${failedChecks === 1 ? "" : "s"}.`;
            }
            availableItems.forEach((item) => grid.appendChild(makeResultCard(item)));
            results.hidden = false;
            results.scrollIntoView({ behavior: "smooth", block: "start" });
            return;
        }

        if (failedChecks === checks.length) {
            showResults(`We couldn't verify ${label} availability right now. Please try again or contact us.`);
            return;
        }

        showNoResults(details, "Try different dates or a smaller guest range.");
    });
});