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

const modal = document.querySelector("[data-map-modal]");
const frame = document.querySelector("[data-map-frame]");
const openButtons = document.querySelectorAll("[data-open-map]");
const closeButton = document.querySelector("[data-close-map]");
const locationButton = document.querySelector("[data-use-location]");
const destination = "Bien Marko's Pension House";

function openMapModal() {
    if (!modal) return;
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
}

function closeMapModal() {
    if (!modal) return;
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");
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
