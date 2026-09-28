<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

$error = '';

/* ---- add room ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check();
    $room_number = trim($_POST['room_number'] ?? '');
    $room_type   = trim($_POST['room_type'] ?? '');
    $capacity    = (int)($_POST['capacity'] ?? 0);

    if ($room_number === '' || $room_type === '' || $capacity <= 0) {
        $error = 'Please fill all fields correctly.';
    } else {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO rooms (room_number, room_type, capacity, occupied, status) VALUES (?,?,?,0,'Available')");
            $stmt->bind_param('ssi', $room_number, $room_type, $capacity);
            $stmt->execute(); $newId = $conn->insert_id;
            $stmt->close();
            audit_admin($conn,'room_add','room',$newId);$conn->commit();
            flash('Room ' . $room_number . ' added.', 'success');
            header('Location: rooms.php');
            exit();
        } catch (mysqli_sql_exception $e) {
            try {$conn->rollback();}catch(Throwable $ignored){}
            error_log('Room add error: '.$e->getMessage());
            $error = ($e->getCode() === 1062) ? 'That room number already exists.' : 'Failed to add room.';
        }
    }
}

/* ---- delete room ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $room_id = (int)($_POST['room_id'] ?? 0);
    try {
        $active = $conn->prepare("SELECT COUNT(*) c FROM room_allocations WHERE room_id = ? AND status='active'");
        $active->bind_param('i', $room_id);
        $active->execute();
        $hasActive = (int)$active->get_result()->fetch_assoc()['c'] > 0;
        $active->close();

        if ($hasActive) {
            flash('Cannot delete: this room has an active allocation.', 'danger');
        } else {
            $conn->begin_transaction();
            $del = $conn->prepare('DELETE FROM rooms WHERE id = ?');
            $del->bind_param('i', $room_id);
            $del->execute();$changed=$del->affected_rows;
            $del->close();
            if ($changed>0) audit_admin($conn,'room_delete','room',$room_id);$conn->commit();
            flash('Room deleted.', 'success');
        }
    } catch (mysqli_sql_exception $e) {
        try {$conn->rollback();}catch(Throwable $ignored){}
        error_log('Room change error: '.$e->getMessage());
        flash('Failed to delete room.', 'danger');
    }
    header('Location: rooms.php');
    exit();
}

/* ---- maintenance toggle ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'maintenance') {
    csrf_check();
    $room_id = (int)($_POST['room_id'] ?? 0);
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare(
            "UPDATE rooms
             SET status = IF(status='Maintenance',
                             IF(occupied >= capacity, 'Full', 'Available'),
                             'Maintenance')
             WHERE id = ?"
        );
        $stmt->bind_param('i', $room_id);
        $stmt->execute();$changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed>0) audit_admin($conn,'room_maintenance','room',$room_id);$conn->commit();
        flash('Room status updated.', 'success');
    } catch (mysqli_sql_exception $e) {
        try {$conn->rollback();}catch(Throwable $ignored){}
        error_log('Room change error: '.$e->getMessage());
        flash('Failed to update room.', 'danger');
    }
    header('Location: rooms.php');
    exit();
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = "%$search%";
    $stmt = $conn->prepare('SELECT * FROM rooms WHERE room_number LIKE ? OR room_type LIKE ? OR status LIKE ? ORDER BY room_number');
    $stmt->bind_param('sss', $like, $like, $like);
} else {
    $stmt = $conn->prepare('SELECT * FROM rooms ORDER BY room_number');
}
$stmt->execute();
$rooms = $stmt->get_result();

$pageTitle = 'Rooms';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>🚪 Rooms</h1>
        <p>Add rooms, toggle maintenance, and keep the inventory clean.</p>
    </div>

    <?php echo flash_html(); ?>
    <?php if ($error): ?><div class="alert alert-danger">⛔ <?php echo safe($error); ?></div><?php endif; ?>

    <div class="card">
        <h2>➕ Add a room</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <div class="field"><label>Room number *</label><input class="input" name="room_number" placeholder="e.g. 302" required></div>
            <div class="field"><label>Room type *</label>
                <select class="input" name="room_type" required>
                    <option value="Single">Single</option>
                    <option value="Double">Double</option>
                    <option value="Triple">Triple</option>
                    <option value="Dormitory">Dormitory</option>
                </select>
            </div>
            <div class="field"><label>Capacity *</label><input class="input" type="number" name="capacity" min="1" max="10" value="1" required></div>
            <div class="field" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Add room</button></div>
        </form>
    </div>

    <div class="card">
        <h2>🏨 All rooms</h2>
        <form method="GET" class="search-bar">
            <input class="input" type="text" name="search" placeholder="Search number, type or status…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="rooms.php">Reset</a>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Room</th><th>Type</th><th>Occupancy</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($rooms->num_rows): while ($r = $rooms->fetch_assoc()):
                    $pct = $r['capacity'] > 0 ? round($r['occupied'] * 100 / $r['capacity']) : 0;
                    $bc = $r['status'] === 'Available' ? 'badge-green' : ($r['status'] === 'Full' ? 'badge-yellow' : 'badge-red');
                ?>
                    <tr>
                        <td><strong><?php echo safe($r['room_number']); ?></strong></td>
                        <td><?php echo safe($r['room_type']); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div class="progress"><span style="width:<?php echo $pct; ?>%"></span></div>
                                <span><?php echo (int)$r['occupied']; ?>/<?php echo (int)$r['capacity']; ?></span>
                            </div>
                        </td>
                        <td><span class="badge <?php echo $bc; ?>"><?php echo safe($r['status']); ?></span></td>
                        <td>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <form method="POST" style="margin:0">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="maintenance">
                                    <input type="hidden" name="room_id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?php echo $r['status'] === 'Maintenance' ? '🔧 End maintenance' : '🔧 Maintenance'; ?></button>
                                </form>
                                <form method="POST" style="margin:0" data-confirm="Delete room <?php echo safe($r['room_number']); ?>?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="room_id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="btn btn-red btn-sm" type="submit">🗑 Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5" class="empty-row">No rooms found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
