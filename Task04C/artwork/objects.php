<?php
require_once __DIR__ . '/library.php';
require_login();
$type = get_text('type') ?: 'artists';
$object = library_objects()[$type] ?? null;
if (!$object) { http_response_code(404); page_start('Not found'); echo '<h1>Collection not found</h1><a href="browse.php">Browse the library</a>'; page_end(); exit; }
$manage = get_text('manage') === '1';
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
page_start($title);
?>
<div class="breadcrumbs"><a href="<?= $manage ? 'manage.php' : 'browse.php' ?>"><?= $manage ? 'Manage library' : 'Browse' ?></a><span>/</span><a href="objects.php?<?= e(http_build_query(['type' => $type, 'manage' => (int)$manage])) ?>"><?= e($object['label']) ?></a></div>
<section class="page-heading"><div><p class="eyebrow"><?= $manage ? 'Library management' : 'Explore the collection' ?></p><h1><?= e($title) ?></h1>
<?php if (!$selected): ?><p><?= !empty($object['relationship']) ? 'Explore the connections between artworks and their classifications.' : 'Discover the people, places, and ideas behind the art.' ?></p><?php endif; ?></div>
<?php if (!isset($object['gate'])): ?><a class="button primary" href="object-add.php?type=<?= e($type) ?>"><?= !empty($object['relationship']) ? 'Assign ' . ($type === 'painting-subjects' ? 'subject' : 'genre') : 'Add ' . e($object['one']) ?></a><?php endif; ?></section>
<?php notices(); if (isset($object['gate'])): ?><p class="notice" role="status"><?= e($object['gate']) ?></p><?php endif; ?>
<?php if ($selected): if (!$record): ?>
<div class="empty"><h2>Record not found</h2><p>The link is stale, the selection is ambiguous, or the record has been removed.</p><a href="objects.php?type=<?= e($type) ?>">Return to <?= e(strtolower($object['label'])) ?></a></div>
<?php else: ?>
<section class="record-detail"><dl class="metadata">
<?php if ($object['key']): ?><div><dt>Record ID</dt><dd><?= e($record[$object['key']]) ?></dd></div><?php endif; ?>
<?php foreach ($object['fields'] as $field => $definition): if (in_array($field, ['Details', 'Description'], true)) continue; ?>
<div><dt><?= e($definition[0]) ?></dt><dd>
<?php if ($field === 'PaintingID'): ?><a href="painting.php?id=<?= (int)$record[$field] ?>"><?= e($record['Title'] ?? 'Missing artwork') ?> · #<?= (int)$record[$field] ?></a>
<?php elseif ($field === 'GenreID'): ?><a href="objects.php?type=genres&amp;id=<?= (int)$record[$field] ?>"><?= e($record['GenreName'] ?? 'Missing genre') ?> · #<?= (int)$record[$field] ?></a>
<?php elseif ($field === 'SubjectID'): ?><a href="objects.php?type=subjects&amp;id=<?= (int)$record[$field] ?>"><?= e($record['SubjectName'] ?? 'Missing subject') ?> · #<?= (int)$record[$field] ?></a>
<?php elseif (($definition[2] ?? '') === 'url' && safe_link($record[$field] ?? '')): ?><a href="<?= e($record[$field]) ?>" rel="noreferrer">Visit reference ↗</a>
<?php else: ?><?= e($record[$field] ?? 'Not recorded') ?><?php endif; ?></dd></div>
<?php endforeach; ?></dl>
<?php foreach (['Details', 'Description'] as $field): if (!empty($record[$field])): ?><div class="prose"><h2><?= $field === 'Details' ? 'About the artist' : 'About this genre' ?></h2><p><?= nl2br(e(strip_tags($record[$field]))) ?></p></div><?php endif; endforeach; ?>
<?php if ($object['key']): ?><a class="button danger" href="object-delete.php?type=<?= e($type) ?>&amp;id=<?= (int)$record[$object['key']] ?>"><?= !empty($object['relationship']) ? 'Remove assignment' : 'Delete ' . e($object['one']) ?></a><?php endif; ?></section>
<?php if (in_array($type, ['artists', 'galleries', 'genres', 'subjects'], true)): $works = related_paintings($type, (int)$record[$object['key']]); ?>
<section class="related"><div class="section-heading"><h2>Artworks</h2><span><?= count($works) ?> in the collection</span></div><?php artwork_cards($works); ?></section>
<?php endif; endif; else:
$count = (int)rows('SELECT COUNT(*) FROM ' . $object['table'])->fetchColumn();
$pages = max(1, (int)ceil($count / 30));
$page = min($pages, max(1, positive_id(get_text('page'))));
$order = $object['order'] ?? 'o.' . $object['name'] . ($object['key'] && $object['name'] !== $object['key'] ? ', o.' . $object['key'] : '');
$records = rows(object_select($type) . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [30, ($page - 1) * 30])->fetchAll();
?>
<p class="result-count"><?= $count ?> <?= e(strtolower($object['label'])) ?> · Page <?= $page ?> of <?= $pages ?></p>
<div class="directory">
<?php foreach ($records as $r): ?><a class="directory-row" href="<?= e(object_url($type, $r, $manage)) ?>"><div><h2><?= e(object_title($type, $r)) ?></h2><p><?= e($r['Nationality'] ?? trim(($r['GalleryCity'] ?? '') . ' ' . ($r['GalleryCountry'] ?? '')) ?: ($r['EraYears'] ?? (!empty($object['relationship']) ? 'Assignment #' . $r[$object['key']] : 'View details'))) ?></p></div><span><?= isset($r['WorkCount']) ? (int)$r['WorkCount'] . ' artworks' : 'View' ?> <span aria-hidden="true">↗</span></span></a><?php endforeach; ?>
<?php if (!$records): ?><p class="empty">No records yet. Add a record to get started.</p><?php endif; ?></div>
<?php if ($pages > 1): ?><nav class="pagination" aria-label="Directory pages"><?php if ($page > 1): ?><a class="button" rel="prev" href="?<?= e(http_build_query(['type' => $type, 'manage' => (int)$manage, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a class="button" rel="next" href="?<?= e(http_build_query(['type' => $type, 'manage' => (int)$manage, 'page' => $page + 1])) ?>">Next</a><?php endif; ?></nav><?php endif; endif; page_end(); ?>
