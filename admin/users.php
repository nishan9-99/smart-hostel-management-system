<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

// Temporary credentials only live in this response, never in flash or logs.
$temporaryPassword = null;
$resetUser = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $targetId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($targetId <= 0 || !in_array($action, ['toggle_status', 'reset_password'], true)) {
        flash('Invalid user action.', 'danger');
    } else {
        try {
            $stmt = $conn->prepare('SELECT id, full_name, status FROM users WHERE id=?');
            $stmt->bind_param('i', $targetId); $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$target) {
                flash('User not found.', 'danger');
            } elseif ($action === 'toggle_status') {
                if ($target['status'] === 'pending_verification') {
                    flash('This student must verify their email before activation.', 'warning');
                } elseif ($targetId === (int)$_SESSION['user_id']) {
                    flash('You cannot deactivate your own account.', 'danger');
                } else {
                    $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
                    $stmt = $conn->prepare('UPDATE users SET status=? WHERE id=?');
                    $conn->begin_transaction();
                    $stmt->bind_param('si', $newStatus, $targetId);
                    $stmt->execute(); $stmt->close();
                    audit_admin($conn, 'user_status', 'user', $targetId, $newStatus); $conn->commit();
                    flash('Account status updated.', 'success');
                }
            } else {
                $temporaryPassword = 'Sh-' . bin2hex(random_bytes(12)) . 'A1!';
                $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
                $conn->begin_transaction();
                $stmt->bind_param('si', $hash, $targetId); $stmt->execute(); $stmt->close();
                audit_admin($conn, 'password_reset', 'user', $targetId); $conn->commit();
                $resetUser = $target['full_name'];
            }
        } catch (mysqli_sql_exception $e) {
            $temporaryPassword = null;
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('User action error: ' . $e->getMessage());
            flash('Could not update account.', 'danger');
        }
    }
    if ($temporaryPassword === null) { header('Location: users.php'); exit; }
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
}

$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? 'all');

$where  = 'WHERE 1=1';
$params = [];
$types  = '';

if ($roleFilter === 'admin' || $roleFilter === 'student') {
    $where .= ' AND role = ?';
    $params[] = $roleFilter; $types .= 's';
}
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ?)';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

require_once __DIR__ . '/../includes/pagination.php';
[$totalRows, $totalPages, $page, $offset] = page_window($conn, "SELECT COUNT(*) FROM users $where", $types, $params);
$stmt = $conn->prepare("SELECT id, full_name, username, email, phone, role, status, created_at FROM users $where ORDER BY id DESC LIMIT ? OFFSET ?");
$pageParams = array_merge($params, [20, $offset]);
$stmt->bind_param($types . 'ii', ...$pageParams);
$stmt->execute();
$users = $stmt->get_result(); $stmt->close();

$pageTitle = 'Users';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>👥 Registered Users</h1>
        <p>Every admin and student account in the system.</p>
    </div>

    <?php echo flash_html(); ?>
    <?php if ($temporaryPassword !== null): ?>
    <div class="alert alert-warning" role="status">
        Temporary password for <?php echo safe($resetUser); ?>: <strong><?php echo safe($temporaryPassword); ?></strong><br>
        Copy it now. It is shown only in this response. Pass it privately to the user and ask them to change it after login.
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="tabs">
            <?php foreach (['all' => 'All', 'student' => 'Students', 'admin' => 'Admins'] as $k => $l): ?>
            <a class="tab <?php echo $roleFilter === $k ? 'active' : ''; ?>" href="users.php?role=<?php echo $k; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="search-bar">
            <input type="hidden" name="role" value="<?php echo safe($roleFilter); ?>">
            <input class="input" type="text" name="search" placeholder="Search name, username, email or phone…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="users.php">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($users->num_rows): while ($u = $users->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo (int)$u['id']; ?></td>
                        <td><strong><?php echo safe($u['full_name']); ?></strong></td>
                        <td><?php echo safe($u['username']); ?></td>
                        <td><?php echo safe($u['email']); ?></td>
                        <td><?php echo safe($u['phone']); ?></td>
                        <td><span class="badge <?php echo $u['role'] === 'admin' ? 'badge-purple' : 'badge-blue'; ?>"><?php echo safe(ucfirst($u['role'])); ?></span></td>
                        <td><span class="badge <?php echo $u['status'] === 'active' ? 'badge-green' : 'badge-gray'; ?>"><?php echo safe(ucfirst($u['status'])); ?></span></td>
                        <td><?php echo formatDate($u['created_at']); ?></td>
                        <td>
                          <?php if ($u['status'] !== 'pending_verification' && ((int)$u['id'] !== (int)$_SESSION['user_id'] || $u['status'] !== 'active')): ?>
                          <form method="POST" style="display:inline" onsubmit="return confirm('Change this account status?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?php echo $u['status'] === 'active' ? 'Deactivate' : 'Reactivate'; ?></button>
                          </form>
                          <?php endif; ?>
                          <form method="POST" style="display:inline" onsubmit="return confirm('Reset this password? The old password will stop working.')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Reset password</button>
                          </form>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="9" class="empty-row">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php page_links($page, $totalPages, $totalRows); ?>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
