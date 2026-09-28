<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id <= 0) { http_response_code(404); exit('Receipt not found.'); }
$studentId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT p.id, p.fee_type, p.amount, p.paid_date, p.verified_at, u.full_name
    FROM payments p JOIN users u ON u.id=p.student_id
    WHERE p.id=? AND p.student_id=? AND p.verification_status='Approved' AND p.status='paid' LIMIT 1");
$stmt->bind_param('ii', $id, $studentId);
$stmt->execute(); $receipt = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$receipt) { http_response_code(404); exit('Receipt not found.'); }

// Small self-contained, single-page PDF using PDF's standard Helvetica font.
// Convert arbitrary UTF-8 input to printable Latin text before embedding in the stream.
function receipt_pdf_text($value) {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$value);
    if ($text === false) $text = '';
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    $text = substr($text, 0, 100);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}
$lines = [
    'SMART HOSTEL - PAYMENT RECEIPT',
    'Receipt number: ' . $receipt['id'],
    'Student: ' . $receipt['full_name'],
    'Fee type: ' . $receipt['fee_type'],
    'Amount: INR ' . number_format((float)$receipt['amount'], 2),
    'Payment date: ' . formatDateTime($receipt['paid_date']),
    'Approval date: ' . formatDateTime($receipt['verified_at']),
];
$stream = "BT /F1 16 Tf 50 780 Td (" . receipt_pdf_text(array_shift($lines)) . ") Tj ET\n";
$y = 730;
foreach ($lines as $line) {
    $stream .= 'BT /F1 12 Tf 50 ' . $y . ' Td (' . receipt_pdf_text($line) . ") Tj ET\n";
    $y -= 35;
}
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
];
$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $i => $object) {
    $offsets[] = strlen($pdf);
    $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
foreach ($offsets as $offset) {
    if ($offset === 0) continue;
    $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
}
$pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="smart-hostel-receipt-' . $id . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo $pdf;
