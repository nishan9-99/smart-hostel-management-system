<?php
require_once __DIR__ . '/../includes/config.php';
redirect_if_logged_in();
$email = trim($_SESSION['registration_email'] ?? ($_POST['email'] ?? ''));
$message=''; $type='danger';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $action = $_POST['action'] ?? 'verify';
    try {
        $stmt = $conn->prepare("SELECT u.id, u.full_name, u.email, rv.otp_hash, rv.attempts, rv.expires_at FROM users u LEFT JOIN registration_verifications rv ON rv.user_id=u.id WHERE u.email=? AND u.role='student' AND u.status='pending_verification' LIMIT 1");
        $stmt->bind_param('s', $email); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) { $message='No pending registration for that email.'; }
        elseif ($action === 'resend') {
            // Reuse the email + IP request limit for sends, including resend.
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45); $key=strtolower($email);
            $lim=$conn->prepare('SELECT COUNT(*) n FROM reset_attempts WHERE email=? AND ip=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
            $lim->bind_param('ss',$key,$ip);$lim->execute();$n=(int)$lim->get_result()->fetch_row()[0];$lim->close();
            if ($n>=5) $message='Too many requests. Try again in a few minutes.';
            else {
                $record=$conn->prepare('INSERT INTO reset_attempts (email,ip) VALUES (?,?)');$record->bind_param('ss',$key,$ip);$record->execute();$record->close();
                $otp=otp_generate();$hash=password_hash($otp,PASSWORD_DEFAULT);$expires=date('Y-m-d H:i:s',time()+OTP_TTL_MINUTES*60);
                $upd=$conn->prepare('INSERT INTO registration_verifications (user_id,otp_hash,expires_at,attempts) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE otp_hash=VALUES(otp_hash),expires_at=VALUES(expires_at),attempts=0');
                $upd->bind_param('iss',$row['id'],$hash,$expires);$upd->execute();$upd->close();
                $dev=otp_deliver($email,$row['full_name'],$otp,'registration verification');
                unset($_SESSION['registration_dev_otp']);
                if ($dev!==null) $_SESSION['registration_dev_otp']=$dev;
                $message='A new code was requested. Check your email.'; $type='info';
            }
        } else {
            $otp=(string)($_POST['otp']??'');
            if (!preg_match('/^[0-9]{4}$/D',$otp)) $message='Enter the 4-digit code.';
            elseif (!$row['otp_hash'] || strtotime($row['expires_at'])<time()) $message='Code expired. Request a new one.';
            elseif ((int)$row['attempts']>=OTP_MAX_ATTEMPTS) $message='Too many wrong codes. Request a new one.';
            elseif (!password_verify($otp,$row['otp_hash'])) {
                $bump=$conn->prepare('UPDATE registration_verifications SET attempts=attempts+1 WHERE user_id=?');$bump->bind_param('i',$row['id']);$bump->execute();$bump->close();
                $message='Wrong code. Try again.';
            } else {
                $conn->begin_transaction();
                $activate=$conn->prepare("UPDATE users SET status='active' WHERE id=? AND status='pending_verification'");
                $activate->bind_param('i',$row['id']);$activate->execute();$activate->close();
                $clear=$conn->prepare('DELETE FROM registration_verifications WHERE user_id=?');$clear->bind_param('i',$row['id']);$clear->execute();$clear->close();
                $conn->commit();
                unset($_SESSION['registration_email'],$_SESSION['registration_dev_otp']);
                flash('Email verified. Please sign in.', 'success'); header('Location: login.php');exit;
            }
        }
    } catch (mysqli_sql_exception $e) {
        try {$conn->rollback();}catch(Throwable $ignored){}
        error_log('Registration verification error: '.$e->getMessage());
        $message='Could not verify right now. Please try again.';
    }
}
if (!empty($_SESSION['otp_mail_error'])) { $message=$_SESSION['otp_mail_error'];$type='warning';unset($_SESSION['otp_mail_error']); }
if (!$message) { $f=flash_pull();if($f){$message=$f['message'];$type=$f['type'];} }
$pageTitle='Verify registration';$sideTitle='Almost there.';$sideText='Confirm your email to activate your student account.';
require __DIR__.'/_auth_layout_top.php';
?>
<?php if (!smtp_configured() && isset($_SESSION['registration_dev_otp'])): ?><div class="otp-dev">Dev mode code: <strong><?php echo safe($_SESSION['registration_dev_otp']); ?></strong></div><?php endif; ?>
<form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="verify">
<div class="field"><label>Registration email</label><input class="input" type="email" name="email" value="<?php echo safe($email); ?>" required></div>
<div class="field"><label>4-digit verification code</label><input class="input" name="otp" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" required></div>
<button class="auth-btn" type="submit">VERIFY EMAIL</button></form>
<form method="POST" style="margin-top:12px"><?php echo csrf_field(); ?><input type="hidden" name="action" value="resend"><input type="hidden" name="email" value="<?php echo safe($email); ?>"><button class="btn btn-ghost" type="submit">Resend code</button></form>
<p class="auth-links"><a href="login.php">Back to sign in</a></p>
<?php require __DIR__.'/_auth_layout_bottom.php'; ?>
