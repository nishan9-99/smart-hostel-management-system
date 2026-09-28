<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

$allowedStatuses = ['Pending', 'In Progress', 'Resolved', 'Closed'];

/* ---- update status + reply ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_check();
    $id     = (int)($_POST['complaint_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $reply  = trim($_POST['admin_reply'] ?? '');
    $priority = trim($_POST['priority'] ?? 'Normal');

    if ($id <= 0 || !in_array($status, $allowedStatuses, true) || !in_array($priority,['Low','Normal','High'],true)) {
        flash('Invalid complaint update.', 'danger');
    } else {
        try {
            $conn->begin_transaction();
            if ($status === 'Resolved') {
                $stmt = $conn->prepare("UPDATE complaints SET status=?, admin_reply=?, priority=?, resolved_at=NOW() WHERE id=?");
            } else {
                $stmt = $conn->prepare("UPDATE complaints SET status=?, admin_reply=?, priority=?, resolved_at=NULL WHERE id=?");
            }
            $stmt->bind_param('sssi', $status, $reply, $priority, $id);
            $stmt->execute(); $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed > 0) audit_admin($conn, 'complaint_update', 'complaint', $id, 'status=' . $status . ', priority=' . $priority);
            $conn->commit();
            flash('Complaint updated.', 'success');
        } catch (mysqli_sql_exception $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Complaint action error: ' . $e->getMessage());
            flash('Failed to update complaint.', 'danger');
        }
    }
    header('Location: complaints.php');
    exit();
}

/* ---- delete ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $id = (int)($_POST['complaint_id'] ?? 0);
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare('DELETE FROM complaints WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute(); $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed > 0) audit_admin($conn, 'complaint_delete', 'complaint', $id);
        $conn->commit();
        flash('Complaint deleted.', 'success');
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Complaint action error: ' . $e->getMessage());
            flash('Failed to delete complaint.', 'danger');
    }
    header('Location: complaints.php');
    exit();
}

$filter = trim($_GET['filter'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$where  = 'WHERE 1=1';
$params = [];
$types  = '';
if (in_array($filter, $allowedStatuses, true)) {
    $where .= ' AND c.status = ?';
    $params[] = $filter; $types .= 's';
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (u.full_name LIKE ? OR c.title LIKE ? OR c.description LIKE ? OR c.category LIKE ?)';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
require_once __DIR__ . '/../includes/pagination.php';
[$totalRows, $totalPages, $page, $offset] = page_window($conn, "SELECT COUNT(*) FROM complaints c LEFT JOIN users u ON u.id=c.user_id $where", $types, $params);
$stmt = $conn->prepare("SELECT c.*, u.username, u.full_name AS student_name FROM complaints c LEFT JOIN users u ON u.id=c.user_id $where ORDER BY c.id DESC LIMIT ? OFFSET ?");
$pageParams = array_merge($params, [20, $offset]);
$stmt->bind_param($types . 'ii', ...$pageParams);
$stmt->execute();
$complaints = $stmt->get_result(); $stmt->close();

$counts = ['Pending' => 0, 'In Progress' => 0, 'Resolved' => 0, 'Closed' => 0];
foreach ($conn->query('SELECT status, COUNT(*) c FROM complaints GROUP BY status')->fetch_all(MYSQLI_ASSOC) as $row) {
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['c'];
}

$pageTitle = 'Complaints';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>💬 Complaints</h1>
        <p>Review student complaints, reply, and move them to resolution.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="stat-grid">
        <div class="stat amber"><div class="stat-label">Pending</div><div class="stat-value"><?php echo $counts['Pending']; ?></div></div>
        <div class="stat blue"><div class="stat-label">In progress</div><div class="stat-value"><?php echo $counts['In Progress']; ?></div></div>
        <div class="stat green"><div class="stat-label">Resolved</div><div class="stat-value"><?php echo $counts['Resolved']; ?></div></div>
        <div class="stat"><div class="stat-label">Closed</div><div class="stat-value"><?php echo $counts['Closed']; ?></div></div>
    </div>

    <div class="card">
        <div class="tabs">
            <?php foreach (array_merge(['all' => 'All'], array_combine($allowedStatuses, $allowedStatuses)) as $k => $l): ?>
            <a class="tab <?php echo $filter === $k ? 'active' : ''; ?>" href="complaints.php?filter=<?php echo urlencode($k); ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="search-bar">
            <input type="hidden" name="filter" value="<?php echo safe($filter); ?>">
            <input class="input" type="text" name="search" placeholder="Search student, title, category…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="complaints.php">Reset</a>
        </form>

        <?php if ($complaints->num_rows): while ($c = $complaints->fetch_assoc()):
            $bc = ['Pending' => 'badge-yellow', 'In Progress' => 'badge-blue', 'Resolved' => 'badge-green', 'Closed' => 'badge-gray'][$c['status']] ?? 'badge-gray';
        ?>
        <div class="notif" style="border-left-color:<?php echo $c['status'] === 'Resolved' ? 'var(--success)' : ($c['status'] === 'In Progress' ? '#3b82f6' : 'var(--accent)'); ?>">
            <div class="notif-meta" style="margin-bottom:8px">
                <span class="badge <?php echo $bc; ?>"><?php echo safe($c['status']); ?></span><span class="badge <?php echo $c['priority']==='High'?'badge-red':'badge-blue'; ?>"><?php echo safe($c['priority']); ?> priority</span>
                <span class="badge badge-purple"><?php echo safe($c['category']); ?></span>
                <span>by <strong><?php echo safe($c['student_name'] ?? 'Deleted student'); ?></strong><?php echo ($c['username'] ?? null) ? ' (@' . safe($c['username']) . ')' : ''; ?></span>
                <span><?php echo formatDateTime($c['created_at']); ?></span>
            </div>
            <div class="notif-title"><?php echo safe($c['title']); ?></div>
            <div class="notif-body"><?php echo nl2br(safe($c['description'])); ?></div>
            <?php if (!empty($c['admin_reply'])): ?>
            <div class="notif-body" style="background:#eef0ff;padding:10px 12px;border-radius:10px"><strong>Admin reply:</strong> <?php echo nl2br(safe($c['admin_reply'])); ?></div>
            <?php endif; ?>
            <form method="POST" class="form-grid" style="margin-top:12px">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="complaint_id" value="<?php echo (int)$c['id']; ?>">
                <div class="field">
                    <select class="input" name="status">
                        <?php foreach ($allowedStatuses as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $c['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><select class="input" name="priority"><?php foreach(['Low','Normal','High'] as $p): ?><option <?php echo $c['priority']===$p?'selected':''; ?>><?php echo $p; ?></option><?php endforeach; ?></select></div>
                <div class="field" style="grid-column:span 2"><input class="input" name="admin_reply" placeholder="Reply to student…" value="<?php echo safe($c['admin_reply'] ?? ''); ?>"></div>
                <div class="field"><button class="btn btn-primary" type="submit">Update</button></div>
            </form>
            <form method="POST" style="margin-top:8px" data-confirm="Delete this complaint permanently?">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="complaint_id" value="<?php echo (int)$c['id']; ?>">
                <button class="btn btn-red btn-sm" type="submit">🗑 Delete complaint</button>
            </form>
        </div>
        <?php endwhile; else: ?>
        <div class="empty"><span class="empty-ico">💬</span><p>No complaints found.</p></div>
        <?php endif; ?>
        <?php page_links($page, $totalPages, $totalRows); ?>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
