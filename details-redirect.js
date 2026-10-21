/* details-redirect.js  (v1) — place in the project root, next to index.html.

   On the landing page, "Choose this room" (Matching accommodations / Available Rooms)
   and the Featured Rooms cards now open the accommodation DETAILS page first:

       accommodation-details.php?room=Barkada%20Room&checkin=2026-10-21&checkout=2026-10-22

   From there the guest views photos, the 360° tour, amenities and house rules, then presses
   "Book Now" to reach the booking form with the same dates already filled in.

   How it works: it listens in the capture phase (the same pattern the map script in
   index.html uses), so it runs before the older click handlers in script.js and
   availability-search.js and replaces their "Ready to Book?" popup for these cards.
   If it can't work out which room a card is for, it does nothing and the old behaviour runs.

   Load it AFTER script.js and availability-search.js:
       <script src="details-redirect.js?v=1"></script>
*/
(function () {
    'use strict';

    var DETAILS_PAGE = 'accommodation-details.php';
    var CONTAINERS   = '#availabilityResultsGrid, #featuredGrid';
    var ACTION_TEXT  = /choose|book|inquire|reserve|select/i;   // buttons that used to start a booking

    function isoValue(id) {
        var el = document.getElementById(id);
        var v = el && el.value ? String(el.value).trim() : '';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { return ''; }
        var p = v.split('-');
        var d = new Date(+p[0], +p[1] - 1, +p[2]);
        var ok = d.getFullYear() === +p[0] && d.getMonth() === +p[1] - 1 && d.getDate() === +p[2];
        return ok ? v : '';
    }

    // Direct child of the grid that holds the clicked button = that room's card.
    function cardOf(node, container) {
        while (node && node.parentElement !== container) { node = node.parentElement; }
        return node || null;
    }

    function dataValue(nodes, keys) {
        for (var i = 0; i < nodes.length; i++) {
            var ds = nodes[i] && nodes[i].dataset;
            if (!ds) { continue; }
            for (var k = 0; k < keys.length; k++) {
                if (ds[keys[k]]) { return String(ds[keys[k]]).trim(); }
            }
        }
        return '';
    }

    function roomName(trigger, card) {
        var fromData = dataValue([trigger, card], ['roomName', 'accommodationName', 'name', 'room']);
        if (fromData) { return fromData; }
        var heading = card.querySelector('h2, h3, h4, .room-name, .room-card-title');
        return heading ? heading.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    function roomId(trigger, card) {
        var id = dataValue([trigger, card], ['accommodationId', 'roomId', 'accId', 'accommodation_id']);
        return /^\d+$/.test(id) ? id : '';
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }

        var target = event.target;
        if (!target || typeof target.closest !== 'function') { return; }

        var container = target.closest(CONTAINERS);
        if (!container) { return; }

        var trigger = target.closest('button, a');
        if (!trigger || !container.contains(trigger)) { return; }
        if (!ACTION_TEXT.test(trigger.textContent || '')) { return; }

        var card = cardOf(trigger, container);
        if (!card) { return; }

        var name = roomName(trigger, card);
        if (!name) { return; }                      // unknown room: leave the old behaviour alone

        var query = [];
        var id = roomId(trigger, card);
        if (id) { query.push('id=' + encodeURIComponent(id)); }
        query.push('room=' + encodeURIComponent(name));

        var checkin  = isoValue('checkin');
        var checkout = isoValue('checkout');
        if (checkin && checkout && checkout > checkin) {
            query.push('checkin=' + encodeURIComponent(checkin));
            query.push('checkout=' + encodeURIComponent(checkout));
        }

        // Replace the older handler that opens the "Ready to Book?" popup.
        event.preventDefault();
        event.stopImmediatePropagation();
        window.location.href = DETAILS_PAGE + '?' + query.join('&');
    }, true);
}());