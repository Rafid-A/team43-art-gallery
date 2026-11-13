<?php
require_once __DIR__ . '/functions.php';
require_login();
$user = current_user();
page_start('Your profile');
?>
<section class="page-heading"><div><p class="eyebrow">Your place in the library</p><h1>Your profile</h1><p><?= $user['role'] === 'admin' ? 'You are signed in as an administrator. You can manage the library and accounts.' : 'You are signed in with a client account. You can browse the whole collection.' ?></p></div><?php if ($user['role'] === 'admin'): ?><a class="button" href="admin.php">Administration</a><?php endif; ?></section>
<?php notices(); ?>
<dl class="metadata"><div><dt>Name</dt><dd><?= e($user['forename'] . ' ' . $user['surname']) ?></dd></div><div><dt>User ID</dt><dd><?= e($user['username']) ?></dd></div><div><dt>Account type</dt><dd><?= e(role_label($user['role'])) ?></dd></div></dl>
<form action="logout.php" method="post"><input type="hidden" name="csrf" value="<?= e(token()) ?>"><button>Log out of the library</button></form>
<?php page_end(); ?>
