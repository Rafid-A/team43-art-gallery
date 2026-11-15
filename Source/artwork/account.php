<?php
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/saved-works.php';
require_login();
$user = current_user();
$savedReady = saved_works_ready(db());
$saved = $savedReady ? saved_works($user['username']) : [];
page_start('Your profile');
?>
<section class="page-heading"><div><p class="eyebrow">Account</p><h1>Your profile</h1><p><?= $user['role'] === 'admin' ? 'You are signed in as an administrator. You can manage the library and accounts.' : 'You are signed in with a client account. You can browse the whole collection and save artworks to your own collection.' ?></p></div><?php if ($user['role'] === 'admin'): ?><a class="button" href="admin.php">Administration</a><?php endif; ?></section>
<?php notices(); ?>
<dl class="metadata"><div><dt>Name</dt><dd><?= e($user['forename'] . ' ' . $user['surname']) ?></dd></div><div><dt>User ID</dt><dd><?= e($user['username']) ?></dd></div><div><dt>Account type</dt><dd><?= e(role_label($user['role'])) ?></dd></div><?php if ($savedReady): ?><div><dt>Saved artworks</dt><dd><?= count($saved) ?></dd></div><?php endif; ?></dl>
<section class="my-collection" id="my-collection" aria-labelledby="my-collection-title" tabindex="-1">
<div class="section-heading"><h2 id="my-collection-title">My collection</h2></div>
<?php if (!$savedReady): ?>
<p class="empty">My collection is unavailable until the database upgrade is completed. The rest of the gallery works as usual.</p>
<?php elseif (!$saved): ?>
<div class="empty collection-empty"><p>You have not saved any artworks yet. Open an artwork and choose “Save to my collection” to keep it here.</p><a class="button" href="art-main.php">View artworks</a></div>
<?php else: ?>
<div class="collection saved-collection">
<?php foreach ($saved as $index => $painting):
    $name = trim(($painting['FirstName'] ?? '') . ' ' . ($painting['LastName'] ?? '')) ?: 'Unknown artist';
    $detailUrl = 'painting.php?id=' . (int)$painting['PaintingID']; ?>
<article class="art-card"><a class="art-image" href="<?= e($detailUrl) ?>"><?php artwork_image($painting, $index < 3); ?></a><div class="art-copy"><h3><a href="<?= e($detailUrl) ?>"><?= e($painting['Title']) ?></a></h3><p class="artist-name"><?= e($name) ?></p><p class="art-meta"><?= e($painting['YearOfWork'] ?: 'Year unknown') ?></p><?php saved_work_form($painting['PaintingID'], true, $painting['Title'], 'account.php#my-collection', true); ?></div></article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
<form action="logout.php" method="post"><input type="hidden" name="csrf" value="<?= e(token()) ?>"><button>Log out of the library</button></form>
<?php page_end(); ?>
