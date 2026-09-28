<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$studentId = (int)$_SESSION['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'profile') {
    csrf_check();
    $phone   = trim($_POST['phone'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $state   = trim($_POST['state'] ?? '');
    $address = trim($_POST['address'] ?? '');
    try {
        $stmt = $conn->prepare('UPDATE users SET phone=?, country=?, state=?, address=? WHERE id=?');
        $stmt->bind_param('ssssi', $phone, $country, $state, $address, $studentId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['phone'] = $phone;
        flash('Profile updated.', 'success');
    } catch (mysqli_sql_exception $e) {
        flash('Failed to update profile.', 'danger');
    }
    header('Location: profile.php');
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'password') {
    csrf_check();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    try {
        $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($current, $row['password'])) {
            flash('Current password is incorrect.', 'danger');
        } elseif (($err = password_policy_error($new)) !== null) {
            flash($err, 'danger');
        } elseif ($new !== $confirm) {
            flash('New passwords do not match.', 'danger');
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $conn->prepare('UPDATE users SET password=? WHERE id=?');
            $upd->bind_param('si', $hash, $studentId);
            $upd->execute();
            $upd->close();
            flash('Password changed.', 'success');
        }
    } catch (mysqli_sql_exception $e) {
        flash('Failed to change password.', 'danger');
    }
    header('Location: profile.php');
    exit();
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare(
    "SELECT r.room_number, r.room_type, ra.allocation_date
     FROM room_allocations ra JOIN rooms r ON r.id = ra.room_id
     WHERE ra.user_id = ? AND ra.status='active' LIMIT 1"
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>👤 My Profile</h1>
        <p>Your account, room and security settings.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="card">
        <h2>🪪 Account details</h2>
        <div class="info-list">
            <div class="info-row"><span>Full name</span><strong><?php echo safe($user['full_name']); ?></strong></div>
            <div class="info-row"><span>Username</span><strong><?php echo safe($user['username']); ?></strong></div>
            <div class="info-row"><span>Email</span><strong><?php echo safe($user['email']); ?></strong></div>
            <div class="info-row"><span>Room</span><strong><?php echo $room ? safe($room['room_number'] . ' (' . $room['room_type'] . ')') : 'Not allocated'; ?></strong></div>
            <div class="info-row"><span>Member since</span><strong><?php echo formatDate($user['created_at']); ?></strong></div>
        </div>
    </div>

    <div class="card">
        <h2>✏️ Edit contact details</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="profile">
            <div class="field"><label>Phone</label><input class="input" name="phone" value="<?php echo safe($user['phone']); ?>"></div>
            <div class="field"><label>Country</label><input class="input" name="country" value="<?php echo safe($user['country'] ?? ''); ?>"></div>
            <div class="field"><label>State</label><input class="input" name="state" value="<?php echo safe($user['state'] ?? ''); ?>"></div>
            <div class="field"><label>Address</label><input class="input" name="address" value="<?php echo safe($user['address'] ?? ''); ?>"></div>
            <div class="field"><button class="btn btn-primary" type="submit">Save changes</button></div>
        </form>
    </div>

    <div class="card">
        <h2>🔑 Change password</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="password">
            <div class="field"><label>Current password</label><input class="input" type="password" name="current_password" id="current_password" required></div>
            <div class="field"><label>New password</label><input class="input" type="password" name="new_password" id="new_password" data-pw-meter required><span class="hint">8+ chars with upper, lower, number & special character.</span></div>
            <div class="field"><label>Confirm new password</label><input class="input" type="password" name="confirm_password" id="confirm_password" data-pw-match="#new_password" required></div>
            <div class="field"><button class="btn btn-primary" type="submit">Change password</button></div>
        </form>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
