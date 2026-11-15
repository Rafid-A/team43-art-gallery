<?php
require_once __DIR__ . '/functions.php';
require_post();

$pdo = db();
require_account_schema($pdo);
$stmt = $pdo->prepare(
    'SELECT forename, surname, username, password, role, status FROM art_users WHERE username = ?'
);
$stmt->execute([input('userid')]);
$user = $stmt->fetch();

if (!$user || !password_verify(input('password', false), $user['password'])) {
    flash('Invalid user ID or password. Please try again.', 'error');
    redirect('art-login.php');
}

if ($user['status'] !== 'active') {
    flash('This account is disabled. Contact an administrator.', 'error');
    redirect('art-login.php');
}

session_regenerate_id(true);
$_SESSION['account_fingerprint'] = hash('sha256', $user['password']);
unset($user['password'], $user['status']);
$_SESSION['user'] = $user;
$_SESSION['csrf'] = bin2hex(random_bytes(32));

// Return to the page that asked for login, rechecked here; otherwise the role's home.
$next = $_SESSION['after_login'] ?? null;
unset($_SESSION['after_login']);
flash('Welcome, ' . $user['forename'] . '. You are logged in as ' . $user['username'] . ' (' . strtolower(role_label($user['role'])) . ').');
redirect(safe_return_page($next) ? $next : home_url($user));
