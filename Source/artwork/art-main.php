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
$activeArtist = null;
foreach ($artists as $a) if ((int)$a['ArtistID'] === $artist) $activeArtist = $a;
$initialView = !$artist && $q === '' && $page === 1 && $sort === 'title' && $dir === 'asc';
$featured = null;
if ($initialView) foreach ($paintings as $painting) {
    if (artwork_image_file($painting['ImageFileName'] ?? '')) { $featured = $painting; break; }
}
$clearContext = array_replace($context, ['artist' => 0, 'q' => '', 'page' => 1]);
page_start('Artworks');
?>
<section class="page-heading collection-heading"><div><h1>Artworks</h1><p>Search artworks by title, or filter and sort by artist.</p></div><?php if (is_admin()): ?><a class="button" href="art-add.php?<?= e(http_build_query($context)) ?>">Add artwork <span aria-hidden="true">+</span></a><?php endif; ?></section>
<?php notices(); ?>
<div data-collection-view="<?= e(artwork_collection_url($context)) ?>" data-density="comfortable">
<?php if ($featured): $featuredUrl = 'painting.php?' . http_build_query(['id' => (int)$featured['PaintingID']] + $context); ?>
<section class="collection-feature" aria-labelledby="featured-title" data-featured-artwork>
    <a class="featured-image" href="<?= e($featuredUrl) ?>"><?php artwork_image($featured, true); ?></a>
    <div class="featured-copy"><p class="feature-label">Featured artwork</p><h2 id="featured-title"><a href="<?= e($featuredUrl) ?>"><?= e($featured['Title']) ?></a></h2><p><?= e(trim(($featured['FirstName'] ?? '') . ' ' . ($featured['LastName'] ?? '')) ?: 'Unknown artist') ?><?php if ($featured['YearOfWork']): ?> <span class="featured-year">(<?= e($featured['YearOfWork']) ?>)</span><?php endif; ?></p><a href="<?= e($featuredUrl) ?>">Explore this artwork</a></div>
</section>
<?php endif; ?>
<p class="collection-discovery-link"><a href="browse.php#thematic-discovery">Discover the collection by subject and movement</a></p>
<form class="filter collection-toolbar" action="art-main.php" method="get" aria-label="Find artworks" data-browse-context="<?= e(http_build_query($context)) ?>">
<?php foreach (directory_return_context($context) as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
<div class="collection-search"><div class="filter-field search-field"><label for="q">Search artwork titles</label><div class="suggestion-wrap"><input id="q" type="search" data-suggestions="title" name="q" maxlength="255" value="<?= e($q) ?>" placeholder="Search the collection"><ul id="q-suggestions" class="suggestion-list" role="listbox" aria-label="Artwork title suggestions" hidden></ul><span class="suggestion-status" role="status" aria-live="polite"></span></div></div><button class="primary" type="submit">View artworks</button></div>
<details class="collection-filters" data-mobile-disclosure open><summary aria-controls="collection-filters">Filters &amp; sort<?= $artist ? ' (artist selected)' : '' ?></summary>
<div id="collection-filters" class="collection-filter-fields">
<div class="filter-field"><label for="artist">Artist</label><select id="artist" name="artist"><option value="0">All artists</option><?php foreach ($artists as $a): ?><option value="<?= (int)$a['ArtistID'] ?>" <?= $artist === (int)$a['ArtistID'] ? 'selected' : '' ?>><?= e(trim($a['FirstName'] . ' ' . $a['LastName'])) ?> (<?= (int)$a['WorkCount'] ?>)</option><?php endforeach; ?></select></div>
<div class="filter-field" data-native-sort><label for="sort">Sort by</label><select id="sort" name="sort"><option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title</option><option value="artist" <?= $sort === 'artist' ? 'selected' : '' ?>>Artist</option></select></div>
<div class="filter-field" data-native-sort><label for="dir">Direction</label><select id="dir" name="dir"><option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option><option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option></select></div>
<div class="filter-field" hidden><label for="collection-sort">Order artworks</label><select id="collection-sort" data-combined-sort><option value="title:asc">Title: A to Z</option><option value="title:desc">Title: Z to A</option><option value="artist:asc">Artist: A to Z</option><option value="artist:desc">Artist: Z to A</option></select></div>
<div class="filter-field artist-lookup" hidden><label for="artist-lookup">Find an artist page</label><div class="suggestion-wrap"><input id="artist-lookup" type="search" data-suggestions="artist" maxlength="255" placeholder="Artist’s last name"><ul id="artist-lookup-suggestions" class="suggestion-list" role="listbox" aria-label="Artist suggestions" hidden></ul><span class="suggestion-status" role="status" aria-live="polite"></span></div></div>
<a class="directory-shortcut" href="objects.php?<?= e(http_build_query(['type' => 'artists'] + browsing_origin_query($context))) ?>">Browse all artists</a>
<button class="mobile-filter-apply" type="submit">Apply filters</button>
</div></details></form>
<?php if ($q !== '' || $artist): ?>
<nav class="active-filters" aria-label="Active filters">
<?php if ($q !== ''): ?><a class="filter-chip" data-remove-filter="q" href="<?= e(artwork_collection_url(array_replace($context, ['q' => '', 'page' => 1]))) ?>" aria-label="<?= e('Remove title filter: ' . $q) ?>">Title: <?= e($q) ?><span aria-hidden="true">×</span></a><?php endif; ?>
<?php if ($artist): $artistLabel = $activeArtist ? trim($activeArtist['FirstName'] . ' ' . $activeArtist['LastName']) : 'Artist #' . $artist; ?><a class="filter-chip" data-remove-filter="artist" href="<?= e(artwork_collection_url(array_replace($context, ['artist' => 0, 'page' => 1]))) ?>" aria-label="<?= e('Remove artist filter: ' . $artistLabel) ?>"><?= e($artistLabel) ?><span aria-hidden="true">×</span></a><?php endif; ?>
<a href="<?= e(artwork_collection_url($clearContext)) ?>">Clear filters</a></nav>
<?php endif; ?>
<section id="collection-results" aria-labelledby="collection-title" tabindex="-1">
<div class="section-heading collection-results-heading"><div><h2 id="collection-title"><?= $q !== '' || $artist ? 'Matching artworks' : 'Explore the collection' ?></h2><p class="result-count"><?= $filtered ? $offset + 1 : 0 ?>–<?= $offset + count($paintings) ?> of <?= e(count_label($filtered, 'painting', 'paintings')) ?><?= $filtered !== $total ? ' · ' . $total . ' in the collection' : '' ?></p></div><div class="density-controls" data-density-controls role="group" aria-label="Gallery density" hidden><button type="button" data-density="comfortable" aria-pressed="true">Comfortable</button><button type="button" data-density="compact" aria-pressed="false">Compact</button></div></div>
<?php if ($paintings): artwork_cards($paintings, $context); else: ?>
<div class="empty collection-empty"><h3>No matching artworks</h3><p>Try a shorter title or remove a filter to see more of the collection.</p><a class="button" href="<?= e(artwork_collection_url($clearContext)) ?>">View all artworks</a><a href="objects.php?<?= e(http_build_query(['type' => 'artists'] + browsing_origin_query($context))) ?>">Explore artists</a></div>
<?php endif; ?>
<?php if ($pageCount > 1): ?><nav class="pagination" aria-label="Collection pages"><?php if ($page > 1): ?><a class="button" rel="prev" aria-label="Previous page, page <?= $page - 1 ?> of <?= $pageCount ?>" href="?<?= e(http_build_query(array_replace($context, ['page' => $page - 1]))) ?>#collection-results">Previous <span aria-hidden="true">(<?= $page - 1 ?>)</span></a><?php endif; ?><span>Page <?= $page ?> of <?= $pageCount ?></span><?php if ($page < $pageCount): ?><a class="button" rel="next" aria-label="Next page, page <?= $page + 1 ?> of <?= $pageCount ?>" href="?<?= e(http_build_query(array_replace($context, ['page' => $page + 1]))) ?>#collection-results">Next <span aria-hidden="true">(<?= $page + 1 ?>)</span></a><?php endif; ?></nav><?php endif; ?>
</section></div>
<?php page_end(); ?>
