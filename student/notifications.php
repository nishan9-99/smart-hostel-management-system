<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$studentId=(int)$_SESSION['user_id'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $id=(int)($_POST['notification_id']??0); $action=$_POST['action']??'';
    try {
        $visibility=$conn->prepare('SELECT id FROM notifications WHERE id=? AND (target_user_id IS NULL OR target_user_id=?)');
        $visibility->bind_param('ii',$id,$studentId);$visibility->execute();$visible=$visibility->get_result()->fetch_assoc();$visibility->close();
        if ($visible && $action==='read') {
            $stmt=$conn->prepare('INSERT IGNORE INTO notification_reads (user_id,notification_id) VALUES (?,?)');
            $stmt->bind_param('ii',$studentId,$id);$stmt->execute();$stmt->close();
        } elseif ($visible && $action==='unread') {
            $stmt=$conn->prepare('DELETE FROM notification_reads WHERE user_id=? AND notification_id=?');
            $stmt->bind_param('ii',$studentId,$id);$stmt->execute();$stmt->close();
        }
    } catch(mysqli_sql_exception $e) { error_log('Notification read error: '.$e->getMessage());flash('Could not update notification.','danger'); }
    header('Location: notifications.php'.(!empty($_GET) ? '?'.http_build_query($_GET) : ''));exit;
}
require_once __DIR__ . '/../includes/pagination.php';
$filter=trim($_GET['filter']??'all');$search=trim($_GET['search']??'');
$where='WHERE (n.target_user_id IS NULL OR n.target_user_id=?)';
$params=[$studentId];$types='i';
if (in_array($filter,['general','fee','maintenance','event'],true)) {
    $where.=' AND n.category=?';$params[]=ucfirst($filter);$types.='s';
}
if ($search!=='') {$like="%$search%";$where.=' AND (n.title LIKE ? OR n.message LIKE ?)';array_push($params,$like,$like);$types.='ss';}
[$totalRows,$totalPages,$page,$offset]=page_window($conn,"SELECT COUNT(*) FROM notifications n $where",$types,$params);
$stmt=$conn->prepare("SELECT n.*,nr.read_at FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=? $where ORDER BY n.id DESC LIMIT ? OFFSET ?");
$args=array_merge([$studentId],$params,[20,$offset]);$stmt->bind_param('i'.$types.'ii',...$args);$stmt->execute();$notifications=$stmt->get_result();$stmt->close();
$counts=['total'=>0,'General'=>0,'Fee'=>0,'Maintenance'=>0,'Event'=>0];
$counter=$conn->prepare('SELECT category,COUNT(*) c FROM notifications WHERE target_user_id IS NULL OR target_user_id=? GROUP BY category');
$counter->bind_param('i',$studentId);$counter->execute();
foreach ($counter->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $c=ucfirst(strtolower($row['category']));if(isset($counts[$c]))$counts[$c]=(int)$row['c'];$counts['total']+=(int)$row['c'];
}
$counter->close();
$badgeMap = ['general' => 'badge-blue', 'fee' => 'badge-yellow', 'maintenance' => 'badge-red', 'event' => 'badge-green'];

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">

    <div class="page-head">
        <h1>🔔 Notifications</h1>
        <p>Announcements from the hostel office.</p>
    </div>

    <?php echo flash_html(); ?>
    <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr))">
        <div class="stat"><div class="stat-label">Total</div><div class="stat-value"><?php echo $counts['total']; ?></div></div>
        <div class="stat blue"><div class="stat-label">General</div><div class="stat-value"><?php echo $counts['General']; ?></div></div>
        <div class="stat amber"><div class="stat-label">Fee</div><div class="stat-value"><?php echo $counts['Fee']; ?></div></div>
        <div class="stat red"><div class="stat-label">Maintenance</div><div class="stat-value"><?php echo $counts['Maintenance']; ?></div></div>
        <div class="stat green"><div class="stat-label">Event</div><div class="stat-value"><?php echo $counts['Event']; ?></div></div>
    </div>

    <div class="card">
        <div class="tabs">
            <?php foreach (['all' => 'All', 'general' => 'General', 'fee' => 'Fee', 'maintenance' => 'Maintenance', 'event' => 'Event'] as $k => $l): ?>
            <a class="tab <?php echo $filter === $k ? 'active' : ''; ?>" href="notifications.php?filter=<?php echo $k; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="search-bar">
            <input type="hidden" name="filter" value="<?php echo safe($filter); ?>">
            <input class="input" type="text" name="search" placeholder="Search announcements…" value="<?php echo safe($search); ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="notifications.php">Reset</a>
        </form>

        <?php if ($notifications->num_rows): while ($n = $notifications->fetch_assoc()):
            $cat = strtolower($n['category'] ?? 'general');
        ?>
        <div class="notif">
            <div class="notif-title"><?php echo safe($n['title']); ?> <?php if (!$n['read_at']): ?><span class="badge badge-red">Unread</span><?php endif; ?></div>
            <div class="notif-body"><?php echo nl2br(safe($n['message'])); ?></div>
            <div class="notif-meta">
                <span class="badge <?php echo $badgeMap[$cat] ?? 'badge-gray'; ?>"><?php echo safe(ucfirst($cat)); ?></span>
                <span><?php echo formatDateTime($n['created_at']); ?></span>
                <form method="POST" style="margin:0 0 0 auto"><?php echo csrf_field(); ?><input type="hidden" name="notification_id" value="<?php echo (int)$n['id']; ?>"><input type="hidden" name="action" value="<?php echo $n['read_at']?'unread':'read'; ?>"><button class="btn btn-ghost btn-sm" type="submit"><?php echo $n['read_at']?'Mark unread':'Mark read'; ?></button></form>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty"><span class="empty-ico">🔕</span><p>No announcements found.</p></div>
        <?php endif; ?>
        <?php page_links($page,$totalPages,$totalRows); ?>
    </div>

</main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
