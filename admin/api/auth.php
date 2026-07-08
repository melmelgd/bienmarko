<?php
/**
 * admin/api/auth.php
 * -----------------------------------------------------------------------
 * Login endpoint posted to by the shared login page (../../login.php) —
 * either as a fetch() JSON request (assets/js/login.js, the primary
 * path) or as a classic form submit if JavaScript is unavailable.
 *
 * login.php no longer has a Guest/Staff choice at all — its form posts
 * to user/api/auth.php directly, every time, so nothing currently links
 * to this file. It's kept fully working anyway (same reasoning as
 * keeping the 'admins' table itself distinct from 'guests'): this file
 * hands off to the exact same bienmarko_handle_login_request() in
 * includes/auth-common.php as its user/api/auth.php twin, which checks
 * the submitted email/password against BOTH the guests and admins
 * tables and honors whichever one actually matches, regardless of which
 * of these two files received the request. The 'admin' below is only a
 * leftover audit-trail label for a failed attempt — see the docblock on
 * bienmarko_handle_login_request() for the full reasoning.
 *
 * Kept at this path, rather than merged into one file, so nothing that
 * already points here — bookmarks, the no-JS form fallback — needs to
 * change.
 *
 * Demo account (local testing only — see the project README):
 *   email:    admin@bienmarko.test
 *   password: Admin@123
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_handle_login_request('admin');
