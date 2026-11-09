<?php
require_once __DIR__ . '/library.php';
require_login();
$artist = positive_id(get_text('artist'));
$q = get_text('q');
if (text_length($q) > 255) { http_response_code(422); page_start('Search too long'); echo '<h1>Search too long</h1><p>Keep title searches within 255 characters.</p><a href="art-main.php">Return to artworks</a>'; page_end(); exit; }
$conditions = $values = [];
if ($artist) { $conditions[] = 'p.ArtistID=?'; $values[] = $artist; }
if ($q !== '') { $conditions[] = "p.Title LIKE ? ESCAPE '!'"; $values[] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'; }
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$total = (int)rows('SELECT COUNT(*) FROM Paintings')->fetchColumn();
$filtered = (int)rows('SELECT COUNT(*) FROM Paintings p' . $where, $values)->fetchColumn();
$pageCount = max(1, (int)ceil($filtered / 24));
$page = min($pageCount, max(1, positive_id(get_text('page'))));
$context = ['artist' => $artist, 'q' => $q, 'page' => $page];
$offset = ($page - 1) * 24;
$paintings = rows('SELECT p.*, a.FirstName, a.LastName FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID' . $where . ' ORDER BY p.Title, p.PaintingID LIMIT ? OFFSET ?', array_merge($values, [24, $offset]))->fetchAll();
$artists = rows('SELECT a.ArtistID, a.FirstName, a.LastName, COUNT(p.PaintingID) AS WorkCount FROM Artists a LEFT JOIN Paintings p ON p.ArtistID=a.ArtistID GROUP BY a.ArtistID, a.FirstName, a.LastName ORDER BY a.LastName, a.ArtistID')->fetchAll();
page_start('Artworks');
?>
<section class="page-heading collection-heading"><div><p class="eyebrow">An open door to art</p><h1>A world of perspectives.</h1><p>Great works. Remarkable artists. Find something that stays with you.</p></div><a class="button primary" href="art-add.php?<?= e(http_build_query(['artist' => $artist, 'q' => $q, 'page' => $page])) ?>">Add artwork <span aria-hidden="true">+</span></a></section>
<?php notices(); ?>
<form class="filter" action="art-main.php" method="get"><div class="filter-field search-field"><label for="q">Search artwork titles</label><input id="q" type="search" name="q" maxlength="255" value="<?= e($q) ?>" placeholder="A title that comes to mind…"></div><div class="filter-field"><label for="artist">Artist</label><select id="artist" name="artist"><option value="0">All artists</option><?php foreach ($artists as $a): ?><option value="<?= (int)$a['ArtistID'] ?>" <?= $artist === (int)$a['ArtistID'] ? 'selected' : '' ?>><?= e(trim($a['FirstName'] . ' ' . $a['LastName'])) ?> (<?= (int)$a['WorkCount'] ?>)</option><?php endforeach; ?></select></div><button>View artworks</button><?php if ($q !== '' || $artist): ?><a href="art-main.php">Clear filters</a><?php endif; ?></form>
<div class="section-heading"><h2>The collection</h2><p><?= $filtered ? $offset + 1 : 0 ?>–<?= $offset + count($paintings) ?> of <?= $filtered ?> paintings<?= $filtered !== $total ? ' · ' . $total . ' in the collection' : '' ?></p></div>
<?php artwork_cards($paintings, $context); ?>
<?php if ($pageCount > 1): ?><nav class="pagination" aria-label="Collection pages"><?php if ($page > 1): ?><a class="button" rel="prev" href="?<?= e(http_build_query(['artist' => $artist, 'q' => $q, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pageCount ?></span><?php if ($page < $pageCount): ?><a class="button" rel="next" href="?<?= e(http_build_query(['artist' => $artist, 'q' => $q, 'page' => $page + 1])) ?>">Next</a><?php endif; ?></nav><?php endif; page_end(); ?>
