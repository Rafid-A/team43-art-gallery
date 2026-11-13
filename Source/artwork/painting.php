<?php
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/saved-works.php';
require_login();

// Lexicographic SQL neighbors match art-main.php, including NULL ordering and
// its ascending PaintingID tie even when the primary sort is descending.
function painting_relative_predicate(array $painting, array $context, $before)
{
    $keys = $context['sort'] === 'artist' ? ['a.LastName' => 'LastName', 'a.FirstName' => 'FirstName'] : ['p.Title' => 'Title'];
    $keys['p.PaintingID'] = 'PaintingID';
    $equal = $equalValues = $branches = $values = [];
    foreach ($keys as $column => $key) {
        $value = $painting[$key];
        $ascending = $key === 'PaintingID' || $context['dir'] !== 'desc';
        $less = $before === $ascending;
        $partValues = [];
        if ($value === null) $comparison = $less ? '0=1' : "$column IS NOT NULL";
        else {
            $comparison = $less ? "($column IS NULL OR $column < ?)" : "$column > ?";
            $partValues[] = $key === 'PaintingID' ? (int)$value : $value;
        }
        $branches[] = '(' . implode(' AND ', array_merge($equal, [$comparison])) . ')';
        $values = array_merge($values, $equalValues, $partValues);
        $equal[] = "$column <=> ?";
        $equalValues[] = $key === 'PaintingID' ? (int)$value : $value;
    }
    return ['(' . implode(' OR ', $branches) . ')', $values];
}

function painting_neighbors(array $painting, array $context)
{
    $conditions = ['1=1']; $values = [];
    if ($context['artist']) { $conditions[] = 'p.ArtistID=?'; $values[] = $context['artist']; }
    if ($context['q'] !== '') {
        $conditions[] = "p.Title LIKE ? ESCAPE '!'";
        $values[] = '%' . strtr($context['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    $from = ' FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID WHERE ' . implode(' AND ', $conditions);
    if (!rows('SELECT 1' . $from . ' AND p.PaintingID=?', array_merge($values, [(int)$painting['PaintingID']]))->fetchColumn()) return [];
    [$before, $beforeValues] = painting_relative_predicate($painting, $context, true);
    $rank = 1 + (int)rows('SELECT COUNT(*)' . $from . ' AND ' . $before, array_merge($values, $beforeValues))->fetchColumn();
    $neighbors = [];
    foreach (['prev' => true, 'next' => false] as $rel => $previous) {
        [$predicate, $bound] = painting_relative_predicate($painting, $context, $previous);
        $ascending = $context['dir'] === 'asc';
        $direction = $ascending !== $previous ? 'ASC' : 'DESC';
        $order = $context['sort'] === 'artist' ? "a.LastName $direction, a.FirstName $direction" : "p.Title $direction";
        $order .= ', p.PaintingID ' . ($previous ? 'DESC' : 'ASC');
        $neighbor = rows('SELECT p.PaintingID, p.Title' . $from . ' AND ' . $predicate . ' ORDER BY ' . $order . ' LIMIT 1', array_merge($values, $bound))->fetch();
        if ($neighbor) {
            $neighborContext = array_replace($context, ['page' => intdiv($rank + ($previous ? -2 : 0), 24) + 1]);
            $neighbors[$rel] = ['title' => $neighbor['Title'], 'url' => 'painting.php?' . http_build_query(['id' => (int)$neighbor['PaintingID']] + $neighborContext)];
        }
    }
    return $neighbors;
}

function painting_neighbor_links(array $neighbors, $viewer = false)
{
    echo '<nav class="painting-neighbors' . ($viewer ? ' viewer-neighbors' : '') . '" aria-label="' . ($viewer ? 'Viewer collection navigation' : 'Collection navigation') . '">';
    foreach (['prev' => 'Previous artwork', 'next' => 'Next artwork'] as $rel => $label) {
        if (isset($neighbors[$rel])) echo '<a rel="' . $rel . '" href="' . e($neighbors[$rel]['url']) . '" aria-label="' . e($label . ': ' . $neighbors[$rel]['title']) . '">' . $label . '<span aria-hidden="true"> ' . ($rel === 'prev' ? '←' : '→') . '</span></a>';
        else echo '<span class="neighbor-unavailable">' . $label . ' unavailable</span>';
    }
    echo '</nav>';
}

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
$admin = is_admin();
page_start($p['Title'], $collectionUrl);
$imageFile = artwork_image_file($p['ImageFileName'] ?? '');
$hasImage = (bool)$imageFile;
$neighbors = painting_neighbors($p, $context);
?>
<div class="breadcrumbs"><a href="<?= e($collectionUrl) ?>">Artworks</a><span>/</span><?php if ($directoryUrl): ?><a href="<?= e($directoryUrl) ?>"><?= e(library_objects()[$context['directory_type']]['label']) ?></a><span>/</span><?php endif; ?><?= e($p['Title']) ?></div>
<?php notices(); ?>
<?php painting_neighbor_links($neighbors); ?>
<article class="painting-detail">
    <header class="painting-heading">
        <p class="eyebrow">Artwork #<?= $id ?></p><h1><?= e($p['Title']) ?></h1>
        <p class="artist-byline"><?php if ($p['ArtistID'] && $p['LastName']): ?><a href="objects.php?<?= e(http_build_query(['type' => 'artists', 'id' => (int)$p['ArtistID']] + $assignmentOrigin)) ?>"><?= e(trim($p['FirstName'] . ' ' . $p['LastName'])) ?></a><?php else: ?>Artist not recorded<?php endif; ?></p>
    </header>
    <figure class="painting-image">
        <?php if ($hasImage): ?>
        <a class="viewer-trigger" data-artwork-viewer href="<?= e($imageFile['url']) ?>" aria-label="View larger image of <?= e($p['Title']) ?>">
            <?php artwork_image($p, true, 'detail'); ?>
            <span class="viewer-hint" aria-hidden="true">View larger ↗</span>
        </a>
        <?php else: artwork_image($p, true); endif; ?>
        <figcaption><?= e($p['CopyrightText'] ?: 'See image credits for attribution') ?></figcaption>
    </figure>
    <div class="painting-copy">
        <?php if (saved_works_ready(db())): saved_work_form($id, saved_work_exists(current_user()['username'], $id), $p['Title'], 'painting.php?' . http_build_query(['id' => $id] + $context)); endif; ?>
        <dl class="metadata">
            <div><dt>Year</dt><dd><?= e($p['YearOfWork'] ?: 'Not recorded') ?></dd></div>
            <div><dt>Medium</dt><dd><?= e($p['Medium'] ?: 'Not recorded') ?></dd></div>
            <div><dt>Gallery</dt><dd><?php if ($p['GalleryName']): ?><a href="objects.php?<?= e(http_build_query(['type' => 'galleries', 'id' => (int)$p['GalleryID']] + $assignmentOrigin)) ?>"><?= e($p['GalleryName']) ?></a><?php else: ?>Not recorded<?php endif; ?></dd></div>
            <?php if ($p['Width'] && $p['Height']): ?><div><dt>Dimensions</dt><dd><?= e($p['Width']) ?> × <?= e($p['Height']) ?> cm</dd></div><?php endif; ?>
        </dl>
        <div class="prose"><h2>About the artwork</h2><p><?= nl2br(e(strip_tags($p['Description'] ?: $p['Excerpt'] ?: 'A description has not been recorded for this artwork.'))) ?></p></div>
        <?php if ($admin): ?>
        <a class="button primary" href="painting-edit.php?<?= e(http_build_query(['id' => $id] + $context)) ?>">Edit artwork</a>
        <a class="button danger" href="painting-delete.php?<?= e(http_build_query(['id' => $id] + $context)) ?>">Delete artwork</a>
        <?php endif; ?>
    </div>
</article>
<?php if ($hasImage): ?>
<dialog class="artwork-viewer" id="artwork-viewer" aria-labelledby="viewer-title" aria-describedby="viewer-caption">
    <div class="viewer-toolbar">
        <h2 id="viewer-title"><?= e($p['Title']) ?></h2>
        <button class="viewer-close" type="button" data-viewer-close autofocus>Close <span aria-hidden="true">×</span></button>
    </div>
    <div class="viewer-controls" role="group" aria-label="Image zoom">
        <button type="button" data-viewer-zoom="out" aria-label="Zoom out">−</button>
        <button type="button" data-viewer-zoom="in" aria-label="Zoom in">+</button>
        <button type="button" data-viewer-fit>Reset / fit</button>
        <button type="button" data-viewer-actual>Actual pixels</button>
        <output data-viewer-status role="status" aria-live="polite" aria-atomic="true">Loading image</output>
    </div>
    <div class="viewer-stage" tabindex="0" role="region" aria-label="Artwork image" aria-describedby="viewer-help"><?php artwork_image($p, true, 'viewer'); ?></div>
    <?php painting_neighbor_links($neighbors, true); ?>
    <p class="viewer-caption" id="viewer-caption"><?= e(trim($p['FirstName'] . ' ' . $p['LastName']) ?: 'Artist not recorded') ?> · <?= e($p['YearOfWork'] ?: 'Year not recorded') ?>.</p>
    <p class="viewer-caption" id="viewer-help">Use + / − to zoom, 0 to reset, and arrow keys to pan the focused image. Drag or pinch to explore; double-click to toggle fit and actual pixels. Zoom stops at the source's actual pixels. Press Escape to close.</p>
</dialog>
<?php endif; ?>
<section class="classifications">
    <div>
        <div class="section-heading"><h2>Genres</h2><?php if ($admin): ?><a href="object-add.php?<?= e(http_build_query(['type' => 'painting-genres'] + $assignmentOrigin)) ?>">Assign genre</a><?php endif; ?></div>
        <?php $genres = rows('SELECT j.*, g.GenreName FROM PaintingGenres j LEFT JOIN Genres g ON g.GenreID=j.GenreID WHERE j.PaintingID=? ORDER BY g.GenreName, j.PaintingGenreID', [$id])->fetchAll(); ?>
        <ul class="classification-list"><?php foreach ($genres as $g): ?><li>
            <a href="objects.php?<?= e(http_build_query(['type' => 'genres', 'id' => (int)$g['GenreID']] + $assignmentOrigin)) ?>"><?= e($g['GenreName'] ?? 'Missing genre #' . $g['GenreID']) ?></a>
            <a href="objects.php?<?= e(http_build_query(['type' => 'painting-genres', 'id' => (int)$g['PaintingGenreID']] + $assignmentOrigin)) ?>">Assignment #<?= (int)$g['PaintingGenreID'] ?></a>
            <?php if ($admin): ?><a href="object-delete.php?<?= e(http_build_query(['type' => 'painting-genres', 'id' => (int)$g['PaintingGenreID']] + $assignmentOrigin)) ?>">Remove<span class="visually-hidden"> genre assignment #<?= (int)$g['PaintingGenreID'] ?></span></a><?php endif; ?>
        </li><?php endforeach; ?></ul>
        <?php if (!$genres): ?><p>No genres assigned yet.</p><?php endif; ?>
    </div>
    <div>
        <div class="section-heading"><h2>Subjects</h2><?php if ($admin): ?><a href="object-add.php?<?= e(http_build_query(['type' => 'painting-subjects'] + $assignmentOrigin)) ?>">Assign subject</a><?php endif; ?></div>
        <?php $subjects = rows('SELECT j.*, s.SubjectName FROM PaintingSubjects j LEFT JOIN Subjects s ON s.SubjectID=j.SubjectID WHERE j.PaintingID=? ORDER BY s.SubjectName, j.PaintingSubjectID', [$id])->fetchAll(); ?>
        <ul class="classification-list"><?php foreach ($subjects as $s): ?><li>
            <?php if ($s['SubjectName'] !== null): ?><a href="objects.php?<?= e(http_build_query(['type' => 'subjects', 'id' => (int)$s['SubjectID']] + $assignmentOrigin)) ?>"><?= e($s['SubjectName']) ?> · #<?= (int)$s['SubjectID'] ?></a><?php else: ?><span>Missing subject #<?= e($s['SubjectID']) ?></span><?php endif; ?>
            <a href="objects.php?<?= e(http_build_query(['type' => 'painting-subjects', 'id' => (int)$s['PaintingSubjectID']] + $assignmentOrigin)) ?>">Assignment #<?= (int)$s['PaintingSubjectID'] ?></a>
            <?php if ($admin): ?><a href="object-delete.php?<?= e(http_build_query(['type' => 'painting-subjects', 'id' => (int)$s['PaintingSubjectID']] + $assignmentOrigin)) ?>">Remove<span class="visually-hidden"> subject assignment #<?= (int)$s['PaintingSubjectID'] ?></span></a><?php endif; ?>
        </li><?php endforeach; ?></ul>
        <?php if (!$subjects): ?><p>No subjects assigned yet. <a href="objects.php?type=painting-subjects">All assignments</a></p><?php endif; ?>
    </div>
</section>
<?php page_end(); ?>
