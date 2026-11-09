<?php require_once __DIR__ . '/library.php'; require_login(); page_start('Browse'); ?>
<section class="page-heading"><div><p class="eyebrow">Find a new perspective</p><h1>Ways of seeing</h1><p>Follow a movement, explore an era, or discover a subject.</p></div></section>
<div class="browse-grid">
<?php foreach (['genres' => ['01', 'Movements & genres', 'The ideas that bring artworks together.'], 'eras' => ['02', 'Through the eras', 'An introduction to art across time.'], 'subjects' => ['03', 'Art by subject', 'The world, as artists see it.']] as $type => [$number, $title, $copy]): ?>
<a class="browse-tile" href="objects.php?type=<?= e($type) ?>"><span class="eyebrow"><?= $number ?> / Browse</span><h2><?= e($title) ?></h2><p><?= e($copy) ?></p><span>Explore <span aria-hidden="true">↗</span></span></a><?php endforeach; ?></div>
<?php page_end(); ?>
