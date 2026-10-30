<?php
// Handles the log-in form POST: looks up the userid and verifies the password hash.
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$userid   = trim($_POST['userid'] ?? '');
$password = $_POST['password'] ?? '';

function fail(string $msg, string $userid): void
{
    $_SESSION['login_error']  = $msg;
    $_SESSION['login_userid'] = $userid;
    header('Location: login.php');
    exit;
}

if ($userid === '' || $password === '') {
    fail('Please enter both a user ID and a password.', $userid);
}

try {
    $stmt = getDb()->prepare(
        'SELECT id, userid, password, firstname, lastname FROM users WHERE userid = ?'
    );
    $stmt->execute([$userid]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('www13 login DB error: ' . $e->getMessage());
    fail('A database error occurred. Please try again later.', $userid);
}

// Same message for unknown user and wrong password (don't reveal which).
if (!$user || !password_verify($password, $user['password'])) {
    fail('Invalid user ID or password.', $userid);
}

// Success: new session id (prevents session fixation), store the user.
session_regenerate_id(true);
$_SESSION['user'] = [
    'id'        => (int) $user['id'],
    'userid'    => $user['userid'],
    'firstname' => $user['firstname'],
    'lastname'  => $user['lastname'],
];
$_SESSION['login_time'] = date('Y-m-d H:i:s');

header('Location: welcome.php');
exit;
