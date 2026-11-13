<?php
require_once __DIR__ . '/library.php';
require_admin();
$accounts = rows("SELECT SUM(role = 'admin' AND status = 'active') AS admins, SUM(role = 'client' AND status = 'active') AS clients, SUM(status = 'disabled') AS disabled FROM art_users")->fetch();
page_start('Administration');
?>
<section class="page-heading"><div><p class="eyebrow">Administrator main page</p><h1>Administration</h1><p>Manage gallery records, reports and accounts. Clients see the collection without these tools.</p></div><a class="button primary" href="art-add.php">Add artwork <span aria-hidden="true">+</span></a></section>
<?php notices(); ?>
<div class="directory">
<a class="directory-row" href="manage.php"><div><h2>Manage library</h2><p>Add, view and delete artists, galleries, genres, eras, subjects and artwork classifications.</p></div><span><?= (int)rows('SELECT COUNT(*) FROM Paintings')->fetchColumn() ?> artworks ↗</span></a>
<a class="directory-row" href="users.php"><div><h2>Accounts</h2><p>Change account types, disable or enable accounts, and delete accounts.</p></div><span><?= (int)$accounts['admins'] ?> administrators · <?= (int)$accounts['clients'] ?> clients<?= (int)$accounts['disabled'] ? ' · ' . (int)$accounts['disabled'] . ' disabled' : '' ?> ↗</span></a>
<a class="directory-row" href="integrity.php"><div><h2>Integrity report</h2><p>A read-only check of references and duplicates across the collection.</p></div><span>View ↗</span></a>
<a class="directory-row" href="art-main.php"><div><h2>Artworks</h2><p>Browse, search and sort the collection as clients see it.</p></div><span>View ↗</span></a>
</div>
<?php page_end(); ?>
