<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$studentId   = (int)$_SESSION['user_id'];

/* ---- raise a complaint (user_id is stored, not just the name) ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check();
    $title    = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $desc     = trim($_POST['description'] ?? '');
    $priority = trim($_POST['priority'] ?? 'Normal');
    if (!in_array($priority, ['Low','Normal','High'], true)) $priority='Normal';
    $allowed  = ['General', 'Room', 'Mess', 'Maintenance', 'Cleanliness', 'Other'];
    if (!in_array($category, $allowed, true)) $category = 'General';

    if ($title === '' || $desc === '') {
        flash('Please fill in the title and description.', 'danger');
    } else {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO complaints (user_id, title, category, description, priority, status)
                 VALUES (?,?,?,?,?,'Pending')"
            );
            $stmt->bind_param('issss', $studentId, $title, $category, $desc, $priority);
            $stmt->execute();
            $stmt->close();
            flash('Complaint submitted. The admin will review it soon.', 'success');
        } catch (mysqli_sql_exception $e) {
            flash('Failed to submit complaint.', 'danger');
        }
    }
    header('Location: complaints.php');
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reopen') {
    csrf_check(); $id=(int)($_POST['complaint_id']??0);
    try {
        $stmt=$conn->prepare("UPDATE complaints SET status='Pending',resolved_at=NULL WHERE id=? AND user_id=? AND status IN ('Resolved','Closed')");
        $stmt->bind_param('ii',$id,$studentId);$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
        flash($changed ? 'Complaint reopened.' : 'This complaint cannot be reopened.', $changed ? 'success' : 'warning');
    } catch(mysqli_sql_exception $e) { error_log('Reopen error: '.$e->getMessage());flash('Could not reopen complaint.','danger'); }
    header('Location: complaints.php');exit;
}
$search = trim($_GET['search'] ?? '');
$where  = 'WHERE user_id = ?';
$params = [$studentId];
$types  = 'i';
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (title LIKE ? OR description LIKE ? OR category LIKE ? OR admin_reply LIKE ?)';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$stmt = $conn->prepare("SELECT * FROM complaints $where ORDER BY id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$complaints = $stmt->get_result();
$stmt->close();

$pageTitle = 'My Complaints';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>💬 My Complaints</h1>
        <p>Raise an issue and track what the admin does about it.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="card">
        <h2>➕ New complaint</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <div class="field" style="grid-column:span 2"><label>Title *</label><input class="input" name="title" placeholder="Short summary" required></div>
            <div class="field">
                <label>Category</label>
                <select class="input" name="category">
                    <?php foreach (['General', 'Room', 'Mess', 'Maintenance', 'Cleanliness', 'Other'] as $c): ?>
                    <option><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Priority</label><select class="input" name="priority"><option>Normal</option><option>Low</option><option>High</option></select></div>
            <div class="field full"><label>Description *</label><textarea class="input" name="description" placeholder="Describe the issue in detail…" required></textarea></div>
            <div class="field"><button class="btn btn-primary" type="submit">Submit complaint</button></div>
        </form>
    </div>

    <div class="card">
        <h2>📋 My complaints</h2>
        <form method="GET" class="search-bar">
            <input class="input" type="text" name="search" placeholder="Search your complaints…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="complaints.php">Reset</a>
        </form>

        <?php if ($complaints->num_rows): while ($c = $complaints->fetch_assoc()):
            $bc = ['Pending' => 'badge-yellow', 'In Progress' => 'badge-blue', 'Resolved' => 'badge-green', 'Closed' => 'badge-gray'][$c['status']] ?? 'badge-gray';
        ?>
        <div class="notif" style="border-left-color:<?php echo $c['status'] === 'Resolved' ? 'var(--success)' : ($c['status'] === 'In Progress' ? '#3b82f6' : 'var(--accent)'); ?>">
            <div class="notif-meta" style="margin-bottom:8px">
                <span class="badge <?php echo $bc; ?>"><?php echo safe($c['status']); ?></span>
                <span class="badge badge-purple"><?php echo safe($c['category']); ?></span><span class="badge <?php echo $c['priority']==='High'?'badge-red':'badge-blue'; ?>"><?php echo safe($c['priority']); ?> priority</span>
                <span><?php echo formatDateTime($c['created_at']); ?></span>
                <?php if ($c['resolved_at']): ?><span>Resolved <?php echo formatDateTime($c['resolved_at']); ?></span><?php endif; ?>
            </div>
            <div class="notif-title"><?php echo safe($c['title']); ?></div>
            <div class="notif-body"><?php echo nl2br(safe($c['description'])); ?></div>
            <?php if (in_array($c['status'], ['Resolved','Closed'], true)): ?><form method="POST" style="margin-top:8px"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="complaint_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-ghost btn-sm" type="submit">Reopen complaint</button></form><?php endif; ?>
            <?php if (!empty($c['admin_reply'])): ?>
            <div class="notif-body" style="background:#eef0ff;padding:10px 12px;border-radius:10px"><strong>Admin reply:</strong> <?php echo nl2br(safe($c['admin_reply'])); ?></div>
            <?php endif; ?>
        </div>
        <?php endwhile; else: ?>
        <div class="empty"><span class="empty-ico">💬</span><p>You have not raised any complaints yet.</p></div>
        <?php endif; ?>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
