<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

/* ---------------------------------------------------------------
   Visitors module (fixed):
   - table comes ONLY from database/smart_hostel.sql (no in-page
     CREATE TABLE)
   - visit_date is captured and stored
   - status enum matches the schema ('Inside' / 'Exited')
   - student picked from the users table (real FK), no free text
   --------------------------------------------------------------- */

/* ---- add visitor ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check();
    $student_id    = (int)($_POST['student_id'] ?? 0);
    $visitor_name  = trim($_POST['visitor_name'] ?? '');
    $visitor_phone = trim(($_POST['country_code'] ?? '') . ' ' . ($_POST['visitor_phone'] ?? ''));
    $relation      = trim($_POST['relation'] ?? '');
    $purpose       = trim($_POST['purpose'] ?? '');
    $visit_date    = trim($_POST['visit_date'] ?? '');

    $dateOk = (bool)DateTime::createFromFormat('Y-m-d', $visit_date);

    if ($student_id <= 0 || $visitor_name === '' || $relation === '' || !$dateOk) {
        flash('Please fill student, visitor name, relation and a valid visit date.', 'danger');
    } else {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare(
                "INSERT INTO visitors (student_id, visitor_name, visitor_phone, relation, purpose, visit_date, entry_time, status)
                 VALUES (?,?,?,?,?,?,NOW(),'Inside')"
            );
            $stmt->bind_param('isssss', $student_id, $visitor_name, $visitor_phone, $relation, $purpose, $visit_date);
            $stmt->execute();$newId=$conn->insert_id;
            $stmt->close();
            audit_admin($conn,'visitor_add','visitor',$newId);$conn->commit();
            flash('Visitor entry recorded.', 'success');
        } catch (mysqli_sql_exception $e) {
            try {$conn->rollback();}catch(Throwable $ignored){}
            error_log('Add visitor error: ' . $e->getMessage());
            flash('Failed to add visitor. Is the student valid?', 'danger');
        }
    }
    header('Location: visitors.php');
    exit();
}

/* ---- mark exit ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'exit') {
    csrf_check();
    $id = (int)($_POST['visitor_id'] ?? 0);
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE visitors SET exit_time = NOW(), status='Exited' WHERE id = ? AND status='Inside'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed>0) audit_admin($conn,'visitor_exit','visitor',$id);$conn->commit();
        flash($changed > 0 ? 'Visitor marked as exited.' : 'Visitor already exited.', $changed > 0 ? 'success' : 'warning');
    } catch (mysqli_sql_exception $e) {
        try {$conn->rollback();}catch(Throwable $ignored){}
        error_log('Visitor change error: '.$e->getMessage());
        flash('Failed to mark exit.', 'danger');
    }
    header('Location: visitors.php');
    exit();
}

/* ---- delete record ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $id = (int)($_POST['visitor_id'] ?? 0);
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare('DELETE FROM visitors WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();$changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed>0) audit_admin($conn,'visitor_delete','visitor',$id);$conn->commit();
        flash('Visitor record deleted.', 'success');
    } catch (mysqli_sql_exception $e) {
        try {$conn->rollback();}catch(Throwable $ignored){}
        error_log('Visitor change error: '.$e->getMessage());
        flash('Failed to delete record.', 'danger');
    }
    header('Location: visitors.php');
    exit();
}

/* ---- data ---- */
$students = $conn->query("SELECT id, full_name, username FROM users WHERE role='student' AND status='active' ORDER BY full_name");

$search = trim($_GET['search'] ?? '');
$where  = 'WHERE 1=1';
$params = [];
$types  = '';
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (u.full_name LIKE ? OR v.visitor_name LIKE ? OR v.visitor_phone LIKE ? OR v.relation LIKE ? OR v.purpose LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
require_once __DIR__ . '/../includes/pagination.php';
[$totalRows, $totalPages, $page, $offset] = page_window($conn, "SELECT COUNT(*) FROM visitors v JOIN users u ON u.id=v.student_id $where", $types, $params);
$stmt = $conn->prepare("SELECT v.*, u.full_name AS student_name FROM visitors v JOIN users u ON u.id=v.student_id $where ORDER BY v.id DESC LIMIT ? OFFSET ?");
$pageParams = array_merge($params, [20, $offset]);
$stmt->bind_param($types . 'ii', ...$pageParams);
$stmt->execute();
$visitors = $stmt->get_result(); $stmt->close();

$insideCount = (int)$conn->query("SELECT COUNT(*) c FROM visitors WHERE status='Inside'")->fetch_assoc()['c'];
$todayCount  = (int)$conn->query("SELECT COUNT(*) c FROM visitors WHERE visit_date = CURDATE()")->fetch_assoc()['c'];

$pageTitle = 'Visitors';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>🧍 Visitors</h1>
        <p>Log visitor entries, mark exits, and keep a clean audit trail.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
        <div class="stat amber"><div class="stat-label">Currently inside</div><div class="stat-value"><?php echo $insideCount; ?></div></div>
        <div class="stat blue"><div class="stat-label">Visits today</div><div class="stat-value"><?php echo $todayCount; ?></div></div>
    </div>

    <div class="card">
        <h2>➕ New visitor entry</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <div class="field">
                <label>Visiting student *</label>
                <select class="input" name="student_id" required>
                    <option value="">Select student…</option>
                    <?php while ($s = $students->fetch_assoc()): ?>
                    <option value="<?php echo (int)$s['id']; ?>"><?php echo safe($s['full_name'] . ' (@' . $s['username'] . ')'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="field"><label>Visitor name *</label><input class="input" name="visitor_name" placeholder="Full name" required></div>
            <div class="field">
                <label>Phone</label>
                <div style="display:flex;gap:8px">
                    <input class="input" name="country_code" value="+91" style="max-width:80px">
                    <input class="input" name="visitor_phone" placeholder="9876543210">
                </div>
            </div>
            <div class="field">
                <label>Relation *</label>
                <select class="input" name="relation" required>
                    <option value="">Select…</option>
                    <?php foreach (['Parent', 'Sibling', 'Guardian', 'Relative', 'Friend', 'Other'] as $r): ?>
                    <option value="<?php echo $r; ?>"><?php echo $r; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Visit date *</label><input class="input" type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="field"><label>Purpose</label><input class="input" name="purpose" placeholder="e.g. Family visit"></div>
            <div class="field" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Record entry</button></div>
        </form>
    </div>

    <div class="card">
        <h2>📋 Visitor log</h2>
        <form method="GET" class="search-bar">
            <input class="input" type="text" name="search" placeholder="Search student, visitor, relation…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="visitors.php">Reset</a>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Visitor</th><th>Phone</th><th>Relation</th><th>Purpose</th><th>Visit date</th><th>Entry</th><th>Exit</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($visitors->num_rows): while ($v = $visitors->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo safe($v['student_name']); ?></strong></td>
                        <td><?php echo safe($v['visitor_name']); ?></td>
                        <td><?php echo safe($v['visitor_phone'] ?: '-'); ?></td>
                        <td><?php echo safe($v['relation']); ?></td>
                        <td><?php echo safe($v['purpose'] ?: '-'); ?></td>
                        <td><?php echo formatDate($v['visit_date']); ?></td>
                        <td><?php echo formatDateTime($v['entry_time']); ?></td>
                        <td><?php echo formatDateTime($v['exit_time']); ?></td>
                        <td><span class="badge <?php echo $v['status'] === 'Inside' ? 'badge-yellow' : 'badge-green'; ?>"><?php echo safe($v['status']); ?></span><?php if ((int)$v['flagged'] && $v['status']==='Inside'): ?> <span class="badge badge-red">Long visit</span><?php endif; ?></td>
                        <td>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <?php if ($v['status'] === 'Inside'): ?>
                                <form method="POST" style="margin:0">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="exit">
                                    <input type="hidden" name="visitor_id" value="<?php echo (int)$v['id']; ?>">
                                    <button class="btn btn-green btn-sm" type="submit">Mark exit</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" style="margin:0" data-confirm="Delete this visitor record?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="visitor_id" value="<?php echo (int)$v['id']; ?>">
                                    <button class="btn btn-red btn-sm" type="submit">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="10" class="empty-row">No visitor records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php page_links($page, $totalPages, $totalRows); ?>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
