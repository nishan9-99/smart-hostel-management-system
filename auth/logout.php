<?php
require_once __DIR__ . '/../includes/config.php';

/* POST + CSRF only. A GET shows a confirm screen instead of
   killing the session (prevents CSRF / accidental logouts). */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']]);
    }
    session_destroy();

    header('Location: /auth/login.php');
    exit();
}

$home = (($_SESSION['role'] ?? '') === 'admin') ? '/admin/dashboard.php' : '/student/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logout | Smart Hostel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="otp-page">
    <div class="otp-card">
        <div class="otp-badge">⏻ Logout</div>
        <h2>Sign out?</h2>
        <p class="otp-sub">Confirm you want to end this session.</p>
        <form method="POST" action="" class="otp-actions">
            <?php echo csrf_field(); ?>
            <button type="submit" class="auth-btn">Yes, sign me out</button>
            <a class="otp-back" href="<?php echo safe($home); ?>">← No, take me back</a>
        </form>
    </div>
</div>
</body>
</html>
