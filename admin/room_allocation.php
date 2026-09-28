<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

/* ---- allocate ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'allocate') {
    csrf_check();
    $user_id = (int)($_POST['user_id'] ?? 0);
    $room_id = (int)($_POST['room_id'] ?? 0);

    try {
        if ($user_id <= 0 || $room_id <= 0) {
            throw new RuntimeException('Please select both a student and a room.');
        }

        $conn->begin_transaction();

        $studentCheck=$conn->prepare("SELECT id FROM users WHERE id=? AND role='student' AND status='active' FOR UPDATE");
        $studentCheck->bind_param('i',$user_id);$studentCheck->execute();
        if (!$studentCheck->get_result()->fetch_assoc()) throw new RuntimeException('Select an active student.');
        $studentCheck->close();

        // one active allocation per student
        $chk = $conn->prepare("SELECT id FROM room_allocations WHERE user_id = ? AND status='active' LIMIT 1");
        $chk->bind_param('i', $user_id);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            throw new RuntimeException('This student already has an active room allocation.');
        }
        $chk->close();

        // lock the room row while we check capacity
        $rs = $conn->prepare('SELECT id, room_number, capacity, occupied, status FROM rooms WHERE id = ? FOR UPDATE');
        $rs->bind_param('i', $room_id);
        $rs->execute();
        $room = $rs->get_result()->fetch_assoc();
        $rs->close();

        if (!$room)                                   throw new RuntimeException('Selected room not found.');
        if ($room['status'] === 'Maintenance')        throw new RuntimeException('Room ' . $room['room_number'] . ' is under maintenance.');
        if ((int)$room['occupied'] >= (int)$room['capacity']) throw new RuntimeException('Room ' . $room['room_number'] . ' is already full.');

        // the AFTER INSERT trigger bumps rooms.occupied and flips status to Full when needed
        $ins = $conn->prepare("INSERT INTO room_allocations (user_id, room_id, allocation_date, status) VALUES (?,?,CURDATE(),'active')");
        $ins->bind_param('ii', $user_id, $room_id);
        $ins->execute(); $allocationId = $conn->insert_id;
        $ins->close();
        audit_admin($conn, 'room_allocate', 'allocation', $allocationId, 'student_id=' . $user_id . ', room_id=' . $room_id);

        $conn->commit();
        flash('Room ' . $room['room_number'] . ' allocated successfully.', 'success');
    } catch (RuntimeException $e) {
        if ($conn->errno === 0) { /* no-op */ }
        try { $conn->rollback(); } catch (Throwable $t) {}
        flash($e->getMessage(), 'danger');
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $t) {}
        error_log('Allocation error: ' . $e->getMessage());
        flash('Failed to allocate room.', 'danger');
    }
    header('Location: room_allocation.php');
    exit();
}

/* ---- end allocation (keep history; trigger frees the bed) ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'vacate') {
    csrf_check();
    $allocation_id = (int)($_POST['allocation_id'] ?? 0);
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE room_allocations SET status='inactive' WHERE id = ? AND status='active'");
        $stmt->bind_param('i', $allocation_id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed > 0) audit_admin($conn, 'room_vacate', 'allocation', $allocation_id);
        $conn->commit();
        flash($changed > 0 ? 'Allocation ended. The bed is free again.' : 'Allocation not found.', $changed > 0 ? 'success' : 'danger');
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Vacate error: ' . $e->getMessage());
        flash('Failed to end allocation.', 'danger');
    }
    header('Location: room_allocation.php');
    exit();
}

/* ---- data ---- */
$freeStudents = $conn->query(
    "SELECT u.id, u.full_name, u.username
     FROM users u
     WHERE u.role='student' AND u.status='active'
       AND NOT EXISTS (SELECT 1 FROM room_allocations ra WHERE ra.user_id = u.id AND ra.status='active')
     ORDER BY u.full_name"
);
$freeRooms = $conn->query("SELECT id, room_number, room_type, capacity, occupied FROM rooms WHERE status='Available' AND occupied < capacity ORDER BY room_number");

$active = $conn->query(
    "SELECT ra.id, ra.allocation_date, u.full_name, u.username, r.room_number, r.room_type
     FROM room_allocations ra
     JOIN users u ON u.id = ra.user_id
     JOIN rooms r ON r.id = ra.room_id
     WHERE ra.status='active'
     ORDER BY ra.id DESC"
);
$history = $conn->query(
    "SELECT ra.id, ra.allocation_date, u.full_name, r.room_number
     FROM room_allocations ra
     JOIN users u ON u.id = ra.user_id
     JOIN rooms r ON r.id = ra.room_id
     WHERE ra.status='inactive'
     ORDER BY ra.id DESC LIMIT 20"
);

$pageTitle = 'Room Allocation';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>🛏️ Room Allocation</h1>
        <p>Assign students to rooms. Occupancy updates itself via database triggers.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="card">
        <h2>➕ Allocate a room</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="allocate">
            <div class="field">
                <label>Student (unallocated) *</label>
                <select class="input" name="user_id" required>
                    <option value="">Select student…</option>
                    <?php while ($s = $freeStudents->fetch_assoc()): ?>
                    <option value="<?php echo (int)$s['id']; ?>"><?php echo safe($s['full_name'] . ' (@' . $s['username'] . ')'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="field">
                <label>Room (with free beds) *</label>
                <select class="input" name="room_id" required>
                    <option value="">Select room…</option>
                    <?php while ($r = $freeRooms->fetch_assoc()): ?>
                    <option value="<?php echo (int)$r['id']; ?>"><?php echo safe($r['room_number'] . ' - ' . $r['room_type'] . ' (' . (int)$r['occupied'] . '/' . (int)$r['capacity'] . ')'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="field" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Allocate</button></div>
        </form>
    </div>

    <div class="card">
        <h2>✅ Active allocations</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Username</th><th>Room</th><th>Type</th><th>Since</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ($active->num_rows): while ($a = $active->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo safe($a['full_name']); ?></strong></td>
                        <td><?php echo safe($a['username']); ?></td>
                        <td><span class="badge badge-blue"><?php echo safe($a['room_number']); ?></span></td>
                        <td><?php echo safe($a['room_type']); ?></td>
                        <td><?php echo formatDate($a['allocation_date']); ?></td>
                        <td>
                            <form method="POST" style="margin:0" data-confirm="End this allocation and free the bed?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="vacate">
                                <input type="hidden" name="allocation_id" value="<?php echo (int)$a['id']; ?>">
                                <button class="btn btn-red btn-sm" type="submit">Vacate</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6" class="empty-row">No active allocations.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>🕘 Recent history</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Room</th><th>Allocated on</th></tr></thead>
                <tbody>
                <?php if ($history->num_rows): while ($h = $history->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo safe($h['full_name']); ?></td>
                        <td><span class="badge badge-gray"><?php echo safe($h['room_number']); ?></span></td>
                        <td><?php echo formatDate($h['allocation_date']); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="3" class="empty-row">No past allocations.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
