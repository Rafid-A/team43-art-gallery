<?php
// Log-in page: shows the form and any error/info message from the last attempt.
session_start();
require_once 'config.php';

// Already logged in? Go straight to the welcome page.
if (isset($_SESSION['user'])) {
    header('Location: welcome.php');
    exit;
}

$error   = $_SESSION['login_error'] ?? '';
$info    = $_SESSION['login_info'] ?? '';
if (isset($_GET['logout'])) {
    $info = 'You have been logged out.';
}
$userid  = $_SESSION['login_userid'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_info'], $_SESSION['login_userid']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>www13 - Log In</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="card">
    <h1>Member Log In</h1>
    <p class="sub">CS 4485 &middot; www13 authentication demo</p>

    <?php if ($error !== ''): ?>
        <div class="msg error"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($info !== ''): ?>
        <div class="msg info"><?= h($info) ?></div>
    <?php endif; ?>

    <!-- autocomplete="off" so the browser does not offer to save the password -->
    <form method="post" action="authenticate.php" autocomplete="off">
        <label for="userid">User ID</label>
        <input type="text" id="userid" name="userid" value="<?= h($userid) ?>"
               maxlength="50" required autofocus autocomplete="off">

        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               required autocomplete="new-password">

        <button type="submit">Log In</button>
    </form>
</main>
<footer>Phuc Tran &middot; pmt220000 &middot; Team 43</footer>
</body>
</html>
