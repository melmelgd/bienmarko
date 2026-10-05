BIEN MARKO'S ACCOMMODATION LISTING UPDATE

Files in this package:
- index.html              Updated home page; removes the duplicate mixed inventory section.
- rooms.html              Dedicated room listings page.
- event-halls.html        Dedicated event-hall listings page.
- cottages.html           Dedicated cottage listings page.
- pool.html               Dedicated pool-access listings page.
- accommodation-list.js  Loads and filters live inventory for the listing pages.
- availability-search.js Searches rooms or event halls by category, dates, and guest count.
- script.js               Existing home-page interactions, updated for the new navigation.
- style.css               Existing design styles plus dedicated listing-page styles.
- api/                    API files supplied with this request.

Install:
1. Copy the site files into the same public web folder as the current index.html.
2. Keep the existing user/, images/, and logo/ folders, plus the PHP includes and
   database configuration required by the API endpoints.
3. The booking search offers Rooms, Event Halls, Cottages, and Pool. It loads
   inventory from api/accommodations.php, then checks each matching item against
   api/availability.php for the chosen dates and guest range before showing results.
   Pool availability accounts for shared guest capacity; the other categories are
   checked as exclusive bookings.
4. The supplied API files are included for reference. Their database and admin
   includes are part of the existing PHP application and are not in this package.

The attached screenshots are design references, not the website's actual image assets.