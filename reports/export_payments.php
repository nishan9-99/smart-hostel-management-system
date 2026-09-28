<?php
require_once __DIR__ . '/_csv.php';
$out = csv_start('payments_report_' . date('Y-m-d') . '.csv', ['ID', 'Student', 'Fee Type', 'Amount', 'Status', 'Verification', 'Method', 'Transaction ID', 'Due Date', 'Paid Date']);
$stmt = $conn->prepare('SELECT p.*, u.full_name AS student_name FROM payments p LEFT JOIN users u ON u.id=p.student_id ORDER BY p.id');
$stmt->execute(); $res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    fputcsv($out, [
        $r['id'], csv_safe($r['student_name'] ?? 'Deleted student'), csv_safe($r['fee_type']), $r['amount'],
        $r['status'], csv_safe($r['verification_status']), csv_safe($r['payment_method']),
        csv_safe($r['transaction_id']), $r['due_date'], formatDateTime($r['paid_date'])
    ]);
}
fclose($out);
exit();
