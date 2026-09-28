<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

/* ---- send ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'send') {
    csrf_check();
    $title    = trim($_POST['title'] ?? '');
    $message  = trim($_POST['message'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $target = (int)($_POST['target_user_id'] ?? 0);
    if ($target < 0) $target = -1;
    $allowed  = ['General', 'Fee', 'Maintenance', 'Event'];
    if (!in_array($category, $allowed, true)) $category = 'General';

    if ($title === '' || $message === '' || $target < 0) {
        flash('Title and message are required.', 'danger');
    } else {
        try {
            $conn->begin_transaction();
            if ($target > 0) {
                $check=$conn->prepare("SELECT id FROM users WHERE id=? AND role='student' AND status='active'");
                $check->bind_param('i',$target);$check->execute();
                if (!$check->get_result()->fetch_assoc()) throw new RuntimeException('Select an active student.');
                $check->close();
            }
            $stmt = $conn->prepare('INSERT INTO notifications (title, message, category, target_user_id) VALUES (?,?,?,?)');
            $targetId = $target ?: null;
            $stmt->bind_param('sssi', $title, $message, $category, $targetId);
            $stmt->execute(); $id = $conn->insert_id;
            $stmt->close();
            audit_admin($conn, 'notification_send', 'notification', $id, $target ? 'student_id=' . $target : 'broadcast'); $conn->commit();
            flash($target ? 'Notification sent to student.' : 'Notification sent to all students.', 'success');
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Notification action error: ' . $e->getMessage());
            flash('Failed to send notification.', 'danger');
        }
    }
    header('Location: notifications.php');
    exit();
}

/* ---- delete: POST + CSRF (no more GET delete link) ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $id = (int)($_POST['notification_id'] ?? 0);
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare('DELETE FROM notifications WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute(); $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed > 0) audit_admin($conn, 'notification_delete', 'notification', $id); $conn->commit();
        flash('Notification deleted.', 'success');
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Notification action error: ' . $e->getMessage());
            flash('Failed to delete notification.', 'danger');
    }
    header('Location: notifications.php');
    exit();
}

$notificationsStmt = $conn->prepare('SELECT n.*, u.full_name AS target_name FROM notifications n LEFT JOIN users u ON u.id=n.target_user_id ORDER BY n.id DESC');
$notificationsStmt->execute(); $notifications = $notificationsStmt->get_result();
$students = $conn->query("SELECT id, full_name FROM users WHERE role='student' AND status='active' ORDER BY full_name");
$counts = ['total' => 0, 'General' => 0, 'Fee' => 0, 'Maintenance' => 0, 'Event' => 0];
foreach ($conn->query('SELECT category, COUNT(*) c FROM notifications GROUP BY category')->fetch_all(MYSQLI_ASSOC) as $row) {
    $c = ucfirst(strtolower($row['category']));
    if (isset($counts[$c])) $counts[$c] = (int)$row['c'];
    $counts['total'] += (int)$row['c'];
}
$badgeMap = ['general' => 'badge-blue', 'fee' => 'badge-yellow', 'maintenance' => 'badge-red', 'event' => 'badge-green'];

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>🔔 Notifications</h1>
        <p>Send announcements to every student or one student.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr))">
        <div class="stat"><div class="stat-label">Total</div><div class="stat-value"><?php echo $counts['total']; ?></div></div>
        <div class="stat blue"><div class="stat-label">General</div><div class="stat-value"><?php echo $counts['General']; ?></div></div>
        <div class="stat amber"><div class="stat-label">Fee</div><div class="stat-value"><?php echo $counts['Fee']; ?></div></div>
        <div class="stat red"><div class="stat-label">Maintenance</div><div class="stat-value"><?php echo $counts['Maintenance']; ?></div></div>
        <div class="stat green"><div class="stat-label">Event</div><div class="stat-value"><?php echo $counts['Event']; ?></div></div>
    </div>

    <div class="card">
        <h2>📣 Send new notification</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send">
            <div class="field" style="grid-column:span 2"><label>Title *</label><input class="input" name="title" placeholder="Notification title" required></div>
            <div class="field">
                <label>Category</label>
                <select class="input" name="category">
                    <option>General</option><option>Fee</option><option>Maintenance</option><option>Event</option>
                </select>
            </div>
            <div class="field"><label>Recipient</label><select class="input" name="target_user_id"><option value="0">All Students</option><?php while($s=$students->fetch_assoc()): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo safe($s['full_name']); ?></option><?php endwhile; ?></select></div>
            <div class="field full"><label>Message *</label><textarea class="input" name="message" placeholder="Write the announcement…" required></textarea></div>
            <div class="field"><button class="btn btn-primary" type="submit">Send notification</button></div>
        </form>
    </div>

    <div class="card">
        <h2>📋 Sent notifications</h2>
        <?php if ($notifications->num_rows): while ($n = $notifications->fetch_assoc()):
            $cat = strtolower($n['category'] ?? 'general');
        ?>
        <div class="notif">
            <div class="notif-title"><?php echo safe($n['title']); ?></div>
            <div class="notif-body"><?php echo nl2br(safe($n['message'])); ?></div>
            <div class="notif-meta">
                <span class="badge <?php echo $badgeMap[$cat] ?? 'badge-gray'; ?>"><?php echo safe(ucfirst($cat)); ?></span>
                <span><?php echo safe($n['target_name'] ?? 'All Students'); ?></span><span><?php echo formatDateTime($n['created_at']); ?></span>
                <form method="POST" style="margin:0 0 0 auto" data-confirm="Delete this notification?">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="notification_id" value="<?php echo (int)$n['id']; ?>">
                    <button class="btn btn-red btn-sm" type="submit">🗑 Delete</button>
                </form>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty"><span class="empty-ico">🔕</span><p>No notifications sent yet.</p></div>
        <?php endif; ?>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
