<?php
/**
 * includes/gmail-config.php
 * -----------------------------------------------------------------------
 * Gmail API (OAuth 2.0) settings for booking emails.
 *
 * This file holds the Google OAuth *Client Secret*. Keep it out of Git
 * (add `includes/gmail-config.php` to .gitignore) and never paste its
 * contents anywhere public. It returns an array, so opening it directly
 * in a browser prints nothing.
 *
 * Where the values come from:
 *   Google Cloud Console → Google Auth Platform → Clients → "Web client 1"
 *   (Client ID is shown on that page; the Client Secret was shown once at
 *   creation — if it was lost, click "Add secret" to make a new one).
 * -----------------------------------------------------------------------
 */
return [

    // From the OAuth client page. Double-check this matches the console
    // exactly (copy/paste it from there if the connect step says
    // "invalid_client").
    'client_id'     => '690778733149-so3g3o209i7ii3t2vvo3ap07hen3icj0.apps.googleusercontent.com',

    // Paste the secret between the quotes. You can instead set the
    // BIENMARKO_GMAIL_CLIENT_SECRET environment variable and leave this as is.
    'client_secret' => getenv('BIENMARKO_GMAIL_CLIENT_SECRET') ?: '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',

    // Leave blank to auto-detect. Auto-detect gives:
    //   local XAMPP : http://localhost/Bookingsystem/gmail_callback.php
    //   live site   : https://YOUR-DOMAIN/gmail_callback.php
    // Whatever it produces must be listed under "Authorized redirect URIs"
    // on the OAuth client. The Settings → Email panel shows the exact value.
    'redirect_uri'  => '',

    // Public base URL of the site, used for links inside emails and by the
    // reminder cron job (which has no browser request to detect it from).
    // Examples: 'https://bienmarkos.com'  or  'http://localhost/Bookingsystem'
    // Leave blank to auto-detect during normal web requests.
    'site_url'      => '',

    // Reminder emails go out this many days before check-in.
    'reminder_days_before' => 1,

    // Secret for running the reminder job through a URL instead of the
    // command line:  https://YOUR-DOMAIN/cron/send-reminders.php?key=THIS
    // Leave blank to disable URL access (command line still works).
    'cron_key'      => '',

    // Only needed if connecting fails with an SSL certificate error on
    // XAMPP. Absolute path to a CA bundle, e.g.
    // 'C:/xampp/php/extras/ssl/cacert.pem'. Blank = auto-detect.
    'ca_bundle'     => '',

    // Network timeout (seconds) for calls to Google.
    'timeout'       => 15,

    // Advanced: override Google endpoints (used only for testing).
    'endpoints'     => [],
];
