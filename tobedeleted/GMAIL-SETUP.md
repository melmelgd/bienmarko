# Gmail booking emails — setup

Guests now get emails for: booking confirmation (with reference number), booking
changed, booking cancelled, and a reminder the day before check-in. Booking never
waits on, or fails because of, email.

## 1. Install (once)
1. Copy the folders in this zip over your project root (`Bookingsystem/`).
   - 7 new files, 4 existing files changed (additions only, nothing removed).
   - No SQL to run: the `gmail_oauth` and `email_log` tables create themselves.
2. Open `includes/gmail-config.php` and
   - paste the **Client Secret** into `client_secret`;
   - check `client_id` matches the Google Cloud console exactly.
3. Add `includes/gmail-config.php` to `.gitignore` (it holds the secret).

## 2. Connect Gmail (once)
Admin → **Settings → Email (Gmail) → Connect Gmail** → choose the sending account
(must be a Test user in Google Cloud) → **Allow**.
Google shows an "unverified app" warning: **Advanced → Go to BienMarko's Booking System**.
Then press **Send test** — you should receive it within seconds.
To change the sending account later: **Switch account**.

## 3. Reminders (daily job)
- Local test:  `C:\xampp\php\php.exe C:\xampp\htdocs\Bookingsystem\cron\send-reminders.php`
  (add `--dry-run` to preview without sending).
- Hostinger: hPanel → Advanced → Cron Jobs → PHP, once a day (e.g. 8:00), pointing at
  `cron/send-reminders.php`. If the job can't reach the database from the command line,
  set `cron_key` in `gmail-config.php` and call
  `https://YOUR-DOMAIN/cron/send-reminders.php?key=YOUR_KEY` instead.
- It is safe to run more than once a day: each booking gets one reminder.

## 4. Before the demo / going live
- Google Cloud → Google Auth Platform → **Audience → Publish app**. While the app is in
  *Testing*, Google disconnects it every 7 days (the admin bell then shows
  "Gmail connection expired" — click Reconnect).
- Add the live URL to the OAuth client: **Authorized redirect URI**
  `https://YOUR-DOMAIN/gmail_callback.php` (the exact value is shown in
  Settings → Email (Gmail) → Connection details). Google requires https.
- Set `site_url` in `gmail-config.php` (e.g. `https://YOUR-DOMAIN`) so reminder emails
  can include the dashboard link.
- Connect Gmail again on the live site (tokens are per database).

## Troubleshooting
| Message | Fix |
|---|---|
| Google rejected the Client ID / Secret | Re-check both values in `gmail-config.php` |
| redirect_uri_mismatch | Add the URI shown in Connection details to the OAuth client; wait a few minutes |
| Access blocked / access_denied | The account isn't in Audience → Test users |
| SSL certificate problem (XAMPP) | Set `ca_bundle` in `gmail-config.php` to `C:/xampp/php/extras/ssl/cacert.pem` |
| Gmail API is not enabled | APIs & Services → Library → Gmail API → Enable |
| Admin session not found on the callback page | Sign in as admin, then start from Settings again |

Every send attempt (sent / failed / skipped, with the reason) is listed under
Settings → Email (Gmail), and stored in the `email_log` table.
