<?php
require_once __DIR__ . '/_csv.php';
$out = csv_start('rooms_report_' . date('Y-m-d') . '.csv', ['ID', 'Room Number', 'Type', 'Capacity', 'Occupied', 'Occupancy %', 'Status', 'Occupants']);
$res = $conn->query('SELECT * FROM room_occupancy_view ORDER BY room_number');
while ($r = $res->fetch_assoc()) {
    fputcsv($out, [
        $r['room_id'], csv_safe($r['room_number']), csv_safe($r['room_type']),
        $r['capacity'], $r['occupied'], $r['occupancy_pct'], $r['status'], csv_safe($r['occupants'])
    ]);
}
fclose($out);
exit();
