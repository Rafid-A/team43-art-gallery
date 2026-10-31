<?php require_once __DIR__ . '/library.php'; require_login(); page_start('Manage library'); ?>
<section class="page-heading"><div><p class="eyebrow">Care for the collection</p><h1>Manage library</h1><p>Browse records, add to the library, and manage artwork connections.</p></div><a class="button" href="integrity.php">Check integrity</a></section>
<?php notices(); ?>
<div class="directory"><a class="directory-row" href="art-main.php"><div><h2>Artworks / Paintings</h2><p>View details, add artwork, and delete with order protection.</p></div><span><?= (int)rows('SELECT COUNT(*) FROM Paintings')->fetchColumn() ?> records ↗</span></a>
<?php foreach (library_objects() as $type => $object): ?><a class="directory-row" href="objects.php?type=<?= e($type) ?>&amp;manage=1"><div><h2><?= e($object['label']) ?><?php if (!empty($object['relationship'])): ?> <small>(<?= e($object['table']) ?>)</small><?php endif; ?></h2><p><?= isset($object['gate']) ? e($object['gate']) : 'View · Details · Add · Delete with dependency checks' ?></p></div><span><?= (int)rows('SELECT COUNT(*) FROM ' . $object['table'])->fetchColumn() ?> records ↗</span></a><?php endforeach; ?></div>
<?php page_end(); ?>
