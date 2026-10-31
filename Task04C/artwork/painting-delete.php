<?php
require_once __DIR__ . '/library.php';
require_login();
$context = artwork_context($_GET);
$collectionUrl = artwork_collection_url($context);
$id = positive_id(get_text('id'));
$painting = rows('SELECT PaintingID, Title FROM Paintings WHERE PaintingID=?', [$id])->fetch();
if (!$painting) http_response_code(404);
page_start('Delete artwork', $collectionUrl); ?>
<section class="confirmation"><p class="eyebrow">Library management</p><h1><?= $painting ? 'Delete this artwork?' : 'Artwork not found' ?></h1>
<?php if ($painting): ?><h2><?= e($painting['Title']) ?></h2>
<p>Artwork #<?= $id ?> and its genre assignments, subject assignments, and reviews will be removed together. Artist, gallery, and shared image files are preserved. Artworks in an order cannot be deleted.</p>
<form action="art-delete.php" method="post">
    <input type="hidden" name="csrf" value="<?= e(token()) ?>">
    <input type="hidden" name="painting_id" value="<?= $id ?>">
    <?php foreach ($context as $name => $value): ?>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php endforeach; ?>
    <button class="danger">Confirm deletion</button>
    <a href="painting.php?<?= e(http_build_query(['id' => $id] + $context)) ?>">Keep artwork</a>
</form>
<?php else: ?><a href="<?= e($collectionUrl) ?>">Return to artworks</a><?php endif; ?></section>
<?php page_end(); ?>
