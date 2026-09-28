<?php
require_once __DIR__ . '/../includes/config.php';
redirect_if_logged_in();

// Only reachable AFTER the OTP has been verified
if (empty($_SESSION['reset_verified_user_id']) || empty($_SESSION['reset_verified_email'])) {
    header('Location: forgot_password.php');
    exit();
}

$userId = (int)$_SESSION['reset_verified_user_id'];
$email  = $_SESSION['reset_verified_email'];

$message = '';
$type = 'danger';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (($err = password_policy_error($new)) !== null) {
        $message = $err;
    } elseif ($new !== $confirm) {
        $message = 'Passwords do not match.';
    } else {
        try {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $conn->prepare('UPDATE users SET password = ? WHERE id = ? AND email = ? LIMIT 1');
            $upd->bind_param('sis', $hash, $userId, $email);
            $upd->execute();
            $changed = $upd->affected_rows;
            $upd->close();

            if ($changed >= 0) {
                // Invalidate every reset code for this account
                $del = $conn->prepare('DELETE FROM password_resets WHERE user_id = ?');
                $del->bind_param('i', $userId);
                $del->execute();
                $del->close();

                unset($_SESSION['reset_verified_user_id'], $_SESSION['reset_verified_email'], $_SESSION['reset_email']);

                flash('Password reset successfully. Sign in with your new password.', 'success');
                header('Location: login.php');
                exit();
            }
            $message = 'Password reset failed. Please try again.';
        } catch (mysqli_sql_exception $e) {
            error_log('Reset password error: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'New password';
$sideTitle = 'Almost done';
$sideText  = 'Identity verified. Choose a strong new password for your account.';
require __DIR__ . '/_auth_layout_top.php';
?>
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label>New password</label>
                    <input class="input" type="password" name="new_password" id="new_password" data-pw-meter placeholder="New password" required autofocus>
                    <span class="hint">8+ chars with upper, lower, number & special character.</span>
                </div>
                <div class="field">
                    <label>Confirm new password</label>
                    <input class="input" type="password" name="confirm_password" id="confirm_password" data-pw-match="#new_password" placeholder="Repeat new password" required>
                </div>
                <button type="submit" class="auth-btn" style="margin-top:8px">RESET PASSWORD</button>
            </form>

            <p class="auth-links"><a href="login.php">← Back to sign in</a></p>
<?php require __DIR__ . '/_auth_layout_bottom.php'; ?>
