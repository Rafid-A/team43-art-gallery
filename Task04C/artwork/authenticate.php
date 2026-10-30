<?php
/*
 * authenticate.php - CS 4485 Task04C Part (4): checks a login from art-login.php.
 * Phuc Tran (pmt220000), Team 43
 *
 *  1. Look the user ID up in art.users with a prepared statement.
 *  2. Compare the typed password with the stored bcrypt hash (password_verify).
 *  3. On success: new session ID, remember the user, go to art-main.php.
 *     On failure: back to art-login.php?error=1 (same message for a wrong
 *     user ID and a wrong password, so the page does not reveal which).
 */
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('art-login.php');
}

$userid   = trim(postString('userid'));
$password = postString('password');

if ($userid === '' || $password === '') {
    redirect('art-login.php?error=1');
}

try {
    $stmt = getPDO()->prepare(
        'SELECT id, firstname, lastname, userid, password FROM users WHERE userid = :userid'
    );
    $stmt->bindValue(':userid', $userid);
    $stmt->execute();
    $user = $stmt->fetch();
} catch (PDOException $ex) {
    error_log('Login query failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('The database is not available. Is MySQL running and is users.sql imported?');
}

if ($user && password_verify($password, $user['password'])) {
    session_regenerate_id(true);   // new session ID after login (prevents session fixation)
    $_SESSION['user'] = [
        'id'        => (int) $user['id'],
        'userid'    => $user['userid'],
        'firstname' => $user['firstname'],
        'lastname'  => $user['lastname'],
    ];
    redirect('art-main.php');
}

redirect('art-login.php?error=1');
