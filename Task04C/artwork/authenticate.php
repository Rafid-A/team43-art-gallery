<?php
require_once __DIR__ . '/functions.php';
require_post();

$stmt = db()->prepare(
    'SELECT forename, surname, username, password FROM art_users WHERE username = ?'
);
$stmt->execute([input('userid')]);
$user = $stmt->fetch();

if (!$user || !password_verify(input('password', false), $user['password'])) {
    flash('Invalid user ID or password. Please try again.', 'error');
    redirect('art-login.php');
}

session_regenerate_id(true);
unset($user['password']);
$_SESSION['user'] = $user;
$_SESSION['csrf'] = bin2hex(random_bytes(32));

flash('Welcome, ' . $user['forename'] . '. You are logged in as ' . $user['username'] . '.');
redirect('art-main.php');
