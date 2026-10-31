<?php
require_once __DIR__ . '/functions.php';
require_login();
page_start('Account');
$user = $_SESSION['user'];
?>
<section class="page-heading"><div><p class="eyebrow">Your place in the library</p><h1>Account</h1><p>You are signed in with the existing gallery customer account.</p></div></section>
<dl class="metadata"><div><dt>Name</dt><dd><?= e($user['forename'] . ' ' . $user['surname']) ?></dd></div><div><dt>User ID</dt><dd><?= e($user['username']) ?></dd></div></dl>
<form action="logout.php" method="post"><input type="hidden" name="csrf" value="<?= e(token()) ?>"><button>Log out of the library</button></form>
<?php page_end(); ?>
