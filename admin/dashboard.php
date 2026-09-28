<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

$adminName = $_SESSION['full_name'] ?? 'Admin';

/* ---- counts ---- */
$totalStudents   = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='student'")->fetch_assoc()['c'];
$totalRooms      = (int)$conn->query("SELECT COUNT(*) c FROM rooms")->fetch_assoc()['c'];
$roomRows        = $conn->query("SELECT status, COUNT(*) c FROM rooms GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$roomBy          = array_column($roomRows, 'c', 'status');
$totalBeds       = (int)$conn->query("SELECT COALESCE(SUM(capacity),0) c FROM rooms")->fetch_assoc()['c'];
$occupiedBeds    = (int)$conn->query("SELECT COALESCE(SUM(occupied),0) c FROM rooms")->fetch_assoc()['c'];
$activeAlloc     = (int)$conn->query("SELECT COUNT(*) c FROM room_allocations WHERE status='active'")->fetch_assoc()['c'];
$visitorsInside  = (int)$conn->query("SELECT COUNT(*) c FROM visitors WHERE status='Inside'")->fetch_assoc()['c'];
$pendComplaints  = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE status='Pending'")->fetch_assoc()['c'];
$collected       = (float)$conn->query("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE verification_status='Approved'")->fetch_assoc()['s'];
$pendingVerify   = (int)$conn->query("SELECT COUNT(*) c FROM payments WHERE verification_status='Pending Verification'")->fetch_assoc()['c'];
$overdueAmount   = (float)$conn->query("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE status='overdue'")->fetch_assoc()['s'];

$roomTypesStmt = $conn->prepare('SELECT room_type, SUM(occupied) occupied, SUM(capacity) capacity FROM rooms GROUP BY room_type ORDER BY room_type');
$roomTypesStmt->execute(); $roomTypes = $roomTypesStmt->get_result()->fetch_all(MYSQLI_ASSOC); $roomTypesStmt->close();
$monthlyStmt = $conn->prepare("SELECT DATE_FORMAT(verified_at, '%Y-%m') month, SUM(amount) total FROM payments WHERE verification_status='Approved' AND verified_at IS NOT NULL GROUP BY DATE_FORMAT(verified_at, '%Y-%m') ORDER BY month DESC LIMIT 6");
$monthlyStmt->execute(); $monthlyFees = $monthlyStmt->get_result()->fetch_all(MYSQLI_ASSOC); $monthlyStmt->close();
$feeMax = max(array_merge([1],array_map(fn($r)=>(float)$r['total'],$monthlyFees)));

$recentComplaints = $conn->query("SELECT u.full_name AS student_name, c.title, c.status, c.created_at FROM complaints c LEFT JOIN users u ON u.id=c.user_id ORDER BY c.id DESC LIMIT 5");
$recentPayments   = $conn->query("SELECT u.full_name AS student_name, p.fee_type, p.amount, p.verification_status, p.created_at FROM payments p LEFT JOIN users u ON u.id=p.student_id ORDER BY p.id DESC LIMIT 5");

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="hero-card">
        <h1>Welcome back, <?php echo safe(explode(' ', $adminName)[0]); ?> 👋</h1>
        <p>Here is what is happening across the hostel right now.</p>
    </div>

    <div class="stat-grid">
        <div class="stat"><div class="stat-label">Students</div><div class="stat-value"><?php echo $totalStudents; ?></div></div>
        <div class="stat blue"><div class="stat-label">Beds occupied</div><div class="stat-value"><?php echo $occupiedBeds; ?>/<?php echo $totalBeds; ?></div></div>
        <div class="stat purple"><div class="stat-label">Active allocations</div><div class="stat-value"><?php echo $activeAlloc; ?></div></div>
        <div class="stat amber"><div class="stat-label">Visitors inside</div><div class="stat-value"><?php echo $visitorsInside; ?></div></div>
        <div class="stat red"><div class="stat-label">Open complaints</div><div class="stat-value"><?php echo $pendComplaints; ?></div></div>
        <div class="stat green"><div class="stat-label">Fees collected</div><div class="stat-value"><?php echo money($collected); ?></div></div>
        <div class="stat amber"><div class="stat-label">Proofs to verify</div><div class="stat-value"><?php echo $pendingVerify; ?></div></div>
        <div class="stat red"><div class="stat-label">Overdue amount</div><div class="stat-value"><?php echo money($overdueAmount); ?></div></div>
    </div>

    <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
        <div class="stat blue"><div class="stat-label">Total rooms</div><div class="stat-value"><?php echo $totalRooms; ?></div></div>
        <div class="stat green"><div class="stat-label">Available</div><div class="stat-value"><?php echo (int)($roomBy['Available'] ?? 0); ?></div></div>
        <div class="stat amber"><div class="stat-label">Full</div><div class="stat-value"><?php echo (int)($roomBy['Full'] ?? 0); ?></div></div>
        <div class="stat red"><div class="stat-label">Maintenance</div><div class="stat-value"><?php echo (int)($roomBy['Maintenance'] ?? 0); ?></div></div>
    </div>

    <div class="card"><h2>🛏️ Occupancy by room type</h2>
        <?php foreach ($roomTypes as $type): $pct = $type['capacity'] ? min(100,100*(int)$type['occupied']/(int)$type['capacity']) : 0; ?>
        <div class="info-row"><span><?php echo safe($type['room_type']); ?></span><div class="progress"><span style="width:<?php echo (int)$pct; ?>%"></span></div><strong><?php echo (int)$type['occupied']; ?>/<?php echo (int)$type['capacity']; ?></strong></div>
        <?php endforeach; ?>
    </div>
    <div class="card"><h2>💰 Monthly collections</h2>
        <?php foreach ($monthlyFees as $month): ?>
        <div class="info-row"><span><?php echo safe($month['month']); ?></span><div class="progress"><span style="width:<?php echo (int)(100*$month['total']/$feeMax); ?>%"></span></div><strong><?php echo money($month['total']); ?></strong></div>
        <?php endforeach; if (!$monthlyFees): ?><p>No approved collections yet.</p><?php endif; ?>
    </div>
    <div class="card">
        <h2>💬 Latest complaints</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Complaint</th><th>Status</th><th>Raised</th></tr></thead>
                <tbody>
                <?php if ($recentComplaints->num_rows): while ($c = $recentComplaints->fetch_assoc()):
                    $st = strtolower($c['status']);
                    $bc = $st === 'resolved' ? 'badge-green' : ($st === 'in progress' ? 'badge-blue' : 'badge-yellow');
                ?>
                    <tr>
                        <td><?php echo safe($c['student_name'] ?? 'Deleted student'); ?></td>
                        <td><?php echo safe($c['title']); ?></td>
                        <td><span class="badge <?php echo $bc; ?>"><?php echo safe($c['status']); ?></span></td>
                        <td><?php echo formatDateTime($c['created_at']); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="4" class="empty-row">No complaints yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>💳 Latest fee activity</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Fee</th><th>Amount</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                <?php if ($recentPayments->num_rows): while ($p = $recentPayments->fetch_assoc()):
                    $vs = strtolower($p['verification_status']);
                    $bc = $vs === 'approved' ? 'badge-green' : ($vs === 'rejected' ? 'badge-red' : ($vs === 'pending verification' ? 'badge-yellow' : 'badge-gray'));
                ?>
                    <tr>
                        <td><?php echo safe($p['student_name'] ?? '-'); ?></td>
                        <td><?php echo safe($p['fee_type']); ?></td>
                        <td><strong><?php echo money($p['amount']); ?></strong></td>
                        <td><span class="badge <?php echo $bc; ?>"><?php echo safe($p['verification_status']); ?></span></td>
                        <td><?php echo formatDateTime($p['created_at']); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5" class="empty-row">No fees assigned yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
