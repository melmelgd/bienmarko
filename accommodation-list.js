/* Live inventory for the dedicated Rooms and Event Halls pages.
   API categories used by the existing booking system:
   "rooms" = bedrooms; "venues" = event spaces. */
document.addEventListener("DOMContentLoaded", () => {
    const grid = document.getElementById("roomGrid");
    const page = document.body;
    if (!grid || !page) return;

    const category = page.dataset.listingCategory;
    const label = page.dataset.listingLabel || "accommodations";

    const escapeHtml = (value) => String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");

    const formatPrice = (value) => {
        const amount = Number.parseFloat(value);
        return Number.isFinite(amount)
            ? "₱" + amount.toLocaleString("en-PH", { maximumFractionDigits: 0 })
            : "₱—";
    };

    const subtitle = (item) => {
        const capacity = Number(item.capacity) || 0;
        const details = [capacity + " Guest" + (capacity === 1 ? "" : "s")];
        if (item.category === "rooms" && item.room_number) {
            details.push("Room " + item.room_number);
        } else if (Array.isArray(item.amenities) && item.amenities.length) {
            details.push(item.amenities.slice(0, 2).join(", "));
        } else if (item.type) {
            details.push(item.type);
        }
        return details.join(" · ");
    };

    const showMessage = (message) => {
        grid.innerHTML = "";
        const paragraph = document.createElement("p");
        paragraph.className = "room-grid-empty";
        paragraph.textContent = message;
        grid.appendChild(paragraph);
    };

    const makeCard = (item, index) => {
        const card = document.createElement("article");
        card.className = "room-card";
        card.dataset.reveal = "";
        card.style.setProperty("--i", index % 4);

        const image = item.image_url
            ? `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}" loading="lazy">`
            : `<div class="room-card-placeholder" aria-label="No photo available">
                   <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                       <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                       <polyline points="9 22 9 12 15 12 15 22"></polyline>
                   </svg>
               </div>`;

        const priceSuffix = category === "rooms" ? "/night" : (category === "pool" ? "/person" : "/day");
        // "Book / Inquire" opens the details page first; "Book Now" there continues to the booking flow.
        const bookingUrl = "accommodation-details.php?" +
            (item.id ? "id=" + encodeURIComponent(item.id) + "&" : "") +
            "room=" + encodeURIComponent(item.name || "");
        card.innerHTML = `
            <div class="room-media">
                ${image}
                <span class="price-tag">${formatPrice(item.price)}${priceSuffix}</span>
                <div class="room-hover">
                    <span>From</span>
                    <strong>${formatPrice(item.price)}</strong>
                    <a href="${escapeHtml(bookingUrl)}">Book / Inquire</a>
                </div>
            </div>
            <h3>${escapeHtml(item.name || "Accommodation")}</h3>
            <p>${escapeHtml(subtitle(item))}</p>
            <a class="listing-book-link" href="${escapeHtml(bookingUrl)}">Book / Inquire</a>`;
        return card;
    };

    fetch("api/accommodations.php", { headers: { Accept: "application/json" } })
        .then((response) => {
            if (!response.ok) throw new Error("Unable to load accommodation data.");
            return response.json();
        })
        .then((data) => {
            const allItems = data && data.success && Array.isArray(data.rooms) ? data.rooms : [];
            const availableItems = allItems.filter((item) =>
                item.category === category && String(item.status || "").toLowerCase() !== "booked"
            );

            if (!availableItems.length) {
                showMessage(`There are no ${label} marked as available right now. Please check back soon or contact us.`);
                return;
            }

            grid.innerHTML = "";
            availableItems.forEach((item, index) => grid.appendChild(makeCard(item, index)));

            grid.querySelectorAll(".room-card img").forEach((img) => {
                img.addEventListener("error", () => {
                    const placeholder = document.createElement("div");
                    placeholder.className = "room-card-placeholder";
                    placeholder.setAttribute("aria-label", "Photo unavailable");
                    placeholder.textContent = "Photo unavailable";
                    img.replaceWith(placeholder);
                }, { once: true });
            });
        })
        .catch(() => {
            showMessage(`We couldn't load ${label} right now. Please refresh the page or contact us for assistance.`);
        });
});