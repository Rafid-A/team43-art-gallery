<?php
// Administrator import of the saved WikiArt sources (issue #16).
// GET previews what would be added; POST imports it in one transaction.
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/wikiart.php';
require_admin();

$pdo = db();
$ready = wikiart_import_ready($pdo);
$extraction = wikiart_extract(wikiart_source_dir());
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    if (!$ready) {
        http_response_code(409);
        $errors[] = 'The import needs database upgrade 11 (art_import_sources) first. Nothing was imported.';
    } elseif (!uploads_available()) {
        http_response_code(409);
        $errors[] = 'Image storage is unavailable on this server (it needs GD and a writable images/uploads folder). Nothing was imported.';
    } else {
        try {
            $summary = wikiart_import($pdo, $extraction, current_user()['username'],
                fn($path) => store_upload(prepare_upload_image($path)), 'discard_upload');
            $added = $summary['artists_created'] + $summary['paintings_created'];
            $linked = $summary['artists_matched'] + $summary['paintings_matched'];
            flash($added || $linked
                ? 'Import complete: added ' . count_label($summary['artists_created'], 'artist', 'artists') . ' and ' . count_label($summary['paintings_created'], 'artwork', 'artworks')
                    . ($linked ? ', and linked ' . count_label($linked, 'existing record', 'existing records') . ' without changing ' . ($linked === 1 ? 'it' : 'them') : '') . '.'
                : 'Nothing new to import: every saved source has already been imported.');
            redirect('import.php');
        } catch (DomainException $ex) {
            http_response_code(409);
            $errors[] = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('WikiArt import failed: ' . $ex->getMessage());
            http_response_code(500);
            $errors[] = 'The import could not be completed, so it was rolled back and nothing was added. Check the server log, then try again.';
        }
    }
}

$plan = [];
if ($ready && $extraction['ok']) {
    try {
        $plan = wikiart_plan($pdo, $extraction);
    } catch (DomainException $ex) {
        // More than one library record matches a source (see wikiart_artist_match).
        $errors[] = $ex->getMessage();
    }
}
$counts = wikiart_plan_counts($plan);
$canImport = $ready && $extraction['ok'] && uploads_available() && ($counts['new'] + $counts['matches']) > 0;
$statusLabel = ['new' => 'Will be added', 'matches' => 'Already in the library: will be linked, not changed', 'imported' => 'Imported', 'linked' => 'Linked to the existing record'];
page_start('Import saved WikiArt pages');
?>
<div class="breadcrumbs"><a href="admin.php">Administration</a><span>/</span>Import</div>
<section class="page-heading"><div><h1>Import saved WikiArt pages</h1><p>Adds the artists and artworks extracted from WikiArt pages saved with the gallery. Only the saved files are read: nothing is downloaded and no script from those pages runs.</p></div></section>
<?php notices(); ?>
<?php if ($errors): ?><div class="notice error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$ready): ?><section class="notice error"><h2>Database upgrade required</h2><p>The import records each saved source in <code>art_import_sources</code>, added by upgrade <code>11-wikiart-import.sql</code>. Ask the person running this gallery to follow the existing-database upgrade steps in the runtime guide. The rest of the gallery works without it.</p></section><?php endif; ?>
<?php $problems = array_filter($extraction['diagnostics'], fn($d) => $d['level'] === 'error'); $notes = array_filter($extraction['diagnostics'], fn($d) => $d['level'] === 'warning'); ?>
<?php if ($problems): ?><section class="notice error"><h2>The saved sources have problems</h2><p>Nothing can be imported until these are corrected.</p><ul><?php foreach ($problems as $d): ?><li><?= $d['file'] ? '<code>' . e($d['file']) . '</code>: ' : '' ?><?= e($d['message']) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php if ($notes): ?><section class="notice"><h2>Notes</h2><ul><?php foreach ($notes as $d): ?><li><?= $d['file'] ? '<code>' . e($d['file']) . '</code>: ' : '' ?><?= e($d['message']) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<dl class="admin-overview" aria-label="Import overview">
<div><dt>Saved artists</dt><dd><?= (int)$extraction['counts']['artists'] ?></dd></div>
<div><dt>Saved artworks</dt><dd><?= (int)$extraction['counts']['paintings'] ?></dd></div>
<div><dt>To add or link</dt><dd><?= $counts['new'] + $counts['matches'] ?></dd></div>
</dl>
<?php if ($ready && $extraction['ok']): ?>
<form method="post" class="import-form">
    <input type="hidden" name="csrf" value="<?= e(token()) ?>">
    <button class="button primary"<?= $canImport ? '' : ' disabled' ?>><?= $canImport ? 'Import ' . count_label($counts['new'] + $counts['matches'], 'record', 'records') : 'Everything is imported' ?></button>
    <p class="hint"><?= $canImport ? 'One transaction: if any record fails, nothing is added. Importing again never adds a source twice.' : (uploads_available() ? 'Every saved source is already in the library.' : 'Image storage is unavailable on this server.') ?></p>
</form>
<?php endif; ?>
<?php foreach ($plan ?: array_map(fn($a) => ['artist' => $a, 'status' => null, 'id' => null, 'paintings' => array_map(fn($p) => ['painting' => $p, 'status' => null, 'id' => null, 'gallery_id' => null, 'genre_id' => null], $a['paintings'])], $extraction['artists']) as $entry): $a = $entry['artist']; ?>
<section class="import-artist">
    <div class="section-heading"><h2><?php if ($entry['id']): ?><a href="objects.php?<?= e(http_build_query(['type' => 'artists', 'id' => $entry['id']])) ?>"><?= e($a['name']) ?></a><?php else: ?><?= e($a['name']) ?><?php endif; ?></h2><?php if ($entry['status']): ?><span class="import-status <?= e($entry['status']) ?>"><?= e($statusLabel[$entry['status']]) ?><?= $entry['id'] ? ' (#' . (int)$entry['id'] . ')' : '' ?></span><?php endif; ?></div>
    <dl class="metadata">
        <div><dt>Born</dt><dd><?= e(($a['birth']['text'] ?? 'Not found') . (!empty($a['birth']['place']) ? ', ' . $a['birth']['place'] : '')) ?></dd></div>
        <div><dt>Died</dt><dd><?= e(($a['death']['text'] ?? 'Not recorded') . (!empty($a['death']['place']) ? ', ' . $a['death']['place'] : '')) ?></dd></div>
        <div><dt>Nationality</dt><dd><?= e($a['nationality']['country'] ?: 'Not recorded') ?></dd></div>
        <div><dt>Source</dt><dd><code><?= e($a['page']) ?></code> (saved from <?= e($a['url']) ?>)</dd></div>
    </dl>
    <ul class="import-list">
    <?php foreach ($entry['paintings'] as $row): $p = $row['painting']; ?>
        <li>
            <div><h3><?php if ($row['id']): ?><a href="painting.php?<?= e(http_build_query(['id' => $row['id']])) ?>"><?= e($p['title']) ?></a><?php else: ?><?= e($p['title']) ?><?php endif; ?></h3>
            <p><?= e(implode(', ', array_filter([$p['year'], $p['medium'], $p['height_cm'] && $p['width_cm'] ? $p['height_cm'] . ' × ' . $p['width_cm'] . ' cm' : null, $p['styles'][0] ?? null]))) ?></p>
            <p class="hint">Gallery: <?= e($p['gallery_name'] ? $p['gallery_name'] . ($row['gallery_id'] ? ' (#' . $row['gallery_id'] . ')' : ' (not in the library, left blank)') : 'not recorded') ?>. Source <code><?= e($p['page']) ?></code>, image <code><?= e($p['image']['file'] ?? '') ?></code>.</p></div>
            <?php if ($row['status']): ?><span class="import-status <?= e($row['status']) ?>"><?= e($statusLabel[$row['status']]) ?><?= $row['id'] ? ' (#' . (int)$row['id'] . ')' : '' ?></span><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>
<?php page_end(); ?>
