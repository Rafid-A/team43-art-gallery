<?php
require_once __DIR__ . '/library.php';
require_login();
$context = artwork_context($_GET);
$collectionUrl = artwork_collection_url($context);
$directoryUrl = directory_return_url($context);
$id = positive_id(get_text('id'));
$assignmentOrigin = browsing_origin_query($context, $id);
$p = rows('SELECT p.*, a.FirstName, a.LastName, g.GalleryName FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID LEFT JOIN Galleries g ON g.GalleryID=p.GalleryID WHERE p.PaintingID=?', [$id])->fetch();
if (!$p) {
    http_response_code(404); page_start('Artwork not found', $collectionUrl);
    echo '<h1>Artwork not found</h1><p>It may have been removed.</p><a href="' . e($collectionUrl) . '">Return to artworks</a>';
    page_end(); exit;
}
page_start($p['Title'], $collectionUrl);
$imageFile = basename($p['ImageFileName'] ?? '');
$hasImage = is_file(__DIR__ . '/images/art/works/square-medium/' . $imageFile . '.jpg');
?>
<div class="breadcrumbs"><a href="<?= e($collectionUrl) ?>">Artworks</a><span>/</span><?php if ($directoryUrl): ?><a href="<?= e($directoryUrl) ?>"><?= e(library_objects()[$context['directory_type']]['label']) ?></a><span>/</span><?php endif; ?><?= e($p['Title']) ?></div>
<?php notices(); ?>
<article class="painting-detail">
    <figure class="painting-image">
        <?php if ($hasImage): ?>
        <a class="viewer-trigger" data-artwork-viewer href="images/art/works/square-medium/<?= e(rawurlencode($imageFile)) ?>.jpg" aria-label="View larger image of <?= e($p['Title']) ?>">
            <?php artwork_image($p, true); ?>
            <span class="viewer-hint" aria-hidden="true">View larger ↗</span>
        </a>
        <?php else: artwork_image($p, true); endif; ?>
        <figcaption><?= e($p['CopyrightText'] ?: 'See image credits for attribution') ?></figcaption>
    </figure>
    <div class="painting-copy">
        <p class="eyebrow">Inside the collection · #<?= $id ?></p><h1><?= e($p['Title']) ?></h1>
        <p class="artist-byline"><?php if ($p['ArtistID'] && $p['LastName']): ?><a href="objects.php?<?= e(http_build_query(['type' => 'artists', 'id' => (int)$p['ArtistID']] + $assignmentOrigin)) ?>"><?= e(trim($p['FirstName'] . ' ' . $p['LastName'])) ?></a><?php else: ?>Artist not recorded<?php endif; ?></p>
        <dl class="metadata">
            <div><dt>Year</dt><dd><?= e($p['YearOfWork'] ?: 'Not recorded') ?></dd></div>
            <div><dt>Medium</dt><dd><?= e($p['Medium'] ?: 'Not recorded') ?></dd></div>
            <div><dt>Gallery</dt><dd><?php if ($p['GalleryName']): ?><a href="objects.php?<?= e(http_build_query(['type' => 'galleries', 'id' => (int)$p['GalleryID']] + $assignmentOrigin)) ?>"><?= e($p['GalleryName']) ?></a><?php else: ?>Not recorded<?php endif; ?></dd></div>
            <?php if ($p['Width'] && $p['Height']): ?><div><dt>Dimensions</dt><dd><?= e($p['Width']) ?> × <?= e($p['Height']) ?> cm</dd></div><?php endif; ?>
        </dl>
        <div class="prose"><h2>About the artwork</h2><p><?= nl2br(e(strip_tags($p['Description'] ?: $p['Excerpt'] ?: 'A description has not been recorded for this artwork.'))) ?></p></div>
        <a class="button primary" href="painting-edit.php?<?= e(http_build_query(['id' => $id] + $context)) ?>">Edit artwork</a>
        <a class="button danger" href="painting-delete.php?<?= e(http_build_query(['id' => $id] + $context)) ?>">Delete artwork</a>
    </div>
</article>
<?php if ($hasImage): ?>
<dialog class="artwork-viewer" id="artwork-viewer" aria-labelledby="viewer-title" aria-describedby="viewer-caption">
    <div class="viewer-toolbar">
        <h2 id="viewer-title"><?= e($p['Title']) ?></h2>
        <button class="viewer-close" type="button" data-viewer-close autofocus>Close <span aria-hidden="true">×</span></button>
    </div>
    <div class="viewer-stage"><?php artwork_image($p, true); ?></div>
    <p class="viewer-caption" id="viewer-caption"><?= e(trim($p['FirstName'] . ' ' . $p['LastName']) ?: 'Artist not recorded') ?> · <?= e($p['YearOfWork'] ?: 'Year not recorded') ?>. Press Escape to close.</p>
</dialog>
<?php endif; ?>
<section class="classifications">
    <div>
        <div class="section-heading"><h2>Genres</h2><a href="object-add.php?<?= e(http_build_query(['type' => 'painting-genres'] + $assignmentOrigin)) ?>">Assign genre</a></div>
        <?php $genres = rows('SELECT j.*, g.GenreName FROM PaintingGenres j LEFT JOIN Genres g ON g.GenreID=j.GenreID WHERE j.PaintingID=? ORDER BY g.GenreName, j.PaintingGenreID', [$id])->fetchAll(); ?>
        <ul class="classification-list"><?php foreach ($genres as $g): ?><li>
            <a href="objects.php?<?= e(http_build_query(['type' => 'genres', 'id' => (int)$g['GenreID']] + $assignmentOrigin)) ?>"><?= e($g['GenreName'] ?? 'Missing genre #' . $g['GenreID']) ?></a>
            <a href="objects.php?<?= e(http_build_query(['type' => 'painting-genres', 'id' => (int)$g['PaintingGenreID']] + $assignmentOrigin)) ?>">Assignment #<?= (int)$g['PaintingGenreID'] ?></a>
            <a href="object-delete.php?<?= e(http_build_query(['type' => 'painting-genres', 'id' => (int)$g['PaintingGenreID']] + $assignmentOrigin)) ?>">Remove<span class="visually-hidden"> genre assignment #<?= (int)$g['PaintingGenreID'] ?></span></a>
        </li><?php endforeach; ?></ul>
        <?php if (!$genres): ?><p>No genres assigned yet.</p><?php endif; ?>
    </div>
    <div>
        <div class="section-heading"><h2>Subjects</h2><a href="object-add.php?<?= e(http_build_query(['type' => 'painting-subjects'] + $assignmentOrigin)) ?>">Assign subject</a></div>
        <?php $subjects = rows('SELECT j.*, s.SubjectName FROM PaintingSubjects j LEFT JOIN Subjects s ON s.SubjectID=j.SubjectID WHERE j.PaintingID=? ORDER BY s.SubjectName, j.PaintingSubjectID', [$id])->fetchAll(); ?>
        <ul class="classification-list"><?php foreach ($subjects as $s): ?><li>
            <?php if ($s['SubjectName'] !== null): ?><a href="objects.php?<?= e(http_build_query(['type' => 'subjects', 'id' => (int)$s['SubjectID']] + $assignmentOrigin)) ?>"><?= e($s['SubjectName']) ?> · #<?= (int)$s['SubjectID'] ?></a><?php else: ?><span>Missing subject #<?= e($s['SubjectID']) ?></span><?php endif; ?>
            <a href="objects.php?<?= e(http_build_query(['type' => 'painting-subjects', 'id' => (int)$s['PaintingSubjectID']] + $assignmentOrigin)) ?>">Assignment #<?= (int)$s['PaintingSubjectID'] ?></a>
            <a href="object-delete.php?<?= e(http_build_query(['type' => 'painting-subjects', 'id' => (int)$s['PaintingSubjectID']] + $assignmentOrigin)) ?>">Remove<span class="visually-hidden"> subject assignment #<?= (int)$s['PaintingSubjectID'] ?></span></a>
        </li><?php endforeach; ?></ul>
        <?php if (!$subjects): ?><p>No subjects assigned yet. <a href="objects.php?type=painting-subjects">All assignments</a></p><?php endif; ?>
    </div>
</section>
<?php page_end(); ?>
