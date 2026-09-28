<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

/* ---------------------------------------------------------------
   Reports - powered by the SQL views created in smart_hostel.sql:
     * student_dues_view    (per-student fee summary)
     * room_occupancy_view  (per-room occupancy + occupants)
   --------------------------------------------------------------- */

$dues      = $conn->query('SELECT * FROM student_dues_view ORDER BY overdue_amount DESC, pending_amount DESC');
$occupancy = $conn->query('SELECT * FROM room_occupancy_view ORDER BY room_number');

$totals = $conn->query(
    "SELECT COALESCE(SUM(pending_amount),0) p,
            COALESCE(SUM(overdue_amount),0) o,
            COALESCE(SUM(paid_amount),0) paid
     FROM student_dues_view"
)->fetch_assoc();

$totalComplaints = (int)$conn->query('SELECT COUNT(*) c FROM complaints')->fetch_assoc()['c'];
$resolvedComplaints = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE status='Resolved'")->fetch_assoc()['c'];
$totalVisitors = (int)$conn->query('SELECT COUNT(*) c FROM visitors')->fetch_assoc()['c'];

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>📈 Reports</h1>
        <p>Live numbers straight from the database views, plus CSV exports.</p>
    </div>

    <div class="stat-grid">
        <div class="stat amber"><div class="stat-label">Pending dues</div><div class="stat-value"><?php echo money($totals['p']); ?></div></div>
        <div class="stat red"><div class="stat-label">Overdue dues</div><div class="stat-value"><?php echo money($totals['o']); ?></div></div>
        <div class="stat green"><div class="stat-label">Collected</div><div class="stat-value"><?php echo money($totals['paid']); ?></div></div>
        <div class="stat blue"><div class="stat-label">Complaints resolved</div><div class="stat-value"><?php echo $resolvedComplaints; ?>/<?php echo $totalComplaints; ?></div></div>
        <div class="stat purple"><div class="stat-label">Total visitor entries</div><div class="stat-value"><?php echo $totalVisitors; ?></div></div>
    </div>

    <div class="card">
        <h2>⬇️ Export data (CSV)</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a class="btn btn-dark" href="/reports/export_users.php">👥 Users</a>
            <a class="btn btn-dark" href="/reports/export_payments.php">💳 Payments</a>
            <a class="btn btn-dark" href="/reports/export_rooms.php">🚪 Rooms</a>
            <a class="btn btn-dark" href="/reports/export_complaints.php">💬 Complaints</a>
        </div>
    </div>

    <div class="card">
        <h2>💰 Student dues <span class="badge badge-blue">student_dues_view</span></h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Email</th><th>Fees</th><th>Pending</th><th>Overdue</th><th>Paid</th><th>Awaiting verify</th></tr></thead>
                <tbody>
                <?php if ($dues->num_rows): while ($d = $dues->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo safe($d['student_name']); ?></strong></td>
                        <td><?php echo safe($d['email']); ?></td>
                        <td><?php echo (int)$d['total_fees']; ?></td>
                        <td><?php echo money($d['pending_amount']); ?></td>
                        <td style="color:<?php echo $d['overdue_amount'] > 0 ? 'var(--danger)' : 'inherit'; ?>;font-weight:700"><?php echo money($d['overdue_amount']); ?></td>
                        <td style="color:var(--success);font-weight:700"><?php echo money($d['paid_amount']); ?></td>
                        <td><?php echo (int)$d['pending_verification_count']; ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="7" class="empty-row">No students yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>🛏️ Room occupancy <span class="badge badge-blue">room_occupancy_view</span></h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Room</th><th>Type</th><th>Occupancy</th><th>Status</th><th>Occupants</th></tr></thead>
                <tbody>
                <?php if ($occupancy->num_rows): while ($r = $occupancy->fetch_assoc()):
                    $pct = (float)$r['occupancy_pct'];
                    $bc = $r['status'] === 'Available' ? 'badge-green' : ($r['status'] === 'Full' ? 'badge-yellow' : 'badge-red');
                ?>
                    <tr>
                        <td><strong><?php echo safe($r['room_number']); ?></strong></td>
                        <td><?php echo safe($r['room_type']); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div class="progress"><span style="width:<?php echo min($pct, 100); ?>%"></span></div>
                                <span><?php echo (int)$r['occupied']; ?>/<?php echo (int)$r['capacity']; ?> (<?php echo $pct; ?>%)</span>
                            </div>
                        </td>
                        <td><span class="badge <?php echo $bc; ?>"><?php echo safe($r['status']); ?></span></td>
                        <td><?php echo safe($r['occupants'] ?? '') ?: '<span style="color:var(--muted)">-</span>'; ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5" class="empty-row">No rooms yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
