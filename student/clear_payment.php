<?php
require_once __DIR__ . '/../includes/config.php';
require_role('student');

/* ---------------------------------------------------------------
   Payment proof submission - hardened:
   * POST + CSRF only
   * extension comes from the DETECTED MIME type, never from the
     user-supplied filename (kills the "shell.php.png" trick)
   * image MIME whitelist only
   * random server-side filename
   * uploads/payment_proofs/.htaccess blocks script execution
     AND PHP files are refused by the web server config
   --------------------------------------------------------------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: payments.php');
    exit();
}
csrf_check();

$studentId = (int)$_SESSION['user_id'];
$pay_id = (int)($_POST['payment_id'] ?? 0);
$method = trim($_POST['payment_method'] ?? '');
$txn_id = trim($_POST['transaction_id'] ?? '');
$note   = trim($_POST['payment_note'] ?? '');

$back = function ($msg, $type = 'danger') {
    flash($msg, $type);
    header('Location: payments.php');
    exit();
};

if ($pay_id <= 0 || $method === '') {
    $back('Please choose a payment method.');
}

try {
    // the due must belong to this student and still be payable
    $chk = $conn->prepare('SELECT id, status, verification_status FROM payments WHERE id = ? AND student_id = ? LIMIT 1');
    $chk->bind_param('ii', $pay_id, $studentId);
    $chk->execute();
    $pay = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$pay) $back('Payment not found.');
    $vs = strtolower($pay['verification_status'] ?? '');
    if ($vs === 'approved')             $back('This payment is already approved.');
    if ($vs === 'pending verification') $back('Proof already submitted. Awaiting verification.');

    /* ---- proof upload (optional but validated hard when present) ---- */
    $proofFile = null;
    $hasFile = !empty($_FILES['proof_file']['name'])
            && !empty($_FILES['proof_file']['tmp_name'])
            && ($_FILES['proof_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if ($hasFile) {
        // MIME -> extension map: the user's filename is never trusted
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        $maxSize = 5 * 1024 * 1024;

        $tmp   = $_FILES['proof_file']['tmp_name'];
        $fsize = (int)$_FILES['proof_file']['size'];

        if ($fsize <= 0 || $fsize > $maxSize) {
            $back('File too large. Maximum size is 5MB.');
        }

        $mime = mime_content_type($tmp);
        if (!isset($mimeMap[$mime])) {
            $back('Only JPG, PNG, GIF or WEBP images are accepted as proof.');
        }

        // second opinion: it must really decode as an image
        if (@getimagesize($tmp) === false) {
            $back('That file is not a valid image.');
        }

        $uploadDir = dirname(__DIR__) . '/uploads/payment_proofs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0750, true);
        }

        $proofFile = 'payment_' . $pay_id . '_' . bin2hex(random_bytes(8)) . '.' . $mimeMap[$mime];
        if (!move_uploaded_file($tmp, $uploadDir . $proofFile)) {
            $back('File upload failed. Please try again.');
        }
        chmod($uploadDir . $proofFile, 0640);
    }

    if ($proofFile) {
        $stmt = $conn->prepare(
            "UPDATE payments
             SET verification_status='Pending Verification', payment_method=?, transaction_id=?,
                 payment_note=?, proof_file=?, paid_date=NOW()
             WHERE id=? AND student_id=?"
        );
        $stmt->bind_param('ssssii', $method, $txn_id, $note, $proofFile, $pay_id, $studentId);
    } else {
        $stmt = $conn->prepare(
            "UPDATE payments
             SET verification_status='Pending Verification', payment_method=?, transaction_id=?,
                 payment_note=?, paid_date=NOW()
             WHERE id=? AND student_id=?"
        );
        $stmt->bind_param('sssii', $method, $txn_id, $note, $pay_id, $studentId);
    }
    $stmt->execute();
    $stmt->close();

    flash('Payment submitted! The admin will verify your proof shortly.', 'success');
    header('Location: payments.php');
    exit();
} catch (mysqli_sql_exception $e) {
    error_log('clear_payment error: ' . $e->getMessage());
    $back('Failed to submit payment. Please try again.');
}
