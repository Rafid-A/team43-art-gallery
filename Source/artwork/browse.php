<?php
require_once __DIR__ . '/library.php';
require_login();
require_once __DIR__ . '/visitor-themes.php';
$themes = visitor_themes(db());
// Pick well-represented connections from real records. Fetch representatives
// together; neither the cards nor the artist directory query once per row.
$discoveries = [];
$queries = [
    'genres' => "SELECT g.GenreID AS DiscoveryID, g.GenreName AS DiscoveryName, COUNT(DISTINCT p.PaintingID) AS WorkCount, MIN(CASE WHEN COALESCE(p.ImageFileName, '') <> '' THEN p.PaintingID END) AS PaintingID FROM Genres g JOIN PaintingGenres j ON j.GenreID=g.GenreID JOIN Paintings p ON p.PaintingID=j.PaintingID GROUP BY g.GenreID, g.GenreName ORDER BY WorkCount DESC, g.GenreID LIMIT 2",
    'subjects' => "SELECT s.SubjectID AS DiscoveryID, s.SubjectName AS DiscoveryName, COUNT(DISTINCT p.PaintingID) AS WorkCount, MIN(CASE WHEN COALESCE(p.ImageFileName, '') <> '' THEN p.PaintingID END) AS PaintingID FROM Subjects s JOIN PaintingSubjects j ON j.SubjectID=s.SubjectID JOIN Paintings p ON p.PaintingID=j.PaintingID GROUP BY s.SubjectID, s.SubjectName ORDER BY WorkCount DESC, s.SubjectID LIMIT 2",
    'artists' => "SELECT a.ArtistID AS DiscoveryID, CONCAT_WS(' ', a.FirstName, a.LastName) AS DiscoveryName, COUNT(p.PaintingID) AS WorkCount, MIN(CASE WHEN COALESCE(p.ImageFileName, '') <> '' THEN p.PaintingID END) AS PaintingID FROM Artists a JOIN Paintings p ON p.ArtistID=a.ArtistID GROUP BY a.ArtistID, a.FirstName, a.LastName ORDER BY WorkCount DESC, a.ArtistID LIMIT 2",
];
foreach ($queries as $type => $sql) foreach (rows($sql)->fetchAll() as $discovery) {
    $discovery['type'] = $type;
    $discoveries[] = $discovery;
}
$images = [];
$ids = array_values(array_unique(array_filter(array_map(static fn($d) => (int)$d['PaintingID'], $discoveries))));
if ($ids) {
    foreach (rows('SELECT * FROM Paintings WHERE PaintingID IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll() as $painting) $images[(int)$painting['PaintingID']] = $painting;
}
page_start('Browse'); ?>
<section class="page-heading"><div><p class="eyebrow">Browse</p><h1>Browse the collection</h1><p>Find artworks by movement, era, genre, and subject.</p></div></section>
<?php visitor_theme_routes($themes); ?>
<?php if ($discoveries): ?>
<section class="discovery" aria-labelledby="discovery-heading"><div class="section-heading"><h2 id="discovery-heading">Artists, genres, and subjects</h2><p>A selection with several recorded artworks.</p></div><div class="discovery-grid">
<?php foreach ($discoveries as $discovery): $painting = $images[(int)$discovery['PaintingID']] ?? null; ?>
<a class="discovery-card" href="objects.php?<?= e(http_build_query(['type' => $discovery['type'], 'id' => (int)$discovery['DiscoveryID']])) ?>">
<div class="discovery-image" aria-hidden="true"><?php if ($painting && artwork_image_file($painting['ImageFileName'])): artwork_image($painting); else: ?><span>Explore the artworks</span><?php endif; ?></div><div class="discovery-copy"><p><?= e(['artists' => 'Artist', 'genres' => 'Genre', 'subjects' => 'Subject'][$discovery['type']]) ?></p><h3><?= e(trim($discovery['DiscoveryName'])) ?></h3><span><?= (int)$discovery['WorkCount'] ?> <?= (int)$discovery['WorkCount'] === 1 ? 'artwork' : 'artworks' ?></span></div></a>
<?php endforeach; ?></div></section>
<?php endif; ?>
<div class="section-heading"><h2>Browse the library</h2></div>
<div class="browse-grid">
<?php foreach (['genres' => ['Movements & genres', 'The ideas that bring artworks together.'], 'eras' => ['Through the eras', 'An introduction to art across time.'], 'subjects' => ['Art by subject', 'The world, as artists see it.']] as $type => [$title, $copy]): ?>
<a class="browse-tile" href="objects.php?type=<?= e($type) ?>"><h3><?= e($title) ?></h3><p><?= e($copy) ?></p><span>Explore <span aria-hidden="true">↗</span></span></a><?php endforeach; ?></div>
<?php page_end(); ?>
