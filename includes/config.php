<?php
/* ============================================================
   Smart Hostel - shared bootstrap
   * strict mysqli (errors surface as exceptions, never silent)
   * env-driven DB config (Docker defaults, overridable)
   * escaping / formatting / CSRF / auth / flash helpers
   * secure OTP mailer with dev-mode fallback
   ============================================================ */

ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors', '1');
register_shutdown_function(function () {
    $last = error_get_last();
    if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal application error: ' . $last['message']);
        if (!headers_sent()) {
            http_response_code(500);
            include __DIR__ . '/../errors/500.html';
        }
    }
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

date_default_timezone_set('Asia/Kolkata');

/* Keep persistent sessions available for up to 30 days on the server too.
   Without this, PHP's default garbage collection can end a remembered login
   after 24 minutes even though its browser cookie is still present. */
define('REMEMBER_SESSION_SECONDS', 30 * 24 * 60 * 60);
ini_set('session.gc_maxlifetime', (string)REMEMBER_SESSION_SECONDS);

/* Session cookie attributes apply to initial requests and reopened remembered sessions.
   APP_FORCE_HTTPS=1 is useful when TLS is terminated by a trusted proxy. */
function session_cookie_options($lifetime) {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (getenv('APP_FORCE_HTTPS') === '1');
    return ['lifetime' => $lifetime, 'path' => '/', 'domain' => '',
        'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
}

/* Reopen the same PHP session with a different cookie lifetime. This must
   set cookie parameters BEFORE session_start(), including on later requests.
   No second remember-me cookie or token is created. */
function session_apply_cookie_lifetime($remember) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_set_cookie_params(session_cookie_options($remember ? REMEMBER_SESSION_SECONDS : 0));
    session_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(session_cookie_options(0));
    session_start();
    if (!empty($_SESSION['keep_signed_in'])) {
        session_apply_cookie_lifetime(true);
    }
}

/* ---------------- Database ---------------- */
$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = (int)(getenv('DB_PORT') ?: 3306);
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root';
$DB_NAME = getenv('DB_NAME') ?: 'smart_hostel';
$DB_SOCKET = getenv('DB_SOCKET') ?: null;

try {
    if ($DB_SOCKET) {
        $conn = new mysqli(null, $DB_USER, $DB_PASS, $DB_NAME, null, $DB_SOCKET);
    } else {
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    }
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+05:30'");
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    error_log('Database connection failed: ' . $e->getMessage());
    if (getenv('APP_DEBUG') === '1') die('Database connection failed. Is the MySQL container up? (docker-compose up --build)');
    header('Location: /errors/500.html', true, 302); exit;
}

/* ---------------- Escaping / formatting ---------------- */
function safe($val) {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function formatDate($date, $fallback = '-') {
    if (empty($date) || $date === '0000-00-00') return $fallback;
    $t = strtotime($date);
    return $t ? date('d M Y', $t) : $fallback;
}

function formatDateTime($date, $fallback = '-') {
    if (empty($date) || $date === '0000-00-00 00:00:00') return $fallback;
    $t = strtotime($date);
    return $t ? date('d M Y, h:i A', $t) : $fallback;
}

function money($amount) {
    return '₹' . number_format((float)$amount, 2);
}

/* ---------------- CSRF ---------------- */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . safe(csrf_token()) . '">';
}

/* Verify the token on every state-changing request. */
function csrf_check() {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        die('Invalid or missing security token. Go back and try again.');
    }
}

/* ---------------- Auth helpers ---------------- */
function require_role($role) {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit();
    }
    if (($_SESSION['role'] ?? '') !== $role) {
        header('Location: ' . (($_SESSION['role'] ?? '') === 'admin'
            ? '/admin/dashboard.php' : '/student/dashboard.php'));
        exit();
    }
}

function redirect_if_logged_in() {
    if (!empty($_SESSION['user_id'])) {
        header('Location: ' . (($_SESSION['role'] ?? '') === 'admin'
            ? '/admin/dashboard.php' : '/student/dashboard.php'));
        exit();
    }
}

/* ---------------- Flash messages ---------------- */
function flash($message, $type = 'success') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_pull() {
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function flash_html() {
    $f = flash_pull();
    if (!$f) return '';
    $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
    $icons = ['success' => '✅', 'danger' => '⛔', 'warning' => '⚠️', 'info' => 'ℹ️'];
    return '<div class="alert alert-' . $type . '">' . $icons[$type] . ' ' . safe($f['message']) . '</div>';
}

/* ---------------- Password rules ---------------- */
function password_policy_error($password) {
    if (strlen($password) < 8)               return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $password))   return 'Password needs an uppercase letter.';
    if (!preg_match('/[a-z]/', $password))   return 'Password needs a lowercase letter.';
    if (!preg_match('/[0-9]/', $password))   return 'Password needs a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'Password needs a special character.';
    return null;
}

/* ============================================================
   OTP password reset
   - 4 digit code, stored HASHED, expires in 10 minutes,
     max 5 attempts, single use
   - email delivery through SMTP (see README) and a dev-mode
     fallback that shows the code on screen when no SMTP is
     configured, so the flow always works in the demo
   ============================================================ */
define('OTP_LENGTH', 4);
define('OTP_TTL_MINUTES', 10);
define('OTP_MAX_ATTEMPTS', 5);

function otp_generate() {
    $min = (int)pow(10, OTP_LENGTH - 1);
    $max = (int)pow(10, OTP_LENGTH) - 1;
    return (string)random_int($min, $max);
}

/* Vendored PHPMailer 6.12.0 (upstream license in lib/PHPMailer/LICENSE). */
function smarthostel_autoload() {
    foreach (['Exception', 'PHPMailer', 'SMTP'] as $class) {
        require_once __DIR__ . '/../lib/PHPMailer/' . $class . '.php';
    }
}

/* Returns true when real SMTP delivery is configured (SMTP_HOST set). */
function smtp_configured() {
    return (getenv('SMTP_HOST') ?: '') !== '';
}

/* Shared SMTP transport for OTP and payment status notices. */
function smarthostel_send_mail($email, $name, $subject, $body) {
            smarthostel_autoload();
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                throw new RuntimeException('PHPMailer library missing from lib/PHPMailer.');
            }

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host    = getenv('SMTP_HOST');
            $mail->Port    = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;

            $user = getenv('SMTP_USER') ?: '';
            $pass = getenv('SMTP_PASS') ?: '';
            if ($user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
            }

            $secure = strtolower(getenv('SMTP_SECURE') ?: 'tls');
            if ($secure === 'ssl' || $secure === 'smtps') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls' || $secure === 'starttls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
                $mail->SMTPSecure  = false;
            }

            $from = getenv('SMTP_FROM') ?: ($user !== '' ? $user : 'no-reply@smarthostel.local');
            $mail->setFrom($from, 'Smart Hostel');
            $mail->addAddress($email, $name);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
}

/*
 * Deliver the OTP.
 * - SMTP_HOST set   -> real email via PHPMailer (Gmail SMTP with an
 *                      App Password - see README "Email / SMTP").
 *                      Returns null and never shows the code on screen.
 * - SMTP_HOST empty -> dev mode: returns the code so the caller can
 *                      show it in a banner, so demos work with zero setup.
 */
function otp_deliver($email, $name, $otp, $purpose = 'password reset') {
    $subject = 'Smart Hostel - your ' . $purpose . ' code';
    $body    = "Hi {$name},\n\n"
             . "Your Smart Hostel {$purpose} code is: {$otp}\n"
             . 'It expires in ' . OTP_TTL_MINUTES . " minutes.\n\n"
             . "If you did not request this, ignore this email.\n\n- Smart Hostel";

    if (smtp_configured()) {
        try {
            smarthostel_send_mail($email, $name, $subject, $body);

            unset($_SESSION['otp_mail_error']);
            return null; // never echo the code when mail transport exists
        } catch (Throwable $t) {
            error_log('OTP mail failed: ' . $t->getMessage());
            // Surface the failure instead of pretending the email went out
            $_SESSION['otp_mail_error'] = 'We could not deliver the email (SMTP error). Check the SMTP settings in docker-compose.yml, then use Resend code.';
            return null;
        }
    }

    error_log("Smart Hostel dev-mode OTP generated for {$email} (SMTP unconfigured)");
    return $otp; // dev-mode fallback: caller shows it in the UI
}

function google_oauth_configured() {
    return (getenv('GOOGLE_CLIENT_ID') ?: '') !== '' && (getenv('GOOGLE_CLIENT_SECRET') ?: '') !== '' && (getenv('GOOGLE_REDIRECT_URI') ?: '') !== '';
}
