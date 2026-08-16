/* register.js — mirrors login.js patterns */
'use strict';

(function () {

    // ---- Password visibility toggle ----------------------------------------
    const toggleBtn    = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            toggleBtn.setAttribute('aria-pressed', String(isHidden));
            toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    }

    // ---- Password strength meter -------------------------------------------
    const strengthWrap  = document.getElementById('passwordStrength');
    const strengthFill  = document.getElementById('strengthFill');
    const strengthLabel = document.getElementById('strengthLabel');

    const STRENGTH_LABELS = ['', 'Weak', 'Fair', 'Good', 'Strong'];

    function scorePassword(pw) {
        if (!pw) return 0;
        let score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        return Math.min(4, Math.max(0, Math.ceil(score * 4 / 5)));
    }

    if (passwordInput && strengthWrap && strengthFill && strengthLabel) {
        passwordInput.addEventListener('input', function () {
            const pw = passwordInput.value;
            if (!pw) {
                strengthWrap.classList.remove('is-visible');
                return;
            }
            strengthWrap.classList.add('is-visible');
            const level = scorePassword(pw);
            strengthFill.setAttribute('data-level', level);
            strengthLabel.setAttribute('data-level', level);
            strengthLabel.textContent = STRENGTH_LABELS[level] || '';
        });
    }

    // ---- Status message helpers --------------------------------------------
    const statusEl = document.getElementById('registerStatus');

    function showError(msg) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.classList.add('is-visible');
        statusEl.classList.remove('is-success');
    }

    function clearStatus() {
        if (!statusEl) return;
        statusEl.textContent = '';
        statusEl.classList.remove('is-visible', 'is-success');
    }

    // ---- Client-side validation --------------------------------------------
    function validateForm(data) {
        const fullName = data.get('full_name').trim();
        const email    = data.get('email').trim();
        const phone    = data.get('phone').trim();
        const password = data.get('password');

        if (!fullName || !email || !phone || !password) {
            return 'All fields are required.';
        }
        if (fullName.length < 2) {
            return 'Please enter your full name.';
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            return 'Please enter a valid email address.';
        }
        if (!/^\+?[\d\s\-()\u00B7]{7,20}$/.test(phone)) {
            return 'Please enter a valid phone number (7–15 digits).';
        }
        if (password.length < 8) {
            return 'Password must be at least 8 characters.';
        }
        return null;
    }

    // ---- Success overlay sequence ------------------------------------------
    //
    //  Timeline (all delays from the moment showSuccessOverlay() is called):
    //    0 ms  — overlay fades in, spinner is already spinning (CSS animation)
    //  800 ms  — spinner fades out, checkmark SVG begins drawing in
    // 1 100 ms — title + subtitle fade up
    // 3 000 ms — redirect to login.php
    //
    const overlay = document.getElementById('regSuccess');

    function showSuccessOverlay() {
        if (!overlay) {
            // Fallback — no overlay element for some reason, just redirect
            window.location.href = 'login.php';
            return;
        }

        // Step 1: make overlay visible (spinner already spinning via CSS)
        overlay.removeAttribute('aria-hidden');
        overlay.classList.add('is-visible');

        // Step 2: swap spinner → checkmark
        setTimeout(function () {
            overlay.classList.add('is-done');
        }, 800);

        // Step 3: reveal title + subtitle
        setTimeout(function () {
            overlay.classList.add('has-text');
        }, 1100);

        // Step 4: navigate to login
        setTimeout(function () {
            window.location.href = 'login.php';
        }, 3000);
    }

    // ---- Form submit via fetch (mirrors login.js pattern) ------------------
    const form      = document.getElementById('registerForm');
    const submitBtn = document.getElementById('registerSubmit');

    if (!form || !submitBtn) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearStatus();

        const formData = new FormData(form);

        // Client-side validation first
        const validationError = validateForm(formData);
        if (validationError) {
            showError(validationError);
            return;
        }

        // Build JSON payload (mirrors login.js)
        const payload = {
            csrf_token: formData.get('csrf_token'),
            website:    formData.get('website') || '',   // honeypot
            full_name:  formData.get('full_name').trim(),
            email:      formData.get('email').trim().toLowerCase(),
            phone:      formData.get('phone').trim(),
            password:   formData.get('password'),
        };

        // Loading state on button
        submitBtn.disabled = true;
        submitBtn.classList.add('is-loading');

        try {
            const resp = await fetch(form.action, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });

            const json = await resp.json().catch(() => null);

            if (json && json.success) {
                // Success path: hide the button spinner, show the full-screen
                // overlay with its own loading → checkmark → text sequence.
                submitBtn.classList.remove('is-loading');
                showSuccessOverlay();
                return;
            }

            // Error path: re-enable the button and show the message inline.
            showError((json && json.message) || 'Registration failed. Please try again.');

        } catch {
            showError('Network error. Please check your connection and try again.');
        } finally {
            // Only re-enable if we didn't navigate away (error path).
            // On success the overlay is showing so we leave the button disabled.
            if (!overlay || !overlay.classList.contains('is-visible')) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('is-loading');
            }
        }
    });

})();
