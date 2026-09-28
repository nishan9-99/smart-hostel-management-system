<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$studentId = (int)$_SESSION['user_id'];

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = "%$search%";
    $stmt = $conn->prepare(
        "SELECT * FROM visitors
         WHERE student_id = ? AND (visitor_name LIKE ? OR visitor_phone LIKE ? OR relation LIKE ? OR purpose LIKE ?)
         ORDER BY id DESC"
    );
    $stmt->bind_param('issss', $studentId, $like, $like, $like, $like);
} else {
    $stmt = $conn->prepare('SELECT * FROM visitors WHERE student_id = ? ORDER BY id DESC');
    $stmt->bind_param('i', $studentId);
}
$stmt->execute();
$visitors = $stmt->get_result();

$pageTitle = 'My Visitors';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>🧍 My Visitors</h1>
        <p>Everyone who has come to see you at the hostel.</p>
    </div>

    <div class="card">
        <form method="GET" class="search-bar">
            <input class="input" type="text" name="search" placeholder="Search visitor, relation, purpose…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="visitor_records.php">Reset</a>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Visitor</th><th>Phone</th><th>Relation</th><th>Purpose</th><th>Visit date</th><th>Entry</th><th>Exit</th><th>Status</th></tr></thead>
                <tbody>
                <?php if ($visitors->num_rows): while ($v = $visitors->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo safe($v['visitor_name']); ?></strong></td>
                        <td><?php echo safe($v['visitor_phone'] ?: '-'); ?></td>
                        <td><?php echo safe($v['relation']); ?></td>
                        <td><?php echo safe($v['purpose'] ?: '-'); ?></td>
                        <td><?php echo formatDate($v['visit_date']); ?></td>
                        <td><?php echo formatDateTime($v['entry_time']); ?></td>
                        <td><?php echo formatDateTime($v['exit_time']); ?></td>
                        <td><span class="badge <?php echo $v['status'] === 'Inside' ? 'badge-yellow' : 'badge-green'; ?>"><?php echo safe($v['status']); ?></span></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="8" class="empty-row">No visitor records yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
