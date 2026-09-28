<?php
/* Shared CSV export bootstrap: admin-only + spreadsheet formula guard. */
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

function csv_start($filename, array $headers) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM so Excel reads UTF-8
    fputcsv($out, $headers);
    return $out;
}

/* Stop =, +, -, @ leading values from executing as formulas in Excel. */
function csv_safe($value) {
    $value = (string)$value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}
