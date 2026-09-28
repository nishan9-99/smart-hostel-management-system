<?php
/* Shared two-panel auth layout. Expects: $pageTitle, $sideTitle, $sideText,
   $message, $type. Not routable on its own. */
if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__)) { http_response_code(403); exit('Forbidden'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo safe($pageTitle); ?> | Smart Hostel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-page<?php echo basename($_SERVER['SCRIPT_NAME']) === 'login.php' ? ' auth-showcase auth-login' : ''; ?>">
    <div class="auth-side">
        <div class="brand">
            <div class="brand-icon">🏠</div>
            <div class="brand-text">Smart<span>Hostel</span></div>
        </div>
        <h1><?php echo safe($sideTitle); ?></h1>
        <p><?php echo safe($sideText); ?></p>
        <div class="auth-points">
            <div class="auth-point">🛡️ &nbsp;Secure, role-based access</div>
            <div class="auth-point">💳 &nbsp;Fees, proofs and verification</div>
            <div class="auth-point">🛏️ &nbsp;Rooms, visitors and complaints</div>
        </div>
    </div>
    <div class="auth-main">
        <div class="auth-card">
            <div class="auth-blade" aria-hidden="true"><span>SMART HOSTEL <b>✦</b> YOUR SPACE, YOUR STORY</span></div>
            <?php if (basename($_SERVER['SCRIPT_NAME']) !== 'login.php'): ?>
            <h2><?php echo safe($pageTitle); ?></h2>
            <p class="auth-sub">Smart Hostel Management System</p>
            <?php endif; ?>
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo in_array($type, ['success','danger','warning','info'], true) ? $type : 'danger'; ?>">
                    <?php echo safe($message); ?>
                </div>
            <?php endif; ?>
