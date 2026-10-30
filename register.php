<?php

require_once __DIR__ . '/includes/auth-common.php';
require_once __DIR__ . '/includes/site-settings.php';

bienmarko_start_session();

// Already signed in? Skip straight to the right dashboard.
if (bienmarko_is_logged_in()) {
    header('Location: ' . bienmarko_dashboard_path($_SESSION['auth_role']));
    exit;
}

$csrfToken = bienmarko_csrf_token();

$errorCode    = $_GET['error'] ?? '';
$errorMessage = '';

if ($errorCode !== '') {
    $errorMessage = match ($errorCode) {
        'csrf'      => 'Invalid request. Please refresh and try again.',
        'exists'    => 'An account with that email already exists.',
        'invalid'   => 'Please fill in all fields correctly.',
        'weak_pass' => 'Password must be at least 8 characters.',
        'phone'     => 'Phone number must be 7–15 digits.',
        'privacy'   => 'Please read and accept the Privacy Policy to create an account.',
        default     => 'Registration failed. Please try again.',
    };
}

$registerEndpoint = 'user/api/register.php';
$privacyPolicyUrl = 'privacy-policy.php';

// Both come from Admin > Settings > General (property name).
$siteBrand   = bienmarko_site_brand();
$siteName    = $siteBrand['short'];
$siteTagline = $siteBrand['full'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Create Account · <?php echo htmlspecialchars($siteName); ?></title>
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/login.css">
<link rel="stylesheet" href="assets/css/register.css">
<style>
/* Privacy Policy consent — page-scoped styles */
.consent {
    margin: 4px 0 2px;
}

.consent__row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    font-size: 0.86rem;
    line-height: 1.5;
    color: var(--text-muted, rgba(255, 255, 255, 0.72));
}

.consent__box {
    flex: none;
    width: 20px;
    height: 20px;
    margin: 1px 0 0;
    accent-color: var(--gold-400, #d4af37);
    cursor: pointer;
}

.consent__text a {
    color: var(--gold-300, #e6c55a);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 3px;
}

.consent__text a:hover {
    color: var(--gold-400, #d4af37);
}

.consent__hint {
    margin: 6px 0 0 30px;
    font-size: 0.76rem;
    line-height: 1.45;
    color: var(--text-muted, rgba(255, 255, 255, 0.55));
}

.consent.is-invalid .consent__row {
    color: var(--red, #f87171);
}

.consent.is-invalid .consent__box {
    outline: 2px solid var(--red, #f87171);
    outline-offset: 2px;
    border-radius: 4px;
}
.consent__hint.is-done {
    color: var(--emerald, #34d399);
}

/* Privacy Policy modal */
html.ppm-lock,
html.ppm-lock body {
    overflow: hidden;
}

.ppm {
    position: fixed;
    inset: 0;
    z-index: 900;
    display: none;
    align-items: flex-end;
    justify-content: center;
}

.ppm.is-open {
    display: flex;
}

.ppm [hidden] {
    display: none !important;
}

.ppm__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.72);
    -webkit-backdrop-filter: blur(4px);
    backdrop-filter: blur(4px);
}

.ppm__panel {
    position: relative;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 760px;
    height: 92vh;
    height: 92dvh;
    background: #15130f;
    border: 1px solid rgba(212, 175, 55, 0.22);
    border-radius: 18px 18px 0 0;
    box-shadow: 0 -12px 50px rgba(0, 0, 0, 0.55);
    overflow: hidden;
    outline: none;
    animation: ppmUp 0.25s ease-out;
}

@keyframes ppmUp {
    from { transform: translateY(24px); opacity: 0; }
    to   { transform: none; opacity: 1; }
}

.ppm__head {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.ppm__title {
    margin: 0;
    font-family: var(--font-display, 'Fraunces', Georgia, serif);
    font-size: 1.15rem;
    color: #ece7da;
}

.ppm__close {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: transparent;
    color: #ece7da;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
}

.ppm__body {
    position: relative;
    flex: 1 1 auto;
    min-height: 0;
    background: #0e0d0b;
}

.ppm__frame {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    background: #0e0d0b;
}

.ppm__loading {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    background: #0e0d0b;
    color: #a8a293;
    font-size: 0.9rem;
}

.ppm__loading.is-hidden {
    display: none;
}

.ppm__foot {
    flex: none;
    padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    background: #15130f;
}

.ppm__progress {
    height: 5px;
    border-radius: 99px;
    background: rgba(255, 255, 255, 0.1);
    overflow: hidden;
}

.ppm__progress span {
    display: block;
    height: 100%;
    width: 0;
    background: linear-gradient(90deg, #e6c55a, #b8941f);
    transition: width 0.15s linear;
}

.ppm__hint {
    margin: 8px 0 12px;
    font-size: 0.8rem;
    line-height: 1.45;
    color: #a8a293;
}

.ppm__hint.is-ready {
    color: #34d399;
}

.ppm__actions {
    display: flex;
    gap: 10px;
}

.ppm__btn {
    flex: 1;
    min-height: 46px;
    padding: 0 18px;
    border-radius: 12px;
    border: 1px solid transparent;
    font: 600 0.92rem/1 var(--font-body, 'Inter', system-ui, sans-serif);
    cursor: pointer;
}

.ppm__btn--ghost {
    background: transparent;
    color: #e6c55a;
    border-color: rgba(212, 175, 55, 0.3);
}

.ppm__btn--primary {
    background: linear-gradient(135deg, #e6c55a, #b8941f);
    color: #15120a;
}

.ppm__btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    filter: grayscale(0.4);
}

@media (min-width: 640px) {
    .ppm {
        align-items: center;
        padding: 24px;
    }

    .ppm__panel {
        height: min(86vh, 780px);
        border-radius: 18px;
    }

    .ppm__btn {
        flex: none;
        min-width: 140px;
    }

    .ppm__actions {
        justify-content: flex-end;
    }
}

@media (prefers-reduced-motion: reduce) {
    .ppm__panel { animation: none; }
    .ppm__progress span { transition: none; }
}
</style>
</head>
<body>

<!-- =====================================================================
     Success overlay — hidden until JS triggers it after API response.
     Sequence: spinner (1.2 s) → checkmark fades in → message appears
     → redirect to login.php after a short pause.
     ===================================================================== -->
<div class="reg-success" id="regSuccess" aria-hidden="true" aria-live="assertive">
    <div class="reg-success__box">
        <div class="reg-success__icon-wrap" id="regSuccessIconWrap">
            <!-- Spinner shown first -->
            <svg class="reg-success__spinner" viewBox="0 0 50 50" aria-hidden="true">
                <circle cx="25" cy="25" r="20" fill="none" stroke-width="4"></circle>
            </svg>
            <!-- Checkmark revealed after spinner -->
            <svg class="reg-success__check" viewBox="0 0 52 52" aria-hidden="true">
                <circle cx="26" cy="26" r="25" fill="none"></circle>
                <path fill="none" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" d="M14 27l8 8 16-16"></path>
            </svg>
        </div>
        <p class="reg-success__title" id="regSuccessTitle">Account Created!</p>
        <p class="reg-success__sub" id="regSuccessSub">Redirecting you to sign in…</p>
    </div>
</div>

<div class="lobby">
    <div class="lobby__glow" aria-hidden="true"></div>

    <div class="lobby__content">
        <div class="lobby__brand">
            <span class="lobby__mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 11l9-8 9 8"></path>
                    <path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"></path>
                </svg>
            </span>
            <div>
                <div class="lobby__brand-name"><?php echo htmlspecialchars($siteName); ?></div>
                <div class="lobby__brand-tagline"><?php echo htmlspecialchars($siteTagline); ?></div>
            </div>
        </div>

        <div class="lobby__card">
            <div class="lobby__heading">
                <h1>Create your account</h1>
                <p>Sign up to book rooms and manage your reservations.</p>
            </div>

            <form class="login-form" id="registerForm" method="POST" action="<?php echo htmlspecialchars($registerEndpoint); ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- Honeypot — real visitors never see this field -->
                <div class="hp-field" aria-hidden="true">
                    <label for="website">Leave this field blank</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="field">
                    <label for="full_name">Full Name</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"></path>
                        </svg>
                        <input type="text" id="full_name" name="full_name" placeholder="Juan dela Cruz" required autocomplete="name" maxlength="200">
                    </div>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                            <path d="M3 7l9 6 9-6"></path>
                        </svg>
                        <input type="email" id="email" name="email" placeholder="you@example.com" required autocomplete="email" maxlength="200">
                    </div>
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7A2 2 0 0 1 22 16.9z"></path>
                        </svg>
                        <input type="tel" id="phone" name="phone" placeholder="09171234567" required autocomplete="tel" maxlength="20">
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input class="has-trailing-icon" type="password" id="password" name="password" placeholder="At least 8 characters" required autocomplete="new-password" maxlength="200">
                        <button type="button" class="field__toggle-visibility" id="togglePassword" aria-pressed="false" aria-label="Show password">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 3l18 18"></path>
                                <path d="M10.6 10.6a3 3 0 0 0 4.24 4.24"></path>
                                <path d="M9.4 5.3A9.6 9.6 0 0 1 12 5c6.5 0 10 7 10 7a13.5 13.5 0 0 1-3.1 3.9M6.2 6.2A13.7 13.7 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 4.2-.9"></path>
                            </svg>
                        </button>
                    </div>
                    <!-- Password strength bar -->
                    <div class="password-strength" id="passwordStrength" aria-hidden="true">
                        <div class="password-strength__bar">
                            <span class="password-strength__fill" id="strengthFill"></span>
                        </div>
                        <span class="password-strength__label" id="strengthLabel"></span>
                    </div>
                </div>

                <!-- Privacy Policy consent — required before an account can be created -->
                <div class="consent" id="consentField">
                    <label class="consent__row" for="agreePrivacy">
                        <input class="consent__box" type="checkbox" id="agreePrivacy" name="agree_privacy" value="1" required aria-required="true" aria-describedby="consentHint">
                        <span class="consent__text">
                            I have read and agree to the
                            <a href="<?php echo htmlspecialchars($privacyPolicyUrl); ?>" target="_blank" rel="noopener">Privacy Policy</a>
                            and consent to the collection and use of my personal information.
                        </span>
                    </label>
                    <p class="consent__hint" id="consentHint">
                        Tap the Privacy Policy link, read it to the end, then tap "I Agree".
                    </p>
                </div>

                <p class="login-form__status<?php echo $errorMessage !== '' ? ' is-visible' : ''; ?>" id="registerStatus" role="alert" aria-live="assertive"><?php echo htmlspecialchars($errorMessage); ?></p>

                <button type="submit" class="btn" id="registerSubmit">
                    <span class="btn__spinner" aria-hidden="true"></span>
                    <span class="btn__label">Sign Up</span>
                </button>
            </form>
        </div>

        <p class="lobby__footnote">
            Already have an account?
            <a href="login.php" class="lobby__footnote-link">Log in</a>
        </p>
    </div>
</div>

<!-- Privacy Policy modal. The policy is loaded from privacy-policy.php?embed=1,
     which reports scroll progress back to this page. "I Agree" stays disabled
     until the reader reaches the bottom. -->
<div class="ppm" id="privacyModal" role="dialog" aria-modal="true" aria-labelledby="privacyModalTitle" aria-hidden="true">
    <div class="ppm__backdrop" id="privacyBackdrop"></div>
    <div class="ppm__panel" id="privacyPanel" tabindex="-1">
        <div class="ppm__head">
            <h2 class="ppm__title" id="privacyModalTitle">Privacy Policy</h2>
            <button type="button" class="ppm__close" id="privacyClose" aria-label="Close privacy policy">&times;</button>
        </div>

        <div class="ppm__body">
            <iframe class="ppm__frame" id="privacyFrame" title="Privacy Policy" data-src="<?php echo htmlspecialchars($privacyPolicyUrl); ?>?embed=1"></iframe>
            <div class="ppm__loading" id="privacyLoading">
                <span id="privacyLoadMsg">Loading privacy policy…</span>
                <button type="button" class="ppm__btn ppm__btn--ghost" id="privacyRetry" hidden>Retry</button>
            </div>
        </div>

        <div class="ppm__foot">
            <div class="ppm__progress" id="privacyProgress" role="progressbar" aria-label="Reading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span id="privacyFill"></span>
            </div>
            <p class="ppm__hint" id="privacyHint" aria-live="polite">Scroll to the bottom of the policy to enable "I Agree".</p>
            <div class="ppm__actions">
                <button type="button" class="ppm__btn ppm__btn--ghost" id="privacyCancel">Cancel</button>
                <button type="button" class="ppm__btn ppm__btn--primary" id="privacyAgree" disabled>I Agree</button>
            </div>
        </div>
    </div>
</div>

<!-- Privacy consent logic. Must load BEFORE register.js so the guard can stop
     the submission (capture phase + stopImmediatePropagation) when unchecked. -->
<script>
(function () {
    'use strict';

    var form        = document.getElementById('registerForm');
    var box         = document.getElementById('agreePrivacy');
    var field       = document.getElementById('consentField');
    var consentHint = document.getElementById('consentHint');
    var status      = document.getElementById('registerStatus');
    var submit      = document.getElementById('registerSubmit');

    var modal     = document.getElementById('privacyModal');
    var panel     = document.getElementById('privacyPanel');
    var backdrop  = document.getElementById('privacyBackdrop');
    var frame     = document.getElementById('privacyFrame');
    var closeBtn  = document.getElementById('privacyClose');
    var cancelBtn = document.getElementById('privacyCancel');
    var agreeBtn  = document.getElementById('privacyAgree');
    var fill      = document.getElementById('privacyFill');
    var progress  = document.getElementById('privacyProgress');
    var hint      = document.getElementById('privacyHint');
    var loading   = document.getElementById('privacyLoading');
    var loadMsg   = document.getElementById('privacyLoadMsg');
    var retryBtn  = document.getElementById('privacyRetry');
    var policyLink = field ? field.querySelector('a') : null;

    if (!form || !box || !field || !status || !modal || !frame) { return; }

    var MSG_UNREAD  = 'Please read the Privacy Policy to the end and tap "I Agree" to create an account.';
    var MSG_UNCHECK = 'Please read and accept the Privacy Policy to create an account.';
    var HINT_DEFAULT = consentHint ? consentHint.textContent : '';

    var hasRead       = false;   // user finished the policy and tapped "I Agree" at least once
    var reachedBottom = false;   // scrolled to the end during this page view
    var maxPct        = 0;
    var loaded        = false;
    var loadTimer     = null;
    var lastFocus     = null;
    var shownMessage  = '';

    /* ---------- consent field helpers ---------- */

    function showError() {
        shownMessage = hasRead ? MSG_UNCHECK : MSG_UNREAD;
        field.classList.add('is-invalid');
        status.textContent = shownMessage;
        status.classList.add('is-visible');
        box.focus();
    }

    function clearError() {
        field.classList.remove('is-invalid');
        if (shownMessage !== '' && status.textContent === shownMessage) {
            status.textContent = '';
            status.classList.remove('is-visible');
        }
        shownMessage = '';
    }

    function refreshConsentHint() {
        if (!consentHint) { return; }
        if (!hasRead) {
            consentHint.textContent = HINT_DEFAULT;
            consentHint.classList.remove('is-done');
        } else if (box.checked) {
            consentHint.textContent = 'Thank you. You have read and agreed to the Privacy Policy.';
            consentHint.classList.add('is-done');
        } else {
            consentHint.textContent = 'Tick the box to confirm your agreement.';
            consentHint.classList.remove('is-done');
        }
    }

    /* ---------- modal: loading + progress ---------- */

    function setProgress(pct) {
        fill.style.width = pct + '%';
        progress.setAttribute('aria-valuenow', String(pct));
    }

    function unlockAgree() {
        if (reachedBottom) { return; }
        reachedBottom = true;
        setProgress(100);
        agreeBtn.disabled = false;
        hint.textContent = 'You have reached the end. Tap "I Agree" to continue.';
        hint.classList.add('is-ready');
    }

    function loadFrame(isRetry) {
        loaded = false;
        loading.classList.remove('is-hidden');
        loadMsg.textContent = 'Loading privacy policy…';
        retryBtn.hidden = true;
        clearTimeout(loadTimer);
        loadTimer = setTimeout(function () {
            if (!loaded) {
                loadMsg.textContent = 'Could not load the privacy policy. Check your connection and try again.';
                retryBtn.hidden = false;
            }
        }, 8000);
        var src = frame.getAttribute('data-src');
        frame.src = isRetry ? src + '&t=' + Date.now() : src;
    }

    /* ---------- modal: open / close ---------- */

    function openModal() {
        lastFocus = document.activeElement;
        if (!frame.getAttribute('src')) { loadFrame(false); }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('ppm-lock');
        panel.focus();
    }

    function closeModal(agreed) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('ppm-lock');
        var target = agreed ? box : lastFocus;
        if (target && typeof target.focus === 'function') { target.focus(); }
    }

    function isOpen() {
        return modal.classList.contains('is-open');
    }

    /* ---------- events: modal ---------- */

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin) { return; }
        if (!frame.contentWindow || e.source !== frame.contentWindow) { return; }
        var d = e.data;
        if (!d || typeof d !== 'object') { return; }

        if (d.type === 'bienmarko-privacy-ready') {
            loaded = true;
            clearTimeout(loadTimer);
            loading.classList.add('is-hidden');
            try { frame.contentWindow.focus(); } catch (err) { /* ignore */ }
            return;
        }

        if (d.type === 'bienmarko-privacy') {
            if (!loaded) {
                loaded = true;
                clearTimeout(loadTimer);
                loading.classList.add('is-hidden');
            }
            var pct = Number(d.pct) || 0;
            if (pct > maxPct) { maxPct = pct; }
            if (!reachedBottom) { setProgress(maxPct); }
            if (d.bottom === true) { unlockAgree(); }
        }
    });

    agreeBtn.addEventListener('click', function () {
        if (!reachedBottom) { return; }
        hasRead = true;
        box.checked = true;
        clearError();
        refreshConsentHint();
        closeModal(true);
    });

    cancelBtn.addEventListener('click', function () { closeModal(false); });
    closeBtn.addEventListener('click', function () { closeModal(false); });
    backdrop.addEventListener('click', function () { closeModal(false); });
    retryBtn.addEventListener('click', function () { loadFrame(true); });

    document.addEventListener('keydown', function (e) {
        if (!isOpen()) { return; }

        if (e.key === 'Escape' || e.key === 'Esc') {
            e.preventDefault();
            closeModal(false);
            return;
        }

        if (e.key === 'Tab') {
            var items = panel.querySelectorAll('button:not([disabled]):not([hidden]), iframe');
            if (!items.length) { return; }
            var first = items[0];
            var last  = items[items.length - 1];
            var active = document.activeElement;
            if (!panel.contains(active)) {
                e.preventDefault();
                first.focus();
            } else if (e.shiftKey && active === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    /* ---------- events: consent checkbox + link ---------- */

    // Until the policy has been read, the checkbox opens the modal instead of ticking.
    box.addEventListener('click', function (e) {
        if (hasRead) { return; }
        e.preventDefault();
        openModal();
    });

    if (policyLink) {
        policyLink.addEventListener('click', function (e) {
            e.preventDefault();
            openModal();
        });
    }

    box.addEventListener('change', function () {
        if (box.checked) { clearError(); }
        refreshConsentHint();
    });

    /* ---------- submit guard ---------- */

    function guard(e) {
        if (box.checked) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        showError();
    }

    // Capture phase so this runs before any handler registered by register.js.
    form.addEventListener('submit', guard, true);
    if (submit) { submit.addEventListener('click', guard, true); }
})();
</script>
<script src="assets/js/register.js"></script>
</body>
</html>