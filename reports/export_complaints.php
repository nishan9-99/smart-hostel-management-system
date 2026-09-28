<?php
require_once __DIR__ . '/_csv.php';
$out = csv_start('complaints_report_' . date('Y-m-d') . '.csv', ['ID', 'Student', 'Title', 'Category', 'Status', 'Admin Reply', 'Created', 'Resolved']);
$stmt = $conn->prepare('SELECT c.*, u.full_name AS student_name FROM complaints c LEFT JOIN users u ON u.id=c.user_id ORDER BY c.id');
$stmt->execute(); $res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    fputcsv($out, [
        $r['id'], csv_safe($r['student_name'] ?? 'Deleted student'), csv_safe($r['title']), csv_safe($r['category']),
        $r['status'], csv_safe($r['admin_reply']), formatDateTime($r['created_at']), formatDateTime($r['resolved_at'])
    ]);
}
fclose($out);
exit();
