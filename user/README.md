# BienMarko — User Dashboard

Guest-facing dashboard UI. This is the **placeholder/empty-state phase** —
there is no database or authentication yet, so every page shows the "no
data" state on purpose. The templates already branch on empty vs. populated
data, so real data will render correctly the moment it exists.

## Run it locally

```bash
php -S localhost:8000
```

Then open `http://localhost:8000/` (or `/dashboard.php` directly).

## Structure

```
includes/
  bootstrap.php      Session start, site constants, placeholder $currentUser.
                      Required first (and only) by every page — it pulls in
                      nav-items.php, icons.php, and ui.php itself.
  nav-items.php       Single source of truth for the bottom navigation.
  icons.php           Single source of truth for icon markup (auto-generated
                      from the Lucide icon set, ISC License).
  ui.php              render_stat_card(), render_empty_state(), page_head().
  head.php            Shared <head> + opens <body> + includes header.php.
  header.php          Shared top bar: brand, notifications, user, logout.
  bottom-nav.php      Shared bottom navigation (the "sidebar", moved below).
  foot.php            Includes bottom-nav.php, loads app.js, closes the page.
  support-modal.php   Contact Support dialog, included only by dashboard.php.

assets/
  css/style.css       Full design system (tokens, layout, components).
  js/app.js           Notifications dropdown, support modal, star rating,
                      logout confirm, and simulated form submissions.

dashboard.php   bookings.php   receipts.php   feedback.php
user-guide.php  profile.php    logout.php     index.php
```

## Why the sidebar became a bottom bar

The nav is still one shared, reusable component (`includes/bottom-nav.php`,
driven entirely by `includes/nav-items.php`) — it's just positioned as a
fixed tab bar at the bottom of the viewport instead of a rail on the side,
so it reads as an app rather than a desktop admin panel.

## Adding a real nav item

Edit `includes/nav-items.php` only. Every page (`bottom-nav.php`) reads that
array, so a new tab appears everywhere at once — no page file needs to
change.

## Wiring up real data later

Each page's placeholder data sits at the top of the file in a clearly
commented block (e.g. `$upcomingStay`, `$activeBookings`, `$receipts`).
Replace those arrays with real query results and the existing conditionals
will automatically render the populated view instead of the empty state.
