<?php
/**
 * user/api/auth.php
 * -----------------------------------------------------------------------
 * Login endpoint posted to by the shared login page (../../login.php) —
 * either as a fetch() JSON request (assets/js/login.js, the primary
 * path) or as a classic form submit if JavaScript is unavailable.
 *
 * login.php no longer has a Guest/Staff choice at all — it posts here
 * directly, every time. This file (and its admin/api/auth.php twin,
 * which nothing currently links to but is kept fully working) both hand
 * off to bienmarko_handle_login_request() in includes/auth-common.php,
 * which checks the submitted email/password against BOTH the guests and
 * admins tables and honors whichever one actually matches, regardless of
 * which of these two files received the request. The 'user' below is
 * only a leftover audit-trail label for a failed attempt — see the
 * docblock on bienmarko_handle_login_request() for the full reasoning.
 *
 * Kept at this path, rather than merged into one file, so nothing that
 * already points here — bookmarks, the no-JS form fallback — needs to
 * change.
 *
 * Demo account (local testing only — see the project README):
 *   email:    shayra@bienmarko.test
 *   password: Guest@123
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_handle_login_request('user');
