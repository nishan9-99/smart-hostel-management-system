<?php
/* Call inside the same transaction as the admin mutation. */
function audit_admin($conn, $action, $targetType, $targetId = null, $details = null) {
    $adminId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare('INSERT INTO admin_audit_log (admin_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)');
    $stmt->bind_param('issis', $adminId, $action, $targetType, $targetId, $details);
    $stmt->execute(); $stmt->close();
}
