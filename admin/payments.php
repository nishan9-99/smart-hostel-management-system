<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

/* Best-effort notice. No email errors or SMTP secrets go to the screen. */
function notify_payment_status($conn, $paymentId, $approved, $remark) {
    if (!smtp_configured()) {
        error_log('Smart Hostel payment email skipped: SMTP unconfigured');
        return;
    }
    try {
        $stmt = $conn->prepare('SELECT u.email, u.full_name, p.fee_type, p.amount FROM payments p JOIN users u ON u.id=p.student_id WHERE p.id=?');
        $stmt->bind_param('i', $paymentId); $stmt->execute();
        $recipient = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$recipient) return;
        $subject = $approved ? 'Smart Hostel - payment approved' : 'Smart Hostel - payment proof rejected';
        $body = 'Hi ' . $recipient['full_name'] . ",\n\n" .
            'Your payment proof for ' . $recipient['fee_type'] . ' (INR ' . $recipient['amount'] . ') was ' .
            ($approved ? 'approved.' : 'rejected.');
        if (!$approved && $remark !== '') $body .= "\nReason: " . $remark;
        $body .= "\n\n- Smart Hostel";
        smarthostel_send_mail($recipient['email'], $recipient['full_name'], $subject, $body);
    } catch (Throwable $e) {
        error_log('Payment notification delivery failed: ' . $e->getMessage());
    }
}

/* ---- assign a due via the assign_fee() stored procedure ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    csrf_check();
    $sid      = (int)($_POST['student_id'] ?? 0);
    $fee_type = trim($_POST['fee_type'] ?? '');
    $custom   = trim($_POST['custom_fee'] ?? '');
    $amount   = (float)($_POST['amount'] ?? 0);
    $due_date = trim($_POST['due_date'] ?? '');
    $final    = ($fee_type === 'Other' && $custom !== '') ? $custom : $fee_type;

    if ($sid <= 0 || $final === '' || $amount <= 0 || !DateTime::createFromFormat('Y-m-d', $due_date)) {
        flash('Please fill all required fields correctly.', 'danger');
    } else {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare('CALL assign_fee(?,?,?,?)');
            $stmt->bind_param('isds', $sid, $final, $amount, $due_date);
            $stmt->execute();
            $stmt->close();
            while ($conn->more_results() && $conn->next_result()) { /* flush CALL results */ }
            $assignedId = (int)$conn->insert_id;
            audit_admin($conn, 'payment_assign', 'payment', $assignedId ?: null, 'student_id=' . $sid);
            $conn->commit();
            flash('Fee assigned successfully.', 'success');
        } catch (mysqli_sql_exception $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Assign fee error: ' . $e->getMessage());
            flash('Could not assign fee. Check the details and try again.', 'danger');
        }
    }
    header('Location: payments.php');
    exit();
}

/* ---- approve ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    csrf_check();
    $pid    = (int)($_POST['payment_id'] ?? 0);
    $remark = trim($_POST['admin_remark'] ?? '');
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE payments SET status='paid', verification_status='Approved', admin_remark=?, verified_at=NOW() WHERE id=?");
        $stmt->bind_param('si', $remark, $pid);
        $stmt->execute();
        $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated > 0) audit_admin($conn, 'payment_approve', 'payment', $pid);
        $conn->commit();
        if ($updated > 0) notify_payment_status($conn, $pid, true, $remark);
        flash('Payment approved.', 'success');
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Payment action error: ' . $e->getMessage());
        flash('Failed to approve payment.', 'danger');
    }
    header('Location: payments.php');
    exit();
}

/* ---- reject ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    csrf_check();
    $pid    = (int)($_POST['payment_id'] ?? 0);
    $remark = trim($_POST['admin_remark'] ?? '');
    if ($remark === '') {
        flash('Please add a remark so the student knows what to fix.', 'warning');
    } else {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE payments SET status='pending', verification_status='Rejected', admin_remark=?, proof_file=NULL, paid_date=NULL WHERE id=?");
            $stmt->bind_param('si', $remark, $pid);
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            if ($updated > 0) audit_admin($conn, 'payment_reject', 'payment', $pid);
            $conn->commit();
            if ($updated > 0) notify_payment_status($conn, $pid, false, $remark);
            flash('Payment rejected with remark.', 'success');
        } catch (mysqli_sql_exception $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Payment action error: ' . $e->getMessage());
        flash('Failed to reject payment.', 'danger');
        }
    }
    header('Location: payments.php');
    exit();
}

/* ---- delete a due ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $pid = (int)($_POST['payment_id'] ?? 0);
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare('DELETE FROM payments WHERE id = ?');
        $stmt->bind_param('i', $pid);
        $stmt->execute(); $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated > 0) audit_admin($conn, 'payment_delete', 'payment', $pid);
        $conn->commit();
        flash('Payment record deleted.', 'success');
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Payment action error: ' . $e->getMessage());
        flash('Failed to delete record.', 'danger');
    }
    header('Location: payments.php');
    exit();
}

/* ---- data ---- */
$students = $conn->query("SELECT id, full_name, username FROM users WHERE role='student' AND status='active' ORDER BY full_name");

$sum = $conn->query(
    "SELECT COUNT(*) total,
            COALESCE(SUM(CASE WHEN verification_status='Approved' THEN amount END),0) collected,
            COALESCE(SUM(verification_status='Pending Verification'),0) pv,
            COALESCE(SUM(status='overdue'),0) ov
     FROM payments"
)->fetch_assoc();

$filter = trim($_GET['filter'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$where  = 'WHERE 1=1';
$params = [];
$types  = '';
if ($filter === 'pending_verify') $where .= " AND p.verification_status='Pending Verification'";
elseif ($filter === 'approved')   $where .= " AND p.verification_status='Approved'";
elseif ($filter === 'rejected')   $where .= " AND p.verification_status='Rejected'";
elseif ($filter === 'overdue')    $where .= " AND p.status='overdue'";
elseif ($filter === 'pending')    $where .= " AND p.status='pending'";
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (u.full_name LIKE ? OR p.fee_type LIKE ? OR p.transaction_id LIKE ?)';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
require_once __DIR__ . '/../includes/pagination.php';
[$totalRows, $totalPages, $page, $offset] = page_window($conn, "SELECT COUNT(*) FROM payments p LEFT JOIN users u ON u.id=p.student_id $where", $types, $params);
$stmt = $conn->prepare("SELECT p.*, u.full_name AS student_name FROM payments p LEFT JOIN users u ON u.id=p.student_id $where ORDER BY p.id DESC LIMIT ? OFFSET ?");
$pageParams = array_merge($params, [20, $offset]);
$stmt->bind_param($types . 'ii', ...$pageParams);
$stmt->execute();
$payments = $stmt->get_result(); $stmt->close();

function adminPayBadge($status, $vs) {
    $vs = strtolower($vs ?? ''); $s = strtolower($status ?? '');
    if ($vs === 'approved')             return '<span class="badge badge-green">Approved / Paid</span>';
    if ($vs === 'pending verification') return '<span class="badge badge-yellow">Pending Verify</span>';
    if ($vs === 'rejected')             return '<span class="badge badge-red">Rejected</span>';
    if ($s === 'overdue')               return '<span class="badge badge-red">Overdue</span>';
    return '<span class="badge badge-blue">Pending</span>';
}

$pageTitle = 'Payments';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>💳 Payments</h1>
        <p>Assign dues, verify submitted proofs, approve or reject with remarks.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="stat-grid">
        <div class="stat"><div class="stat-label">Total records</div><div class="stat-value"><?php echo (int)$sum['total']; ?></div></div>
        <div class="stat green"><div class="stat-label">Collected</div><div class="stat-value"><?php echo money($sum['collected']); ?></div></div>
        <div class="stat amber"><div class="stat-label">Pending verification</div><div class="stat-value"><?php echo (int)$sum['pv']; ?></div></div>
        <div class="stat red"><div class="stat-label">Overdue</div><div class="stat-value"><?php echo (int)$sum['ov']; ?></div></div>
    </div>

    <div class="card">
        <h2>➕ Assign a fee due</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="assign">
            <div class="field">
                <label>Student *</label>
                <select class="input" name="student_id" required>
                    <option value="">Select student…</option>
                    <?php while ($s = $students->fetch_assoc()): ?>
                    <option value="<?php echo (int)$s['id']; ?>"><?php echo safe($s['full_name'] . ' (@' . $s['username'] . ')'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="field">
                <label>Fee type *</label>
                <select class="input" name="fee_type" id="feeType" required>
                    <option value="Hostel Fee">Hostel Fee</option>
                    <option value="Mess Fee">Mess Fee</option>
                    <option value="Maintenance Fee">Maintenance Fee</option>
                    <option value="Security Deposit">Security Deposit</option>
                    <option value="Other">Other (custom)</option>
                </select>
            </div>
            <div class="field" id="customFeeWrap" style="display:none">
                <label>Custom fee name</label>
                <input class="input" name="custom_fee" placeholder="e.g. Laundry Fee">
            </div>
            <div class="field"><label>Amount (₹) *</label><input class="input" type="number" name="amount" min="1" step="0.01" placeholder="5000" required></div>
            <div class="field"><label>Due date *</label><input class="input" type="date" name="due_date" required></div>
            <div class="field" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Assign fee</button></div>
        </form>
    </div>

    <div class="card">
        <h2>📋 All payment records</h2>
        <div class="tabs">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'pending_verify' => 'Pending Verify', 'approved' => 'Approved', 'rejected' => 'Rejected', 'overdue' => 'Overdue'] as $k => $l): ?>
            <a class="tab <?php echo $filter === $k ? 'active' : ''; ?>" href="payments.php?filter=<?php echo $k; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="search-bar">
            <input type="hidden" name="filter" value="<?php echo safe($filter); ?>">
            <input class="input" type="text" name="search" placeholder="Search student, fee, transaction…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="payments.php">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Student</th><th>Fee</th><th>Amount</th><th>Due</th><th>Status</th><th>Proof</th><th>Method / TXN</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($payments->num_rows): while ($p = $payments->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo (int)$p['id']; ?></td>
                        <td><strong><?php echo safe($p['student_name'] ?? '-'); ?></strong></td>
                        <td><?php echo safe($p['fee_type']); ?></td>
                        <td><strong><?php echo money($p['amount']); ?></strong></td>
                        <td><?php echo formatDate($p['due_date']); ?></td>
                        <td><?php echo adminPayBadge($p['status'], $p['verification_status']); ?></td>
                        <td>
                            <?php if (!empty($p['proof_file'])): ?>
                            <a class="btn btn-ghost btn-sm" href="/uploads/payment_proofs/<?php echo safe(basename($p['proof_file'])); ?>" target="_blank">View</a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <?php echo safe($p['payment_method'] ?? '-'); ?>
                            <?php if ($p['transaction_id']): ?><br><small style="color:var(--muted)"><?php echo safe($p['transaction_id']); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <?php if ($p['verification_status'] === 'Pending Verification'): ?>
                                <button class="btn btn-green btn-sm" type="button" onclick="openReview(<?php echo (int)$p['id']; ?>, 'approve')">Approve</button>
                                <button class="btn btn-red btn-sm" type="button" onclick="openReview(<?php echo (int)$p['id']; ?>, 'reject')">Reject</button>
                                <?php endif; ?>
                                <form method="POST" style="margin:0" data-confirm="Delete payment record #<?php echo (int)$p['id']; ?>?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="9" class="empty-row">No payment records.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php page_links($page, $totalPages, $totalRows); ?>
    </div>

</main>
</div>

<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <h3 id="reviewTitle">Review payment</h3>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="reviewAction" value="">
            <input type="hidden" name="payment_id" id="reviewId" value="">
            <div class="field">
                <label>Remark (required when rejecting)</label>
                <textarea class="input" name="admin_remark" placeholder="Note for the student…"></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReview(id, action) {
    document.getElementById('reviewId').value = id;
    document.getElementById('reviewAction').value = action;
    document.getElementById('reviewTitle').textContent = (action === 'approve' ? 'Approve' : 'Reject') + ' payment #' + id;
    openModal('reviewModal');
}
document.getElementById('feeType').addEventListener('change', function () {
    document.getElementById('customFeeWrap').style.display = this.value === 'Other' ? 'flex' : 'none';
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
