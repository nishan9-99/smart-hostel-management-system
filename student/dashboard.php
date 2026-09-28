<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$studentId = (int)$_SESSION['user_id'];

/* my room */
$stmt = $conn->prepare(
    "SELECT r.room_number, r.room_type, r.capacity, r.occupied, ra.allocation_date
     FROM room_allocations ra JOIN rooms r ON r.id = ra.room_id
     WHERE ra.user_id = ? AND ra.status='active' LIMIT 1"
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* my dues via the view */
$stmt = $conn->prepare('SELECT * FROM student_dues_view WHERE student_id = ?');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$dues = $stmt->get_result()->fetch_assoc() ?: ['pending_amount' => 0, 'overdue_amount' => 0, 'paid_amount' => 0, 'pending_verification_count' => 0];
$stmt->close();

$openComplaints = 0;
$stmt = $conn->prepare("SELECT COUNT(*) c FROM complaints WHERE user_id = ? AND status IN ('Pending','In Progress')");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$openComplaints = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$notificationsStmt = $conn->prepare('SELECT title, message, category, created_at FROM notifications WHERE target_user_id IS NULL OR target_user_id=? ORDER BY id DESC LIMIT 4');
$notificationsStmt->bind_param('i', $studentId); $notificationsStmt->execute();
$notifications = $notificationsStmt->get_result();

$badgeMap = ['general' => 'badge-blue', 'fee' => 'badge-yellow', 'maintenance' => 'badge-red', 'event' => 'badge-green'];

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="hero-card">
        <h1>Hi <?php echo safe(explode(' ', $_SESSION['full_name'] ?? 'there')[0]); ?> 👋</h1>
        <p>Your room, dues and announcements at a glance.</p>
    </div>

    <div class="stat-grid">
        <div class="stat blue">
            <div class="stat-label">My room</div>
            <div class="stat-value"><?php echo $room ? safe($room['room_number']) : '—'; ?></div>
        </div>
        <div class="stat amber"><div class="stat-label">Pending dues</div><div class="stat-value"><?php echo money($dues['pending_amount']); ?></div></div>
        <div class="stat red"><div class="stat-label">Overdue</div><div class="stat-value"><?php echo money($dues['overdue_amount']); ?></div></div>
        <div class="stat green"><div class="stat-label">Paid so far</div><div class="stat-value"><?php echo money($dues['paid_amount']); ?></div></div>
        <div class="stat purple"><div class="stat-label">Open complaints</div><div class="stat-value"><?php echo $openComplaints; ?></div></div>
        <div class="stat"><div class="stat-label">Proofs in review</div><div class="stat-value"><?php echo (int)$dues['pending_verification_count']; ?></div></div>
    </div>

    <div class="card">
        <h2>🛏️ My room</h2>
        <?php if ($room): ?>
        <div class="info-list">
            <div class="info-row"><span>Room number</span><strong><?php echo safe($room['room_number']); ?></strong></div>
            <div class="info-row"><span>Type</span><strong><?php echo safe($room['room_type']); ?></strong></div>
            <div class="info-row"><span>Occupancy</span><strong><?php echo (int)$room['occupied']; ?>/<?php echo (int)$room['capacity']; ?></strong></div>
            <div class="info-row"><span>Allocated on</span><strong><?php echo formatDate($room['allocation_date']); ?></strong></div>
        </div>
        <?php else: ?>
        <div class="empty"><span class="empty-ico">🛏️</span><p>No room allocated yet. The admin will assign you one.</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>🔔 Latest announcements</h2>
        <?php if ($notifications->num_rows): while ($n = $notifications->fetch_assoc()):
            $cat = strtolower($n['category'] ?? 'general');
        ?>
        <div class="notif">
            <div class="notif-title"><?php echo safe($n['title']); ?></div>
            <div class="notif-body"><?php echo nl2br(safe($n['message'])); ?></div>
            <div class="notif-meta">
                <span class="badge <?php echo $badgeMap[$cat] ?? 'badge-gray'; ?>"><?php echo safe(ucfirst($cat)); ?></span>
                <span><?php echo formatDateTime($n['created_at']); ?></span>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty"><span class="empty-ico">🔕</span><p>No announcements yet.</p></div>
        <?php endif; ?>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
