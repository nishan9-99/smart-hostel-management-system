<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$navRole = $_SESSION['role'] ?? '';
$unreadCount = 0;
if ($navRole === 'student') {
    $studentId = (int)$_SESSION['user_id'];
    $unread = $conn->prepare('SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? WHERE (n.target_user_id IS NULL OR n.target_user_id=?) AND nr.notification_id IS NULL');
    $unread->bind_param('ii', $studentId, $studentId); $unread->execute();
    $unreadCount = (int)$unread->get_result()->fetch_row()[0]; $unread->close();
}
$navName = $_SESSION['full_name'] ?? 'User';
$nameParts = preg_split('/\s+/', trim($navName));
$navInitials = strtoupper(substr($nameParts[0] ?? 'U', 0, 1) . substr($nameParts[1] ?? '', 0, 1));
?>
<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon">🏠</div>
        <div class="brand-text">Smart<span>Hostel</span></div>
    </div>

    <nav class="nav">
    <?php if ($navRole === 'admin'): ?>
        <a href="/admin/dashboard.php"       class="<?php echo $currentPage === 'dashboard.php'       ? 'active' : ''; ?>"><span class="nav-ico">📊</span> Dashboard</a>
        <a href="/admin/users.php"           class="<?php echo $currentPage === 'users.php'           ? 'active' : ''; ?>"><span class="nav-ico">👥</span> Users</a>
        <a href="/admin/rooms.php"           class="<?php echo $currentPage === 'rooms.php'           ? 'active' : ''; ?>"><span class="nav-ico">🚪</span> Rooms</a>
        <a href="/admin/room_allocation.php" class="<?php echo $currentPage === 'room_allocation.php' ? 'active' : ''; ?>"><span class="nav-ico">🛏️</span> Allocation</a>
        <a href="/admin/visitors.php"        class="<?php echo $currentPage === 'visitors.php'        ? 'active' : ''; ?>"><span class="nav-ico">🧍</span> Visitors</a>
        <a href="/admin/complaints.php"      class="<?php echo $currentPage === 'complaints.php'      ? 'active' : ''; ?>"><span class="nav-ico">💬</span> Complaints</a>
        <a href="/admin/payments.php"        class="<?php echo $currentPage === 'payments.php'        ? 'active' : ''; ?>"><span class="nav-ico">💳</span> Payments</a>
        <a href="/admin/audit_log.php" class="<?php echo $currentPage === 'audit_log.php' ? 'active' : ''; ?>"><span class="nav-ico">🔐</span> Audit Log</a>
        <a href="/admin/reports.php"         class="<?php echo $currentPage === 'reports.php'         ? 'active' : ''; ?>"><span class="nav-ico">📈</span> Reports</a>
        <a href="/admin/notifications.php"   class="<?php echo $currentPage === 'notifications.php'   ? 'active' : ''; ?>"><span class="nav-ico">🔔</span> Notifications</a>
        <a href="/admin/profile.php"         class="<?php echo $currentPage === 'profile.php'         ? 'active' : ''; ?>"><span class="nav-ico">👤</span> Profile</a>
    <?php else: ?>
        <a href="/student/dashboard.php"       class="<?php echo $currentPage === 'dashboard.php'       ? 'active' : ''; ?>"><span class="nav-ico">📊</span> Dashboard</a>
        <a href="/student/room_details.php"    class="<?php echo $currentPage === 'room_details.php'    ? 'active' : ''; ?>"><span class="nav-ico">🚪</span> My Room</a>
        <a href="/student/payments.php"        class="<?php echo $currentPage === 'payments.php'        ? 'active' : ''; ?>"><span class="nav-ico">💳</span> Payments</a>
        <a href="/student/complaints.php"      class="<?php echo $currentPage === 'complaints.php'      ? 'active' : ''; ?>"><span class="nav-ico">💬</span> Complaints</a>
        <a href="/student/visitor_records.php" class="<?php echo $currentPage === 'visitor_records.php' ? 'active' : ''; ?>"><span class="nav-ico">🧍</span> Visitors</a>
        <a href="/student/notifications.php"   class="<?php echo $currentPage === 'notifications.php'   ? 'active' : ''; ?>"><span class="nav-ico">🔔</span> Notifications <?php if ($unreadCount): ?><span class="badge badge-red"><?php echo $unreadCount; ?></span><?php endif; ?></a>
        <a href="/student/profile.php"         class="<?php echo $currentPage === 'profile.php'         ? 'active' : ''; ?>"><span class="nav-ico">👤</span> Profile</a>
    <?php endif; ?>
    </nav>

    <div class="sidebar-user">
        <div class="avatar"><?php echo safe($navInitials); ?></div>
        <div class="sidebar-user-meta">
            <strong><?php echo safe($navName); ?></strong>
            <small><?php echo safe(ucfirst($navRole)); ?></small>
        </div>
    </div>

    <form method="POST" action="/auth/logout.php" class="logout-form">
        <?php echo csrf_field(); ?>
        <button type="submit" class="logout-btn">⏻ Logout</button>
    </form>
</aside>
