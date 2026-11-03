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
$waitSeconds  = isset($_GET['wait']) ? max(0, (int) $_GET['wait']) : 0;
$errorMessage = $errorCode !== '' ? bienmarko_auth_message($errorCode, $waitSeconds) : '';

$resetNotice = isset($_GET['reset']) && $_GET['reset'] === '1'
    ? 'Password updated. You can now log in with your new password.'
    : '';

$loginEndpoint = 'user/api/auth.php';

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
<title>Log In · <?php echo htmlspecialchars($siteName); ?></title>
<link rel="icon" href="data:,">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
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
                <div class="lobby__brand-tagline" style="font-size: 28px;">Pension House</div>
            </div>
        </div>

        <div class="lobby__card">
            <div class="lobby__heading">
                <h1>Welcome back</h1>
                <p>Sign in to continue to your dashboard.</p>
            </div>

            <form class="login-form" id="loginForm" method="POST" action="<?php echo htmlspecialchars($loginEndpoint); ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- Honeypot — real visitors never see this field; see includes/auth-common.php -->
                <div class="hp-field" aria-hidden="true">
                    <label for="website">Leave this field blank</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                            <path d="M3 7l9 6 9-6"></path>
                        </svg>
                        <input type="email" id="email" name="email" placeholder="you@example.com" required autocomplete="username">
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="field__control">
                        <svg class="field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input class="has-trailing-icon" type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
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
                </div>

                <div class="checkbox-row" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                    <label class="checkbox">
                        <input type="checkbox" name="remember" id="remember">
                        <span>Keep me signed in on this device</span>
                    </label>
                    <a href="forgot-password.php" class="lobby__footnote-link">Forgot password?</a>
                </div>

                <?php if ($resetNotice !== '' && $errorMessage === ''): ?>
                <p class="login-form__status is-visible" role="status" style="color:#34d399"><?php echo htmlspecialchars($resetNotice); ?></p>
                <?php endif; ?>

                <p class="login-form__status<?php echo $errorMessage !== '' ? ' is-visible' : ''; ?>" id="loginStatus" role="alert" aria-live="assertive" data-wait="<?php echo (int) $waitSeconds; ?>"><?php echo htmlspecialchars($errorMessage); ?></p>

                <button type="submit" class="btn" id="loginSubmit">
                    <span class="btn__spinner" aria-hidden="true"></span>
                    <span class="btn__label">Log In</span>
                </button>
            </form>
        </div>

        <p class="lobby__footnote">Don't have an account? <a href="register.php">Sign up</a></p>
    </div>
</div>
<script src="assets/js/login.js"></script>
</body>
</html>