<?php
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Smart Hostel Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="landing">
    <nav class="landing-nav">
        <div class="brand">
            <div class="brand-icon">🏠</div>
            <div class="brand-text">Smart<span>Hostel</span></div>
        </div>
        <div class="nav-links">
            <a class="btn-landing ghost" href="/auth/login.php">Sign in</a>
            <a class="btn-landing primary" href="/auth/register.php">Register</a>
        </div>
    </nav>

    <header class="landing-hero">
        <h1>Hostel management, <span>minus the chaos</span></h1>
        <p>Rooms, allocations, fees with proof verification, complaints, visitor logs and announcements - one clean system for admins and students.</p>
        <div class="landing-cta">
            <a class="btn-landing primary" href="/auth/register.php">Get started</a>
            <a class="btn-landing ghost" href="/auth/login.php">I have an account</a>
        </div>
    </header>

    <section class="feature-row">
        <div class="feature"><div class="f-ico">🛏️</div><h3>Rooms & Allocation</h3><p>Capacity-aware allocation with occupancy that updates itself.</p></div>
        <div class="feature"><div class="f-ico">💳</div><h3>Fee Payments</h3><p>Assign dues, upload proofs, approve or reject with remarks.</p></div>
        <div class="feature"><div class="f-ico">💬</div><h3>Complaints</h3><p>Students raise issues, admins reply and resolve them.</p></div>
        <div class="feature"><div class="f-ico">🧍</div><h3>Visitor Log</h3><p>Track every entry and exit against the right student.</p></div>
        <div class="feature"><div class="f-ico">🔔</div><h3>Announcements</h3><p>Broadcast notices to every student in one click.</p></div>
        <div class="feature"><div class="f-ico">🔐</div><h3>Secure by Design</h3><p>Hashed passwords, OTP resets, CSRF protection, safe uploads.</p></div>
    </section>

    <footer class="landing-footer">Smart Hostel Management System · BCS403 DBMS Mini Project</footer>
</div>
</body>
</html>
