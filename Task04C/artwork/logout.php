<?php
require_once __DIR__ . '/functions.php';
require_post();

$_SESSION = [];
$params = session_get_cookie_params();
setcookie(
    session_name(),
    '',
    time() - 3600,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
);
session_destroy();

redirect('art-login.php');
