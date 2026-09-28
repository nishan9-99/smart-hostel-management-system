<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$studentId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT r.room_number, r.room_type, r.capacity, r.occupied, r.status, ra.allocation_date
     FROM room_allocations ra JOIN rooms r ON r.id = ra.room_id
     WHERE ra.user_id = ? AND ra.status='active' LIMIT 1"
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

$roommates = [];
if ($room) {
    $stmt = $conn->prepare(
        "SELECT u.full_name FROM room_allocations ra JOIN users u ON u.id = ra.user_id
         JOIN rooms r ON r.id = ra.room_id
         WHERE r.room_number = ? AND ra.status='active' AND u.id != ?"
    );
    $stmt->bind_param('si', $room['room_number'], $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $roommates[] = $row['full_name'];
    $stmt->close();
}

$pageTitle = 'My Room';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>🚪 My Room</h1>
        <p>Everything about where you stay.</p>
    </div>

    <?php if ($room):
        $pct = $room['capacity'] > 0 ? round($room['occupied'] * 100 / $room['capacity']) : 0;
    ?>
    <div class="card">
        <h2>Room <?php echo safe($room['room_number']); ?></h2>
        <div class="info-list">
            <div class="info-row"><span>Type</span><strong><?php echo safe($room['room_type']); ?></strong></div>
            <div class="info-row"><span>Status</span><strong><?php echo safe($room['status']); ?></strong></div>
            <div class="info-row"><span>Allocated on</span><strong><?php echo formatDate($room['allocation_date']); ?></strong></div>
        </div>
        <div style="margin-top:18px">
            <div class="stat-label" style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Occupancy <?php echo (int)$room['occupied']; ?>/<?php echo (int)$room['capacity']; ?></div>
            <div class="progress" style="height:10px"><span style="width:<?php echo $pct; ?>%"></span></div>
        </div>
    </div>

    <div class="card">
        <h2>🧑‍🤝‍🧑 Roommates</h2>
        <?php if ($roommates): ?>
        <div class="info-list">
            <?php foreach ($roommates as $m): ?>
            <div class="info-row"><span>Roommate</span><strong><?php echo safe($m); ?></strong></div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty"><span class="empty-ico">🛏️</span><p>You have this room to yourself (so far).</p></div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="empty"><span class="empty-ico">🚪</span><p>No room allocated yet. Please check back after the admin assigns one.</p></div>
    </div>
    <?php endif; ?>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
