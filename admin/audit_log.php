<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');
require_once __DIR__ . '/../includes/pagination.php';
$admin = filter_input(INPUT_GET, 'admin', FILTER_VALIDATE_INT) ?: 0;
$action = trim($_GET['action'] ?? '');
$from = trim($_GET['from'] ?? ''); $to = trim($_GET['to'] ?? '');
$where='WHERE 1=1'; $params=[]; $types='';
if ($admin) { $where.=' AND l.admin_id=?'; $params[]=$admin; $types.='i'; }
if ($action !== '') { $where.=' AND l.action=?'; $params[]=$action; $types.='s'; }
foreach (['from'=>'>=','to'=>'<='] as $field=>$op) {
    $value = $$field;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && DateTime::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value) {
        $where.=" AND DATE(l.created_at) $op ?"; $params[]=$value; $types.='s';
    }
}
[$totalRows,$totalPages,$page,$offset]=page_window($conn,"SELECT COUNT(*) FROM admin_audit_log l $where",$types,$params);
$stmt=$conn->prepare("SELECT l.*,u.full_name admin_name FROM admin_audit_log l LEFT JOIN users u ON u.id=l.admin_id $where ORDER BY l.id DESC LIMIT ? OFFSET ?");
$args=array_merge($params,[20,$offset]); $stmt->bind_param($types.'ii',...$args);$stmt->execute();$rows=$stmt->get_result();$stmt->close();
$admins=$conn->prepare("SELECT id, full_name FROM users WHERE role='admin' ORDER BY full_name");$admins->execute();$adminRows=$admins->get_result();
$actions=$conn->prepare('SELECT DISTINCT action FROM admin_audit_log ORDER BY action');$actions->execute();$actionRows=$actions->get_result();
$pageTitle='Audit Log';require_once __DIR__.'/../includes/header.php';
?>
<div class="layout"><?php require_once __DIR__.'/../includes/sidebar.php'; ?><main class="main">
<div class="page-head"><h1>🔐 Admin Audit Log</h1><p>Admin changes with timestamps and target records.</p></div>
<div class="card"><form class="form-grid" method="GET">
<div class="field"><label>Admin</label><select class="input" name="admin"><option value="">All admins</option><?php while($a=$adminRows->fetch_assoc()): ?><option value="<?php echo (int)$a['id']; ?>" <?php echo $admin===(int)$a['id']?'selected':''; ?>><?php echo safe($a['full_name']); ?></option><?php endwhile; ?></select></div>
<div class="field"><label>Action</label><select class="input" name="action"><option value="">All actions</option><?php while($a=$actionRows->fetch_assoc()): ?><option value="<?php echo safe($a['action']); ?>" <?php echo $action===$a['action']?'selected':''; ?>><?php echo safe($a['action']); ?></option><?php endwhile; ?></select></div>
<div class="field"><label>From</label><input class="input" type="date" name="from" value="<?php echo safe($from); ?>"></div><div class="field"><label>To</label><input class="input" type="date" name="to" value="<?php echo safe($to); ?>"></div>
<div class="field"><button class="btn btn-primary" type="submit">Filter</button> <a class="btn btn-ghost" href="audit_log.php">Reset</a></div></form>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th></tr></thead><tbody>
<?php if($rows->num_rows):while($r=$rows->fetch_assoc()): ?><tr><td><?php echo formatDateTime($r['created_at']); ?></td><td><?php echo safe($r['admin_name']??'Deleted admin'); ?></td><td><span class="badge badge-blue"><?php echo safe($r['action']); ?></span></td><td><?php echo safe($r['target_type']); ?> #<?php echo (int)$r['target_id']; ?></td><td><?php echo safe($r['details']??''); ?></td></tr><?php endwhile;else: ?><tr><td colspan="5" class="empty-row">No admin changes found.</td></tr><?php endif; ?>
</tbody></table></div><?php page_links($page,$totalPages,$totalRows); ?></div></main></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
