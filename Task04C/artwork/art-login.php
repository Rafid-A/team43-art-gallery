<?php
/*
 * art-login.php - CS 4485 Task04C Part (4): login page of the Art Gallery.
 * Phuc Tran (pmt220000), Team 43
 *
 * The form posts to authenticate.php, which checks the users table.
 * authenticate.php sends the browser back here with ?error=1 when the login fails.
 */
require_once __DIR__ . '/session.php';

// Already logged in: go straight to the gallery.
if (!empty($_SESSION['user'])) {
    redirect('art-main.php');
}

$message = null;
if (isset($_GET['error'])) {
    $message = ['negative', 'Login failed', 'The user ID or password is incorrect. Please try again.'];
} elseif (isset($_GET['required'])) {
    $message = ['warning', 'Please log in', 'You must log in to view the Art Gallery.'];
} elseif (isset($_GET['logout'])) {
    $message = ['positive', 'Logged out', 'You have been logged out. See you again soon.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Art Gallery - Login</title>
   <link href="semantic/semantic.css" rel="stylesheet">
   <link href="style.css" rel="stylesheet">
</head>
<body class="login-page">
   <main class="login-box">
      <div class="ui segment login-card">
         <h1 class="ui header login-title">
            <i class="paint brush icon"></i>
            <div class="content">
               Art Gallery
               <div class="sub header">Customer login</div>
            </div>
         </h1>

         <?php if ($message): ?>
         <div class="ui <?= $message[0] ?> message" id="login-message">
            <div class="header"><?= e($message[1]) ?></div>
            <p><?= e($message[2]) ?></p>
         </div>
         <?php endif; ?>

         <!-- autocomplete="off": the browser should not offer to save or fill in the password -->
         <form class="ui large form" method="post" action="authenticate.php" autocomplete="off">
            <div class="required field">
               <label for="userid">User ID</label>
               <div class="ui left icon input">
                  <i class="user icon"></i>
                  <input type="text" id="userid" name="userid" placeholder="e.g. user1"
                         maxlength="50" required autofocus autocomplete="off">
               </div>
            </div>
            <div class="required field">
               <label for="password">Password</label>
               <div class="ui left icon input">
                  <i class="lock icon"></i>
                  <input type="password" id="password" name="password" placeholder="Password"
                         maxlength="100" required autocomplete="new-password">
               </div>
            </div>
            <button class="ui fluid large primary button" type="submit">
               <i class="sign in icon"></i> Log in
            </button>
         </form>
      </div>
      <p class="login-footer">CS 4485 &middot; Task04C &middot; Phuc Tran (pmt220000) &middot; Team 43</p>
   </main>
</body>
</html>
