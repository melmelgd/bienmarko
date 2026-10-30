<?php
/**
 * privacy-policy.php
 * Public Privacy Policy page for Bien Marko's Pension House Online Booking System.
 * Standalone: no session, no database, no auth include required.
 *
 * EDIT THE VALUES BELOW to match the resort's real details.
 */

$siteName      = 'BienMarko';
$businessName  = "Bien Marko's Pension House";
$businessAddr  = '1st Ave Street, Poblacion, Tabango, Leyte, Philippines';

// Leave empty ('') to hide a line. The page falls back to "the resort front desk".
$contactEmail  = '';   // e.g. 'bienmarkos@gmail.com'
$contactPhone  = '';   // e.g. '09171234567'
$privacyOfficer = '';  // e.g. 'Juan dela Cruz' (Data Protection Officer / person in charge)

$policyVersion   = '1.0';
$policyEffective = 'October 18, 2026';

// ?embed=1 is used by the sign-up page modal: hides the page chrome and
// reports scroll progress to the parent window (see script at the bottom).
$embed = (($_GET['embed'] ?? '') === '1');

function pp_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Privacy Policy · <?php echo pp_e($siteName); ?></title>
<meta name="description" content="How <?php echo pp_e($businessName); ?> collects, uses, protects and shares your personal information through its online booking system.">
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #0e0d0b;
    --surface: #171612;
    --surface-2: #1f1d17;
    --border: rgba(212, 175, 55, 0.18);
    --border-soft: rgba(255, 255, 255, 0.07);
    --text: #ece7da;
    --muted: #a8a293;
    --gold-300: #e6c55a;
    --gold-400: #d4af37;
    --gold-500: #b8941f;
    --radius: 14px;
    --serif: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;
    --sans: 'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
}

* { box-sizing: border-box; }

html {
    scroll-behavior: smooth;
    -webkit-text-size-adjust: 100%;
}

body {
    margin: 0;
    background:
        radial-gradient(900px 420px at 50% -120px, rgba(212, 175, 55, 0.10), transparent 70%),
        var(--bg);
    color: var(--text);
    font-family: var(--sans);
    font-size: 16px;
    line-height: 1.7;
    padding: env(safe-area-inset-top, 0) env(safe-area-inset-right, 0) env(safe-area-inset-bottom, 0) env(safe-area-inset-left, 0);
}

a { color: var(--gold-300); text-underline-offset: 3px; }
a:hover { color: var(--gold-400); }

.pp-wrap {
    width: 100%;
    max-width: 860px;
    margin: 0 auto;
    padding: 20px 16px 56px;
}

/* ---------- Top bar ---------- */
.pp-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 0 22px;
}

.pp-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: var(--text);
}

.pp-brand__mark {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    color: #15120a;
    background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
    flex: none;
}

.pp-brand__mark svg { width: 20px; height: 20px; }

/* System title: same treatment as the landing page logo (Cormorant Garamond, spaced caps) */
.pp-brand__name {
    font-family: var(--serif);
    font-weight: 400;
    font-size: 1.35rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    line-height: 1.1;
}

.pp-brand__tag {
    font-size: 0.72rem;
    color: var(--muted);
}

.pp-back {
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    padding: 9px 14px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--gold-300);
    white-space: nowrap;
}

.pp-back:hover { background: rgba(212, 175, 55, 0.08); }

/* ---------- Hero ---------- */
.pp-hero h1 {
    font-family: var(--serif);
    font-size: clamp(1.9rem, 6vw, 2.7rem);
    line-height: 1.15;
    margin: 0 0 8px;
    font-weight: 700;
}

.pp-hero p {
    margin: 0;
    color: var(--muted);
    font-size: 0.95rem;
}

.pp-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
}

.pp-chip {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 5px 11px;
    border-radius: 999px;
    border: 1px solid var(--border);
    color: var(--gold-300);
    background: rgba(212, 175, 55, 0.06);
}

/* ---------- Cards ---------- */
.pp-card {
    background: var(--surface);
    border: 1px solid var(--border-soft);
    border-radius: var(--radius);
    padding: 20px 18px;
    margin-top: 20px;
}

.pp-card--accent {
    border-color: var(--border);
    background:
        linear-gradient(180deg, rgba(212, 175, 55, 0.07), transparent 60%),
        var(--surface);
}

.pp-card h2 {
    font-family: var(--serif);
    font-size: 1.2rem;
    margin: 0 0 10px;
    line-height: 1.3;
}

.pp-card h3 {
    font-size: 0.98rem;
    margin: 18px 0 6px;
    color: var(--gold-300);
}

.pp-card p { margin: 0 0 12px; }
.pp-card p:last-child { margin-bottom: 0; }

.pp-card ul {
    margin: 0 0 12px;
    padding-left: 20px;
}

.pp-card li { margin-bottom: 6px; }
.pp-card li:last-child { margin-bottom: 0; }

.pp-num {
    color: var(--gold-400);
    margin-right: 6px;
}

/* ---------- Table of contents ---------- */
.pp-toc {
    columns: 1;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.92rem;
}

.pp-toc li { margin-bottom: 4px; break-inside: avoid; }
.pp-toc a { text-decoration: none; color: var(--text); }
.pp-toc a:hover { color: var(--gold-300); }
.pp-toc span { color: var(--gold-400); font-weight: 600; margin-right: 6px; }

/* ---------- Table ---------- */
.pp-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border: 1px solid var(--border-soft);
    border-radius: 10px;
    margin: 6px 0 12px;
}

.pp-table {
    width: 100%;
    min-width: 560px;
    border-collapse: collapse;
    font-size: 0.88rem;
}

.pp-table th,
.pp-table td {
    text-align: left;
    vertical-align: top;
    padding: 10px 12px;
    border-bottom: 1px solid var(--border-soft);
}

.pp-table th {
    background: var(--surface-2);
    color: var(--gold-300);
    font-weight: 600;
    white-space: nowrap;
}

.pp-table tr:last-child td { border-bottom: 0; }

/* ---------- Callout ---------- */
.pp-note {
    border-left: 3px solid var(--gold-400);
    background: rgba(212, 175, 55, 0.06);
    padding: 12px 14px;
    border-radius: 0 10px 10px 0;
    font-size: 0.92rem;
    margin: 12px 0;
}

section[id] { scroll-margin-top: 16px; }

.pp-foot {
    margin-top: 28px;
    text-align: center;
    font-size: 0.82rem;
    color: var(--muted);
}

.pp-cta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    margin-top: 22px;
}

.pp-btn {
    display: inline-block;
    padding: 12px 22px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.92rem;
    text-decoration: none;
    color: #15120a;
    background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
}

.pp-btn:hover { color: #15120a; filter: brightness(1.07); }

.pp-btn--ghost {
    background: transparent;
    color: var(--gold-300);
    border: 1px solid var(--border);
}

.pp-btn--ghost:hover { color: var(--gold-300); background: rgba(212, 175, 55, 0.08); }

@media (min-width: 640px) {
    .pp-wrap { padding: 32px 24px 72px; }
    .pp-card { padding: 26px 28px; }
    .pp-toc { columns: 2; column-gap: 28px; }
    .pp-table { min-width: 0; }
}

.pp-end {
    text-align: center;
    color: var(--muted);
    font-size: 0.8rem;
    margin: 28px 0 0;
}

.is-embed .pp-wrap { padding-top: 6px; padding-bottom: 28px; }

@media print {
    body { background: #fff; color: #000; }
    .pp-card, .pp-table-wrap { border-color: #ccc; background: #fff; }
    .pp-card h3, .pp-table th, a { color: #000; }
    .pp-back, .pp-cta { display: none; }
}
</style>
</head>
<body<?php echo $embed ? ' class="is-embed"' : ''; ?>>
<div class="pp-wrap">

<?php if (!$embed): ?>
    <header class="pp-top">
        <a class="pp-brand" href="index.html" aria-label="<?php echo pp_e($businessName); ?> home">
            <span class="pp-brand__mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 11l9-8 9 8"></path>
                    <path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"></path>
                </svg>
            </span>
            <span>
                <span class="pp-brand__name" style="display:block;"><?php echo pp_e($siteName); ?></span>
                <span class="pp-brand__tag" style="display:block;"><?php echo pp_e($businessName); ?></span>
            </span>
        </a>
        <a class="pp-back" href="register.php">&larr; Back to Sign Up</a>
    </header>
<?php endif; ?>

    <div class="pp-hero">
        <h1>Privacy Policy</h1>
        <p>How we collect, use, protect, and share your personal information when you use the <?php echo pp_e($businessName); ?> Online Booking System.</p>
        <div class="pp-meta">
            <span class="pp-chip">Version <?php echo pp_e($policyVersion); ?></span>
            <span class="pp-chip">Effective <?php echo pp_e($policyEffective); ?></span>
            <span class="pp-chip">Data Privacy Act of 2012 (RA 10173)</span>
        </div>
    </div>

    <!-- Summary -->
    <div class="pp-card pp-card--accent">
        <h2>The short version</h2>
        <ul>
            <li>We collect only what we need to create your account, manage your bookings, and keep accurate resort records.</li>
            <li>We do <strong>not</strong> sell your personal information or use it for third-party advertising.</li>
            <li>This system does <strong>not</strong> process payments. You pay the resort directly in cash; we only record the payment and compute the bill.</li>
            <li>Your data is visible only to authorized resort personnel and the service providers needed to run the system.</li>
            <li>You may ask to see, correct, or delete your information at any time.</li>
        </ul>
    </div>

    <!-- Table of contents -->
    <div class="pp-card">
        <h2>Contents</h2>
        <ol class="pp-toc">
            <li><span>1.</span><a href="#about">About this policy</a></li>
            <li><span>2.</span><a href="#collect">Information we collect</a></li>
            <li><span>3.</span><a href="#use">How we use your information</a></li>
            <li><span>4.</span><a href="#basis">Basis for processing and your consent</a></li>
            <li><span>5.</span><a href="#notifications">Notifications and reminders</a></li>
            <li><span>6.</span><a href="#guest">Booking as a guest (no account)</a></li>
            <li><span>7.</span><a href="#feedback">Feedback and public display</a></li>
            <li><span>8.</span><a href="#sharing">Who we share information with</a></li>
            <li><span>9.</span><a href="#cookies">Cookies and similar technologies</a></li>
            <li><span>10.</span><a href="#security">How we protect your information</a></li>
            <li><span>11.</span><a href="#retention">How long we keep your information</a></li>
            <li><span>12.</span><a href="#rights">Your rights</a></li>
            <li><span>13.</span><a href="#children">Children</a></li>
            <li><span>14.</span><a href="#changes">Changes to this policy</a></li>
            <li><span>15.</span><a href="#contact">Contact us and complaints</a></li>
        </ol>
    </div>

    <!-- 1 -->
    <section class="pp-card" id="about">
        <h2><span class="pp-num">1.</span>About this policy</h2>
        <p>
            This Privacy Policy explains how <?php echo pp_e($businessName); ?> ("the resort", "we", "us", "our"),
            located at <?php echo pp_e($businessAddr); ?>, handles personal information collected through its web-based
            online booking system (the "System"). The resort is the personal information controller for the data
            described here.
        </p>
        <p>
            It applies to everyone who uses the System: visitors browsing rooms, guests who book without an account,
            registered users, and resort personnel. It is written in line with the Data Privacy Act of 2012
            (Republic Act No. 10173) and its Implementing Rules and Regulations.
        </p>
        <p>
            The System is built only for <?php echo pp_e($businessName); ?>. It manages room reservations, guest
            information, billing and payment records, and customer feedback. It is not used for payroll, accounting
            of other businesses, or restaurant services.
        </p>
    </section>

    <!-- 2 -->
    <section class="pp-card" id="collect">
        <h2><span class="pp-num">2.</span>Information we collect</h2>
        <p>We collect the following categories of information, depending on how you use the System.</p>

        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>What it includes</th>
                        <th>Collected when</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Account information</strong></td>
                        <td>Full name, email address, phone number, and your password (kept in protected, hashed form, never as plain text).</td>
                        <td>You create an account or sign in.</td>
                    </tr>
                    <tr>
                        <td><strong>Booking information</strong></td>
                        <td>Selected accommodation, check-in and check-out dates, number of guests, details of additional guests, your contact details, and the booking reference number.</td>
                        <td>You submit a booking, as a registered user or as a guest.</td>
                    </tr>
                    <tr>
                        <td><strong>Billing and payment records</strong></td>
                        <td>Room charges, additional-guest fees, other charges and discounts entered by staff, amount, and payment status. Payments are made in cash only, so we do not collect card numbers, e-wallet details, or bank account information.</td>
                        <td>A booking is priced, or staff record a payment at check-in or check-out.</td>
                    </tr>
                    <tr>
                        <td><strong>Feedback</strong></td>
                        <td>Ratings, comments, and suggestions about your stay.</td>
                        <td>A registered user submits feedback from their dashboard.</td>
                    </tr>
                    <tr>
                        <td><strong>Walk-in and staff-entered records</strong></td>
                        <td>Guest and booking details that resort personnel enter on your behalf, such as walk-in bookings, booking changes, and additional charges.</td>
                        <td>You book or change a booking in person, by phone, or by message.</td>
                    </tr>
                    <tr>
                        <td><strong>Technical and security data</strong></td>
                        <td>Session identifiers (cookies), basic browser and device information, and activity logs used to keep the System secure and working.</td>
                        <td>You visit or use the System.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p>
            Please give accurate information. Incorrect contact details can prevent us from sending your booking
            confirmation or reminders.
        </p>
    </section>

    <!-- 3 -->
    <section class="pp-card" id="use">
        <h2><span class="pp-num">3.</span>How we use your information</h2>
        <p>We use your personal information only for the purposes below:</p>
        <ul>
            <li><strong>Accounts:</strong> to create and secure your account and let you sign in.</li>
            <li><strong>Reservations:</strong> to check real-time room availability, detect scheduling conflicts, approve bookings automatically when no conflict exists, and issue a booking reference number.</li>
            <li><strong>Changes and cancellations:</strong> to let registered users modify or cancel bookings up to one (1) week before check-in, and to help resort staff handle requests made after that period.</li>
            <li><strong>Billing:</strong> to compute room charges, additional-guest fees, other applicable fees, and discounts, and to keep accurate payment records.</li>
            <li><strong>Communication:</strong> to send booking confirmations, reference numbers, reminders, and important updates about your stay.</li>
            <li><strong>Feedback:</strong> to collect and moderate guest comments so we can improve our service.</li>
            <li><strong>Operations and reports:</strong> to manage room occupancy and inventory, and to produce reports (for example bookings, occupancy, revenue, and guest statistics) that help management make decisions.</li>
            <li><strong>Security and reliability:</strong> to prevent fraud, spam, and unauthorized access, to keep scheduled backups, and to troubleshoot problems.</li>
            <li><strong>Legal compliance:</strong> to meet record-keeping and other obligations required by Philippine law.</li>
        </ul>
        <p>We do not use your information for automated decisions that have legal effects on you, and we do not sell it.</p>
    </section>

    <!-- 4 -->
    <section class="pp-card" id="basis">
        <h2><span class="pp-num">4.</span>Basis for processing and your consent</h2>
        <p>We process your personal information on one or more of these bases under the Data Privacy Act:</p>
        <ul>
            <li><strong>Your consent</strong>, for example when you agree to this policy while creating an account, or when you submit feedback.</li>
            <li><strong>Performance of a contract</strong>, because we need your details to confirm and deliver your reservation.</li>
            <li><strong>Legitimate interests</strong> of the resort, such as keeping the System secure and keeping accurate operational records.</li>
            <li><strong>Legal obligation</strong>, where the law requires us to keep or disclose certain records.</li>
        </ul>
        <div class="pp-note">
            By reading this policy to the end and tapping <em>"I Agree"</em> on the sign-up page, you confirm that you
            have read it and consent to the collection and use of your personal information as described here.
            You may withdraw your consent at any time (see Section 12), but we may then be unable to keep your account
            or process new bookings.
        </div>
    </section>

    <!-- 5 -->
    <section class="pp-card" id="notifications">
        <h2><span class="pp-num">5.</span>Notifications and reminders</h2>
        <p>
            We use your email address and phone number to send service messages about your reservation, such as
            booking confirmations, reference numbers, status updates, and reminders before your check-in date.
            These may be sent by email or by SMS through the providers described in Section 8.
        </p>
        <p>
            These are transactional messages related to your stay. We do not send marketing messages through the System
            unless you separately agree to receive them.
        </p>
    </section>

    <!-- 6 -->
    <section class="pp-card" id="guest">
        <h2><span class="pp-num">6.</span>Booking as a guest (no account)</h2>
        <p>
            You can submit a booking without registering. In that case we collect only the details needed to make and
            confirm the reservation (such as your name, contact information, dates, and number of guests) and we send
            your confirmation through the contact details you provide.
        </p>
        <p>
            Guests without an account cannot modify or cancel bookings through the System and have no dashboard or
            feedback access. To change or cancel such a booking, contact the resort directly using the details in
            Section 15. The same protections and rights in this policy apply to guest bookings.
        </p>
    </section>

    <!-- 7 -->
    <section class="pp-card" id="feedback">
        <h2><span class="pp-num">7.</span>Feedback and public display</h2>
        <p>
            Registered users can submit ratings and comments. Every entry is reviewed by an administrator before it is
            shown publicly, so inappropriate or irrelevant comments are not published.
        </p>
        <p>
            Feedback that is approved may appear on the resort's landing page, together with the rating and the name
            shown with the review. Please do not include sensitive personal details (such as your address, ID numbers,
            or health information) in your comments. You may ask us to remove or edit your published feedback at any
            time.
        </p>
    </section>

    <!-- 8 -->
    <section class="pp-card" id="sharing">
        <h2><span class="pp-num">8.</span>Who we share information with</h2>
        <p>Your information is shared only as needed, and never sold.</p>

        <h3>Resort personnel</h3>
        <p>
            Authorized administrators and front-desk staff can access booking, guest, billing, inventory, and feedback
            records through the admin dashboard. Access is limited by role so that staff only see what their duties
            require.
        </p>

        <h3>Service providers</h3>
        <p>We rely on a small number of providers to run the System. They may process limited data on our behalf:</p>
        <ul>
            <li><strong>Web hosting and database services</strong>, which store and serve the System and its data.</li>
            <li><strong>Email delivery service</strong> (Gmail), used to send booking confirmations and reminders to your email address.</li>
            <li><strong>SMS gateway provider</strong>, used to send text notifications and one-time codes to your phone number.</li>
            <li><strong>Google Fonts</strong>, which delivers the typefaces used on our pages. Your browser may contact Google's servers to load them.</li>
        </ul>
        <p>These providers may only use your data to deliver their service to us.</p>

        <h3>Legal and safety reasons</h3>
        <p>
            We may disclose information when required by law, a court order, or a lawful request from a government
            authority, or when necessary to protect the rights, safety, or property of the resort, our guests, or others.
        </p>
    </section>

    <!-- 9 -->
    <section class="pp-card" id="cookies">
        <h2><span class="pp-num">9.</span>Cookies and similar technologies</h2>
        <p>
            The System uses a session cookie to keep you signed in and a security token to protect forms from forged
            requests. These are essential for the System to work, so they cannot be switched off while using it.
            We do not use advertising or cross-site tracking cookies.
        </p>
        <p>
            The sign-up form also contains a hidden anti-spam field. It is invisible to people and only helps us
            reject automated sign-ups; it does not collect any personal information.
        </p>
    </section>

    <!-- 10 -->
    <section class="pp-card" id="security">
        <h2><span class="pp-num">10.</span>How we protect your information</h2>
        <p>We use organizational, physical, and technical safeguards appropriate to the sensitivity of the data, including:</p>
        <ul>
            <li>Encrypted authentication, with passwords stored in protected (hashed) form.</li>
            <li>Role-based access control, so only authorized personnel can open the admin dashboard.</li>
            <li>Session management and form protection against forged requests.</li>
            <li>Scheduled database backups to guard against data loss.</li>
            <li>Encrypted connections (HTTPS) where supported by our hosting environment.</li>
        </ul>
        <p>
            No online system can be guaranteed to be 100% secure. Please keep your password private, use a strong and
            unique one, and sign out on shared devices. If you suspect unauthorized access to your account, contact us
            right away. If a personal data breach occurs that is likely to put you at risk, we will notify the National
            Privacy Commission and affected individuals as required by law.
        </p>
    </section>

    <!-- 11 -->
    <section class="pp-card" id="retention">
        <h2><span class="pp-num">11.</span>How long we keep your information</h2>
        <p>We keep personal information only for as long as it is needed for the purposes in this policy:</p>
        <ul>
            <li><strong>Account information</strong> is kept while your account is active, until you ask us to close it.</li>
            <li><strong>Booking, billing, and payment records</strong> are kept as long as needed for operations, reporting, resolving disputes, and meeting legal and record-keeping requirements.</li>
            <li><strong>Feedback</strong> is kept until you ask for its removal or the resort chooses to retire it.</li>
            <li><strong>Backups</strong> are overwritten or deleted on a regular cycle, so removed data may remain in backups for a short time before it is purged.</li>
        </ul>
        <p>When information is no longer needed, we delete it or anonymize it so it can no longer identify you.</p>
    </section>

    <!-- 12 -->
    <section class="pp-card" id="rights">
        <h2><span class="pp-num">12.</span>Your rights</h2>
        <p>Under the Data Privacy Act of 2012, you have the right to:</p>
        <ul>
            <li><strong>Be informed</strong> about how your personal data is processed.</li>
            <li><strong>Access</strong> the personal data we hold about you.</li>
            <li><strong>Object</strong> to processing, including withdrawing your consent.</li>
            <li><strong>Rectify</strong> inaccurate or outdated data. Registered users can also update many details from their dashboard.</li>
            <li><strong>Erase or block</strong> your data when it is no longer needed, was unlawfully obtained, or you withdraw consent, subject to records we must keep by law.</li>
            <li><strong>Data portability</strong>, meaning a copy of your data in a commonly used electronic format where applicable.</li>
            <li><strong>Claim damages</strong> if you are harmed by inaccurate, incomplete, outdated, false, unlawfully obtained, or unauthorized use of your data.</li>
            <li><strong>File a complaint</strong> with the National Privacy Commission.</li>
        </ul>
        <p>
            To exercise any of these rights, contact us using the details in Section 15. We may ask you to verify your
            identity first, and we aim to respond within a reasonable time.
        </p>
    </section>

    <!-- 13 -->
    <section class="pp-card" id="children">
        <h2><span class="pp-num">13.</span>Children</h2>
        <p>
            The System is intended for adults. Accounts should be created only by people aged 18 and above. Bookings
            that include minors should be made by a parent or legal guardian, who is responsible for providing the
            minor's details. If you believe a minor has created an account without a guardian's permission, please
            contact us so we can remove it.
        </p>
    </section>

    <!-- 14 -->
    <section class="pp-card" id="changes">
        <h2><span class="pp-num">14.</span>Changes to this policy</h2>
        <p>
            We may update this policy when the System or the law changes. The version number and effective date at the
            top of this page will show the latest revision. If we make a significant change, we will notify registered
            users through the System or by email. Continuing to use the System after an update means you accept the
            revised policy.
        </p>
    </section>

    <!-- 15 -->
    <section class="pp-card" id="contact">
        <h2><span class="pp-num">15.</span>Contact us and complaints</h2>
        <p>
            For questions about this policy, or to exercise your privacy rights, please contact:
        </p>
        <p>
            <strong><?php echo pp_e($businessName); ?></strong><br>
            <?php echo pp_e($businessAddr); ?><br>
            <?php if ($privacyOfficer !== ''): ?>
                Attn: <?php echo pp_e($privacyOfficer); ?><br>
            <?php endif; ?>
            <?php if ($contactEmail !== ''): ?>
                Email: <a href="mailto:<?php echo pp_e($contactEmail); ?>"><?php echo pp_e($contactEmail); ?></a><br>
            <?php endif; ?>
            <?php if ($contactPhone !== ''): ?>
                Phone: <a href="tel:<?php echo pp_e(preg_replace('/[^0-9+]/', '', $contactPhone)); ?>"><?php echo pp_e($contactPhone); ?></a><br>
            <?php endif; ?>
            <?php if ($contactEmail === '' && $contactPhone === ''): ?>
                You may also visit or call the resort front desk.
            <?php endif; ?>
        </p>
        <p>
            If you believe your privacy rights have been violated and we have not resolved your concern, you may
            lodge a complaint with the <strong>National Privacy Commission</strong> of the Philippines at
            <a href="https://privacy.gov.ph" target="_blank" rel="noopener">privacy.gov.ph</a>.
        </p>
    </section>

    <p class="pp-end">&mdash; End of Privacy Policy &mdash;</p>

<?php if (!$embed): ?>
    <div class="pp-cta">
        <a class="pp-btn" href="register.php">Back to Sign Up</a>
        <a class="pp-btn pp-btn--ghost" href="#about">Back to top</a>
    </div>

    <p class="pp-foot">
        &copy; <?php echo date('Y'); ?> <?php echo pp_e($businessName); ?> &middot; Privacy Policy v<?php echo pp_e($policyVersion); ?>
    </p>
<?php endif; ?>
</div>

<?php if ($embed): ?>
<script>
/* Embedded mode: report scroll progress to the sign-up page modal. */
(function () {
    'use strict';
    if (window.parent === window) { return; }

    var origin = window.location.origin;

    function post(msg) {
        try { window.parent.postMessage(msg, origin); } catch (e) { /* ignore */ }
    }

    function report() {
        var el  = document.documentElement;
        var max = el.scrollHeight - el.clientHeight;
        var top = window.pageYOffset || el.scrollTop || 0;
        var pct = max <= 0 ? 100 : Math.max(0, Math.min(100, Math.round((top / max) * 100)));
        var bottom = max <= 0 || (max - top) <= 24;
        post({ type: 'bienmarko-privacy', pct: pct, bottom: bottom });
    }

    window.addEventListener('scroll', report, { passive: true });
    window.addEventListener('resize', report);
    window.addEventListener('load', function () {
        post({ type: 'bienmarko-privacy-ready' });
        report();
    });
})();
</script>
<?php endif; ?>
</body>
</html>