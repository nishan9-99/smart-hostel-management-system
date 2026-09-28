<?php
require_once __DIR__ . '/../includes/config.php';
redirect_if_logged_in();

$message = '';
$type = 'danger';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['auth_mode'] ?? '') !== 'register') {
    csrf_check();

    $login_id = trim($_POST['login_id'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($login_id === '' || $password === '') {
        $message = 'Please enter your login details and password.';
    } else {
        // Login by email, username, or phone (with or without +91)
        $phone_plain = $login_id;
        $phone_coded = $login_id;
        if (preg_match('/^[0-9]{10}$/', $login_id)) {
            $phone_coded = '+91 ' . $login_id;
        }

        try {
            // Count failures against the submitted identifier even if no account exists.
            // This keeps account existence out of the response. OAuth does not use this table.
            $identifier = strtolower($login_id);
            if (strlen($identifier) > 255) {
                $message = 'Invalid login details. Please check and try again.';
            } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS failures, MIN(attempted_at) AS oldest FROM login_attempts WHERE identifier=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $stmt->bind_param('s', $identifier);
            $stmt->execute();
            $attempts = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int)$attempts['failures'] >= 5) {
                $remaining = max(1, 15 - (int)floor((time() - strtotime($attempts['oldest'])) / 60));
                $message = "Too many attempts, try again in {$remaining} minutes.";
            } else {
            $stmt = $conn->prepare(
                "SELECT * FROM users
                 WHERE (email = ? OR username = ? OR phone = ? OR phone = ?)
                   AND status = 'active'
                 LIMIT 1"
            );
            $stmt->bind_param('ssss', $login_id, $login_id, $phone_plain, $phone_coded);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Hashed passwords only - no plaintext fallback.
            if ($user && password_verify($password, $user['password'])) {
                $clear = $conn->prepare('DELETE FROM login_attempts WHERE identifier=?');
                $clear->bind_param('s', $identifier);
                $clear->execute(); $clear->close();
                // Refresh the session ID and its cookie with the chosen lifetime.
                // A zero lifetime remains a browser-session cookie.
                $keepSignedIn = ($_POST['keep_signed_in'] ?? null) === '1';
                session_apply_cookie_lifetime($keepSignedIn);
                session_regenerate_id(true);
                $_SESSION['keep_signed_in'] = $keepSignedIn;
                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['phone']     = $user['phone'];
                $_SESSION['role']      = $user['role'];

                header('Location: ' . ($user['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php'));
                exit();
            }

            $stmt = $conn->prepare('INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)');
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $stmt->bind_param('ss', $identifier, $ip);
            $stmt->execute(); $stmt->close();
            $message = 'Invalid login details. Please check and try again.';
            }
            }
        } catch (mysqli_sql_exception $e) {
            error_log('Login error: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
}

$flash = flash_pull();
if ($flash) { $message = $flash['message']; $type = $flash['type']; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['auth_mode'] ?? '') === 'register') {
    require __DIR__ . '/_register_action.php';
}

$pageTitle = 'Sign in';
$sideTitle = 'A space to call yours.';
$sideText  = 'Everything about hostel life, in one place.';
require __DIR__ . '/_auth_layout_top.php';
?>
            <div class="auth-form-panel auth-form-login" id="panel-login">
            <h2 class="serif-heading">Welcome back.</h2>
            <p class="auth-sub">Sign in to your space.</p>
            <form method="POST" action="/auth/login.php">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label>Email, Username or Phone</label>
                    <input class="input icon-input" type="text" name="login_id" placeholder="you@example.com"
                           value="<?php echo safe($_POST['login_id'] ?? ''); ?>" required autofocus>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input class="input icon-input" type="password" name="password" id="login_password" placeholder="Your password" required>
                </div>
                <label class="checkline" style="margin:12px 0"><input type="checkbox" name="keep_signed_in" value="1"> Keep me signed in for 30 days</label>
                <button type="submit" class="auth-btn" style="margin-top:8px">SIGN IN</button>
            </form>

            <p class="auth-links"><a href="forgot_password.php">Forgot your password?</a> · <a href="verify_registration.php">Verify registration</a></p>
            <?php require __DIR__ . '/_google_button.php'; ?>
            <p class="auth-links">New here? <a class="auth-switch" href="/auth/login.php?panel=register">Create a student account</a></p>
            <p class="auth-links"><a href="/index.php">← Back to home</a></p>
            </div>
            <div class="auth-form-panel auth-form-register" id="panel-register">
              <h2 class="serif-heading">Start the first page.</h2>
              <p class="auth-sub">Create a student account.</p>
              <?php require __DIR__ . '/_register_form.php'; ?>
            </div>
            <div class="auth-decor" aria-hidden="true"><span>SMART HOSTEL</span><strong class="decor-login">Welcome<br><em>back.</em></strong><strong class="decor-register">Start the<br><em>first page.</em></strong><small>One space for your room, your people and everything in between.</small></div>
<?php require __DIR__ . '/_auth_layout_bottom.php'; ?>
