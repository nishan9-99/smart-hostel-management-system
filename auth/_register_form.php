<?php /* Included in the one-card auth page. */ ?>
            <form method="POST" action="/auth/login.php?panel=register">
                <input type="hidden" name="auth_mode" value="register">
                <?php echo csrf_field(); ?>
                <div class="form-grid">
                    <div class="field full">
                        <label>Full name *</label>
                        <input class="input icon-input" type="text" name="full_name" placeholder="Your full name"
                               value="<?php echo safe($_POST['full_name'] ?? ''); ?>" required>
                    </div>
                    <div class="field full">
                        <label>Username *</label>
                        <input class="input" type="text" name="username" id="username" placeholder="4-30 characters"
                               value="<?php echo safe($_POST['username'] ?? ''); ?>" required autocomplete="off">
                        <div class="username-feedback" id="usernameFeedback"></div>
                        <div class="suggestion-box" id="suggestionBox"></div>
                    </div>
                    <div class="field">
                        <label>Email *</label>
                        <input class="input icon-input" type="email" name="email" placeholder="you@example.com"
                               value="<?php echo safe($_POST['email'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label>Country *</label>
                        <input class="input" type="text" name="country" placeholder="India"
                               value="<?php echo safe($_POST['country'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label>Country code *</label>
                        <input class="input" type="text" name="country_code" placeholder="+91"
                               value="<?php echo safe($_POST['country_code'] ?? '+91'); ?>" required>
                    </div>
                    <div class="field">
                        <label>Phone *</label>
                        <input class="input" type="text" name="phone" placeholder="9876543210"
                               value="<?php echo safe($_POST['phone'] ?? ''); ?>" required>
                    </div>
                    <div class="field full">
                        <label>State *</label>
                        <input class="input" type="text" name="state" placeholder="Karnataka"
                               value="<?php echo safe($_POST['state'] ?? ''); ?>" required>
                    </div>
                    <div class="field full">
                        <label>Address *</label>
                        <textarea class="input" name="address" placeholder="Your address" required><?php echo safe($_POST['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="field">
                        <label>Password *</label>
                        <input class="input" type="password" name="password" id="reg_password" data-pw-meter placeholder="Strong password" required>
                        <span class="hint">8+ chars with upper, lower, number & special character.</span>
                    </div>
                    <div class="field">
                        <label>Confirm password *</label>
                        <input class="input" type="password" name="confirm_password" id="reg_confirm" data-pw-match="#reg_password" placeholder="Repeat password" required>
                    </div>
                </div>
                <div class="checkline" style="margin:16px 0">
                    <input type="checkbox" id="agree" name="agree_terms" value="yes" required>
                    <label for="agree">I agree to the hostel rules and terms of use.</label>
                </div>
                <button type="submit" class="auth-btn">CREATE ACCOUNT</button>
            </form>

            <?php require __DIR__ . '/_google_button.php'; ?>
            <p class="auth-links">Already have an account? <a class="auth-switch" href="/auth/login.php">Sign in</a></p>
            <p class="auth-links"><a href="/index.php">← Back to home</a></p>

<script>
(function () {
    var input = document.getElementById('username');
    var feedback = document.getElementById('usernameFeedback');
    var suggestions = document.getElementById('suggestionBox');
    var timer = null;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var val = input.value.trim();
        feedback.textContent = '';
        feedback.className = 'username-feedback';
        suggestions.innerHTML = '';
        if (val.length < 4) return;
        timer = setTimeout(function () { check(val); }, 450);
    });

    function check(val) {
        fetch('check_username.php?username=' + encodeURIComponent(val))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'available') {
                    feedback.textContent = '✓ ' + data.message;
                    feedback.className = 'username-feedback uf-ok';
                } else if (data.status === 'taken') {
                    feedback.textContent = '✗ ' + data.message;
                    feedback.className = 'username-feedback uf-bad';
                    (data.suggestions || []).forEach(function (s) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'sug-btn';
                        b.textContent = s;
                        b.onclick = function () {
                            input.value = s;
                            feedback.textContent = '✓ Username is available.';
                            feedback.className = 'username-feedback uf-ok';
                            suggestions.innerHTML = '';
                        };
                        suggestions.appendChild(b);
                    });
                } else if (data.status === 'invalid' || data.status === 'limited') {
                    feedback.textContent = data.message;
                    feedback.className = 'username-feedback uf-warn';
                }
            })
            .catch(function () {});
    }
})();
</script>
