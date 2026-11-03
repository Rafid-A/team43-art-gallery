<?php
require_once __DIR__ . '/library.php'; require_login();
$checks = [
    'Paintings with missing artists' => 'SELECT COUNT(*) FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID WHERE p.ArtistID IS NOT NULL AND a.ArtistID IS NULL',
    'Paintings with missing galleries' => 'SELECT COUNT(*) FROM Paintings p LEFT JOIN Galleries g ON g.GalleryID=p.GalleryID WHERE p.GalleryID IS NOT NULL AND g.GalleryID IS NULL',
    'Genre assignments with missing artworks' => 'SELECT COUNT(*) FROM PaintingGenres j LEFT JOIN Paintings p ON p.PaintingID=j.PaintingID WHERE p.PaintingID IS NULL',
    'Genre assignments with missing genres' => 'SELECT COUNT(*) FROM PaintingGenres j LEFT JOIN Genres g ON g.GenreID=j.GenreID WHERE g.GenreID IS NULL',
    'Subject assignments with missing artworks' => 'SELECT COUNT(*) FROM PaintingSubjects j LEFT JOIN Paintings p ON p.PaintingID=j.PaintingID WHERE p.PaintingID IS NULL',
    'Subject assignments with missing subjects' => 'SELECT COUNT(*) FROM PaintingSubjects j LEFT JOIN Subjects s ON s.SubjectID=j.SubjectID WHERE s.SubjectID IS NULL',
    'Duplicate genre pairs' => 'SELECT COUNT(*) FROM (SELECT PaintingID, GenreID FROM PaintingGenres GROUP BY PaintingID, GenreID HAVING COUNT(*)>1) duplicates',
    'Duplicate subject pairs' => 'SELECT COUNT(*) FROM (SELECT PaintingID, SubjectID FROM PaintingSubjects GROUP BY PaintingID, SubjectID HAVING COUNT(*)>1) duplicates',
    'Duplicate eras (same name and years)' => 'SELECT COUNT(*) FROM (SELECT EraName, EraYears FROM Eras GROUP BY EraName, EraYears HAVING COUNT(*)>1) duplicates',
];
page_start('Integrity checks'); ?>
<div class="breadcrumbs"><a href="manage.php">Manage library</a><span>/</span>Integrity</div><section class="page-heading"><div><p class="eyebrow">Collection health</p><h1>Integrity checks</h1><p>A read-only snapshot of the current database. No repairs or identity changes are applied.</p></div></section>
<dl class="integrity-list"><?php foreach ($checks as $label => $sql): $count = (int)rows($sql)->fetchColumn(); ?><div><dt><?= e($label) ?></dt><dd class="<?= $count ? 'field-error' : '' ?>"><?= $count ? $count . ' — needs review' : '0 — clear' ?></dd></div><?php endforeach; ?></dl>
<section class="notice"><h2>Schema contracts</h2><p><?= era_identity_ready() ? 'Eras use a stable EraID: the six supplied eras keep IDs 1 to 6 in date order and new eras take the next ID.' : 'Eras have no primary key yet; run artwork/sql/06-era-identities.sql to enable adding and deleting eras.' ?> Subject identities are explicit and existing numeric assignments retain their original IDs.</p><p><?= (int)rows('SELECT COUNT(*) FROM PaintingSubjects')->fetchColumn() ?> subject assignments are checked against the authoritative mapping.</p></section>
<?php page_end(); ?>
