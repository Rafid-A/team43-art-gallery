<?php
// Destroys the session and returns to the log-in page with a message.
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// The session is gone, so the "logged out" notice travels in the query string.
header('Location: login.php?logout=1');
exit;
