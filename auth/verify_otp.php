<?php
require_once __DIR__ . '/../includes/config.php';
redirect_if_logged_in();

$email = trim($_GET['email'] ?? ($_SESSION['reset_email'] ?? ''));

/* ---------------- AJAX verify ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    csrf_check();

    $otp    = preg_replace('/[^0-9]/', '', $_POST['otp'] ?? '');
    $emailP = trim($_POST['email'] ?? '');

    $fail = function ($msg, $left = null) {
        echo json_encode(['ok' => false, 'error' => $msg, 'attempts_left' => $left]);
        exit();
    };

    if (strlen($otp) !== OTP_LENGTH) $fail('Enter the ' . OTP_LENGTH . '-digit code.');

    try {
        $stmt = $conn->prepare(
            'SELECT pr.id, pr.user_id, pr.otp_hash, pr.expires_at, pr.attempts
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.email = ? AND pr.used = 0
             ORDER BY pr.id DESC LIMIT 1'
        );
        $stmt->bind_param('s', $emailP);
        $stmt->execute();
        $reset = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$reset) $fail('No active reset request. Please request a new code.');
        if (strtotime($reset['expires_at']) < time()) $fail('That code has expired. Please request a new one.');
        if ((int)$reset['attempts'] >= OTP_MAX_ATTEMPTS) $fail('Too many wrong attempts. Please request a new code.');

        if (!password_verify($otp, $reset['otp_hash'])) {
            $left = OTP_MAX_ATTEMPTS - ((int)$reset['attempts'] + 1);
            $bump = $conn->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?');
            $bump->bind_param('i', $reset['id']);
            $bump->execute();
            $bump->close();
            $fail('Wrong code. ' . max($left, 0) . ' attempt(s) left.', max($left, 0));
        }

        // Correct: single use, then authorize the password change
        $use = $conn->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
        $use->bind_param('i', $reset['id']);
        $use->execute();
        $use->close();

        unset($_SESSION['reset_dev_otp']);
        $_SESSION['reset_verified_user_id'] = (int)$reset['user_id'];
        $_SESSION['reset_verified_email']   = $emailP;

        echo json_encode(['ok' => true, 'redirect' => 'reset_password.php']);
        exit();
    } catch (mysqli_sql_exception $e) {
        error_log('OTP verify error: ' . $e->getMessage());
        $fail('Something went wrong. Please try again.');
    }
}

/* ---------------- Resend ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    csrf_check();
    $emailP = trim($_POST['email'] ?? '');
    if ($emailP !== '') {
        try {
            $stmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param('s', $emailP);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45);
            $key = strtolower($emailP);
            $lim=$conn->prepare('SELECT COUNT(*) FROM reset_attempts WHERE email=? AND ip=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
            $lim->bind_param('ss',$key,$ip);$lim->execute();$n=(int)$lim->get_result()->fetch_row()[0];$lim->close();
            if ($n>=5) { flash('Too many requests. Try again in a few minutes.', 'warning'); header('Location: verify_otp.php'); exit; }
            $mark=$conn->prepare('INSERT INTO reset_attempts (email,ip) VALUES (?,?)');
            $mark->bind_param('ss',$key,$ip);$mark->execute();$mark->close();
            if ($user) {
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
                if ($devOtp !== null) {
                    $_SESSION['reset_dev_otp'] = $devOtp;
                }
                $_SESSION['reset_email'] = $user['email'];
            }
        } catch (mysqli_sql_exception $e) {
            error_log('OTP resend error: ' . $e->getMessage());
        }
    }
    header('Location: verify_otp.php?email=' . urlencode($emailP) . '&resent=1');
    exit();
}

$devOtp = $_SESSION['reset_dev_otp'] ?? null;
$resent = isset($_GET['resent']);
$flash  = flash_pull();

// One-shot warning when SMTP delivery failed (set by otp_deliver())
$mailError = $_SESSION['otp_mail_error'] ?? null;
unset($_SESSION['otp_mail_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify code | Smart Hostel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="otp-page">
    <div class="otp-card">
        <div class="otp-badge">🔐 OTP Verification</div>
        <h2>Verify your email</h2>
        <p class="otp-sub">Enter the <?php echo OTP_LENGTH; ?>-digit code sent to<br><strong><?php echo safe($email !== '' ? $email : 'your email'); ?></strong></p>

        <?php if ($devOtp !== null): ?>
        <div class="otp-dev">
            DEV MODE (no SMTP configured) - your code is <code><?php echo safe($devOtp); ?></code>
        </div>
        <?php endif; ?>

        <?php if ($mailError !== null): ?>
        <div class="alert alert-warning" style="text-align:left"><?php echo safe($mailError); ?></div>
        <?php endif; ?>

        <?php if ($flash): ?>
        <div class="alert alert-<?php echo safe($flash['type']); ?>" style="text-align:left"><?php echo safe($flash['message']); ?></div>
        <?php elseif ($resent): ?>
        <div class="alert alert-info" style="text-align:left">If the email is registered, a fresh code has been sent.</div>
        <?php endif; ?>

        <div class="otp-stage">
            <div class="otp-boxes" id="otpBoxes">
                <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" autocomplete="one-time-code" autofocus>
                <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
            </div>
            <div class="otp-verified" id="otpVerified">✓</div>
        </div>

        <div class="otp-status" id="otpStatus"></div>

        <div class="otp-actions">
            <form method="POST" action="" style="margin:0">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="resend">
                <input type="hidden" name="email" value="<?php echo safe($email); ?>">
                <button type="submit" class="otp-resend" id="resendBtn">Resend code</button>
            </form>
            <a class="otp-back" href="forgot_password.php">← Use a different email</a>
        </div>
    </div>
</div>

<script>
(function () {
    var boxes   = Array.prototype.slice.call(document.querySelectorAll('.otp-digit'));
    var wrap    = document.getElementById('otpBoxes');
    var status  = document.getElementById('otpStatus');
    var csrf    = <?php echo json_encode(csrf_token()); ?>;
    var email   = <?php echo json_encode($email); ?>;
    var busy    = false;

    boxes.forEach(function (box, i) {
        box.addEventListener('input', function () {
            box.value = box.value.replace(/[^0-9]/g, '').slice(0, 1);
            box.classList.toggle('filled', box.value !== '');
            if (box.value !== '' && i < boxes.length - 1) boxes[i + 1].focus();
            maybeSubmit();
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && box.value === '' && i > 0) boxes[i - 1].focus();
        });
        box.addEventListener('paste', function (e) {
            e.preventDefault();
            var digits = (e.clipboardData.getData('text') || '').replace(/[^0-9]/g, '');
            for (var j = 0; j < boxes.length; j++) {
                boxes[j].value = digits[j] || '';
                boxes[j].classList.toggle('filled', !!digits[j]);
            }
            if (digits.length >= boxes.length) { boxes[boxes.length - 1].focus(); maybeSubmit(); }
            else if (digits.length > 0) boxes[digits.length].focus();
        });
    });

    function maybeSubmit() {
        var otp = boxes.map(function (b) { return b.value; }).join('');
        if (otp.length !== boxes.length || busy) return;
        busy = true;
        status.textContent = 'Verifying…';
        status.className = 'otp-status';

        fetch('verify_otp.php?ajax=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(csrf) +
                  '&email=' + encodeURIComponent(email) +
                  '&otp=' + encodeURIComponent(otp)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                status.textContent = 'Code verified!';
                status.className = 'otp-status ok';
                wrap.classList.add('orbit');
                setTimeout(function () {
                    wrap.style.display = 'none';
                    document.getElementById('otpVerified').classList.add('show');
                }, 1350);
                setTimeout(function () { window.location.href = data.redirect; }, 2300);
            } else {
                status.textContent = data.error || 'Verification failed.';
                status.className = 'otp-status err';
                wrap.classList.add('shake');
                setTimeout(function () {
                    wrap.classList.remove('shake');
                    boxes.forEach(function (b) { b.value = ''; b.classList.remove('filled'); });
                    boxes[0].focus();
                    busy = false;
                }, 500);
            }
        })
        .catch(function () {
            status.textContent = 'Network error. Try again.';
            status.className = 'otp-status err';
            busy = false;
        });
    }
})();
</script>
</body>
</html>
