<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$studentId = (int)$_SESSION['user_id'];

$filter = trim($_GET['filter'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$where  = 'WHERE student_id = ?';
$params = [$studentId];
$types  = 'i';
if ($filter === 'pending')       $where .= " AND status='pending'";
elseif ($filter === 'paid')      $where .= " AND status='paid'";
elseif ($filter === 'overdue')   $where .= " AND status='overdue'";
elseif ($filter === 'pv')        $where .= " AND verification_status='Pending Verification'";
if ($search !== '') {
    $like = "%$search%";
    $where .= ' AND (fee_type LIKE ? OR transaction_id LIKE ? OR payment_note LIKE ?)';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
$stmt = $conn->prepare("SELECT * FROM payments $where ORDER BY id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$payments = $stmt->get_result();
$stmt->close();

$stmt = $conn->prepare('SELECT * FROM student_dues_view WHERE student_id = ?');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$dues = $stmt->get_result()->fetch_assoc() ?: ['pending_amount' => 0, 'overdue_amount' => 0, 'paid_amount' => 0, 'pending_verification_count' => 0];
$stmt->close();

function stuPayBadge($status, $vs) {
    $vs = strtolower($vs ?? ''); $s = strtolower($status ?? '');
    if ($vs === 'approved')             return '<span class="badge badge-green">Approved / Paid</span>';
    if ($vs === 'pending verification') return '<span class="badge badge-yellow">Under review</span>';
    if ($vs === 'rejected')             return '<span class="badge badge-red">Rejected</span>';
    if ($s === 'overdue')               return '<span class="badge badge-red">Overdue</span>';
    return '<span class="badge badge-blue">Pending</span>';
}

$pageTitle = 'My Payments';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>💳 My Payments</h1>
        <p>Your dues, submitted proofs and approval status.</p>
    </div>

    <?php echo flash_html(); ?>

    <div class="stat-grid">
        <div class="stat green"><div class="stat-label">Total paid</div><div class="stat-value"><?php echo money($dues['paid_amount']); ?></div></div>
        <div class="stat blue"><div class="stat-label">Pending</div><div class="stat-value"><?php echo money($dues['pending_amount']); ?></div></div>
        <div class="stat red"><div class="stat-label">Overdue</div><div class="stat-value"><?php echo money($dues['overdue_amount']); ?></div></div>
        <div class="stat amber"><div class="stat-label">In review</div><div class="stat-value"><?php echo (int)$dues['pending_verification_count']; ?></div></div>
    </div>

    <div class="card">
        <div class="tabs">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'pv' => 'In Review', 'paid' => 'Paid', 'overdue' => 'Overdue'] as $k => $l): ?>
            <a class="tab <?php echo $filter === $k ? 'active' : ''; ?>" href="payments.php?filter=<?php echo $k; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="search-bar">
            <input type="hidden" name="filter" value="<?php echo safe($filter); ?>">
            <input class="input" type="text" name="search" placeholder="Search fee or transaction…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="payments.php">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Fee</th><th>Amount</th><th>Status</th><th>Due date</th><th>Proof</th><th>Admin remark</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ($payments->num_rows): while ($p = $payments->fetch_assoc()):
                    $vs = strtolower($p['verification_status']);
                    $canPay = in_array(strtolower($p['status']), ['pending', 'overdue'], true) && $vs !== 'pending verification';
                ?>
                    <tr>
                        <td><strong><?php echo safe($p['fee_type']); ?></strong>
                            <?php if ($p['payment_note']): ?><br><small style="color:var(--muted)"><?php echo safe($p['payment_note']); ?></small><?php endif; ?>
                        </td>
                        <td><strong><?php echo money($p['amount']); ?></strong></td>
                        <td><?php echo stuPayBadge($p['status'], $p['verification_status']); ?></td>
                        <td><?php echo formatDate($p['due_date']); ?></td>
                        <td>
                            <?php if (!empty($p['proof_file'])): ?>
                            <a class="btn btn-ghost btn-sm" href="/uploads/payment_proofs/<?php echo safe(basename($p['proof_file'])); ?>" target="_blank">View</a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td style="max-width:170px;font-size:12.5px;color:<?php echo $vs === 'rejected' ? 'var(--danger)' : 'var(--muted)'; ?>">
                            <?php echo !empty($p['admin_remark']) ? safe($p['admin_remark']) : '-'; ?>
                        </td>
                        <td>
                            <?php if ($canPay): ?>
                            <button class="btn btn-primary btn-sm" type="button"
                                data-pay-id="<?php echo (int)$p['id']; ?>"
                                data-pay-fee="<?php echo safe($p['fee_type']); ?>"
                                data-pay-amount="<?php echo safe(money($p['amount'])); ?>">
                                Pay now
                            </button>
                            <?php elseif ($vs === 'pending verification'): ?>
                            <span class="badge badge-yellow">Awaiting review</span>
                            <?php elseif ($vs === 'approved'): ?>
                            <span class="badge badge-green">✓ Paid</span>
                            <a class="btn btn-ghost btn-sm" href="/student/receipt.php?id=<?php echo (int)$p['id']; ?>">Download receipt</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="7" class="empty-row">No dues found. The admin will assign fees to your account.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>

<div class="modal-overlay" id="payModal">
    <div class="modal-box">
        <h3>💳 Submit payment</h3>
        <p id="payDesc" style="color:var(--muted);font-size:13.5px;margin-bottom:16px"></p>
        <form method="POST" action="clear_payment.php" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="payment_id" id="payId">
            <div class="field" style="margin-bottom:14px">
                <label>Payment method *</label>
                <div class="choice-grid">
                    <?php foreach (['UPI', 'Net Banking', 'Cash', 'Bank Transfer', 'Cheque', 'Other'] as $m): ?>
                    <label class="choice"><input type="radio" name="payment_method" value="<?php echo $m; ?>" required> <?php echo $m; ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field" style="margin-bottom:14px">
                <label>Transaction ID (optional)</label>
                <input class="input" name="transaction_id" placeholder="e.g. UPI reference number">
            </div>
            <div class="field" style="margin-bottom:14px">
                <label>Note (optional)</label>
                <input class="input" name="payment_note" placeholder="Anything the admin should know">
            </div>
            <div class="field" style="margin-bottom:14px">
                <label>Payment proof image (JPG / PNG / GIF / WEBP, max 5MB)</label>
                <input class="input" type="file" name="proof_file" accept="image/jpeg,image/png,image/gif,image/webp">
                <span class="hint">Only real image files are accepted - verified by content, not by filename.</span>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Submit payment</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('[data-pay-id]').forEach(function (button) {
    button.addEventListener('click', function () {
        openPay(button.dataset.payId, button.dataset.payFee, button.dataset.payAmount);
    });
});
function openPay(id, fee, amount) {
    document.getElementById('payId').value = id;
    document.getElementById('payDesc').textContent = 'Paying ' + amount + ' towards "' + fee + '" (due #' + id + ').';
    openModal('payModal');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
