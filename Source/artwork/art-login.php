<?php
require_once __DIR__ . '/functions.php';

if ($user = current_user())
    redirect(home_url($user));

page_start('Log in');
?>
<section class="login-layout">
    <div class="login-intro">
        <img src="images/art/works/square-medium/019130.jpg"
             alt="A painting of purple irises" width="647" height="500"
             fetchpriority="high">
    </div>

    <div class="login-form">
        <h1>Log in to the gallery</h1>
        <p>Enter your client or administrator account to view the collection.</p>
        <?php notices(); ?>

        <form action="authenticate.php" method="post" data-submitting-label="Logging in…">
            <input type="hidden" name="csrf" value="<?= e(token()) ?>">

            <label for="userid">User ID</label>
            <input id="userid" name="userid" maxlength="32"
                   autocomplete="off" autocapitalize="none"
                   spellcheck="false" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password"
                   maxlength="255" autocomplete="off" required>

            <button class="primary full">Log in</button>
        </form>
        <p>New to the gallery? <a href="register.php">Create a client account</a></p>
    </div>
</section>
<?php page_end(); ?>
