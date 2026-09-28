<?php
require_once __DIR__ . '/../includes/config.php';
redirect_if_logged_in();
function google_fail($message = 'Google sign-in could not be completed. Please try again.') {
    flash($message, 'danger');
    header('Location: /auth/login.php');
    exit;
}
function google_api($url, $params = null, $bearer = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $bearer ? ['Authorization: Bearer ' . $bearer] : [],
    ]);
    if ($params !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status !== 200) return null;
    $result = json_decode($raw, true);
    return is_array($result) ? $result : null;
}
if (!google_oauth_configured() || empty($_SESSION['google_oauth_state'])
    || !is_string($_GET['state'] ?? null)
    || !hash_equals($_SESSION['google_oauth_state'], $_GET['state'])
    || time() - (int)($_SESSION['google_oauth_started'] ?? 0) > 600) google_fail();
unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_started']);
if (!is_string($_GET['code'] ?? null) || strlen($_GET['code']) > 2048) google_fail();
$tokens = google_api('https://oauth2.googleapis.com/token', [
    'code' => $_GET['code'], 'client_id' => getenv('GOOGLE_CLIENT_ID'),
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => getenv('GOOGLE_REDIRECT_URI'),
    'grant_type' => 'authorization_code'
]);
if (!isset($tokens['access_token']) || !is_string($tokens['access_token'])) google_fail();
// HTTPS userinfo validates the access token at Google. No client-supplied profile is trusted.
$profile = google_api('https://openidconnect.googleapis.com/v1/userinfo', null, $tokens['access_token']);
if (!$profile || empty($profile['sub']) || strlen($profile['sub']) > 255
    || !filter_var($profile['email'] ?? '', FILTER_VALIDATE_EMAIL)
    || !in_array($profile['email_verified'] ?? null, [true, 'true', 1, '1'], true)) google_fail();
$sub = (string)$profile['sub'];
$email = strtolower((string)$profile['email']);
if (strlen($email) > 100) google_fail();
$name = trim((string)($profile['name'] ?? ''));
$name = substr($name !== '' ? $name : strstr($email, '@', true), 0, 100);
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->bind_param('s', $sub); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user) {
        // Google is authoritative for Gmail addresses. A verified third-party
        // address may have changed hands, so never link it to an existing account.
        $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email); $stmt->execute(); $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($existing) {
            if (!str_ends_with($email, '@gmail.com') || $existing['role'] !== 'student') {
                $conn->rollback(); google_fail('This email already has an account. Please sign in with your password.');
            }
            $user = $existing;
        } else {
            $username = 'g_' . bin2hex(random_bytes(10));
            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (full_name,username,email,phone,password,role,status,google_id,auth_provider) VALUES (?,?,?,'',?,'student','active',?,'google')");
            $stmt->bind_param('sssss', $name, $username, $email, $hash, $sub);
            $stmt->execute(); $userId = $conn->insert_id; $stmt->close();
            $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->bind_param('i', $userId); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        }
        if ($existing) {
            // Never overwrite a different Google binding. A Google subject is immutable.
            if (!empty($user['google_id']) && $user['google_id'] !== $sub) { $conn->rollback(); google_fail(); }
            $stmt = $conn->prepare("UPDATE users SET google_id=?, auth_provider='google' WHERE id=?");
            $stmt->bind_param('si', $sub, $user['id']); $stmt->execute(); $stmt->close();
        }
    }
    if ($user['role'] !== 'student' || $user['status'] !== 'active') { $conn->rollback(); google_fail('This account cannot sign in with Google.'); }
    $conn->commit();
    session_regenerate_id(true);
    foreach (['id'=>'user_id','full_name'=>'full_name','username'=>'username','email'=>'email','phone'=>'phone','role'=>'role'] as $field=>$key) $_SESSION[$key] = $user[$field];
    if ($user['role'] === 'student' && $user['phone'] === '') flash('Signed in. Add your phone and address in your profile to complete your hostel details.', 'info');
    header('Location: ' . ($user['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php'));
    exit;
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $rollbackError) {}
    error_log('Google login failed: ' . $e->getMessage());
    google_fail();
}
