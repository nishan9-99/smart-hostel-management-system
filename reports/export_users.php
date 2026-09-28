<?php
require_once __DIR__ . '/_csv.php';
$out = csv_start('users_report_' . date('Y-m-d') . '.csv', ['ID', 'Full Name', 'Username', 'Email', 'Phone', 'Role', 'Status', 'Joined']);
$res = $conn->query('SELECT id, full_name, username, email, phone, role, status, created_at FROM users ORDER BY id');
while ($r = $res->fetch_assoc()) {
    fputcsv($out, [
        $r['id'], csv_safe($r['full_name']), csv_safe($r['username']), csv_safe($r['email']),
        csv_safe($r['phone']), $r['role'], $r['status'], formatDateTime($r['created_at'])
    ]);
}
fclose($out);
exit();
