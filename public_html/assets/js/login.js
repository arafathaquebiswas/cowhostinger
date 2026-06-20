'use strict';

(function () {
    const form        = document.getElementById('loginForm');
    const emailInput  = document.getElementById('email');
    const passInput   = document.getElementById('password');
    const emailError  = document.getElementById('emailError');
    const passError   = document.getElementById('passwordError');
    const loginBtn    = document.getElementById('loginBtn');
    const btnText     = loginBtn.querySelector('.btn-text');
    const btnSpinner  = loginBtn.querySelector('.btn-spinner');
    const togglePwd   = document.getElementById('togglePwd');
    const eyeShow     = document.getElementById('eyeShow');
    const eyeHide     = document.getElementById('eyeHide');

    // ── Password toggle ───────────────────────────────────────
    togglePwd.addEventListener('click', function () {
        const isPassword = passInput.type === 'password';
        passInput.type   = isPassword ? 'text' : 'password';
        eyeShow.style.display = isPassword ? 'none'  : '';
        eyeHide.style.display = isPassword ? ''      : 'none';
        togglePwd.setAttribute('aria-label',
            isPassword ? 'Hide password' : 'Show password');
    });

    // ── Live validation ───────────────────────────────────────
    emailInput.addEventListener('blur', function () { validateEmail(); });
    passInput.addEventListener('blur',  function () { validatePassword(); });

    emailInput.addEventListener('input', function () {
        if (emailInput.classList.contains('is-invalid')) validateEmail();
    });
    passInput.addEventListener('input', function () {
        if (passInput.classList.contains('is-invalid')) validatePassword();
    });

    function validateEmail() {
        const val = emailInput.value.trim();
        if (val === '') {
            setError(emailInput, emailError, 'Email address is required.');
            return false;
        }
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!re.test(val)) {
            setError(emailInput, emailError, 'Please enter a valid email address.');
            return false;
        }
        clearError(emailInput, emailError);
        return true;
    }

    function validatePassword() {
        const val = passInput.value;
        if (val === '') {
            setError(passInput, passError, 'Password is required.');
            return false;
        }
        clearError(passInput, passError);
        return true;
    }

    function setError(input, errorEl, message) {
        input.classList.add('is-invalid');
        errorEl.textContent = message;
    }

    function clearError(input, errorEl) {
        input.classList.remove('is-invalid');
        errorEl.textContent = '';
    }

    // ── Form submit ───────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        const emailOk = validateEmail();
        const passOk  = validatePassword();

        if (!emailOk || !passOk) {
            e.preventDefault();
            return;
        }

        // Show loading state
        loginBtn.disabled    = true;
        btnText.style.display   = 'none';
        btnSpinner.style.display = '';
    });
}());
