<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

/* ---------------------------------------------------------------
   Username availability probe used by the register form.
   Rate-limited per session to stop username enumeration abuse.
   --------------------------------------------------------------- */
$now = time();
$win = &$_SESSION['uname_probe'];
if (!is_array($win) || ($win['start'] ?? 0) < $now - 600) {
    $win = ['start' => $now, 'count' => 0];
}
$win['count']++;

$respond = function ($status, $message, $suggestions = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'suggestions' => $suggestions]);
    exit;
};

if ($win['count'] > 30) {
    $respond('limited', 'Too many checks. Please wait a few minutes and try again.');
}

$username = trim($_GET['username'] ?? '');

if ($username === '')                 $respond('empty', '');
if (strlen($username) < 4)            $respond('invalid', 'Username must be at least 4 characters.');
if (strlen($username) > 30)           $respond('invalid', 'Username must be 30 characters or fewer.');
if (!preg_match('/^[A-Za-z0-9_.]+$/', $username)) {
    $respond('invalid', 'Only letters, numbers, dot and underscore allowed.');
}

try {
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $taken = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$taken) {
        $respond('available', 'Username is available.');
    }

    // Suggest up to 3 free variants
    $base = substr(preg_replace('/[^A-Za-z0-9]/', '', $username) ?: 'user', 0, 20);
    $suggestions = [];
    $guard = 0;
    while (count($suggestions) < 3 && $guard++ < 15) {
        $candidate = $base . random_int(100, 999);
        $c = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $c->bind_param('s', $candidate);
        $c->execute();
        $free = !$c->get_result()->fetch_assoc();
        $c->close();
        if ($free && !in_array($candidate, $suggestions, true)) {
            $suggestions[] = $candidate;
        }
    }

    $respond('taken', 'Username is already taken.', $suggestions);
} catch (mysqli_sql_exception $e) {
    error_log('check_username error: ' . $e->getMessage());
    $respond('error', 'Could not check right now.');
}
