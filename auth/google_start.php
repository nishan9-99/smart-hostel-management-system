<?php
require_once __DIR__ . '/../includes/config.php';
redirect_if_logged_in();
if (!google_oauth_configured()) { http_response_code(503); exit('Google sign-in is not configured.'); }
$state = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_started'] = time();
$params = [
    'client_id' => getenv('GOOGLE_CLIENT_ID'),
    'redirect_uri' => getenv('GOOGLE_REDIRECT_URI'),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account'
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
exit;
