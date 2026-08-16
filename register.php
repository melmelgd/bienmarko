<?php

require_once __DIR__ . '/includes/auth-common.php';

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
        default     => 'Registration failed. Please try again.',
    };
}

$registerEndpoint = 'user/api/register.php';

$siteName    = 'BienMarko';
$siteTagline = "Bien Marko's Pension House";
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
<script src="assets/js/register.js"></script>
</body>
</html>
