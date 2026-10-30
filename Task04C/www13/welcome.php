<?php
// Protected page: only reachable with a logged-in session.
session_start();
require_once 'config.php';

if (!isset($_SESSION['user'])) {
    $_SESSION['login_error'] = 'Please log in to view that page.';
    header('Location: login.php');
    exit;
}
$user = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>www13 - Welcome</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="card">
    <h1>Welcome, <?= h($user['firstname'] . ' ' . $user['lastname']) ?>!</h1>
    <div class="msg success">You have logged in successfully.</div>
    <table class="details">
        <tr><th>User ID</th><td><?= h($user['userid']) ?></td></tr>
        <tr><th>Account #</th><td><?= h((string) $user['id']) ?></td></tr>
        <tr><th>Logged in at</th><td><?= h($_SESSION['login_time'] ?? '') ?></td></tr>
    </table>
    <a class="button" href="logout.php">Log Out</a>
</main>
<footer>Phuc Tran &middot; pmt220000 &middot; Team 43</footer>
</body>
</html>
