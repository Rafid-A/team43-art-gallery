<?php
require_once __DIR__ . '/library.php';
require_login();
$artist = positive_id(get_text('artist'));
$q = get_text('q');
$queryLength = text_length($q);
if ($queryLength === false || $queryLength > 255) { http_response_code(422); page_start('Invalid search'); echo '<h1>Invalid search</h1><p>Use valid text and keep title searches within 255 characters.</p><a href="art-main.php">Return to artworks</a>'; page_end(); exit; }
$conditions = $values = [];
if ($artist) { $conditions[] = 'p.ArtistID=?'; $values[] = $artist; }
if ($q !== '') { $conditions[] = "p.Title LIKE ? ESCAPE '!'"; $values[] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'; }
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$total = (int)rows('SELECT COUNT(*) FROM Paintings')->fetchColumn();
$filtered = (int)rows('SELECT COUNT(*) FROM Paintings p' . $where, $values)->fetchColumn();
$pageCount = max(1, (int)ceil($filtered / 24));
$page = min($pageCount, max(1, positive_id(get_text('page'))));
$context = artwork_context($_GET);
$context['artist'] = $artist;
$context['q'] = $q;
$context['page'] = $page;
$sort = $context['sort'];
$dir = $context['dir'];
$direction = $dir === 'desc' ? 'DESC' : 'ASC';
$order = $sort === 'artist' ? "a.LastName $direction, a.FirstName $direction, p.PaintingID ASC" : "p.Title $direction, p.PaintingID ASC";
$offset = ($page - 1) * 24;
$paintings = rows('SELECT p.*, a.FirstName, a.LastName FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', array_merge($values, [24, $offset]))->fetchAll();
$artists = rows('SELECT a.ArtistID, a.FirstName, a.LastName, COUNT(p.PaintingID) AS WorkCount FROM Artists a LEFT JOIN Paintings p ON p.ArtistID=a.ArtistID GROUP BY a.ArtistID, a.FirstName, a.LastName ORDER BY a.LastName, a.ArtistID')->fetchAll();
page_start('Artworks');
?>
<section class="page-heading collection-heading"><div><p class="eyebrow">An open door to art</p><h1>A world of perspectives.</h1><p>Great works. Remarkable artists. Find something that stays with you.</p></div><?php if (is_admin()): ?><a class="button primary" href="art-add.php?<?= e(http_build_query($context)) ?>">Add artwork <span aria-hidden="true">+</span></a><?php endif; ?></section>
<?php notices(); ?>
<form class="filter" action="art-main.php" method="get" data-browse-context="<?= e(http_build_query($context)) ?>"><?php foreach (directory_return_context($context) as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><div class="filter-field search-field"><label for="q">Search artwork titles</label><div class="suggestion-wrap"><input id="q" type="search" data-suggestions="title" name="q" maxlength="255" value="<?= e($q) ?>" placeholder="A title that comes to mind…"><ul id="q-suggestions" class="suggestion-list" role="listbox" aria-label="Artwork title suggestions" hidden></ul><span class="suggestion-status" role="status" aria-live="polite"></span></div></div><div class="filter-field"><label for="artist">Artist</label><select id="artist" name="artist"><option value="0">All artists</option><?php foreach ($artists as $a): ?><option value="<?= (int)$a['ArtistID'] ?>" <?= $artist === (int)$a['ArtistID'] ? 'selected' : '' ?>><?= e(trim($a['FirstName'] . ' ' . $a['LastName'])) ?> (<?= (int)$a['WorkCount'] ?>)</option><?php endforeach; ?></select></div><div class="filter-field"><label for="sort">Sort by</label><select id="sort" name="sort"><option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title</option><option value="artist" <?= $sort === 'artist' ? 'selected' : '' ?>>Artist</option></select></div><div class="filter-field"><label for="dir">Direction</label><select id="dir" name="dir"><option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option><option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option></select></div><button>View artworks</button><?php if ($q !== '' || $artist): ?><a href="art-main.php">Clear filters</a><?php endif; ?></form>
<div class="artist-lookup" data-browse-context="<?= e(http_build_query($context)) ?>" hidden><label for="artist-lookup">Find an artist page by last name</label><div class="suggestion-wrap"><input id="artist-lookup" type="search" data-suggestions="artist" maxlength="255" placeholder="Start typing a last name"><ul id="artist-lookup-suggestions" class="suggestion-list" role="listbox" aria-label="Artist suggestions" hidden></ul><span class="suggestion-status" role="status" aria-live="polite"></span></div></div>
<div class="section-heading"><h2>The collection</h2><p><?= $filtered ? $offset + 1 : 0 ?>–<?= $offset + count($paintings) ?> of <?= $filtered ?> paintings<?= $filtered !== $total ? ' · ' . $total . ' in the collection' : '' ?></p></div>
<?php artwork_cards($paintings, $context); ?>
<?php if ($pageCount > 1): ?><nav class="pagination" aria-label="Collection pages"><?php if ($page > 1): ?><a class="button" rel="prev" href="?<?= e(http_build_query(array_replace($context, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pageCount ?></span><?php if ($page < $pageCount): ?><a class="button" rel="next" href="?<?= e(http_build_query(array_replace($context, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></nav><?php endif; page_end(); ?>
