<?php
$message = '';
$type = 'danger';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['auth_mode'] ?? '') === 'register') {
    csrf_check();

    $full_name    = trim($_POST['full_name'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $country_code = trim($_POST['country_code'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $country      = trim($_POST['country'] ?? '');
    $state        = trim($_POST['state'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $password     = (string)($_POST['password'] ?? '');
    $confirm      = (string)($_POST['confirm_password'] ?? '');
    $agree        = $_POST['agree_terms'] ?? '';

    $full_phone = trim($country_code . ' ' . $phone);

    if (!$full_name || !$username || !$email || !$country_code || !$phone || !$country || !$state || !$address || !$password || !$confirm) {
        $message = 'Please fill in all required fields.';
    } elseif (strlen($username) < 4 || strlen($username) > 30) {
        $message = 'Username must be 4-30 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9_.]+$/', $username)) {
        $message = 'Username may only contain letters, numbers, dot and underscore.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9]{6,15}$/', $phone)) {
        $message = 'Phone must be 6-15 digits.';
    } elseif (($err = password_policy_error($password)) !== null) {
        $message = $err;
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
    } elseif ($agree !== 'yes') {
        $message = 'Please agree to the terms to continue.';
    } else {
        try {
            $chk = $conn->prepare('SELECT id, username, email, phone FROM users WHERE username = ? OR email = ? OR phone = ? LIMIT 1');
            $chk->bind_param('sss', $username, $email, $full_phone);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($existing) {
                if ($existing['username'] === $username)   $message = 'That username is already taken.';
                elseif ($existing['email'] === $email)     $message = 'That email is already registered.';
                else                                       $message = 'That phone number is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $conn->prepare(
                    "INSERT INTO users (full_name, username, email, phone, country, state, address, password, role, status)
                     VALUES (?,?,?,?,?,?,?,?,'student','pending_verification')"
                );
                $ins->bind_param('ssssssss', $full_name, $username, $email, $full_phone, $country, $state, $address, $hash);
                $ins->execute();
                $newUserId = $conn->insert_id;
                $ins->close();
                $otp = otp_generate();
                $hashOtp = password_hash($otp, PASSWORD_DEFAULT);
                $expires = date('Y-m-d H:i:s', time() + OTP_TTL_MINUTES * 60);
                $code = $conn->prepare('INSERT INTO registration_verifications (user_id, otp_hash, expires_at) VALUES (?,?,?)');
                $code->bind_param('iss', $newUserId, $hashOtp, $expires);
                $code->execute(); $code->close();
                $devOtp = otp_deliver($email, $full_name, $otp, 'registration verification');
                $_SESSION['registration_email'] = $email;
                if ($devOtp !== null) $_SESSION['registration_dev_otp'] = $devOtp;
                flash('Account created. Verify your email before signing in.', 'info');
                header('Location: verify_registration.php');
                exit();
            }
        } catch (mysqli_sql_exception $e) {
            error_log('Register error: ' . $e->getMessage());
            $message = 'Registration failed. Please try again.';
        }
    }
}

