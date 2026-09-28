<?php
require_once __DIR__ . '/../includes/config.php';
redirect_if_logged_in();

$message = '';
$type = 'danger';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        try {
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $key = strtolower($email);
            $countStmt = $conn->prepare('SELECT COUNT(*) n, MIN(attempted_at) oldest FROM reset_attempts WHERE email=? AND ip=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
            $countStmt->bind_param('ss', $key, $ip); $countStmt->execute();
            $attempt = $countStmt->get_result()->fetch_assoc(); $countStmt->close();
            if ((int)$attempt['n'] >= 5) {
                $message = 'Too many requests. Try again in a few minutes.';
            } else {
            $mark = $conn->prepare('INSERT INTO reset_attempts (email,ip) VALUES (?,?)');
            $mark->bind_param('ss', $key, $ip); $mark->execute(); $mark->close();
            $stmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user) {
                // One active reset per account: wipe previous codes
                $del = $conn->prepare('DELETE FROM password_resets WHERE user_id = ?');
                $del->bind_param('i', $user['id']);
                $del->execute();
                $del->close();

                $otp     = otp_generate();
                $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                $expires = date('Y-m-d H:i:s', time() + OTP_TTL_MINUTES * 60);

                $ins = $conn->prepare('INSERT INTO password_resets (user_id, email, otp_hash, expires_at) VALUES (?,?,?,?)');
                $ins->bind_param('isss', $user['id'], $user['email'], $otpHash, $expires);
                $ins->execute();
                $ins->close();

                $devOtp = otp_deliver($user['email'], $user['full_name'], $otp);

                // Dev mode only: show the code on the next screen (no SMTP configured)
                if ($devOtp !== null) {
                    $_SESSION['reset_dev_otp'] = $devOtp;
                }
                $_SESSION['reset_email'] = $user['email'];
            }

            // Same response either way - never reveal whether the email exists
            flash('If that email is registered, a ' . OTP_LENGTH . '-digit verification code has been sent. It expires in ' . OTP_TTL_MINUTES . ' minutes.', 'info');
            header('Location: verify_otp.php' . ($user ? '?email=' . urlencode($email) : ''));
            exit();
            }
        } catch (mysqli_sql_exception $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Forgot password';
$sideTitle = 'Reset access';
$sideText  = 'Enter your registered email and we will send you a secure verification code.';
require __DIR__ . '/_auth_layout_top.php';
?>
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label>Registered email address</label>
                    <input class="input" type="email" name="email" placeholder="you@example.com"
                           value="<?php echo safe($_POST['email'] ?? ''); ?>" required autofocus>
                    <span class="hint">We will send a <?php echo OTP_LENGTH; ?>-digit code to verify it is really you.</span>
                </div>
                <button type="submit" class="auth-btn" style="margin-top:8px">SEND VERIFICATION CODE</button>
            </form>

            <p class="auth-links">Remembered it? <a href="login.php">Sign in</a></p>
            <p class="auth-links"><a href="/index.php">← Back to home</a></p>
<?php require __DIR__ . '/_auth_layout_bottom.php'; ?>
