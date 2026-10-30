<?php
/*
 * logout.php - ends the session and returns to the login page.
 */
require_once __DIR__ . '/session.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: art-login.php?logout=1');
exit;
