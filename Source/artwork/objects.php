<?php
require_once __DIR__ . '/library.php';
require_login();
$type = get_text('type') ?: 'artists';
$object = library_objects()[$type] ?? null;
if (!$object) { http_response_code(404); page_start('Not found'); echo '<h1>Collection not found</h1><a href="browse.php">Browse the library</a>'; page_end(); exit; }
$context = directory_context($type, $_GET);
$admin = is_admin();
if (!$admin) $context['manage'] = 0;
$manage = (bool)$context['manage'];
$selected = isset($_GET['id']) || isset($_GET['name']);
$record = null;
if ($selected) {
    if ($object['key']) $record = rows(object_select($type) . ' WHERE o.' . $object['key'] . '=?', [positive_id(get_text('id'))])->fetch();
    else {
        $conditions = ' WHERE COALESCE(o.' . $object['name'] . ", '')=?";
        $values = [get_text('name')];
        if ($type === 'eras') { $conditions .= " AND COALESCE(o.EraYears, '')=?"; $values[] = get_text('years'); }
        $matches = rows(object_select($type) . $conditions, $values)->fetchAll();
        $record = count($matches) === 1 ? $matches[0] : null;
    }
    if (!$record) http_response_code(404);
}
$title = $selected ? ($record ? object_title($type, $record) : 'Record not found') : $object['label'];
$originUrl = browsing_origin_url($context);
$directoryUrl = directory_return_url($context);
// Keep an existing source directory when traversing related records repeatedly.
$relatedContext = $originUrl ? origin_artwork_context(browsing_origin($context)) : artwork_context([]);
$relatedContext += directory_return_context($context) + directory_return_query($context);
$relatedOrigin = browsing_origin($context) + directory_return_context($relatedContext);
$works = $record && in_array($type, ['artists', 'galleries', 'genres', 'subjects'], true)
    ? related_paintings($type, (int)$record[$object['key']]) : [];
$artistFeature = null;
if ($type === 'artists') foreach ($works as $work) {
    if (artwork_image_file($work['ImageFileName'] ?? '')) { $artistFeature = $work; break; }
}
$artistYears = static function (array $artist) {
    $birth = (int)($artist['YearOfBirth'] ?? 0);
    $death = (int)($artist['YearOfDeath'] ?? 0);
    if ($birth && $death) return $birth . '–' . $death;
    if ($birth) return 'Born ' . $birth;
    return $death ? 'Died ' . $death : 'Life years not recorded';
};
page_start($title, $originUrl ?: 'art-main.php');
?>
<div class="breadcrumbs"><?php if ($originUrl): ?><a href="<?= e($originUrl) ?>"><?= $context['origin'] === 'painting' ? 'Artwork' : 'Artworks' ?></a><span>/</span><?php endif; ?><?php if ($directoryUrl): ?><a href="<?= e($directoryUrl) ?>"><?= e(library_objects()[$context['directory_type']]['label']) ?></a><span>/</span><?php endif; ?><a href="<?= $manage ? 'manage.php' : 'browse.php' ?>"><?= $manage ? 'Manage library' : 'Browse' ?></a><span>/</span><a href="objects.php?<?= e(http_build_query($context)) ?>"><?= e($object['label']) ?></a></div>
<section class="page-heading"><div><p class="eyebrow"><?= $manage ? 'Library management' : 'Explore the collection' ?></p><h1><?= e($title) ?></h1>
<?php if (!$selected): ?><p><?= !empty($object['relationship']) ? 'Explore the connections between artworks and their classifications.' : 'Discover the people, places, and ideas behind the art.' ?></p><?php endif; ?></div>
<?php if ($admin && !isset($object['gate'])): ?><a class="button primary" href="object-add.php?<?= e(http_build_query($context)) ?>"><?= !empty($object['relationship']) ? 'Assign ' . ($type === 'painting-subjects' ? 'subject' : 'genre') : 'Add ' . e($object['one']) ?></a><?php endif; ?></section>
<?php notices(); if (isset($object['gate'])): ?><p class="notice" role="status"><?= e($object['gate']) ?></p><?php endif; ?>
<?php if ($selected): if (!$record): ?>
<div class="empty"><h2>Record not found</h2><p>The link is stale, the selection is ambiguous, or the record has been removed.</p><a href="<?= e(directory_url($context)) ?>">Return to <?= e(strtolower($object['label'])) ?></a></div>
<?php else: ?>
<section class="record-detail <?= $type === 'artists' ? 'artist-profile' : '' ?>">
<?php if ($type === 'artists'): ?>
<div class="artist-profile-intro">
<?php if ($artistFeature): ?><figure class="artist-profile-work"><a href="painting.php?<?= e(http_build_query(['id' => (int)$artistFeature['PaintingID']] + $relatedContext)) ?>"><?php artwork_image($artistFeature, true); ?></a><figcaption><?= e($artistFeature['Title']) ?><?php if ($artistFeature['YearOfWork']): ?>, <?= e($artistFeature['YearOfWork']) ?><?php endif; ?></figcaption></figure><?php endif; ?>
<div class="artist-story"><p class="artist-life"><?= e($artistYears($record)) ?><?php if ($record['Nationality']): ?> · <?= e($record['Nationality']) ?><?php endif; ?></p><h2>About the artist</h2>
<?php if (!empty($record['Details'])): ?><div class="prose"><p><?= nl2br(e(strip_tags($record['Details']))) ?></p></div><?php else: ?><p class="hint">A biography has not been recorded for this artist.</p><?php endif; ?>
<a href="#artist-artworks"><?= count($works) ?> <?= count($works) === 1 ? 'artwork' : 'artworks' ?> in the collection</a></div></div>
<details class="artist-facts"><summary>Artist details &amp; references</summary>
<?php endif; ?>
<dl class="metadata">
<?php if ($object['key']): ?><div><dt>Record ID</dt><dd><?= e($record[$object['key']]) ?></dd></div><?php endif; ?>
<?php foreach ($object['fields'] as $field => $definition): if (in_array($field, ['Details', 'Description'], true)) continue; ?>
<div><dt><?= e($definition[0]) ?></dt><dd>
<?php if ($field === 'PaintingID'): if ($record['Title'] !== null): ?><a href="painting.php?<?= e(http_build_query(['id' => (int)$record[$field]] + $relatedContext)) ?>"><?= e($record['Title']) ?> · #<?= (int)$record[$field] ?></a><?php else: ?><span>Missing artwork #<?= e($record[$field]) ?></span><?php endif; ?>
<?php elseif ($field === 'GenreID'): ?><a href="objects.php?<?= e(http_build_query(['type' => 'genres', 'id' => (int)$record[$field]] + $relatedOrigin)) ?>"><?= e($record['GenreName'] ?? 'Missing genre') ?> · #<?= (int)$record[$field] ?></a>
<?php elseif ($field === 'SubjectID'): if ($record['SubjectName'] !== null): ?><a href="objects.php?<?= e(http_build_query(['type' => 'subjects', 'id' => (int)$record[$field]] + $relatedOrigin)) ?>"><?= e($record['SubjectName']) ?> · #<?= (int)$record[$field] ?></a><?php else: ?><span>Missing subject #<?= e($record[$field]) ?></span><?php endif; ?>
<?php elseif (($definition[2] ?? '') === 'url' && safe_link($record[$field] ?? '')): ?><a href="<?= e($record[$field]) ?>" rel="noreferrer">Visit reference ↗</a>
<?php else: ?><?= e($record[$field] ?? 'Not recorded') ?><?php endif; ?></dd></div>
<?php endforeach; ?></dl>
<?php if ($type === 'artists'): ?></details><?php endif; ?>
<?php foreach (['Details', 'Description'] as $field): if (!empty($record[$field]) && !($type === 'artists' && $field === 'Details')): ?><div class="prose"><h2><?= $field === 'Details' ? 'About the artist' : 'About this genre' ?></h2><p><?= nl2br(e(strip_tags($record[$field]))) ?></p></div><?php endif; endforeach; ?>
<?php if ($admin && $type === 'artists'): ?><a class="button primary" href="artist-edit.php?<?= e(http_build_query(['id' => (int)$record['ArtistID']] + $context)) ?>">Edit artist</a> <?php endif; ?><?php if ($admin && $object['key'] && !isset($object['gate'])): ?><a class="button danger" href="object-delete.php?<?= e(http_build_query(['id' => (int)$record[$object['key']]] + $context)) ?>"><?= !empty($object['relationship']) ? 'Remove assignment' : 'Delete ' . e($object['one']) ?></a><?php endif; ?></section>
<?php if (in_array($type, ['artists', 'galleries', 'genres', 'subjects'], true)): ?>
<section class="related" <?= $type === 'artists' ? 'id="artist-artworks"' : '' ?>><div class="section-heading"><h2>Artworks</h2><span><?= count($works) ?> in the collection</span></div><?php artwork_cards($works, $relatedContext); ?></section>
<?php endif; endif; else:
$count = (int)rows('SELECT COUNT(*) FROM ' . $object['table'])->fetchColumn();
$pages = max(1, (int)ceil($count / 30));
$page = min($pages, $context['page']);
$context['page'] = $page;
$order = directory_order($type, $context);
$records = rows(object_select($type) . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [30, ($page - 1) * 30])->fetchAll();
$representatives = [];
if ($type === 'artists' && $records) {
    // One lookup for this directory page, rather than one query per artist.
    $ids = array_map(static fn($r) => (int)$r['ArtistID'], $records);
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $representativeWorks = rows('SELECT p.* FROM Paintings p INNER JOIN (SELECT ArtistID, MIN(PaintingID) AS PaintingID FROM Paintings WHERE ArtistID IN (' . $placeholders . ") AND COALESCE(ImageFileName, '') <> '' GROUP BY ArtistID) chosen ON chosen.PaintingID=p.PaintingID", $ids)->fetchAll();
    foreach ($representativeWorks as $work) $representatives[(int)$work['ArtistID']] = $work;
}
?>
<form method="get" class="filter" aria-label="Sort directory">
<input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="manage" value="<?= (int)$manage ?>">
<?php foreach (browsing_origin($context) + directory_return_context($context) as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
<div class="filter-field"><label for="directory-sort">Sort by</label><select id="directory-sort" name="sort"><?php foreach (directory_sorts($type) as $key => $option): ?><option value="<?= e($key) ?>" <?= $context['sort'] === $key ? 'selected' : '' ?>><?= e($option[0]) ?></option><?php endforeach; ?></select></div>
<div class="filter-field"><label for="directory-dir">Direction</label><select id="directory-dir" name="dir"><option value="asc" <?= $context['dir'] === 'asc' ? 'selected' : '' ?>>Ascending</option><option value="desc" <?= $context['dir'] === 'desc' ? 'selected' : '' ?>>Descending</option></select></div><button type="submit">Apply</button></form>
<p class="result-count"><?= $count ?> <?= e(strtolower($object['label'])) ?> · Page <?= $page ?> of <?= $pages ?></p>
<div class="directory <?= $type === 'artists' ? 'artist-directory' : '' ?>">
<?php foreach ($records as $r): ?><a class="directory-row" href="<?= e(object_url($type, $r, $manage, $context)) ?>">
<?php if ($type === 'artists'): ?><div class="artist-thumbnail" aria-hidden="true"><?php $representative = $representatives[(int)$r['ArtistID']] ?? null; if ($representative && artwork_image_file($representative['ImageFileName'])): artwork_image($representative); else: ?><span>No image</span><?php endif; ?></div><?php endif; ?>
<div class="directory-copy"><h2><?= e(object_title($type, $r)) ?></h2><p><?= e($r['Nationality'] ?? trim(($r['GalleryCity'] ?? '') . ' ' . ($r['GalleryCountry'] ?? '')) ?: ($r['EraYears'] ?? (!empty($object['relationship']) ? 'Assignment #' . $r[$object['key']] : 'View details'))) ?></p><?php if ($type === 'artists'): ?><p class="artist-years"><?= e($artistYears($r)) ?></p><?php endif; ?></div><span><?= isset($r['WorkCount']) ? (int)$r['WorkCount'] . ' artworks' : 'View' ?> <span aria-hidden="true">↗</span></span></a><?php endforeach; ?>
<?php if (!$records): ?><p class="empty">No records yet. Add a record to get started.</p><?php endif; ?></div>
<?php if ($pages > 1): ?><nav class="pagination" aria-label="Directory pages"><?php if ($page > 1): ?><a class="button" rel="prev" href="?<?= e(http_build_query(array_replace($context, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a class="button" rel="next" href="?<?= e(http_build_query(array_replace($context, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></nav><?php endif; endif; page_end(); ?>
