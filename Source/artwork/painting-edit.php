<?php
require_once __DIR__ . '/library.php';
require_admin();

$id = positive_id(get_text('id'));
$context = artwork_context($_GET);
$collectionUrl = artwork_collection_url($context);
$returnUrl = 'painting.php?' . http_build_query(['id' => $id] + $context);
$record = rows('SELECT PaintingID, Title, ArtistID, GalleryID, YearOfWork, Medium FROM Paintings WHERE PaintingID=?', [$id])->fetch();
$artists = rows('SELECT ArtistID, FirstName, LastName FROM Artists ORDER BY LastName, FirstName')->fetchAll();
$galleries = rows('SELECT GalleryID, GalleryName FROM Galleries ORDER BY GalleryName')->fetchAll();
$errors = [];
$values = [];

if (!$record) {
    http_response_code(404);
    page_start('Artwork not found', $collectionUrl);
    echo '<h1>Artwork not found</h1><p>It may have been removed.</p><a href="' . e(directory_return_url($context) ?: $collectionUrl) . '">Return to artworks</a>';
    page_end();
    exit;
}

$values = ['Title' => $record['Title'], 'ArtistID' => $record['ArtistID'], 'GalleryID' => $record['GalleryID'], 'YearOfWork' => $record['YearOfWork'] ?: '', 'Medium' => $record['Medium']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    foreach (array_keys($values) as $field) $values[$field] = is_string($_POST[$field] ?? null) ? input($field) : ($_POST[$field] ?? '');
    $paintingValues = ['title' => $values['Title'], 'artist_id' => $values['ArtistID'], 'gallery_id' => $values['GalleryID'], 'year' => $values['YearOfWork'], 'medium' => $values['Medium']];
    $fieldErrors = painting_field_errors($paintingValues, array_map('strval', array_column($artists, 'ArtistID')), array_map('strval', array_column($galleries, 'GalleryID')));
    $errorFields = ['year' => 'YearOfWork', 'title' => 'Title', 'artist_id' => 'ArtistID', 'gallery_id' => 'GalleryID', 'medium' => 'Medium'];
    foreach ($fieldErrors as $field => $error) $errors[$errorFields[$field]] = $error;
    foreach ($values as $field => $value) if (!is_string($value)) $values[$field] = '';
    $year = filter_var($values['YearOfWork'], FILTER_VALIDATE_INT);
    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $locked = rows('SELECT PaintingID FROM Paintings WHERE PaintingID=? FOR UPDATE', [$id])->fetch();
            if (!$locked) throw new DomainException('The artwork no longer exists. Refresh and try again; no record was changed.');
            lock_parent('Artists', 'ArtistID', (int)$values['ArtistID'], 'artist');
            lock_parent('Galleries', 'GalleryID', (int)$values['GalleryID'], 'gallery');
            rows('UPDATE Paintings SET Title=?, ArtistID=?, GalleryID=?, YearOfWork=?, Medium=? WHERE PaintingID=?', [
                $values['Title'], (int)$values['ArtistID'], (int)$values['GalleryID'], $year, $values['Medium'], $id
            ]);
            $pdo->commit();
            flash('Updated artwork.');
            redirect($returnUrl);
        } catch (DomainException $ex) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $errors['form'] = $ex->getMessage();
        } catch (PDOException $ex) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log($ex->getMessage());
            $errors['form'] = 'The artwork could not be updated. No records were changed.';
        }
    }
    if ($errors) http_response_code(422);
}

page_start('Edit artwork', $returnUrl);
?>
<div class="breadcrumbs"><a href="<?= e($returnUrl) ?>">Artwork</a><span>/</span>Edit artwork</div>
<section class="page-heading"><div><p class="eyebrow">Library management · Artwork #<?= $id ?></p><h1>Edit <?= e($record['Title']) ?></h1><p>Only title, artist, gallery, year, and medium are editable. The ID, image, classifications, and other metadata remain unchanged.</p></div></section>
<?php if ($errors): ?><div class="notice error" role="alert"><p>Please correct the following:</p><ul><?php foreach ($errors as $field => $error): ?><li><a href="<?= $field === 'form' ? '#painting-form' : '#' . e($field) ?>"><?= e($error) ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="add-form" id="painting-form" method="post" action="painting-edit.php?<?= e(http_build_query(['id' => $id] + $context)) ?>" data-unsaved-warning data-has-unsaved-values="<?= $errors ? 'true' : 'false' ?>">
<input type="hidden" name="csrf" value="<?= e(token()) ?>"><fieldset class="form-section"><legend>Artwork details</legend><div class="form-grid">
<div class="wide"><label for="Title">Title *</label><input id="Title" name="Title" maxlength="255" required value="<?= e($values['Title']) ?>" aria-describedby="Title-help Title-error" <?= isset($errors['Title']) ? 'aria-invalid="true"' : '' ?>><p id="Title-help" class="form-note">For example, "The Tragedy" or "Self-portrait".</p><p id="Title-error" class="field-error"><?= e($errors['Title'] ?? '') ?></p></div>
<div><label for="ArtistID">Artist *</label><select id="ArtistID" name="ArtistID" required aria-describedby="ArtistID-error" <?= isset($errors['ArtistID']) ? 'aria-invalid="true"' : '' ?>><option value="">Choose an artist</option><?php foreach ($artists as $artist): ?><option value="<?= (int)$artist['ArtistID'] ?>" <?= (string)$values['ArtistID'] === (string)$artist['ArtistID'] ? 'selected' : '' ?>><?= e(trim($artist['FirstName'] . ' ' . $artist['LastName'])) ?></option><?php endforeach; ?></select><p id="ArtistID-error" class="field-error"><?= e($errors['ArtistID'] ?? '') ?></p></div>
<div><label for="GalleryID">Gallery *</label><select id="GalleryID" name="GalleryID" required aria-describedby="GalleryID-error" <?= isset($errors['GalleryID']) ? 'aria-invalid="true"' : '' ?>><option value="">Choose a gallery</option><?php foreach ($galleries as $gallery): ?><option value="<?= (int)$gallery['GalleryID'] ?>" <?= (string)$values['GalleryID'] === (string)$gallery['GalleryID'] ? 'selected' : '' ?>><?= e($gallery['GalleryName']) ?></option><?php endforeach; ?></select><p id="GalleryID-error" class="field-error"><?= e($errors['GalleryID'] ?? '') ?></p></div>
<div><label for="YearOfWork">Year of work *</label><input id="YearOfWork" name="YearOfWork" type="number" min="1" max="2100" required value="<?= e($values['YearOfWork']) ?>" aria-describedby="YearOfWork-help YearOfWork-error" <?= isset($errors['YearOfWork']) ? 'aria-invalid="true"' : '' ?>><p id="YearOfWork-help" class="form-note">Whole year from 1 to 2100, such as 1901.</p><p id="YearOfWork-error" class="field-error"><?= e($errors['YearOfWork'] ?? '') ?></p></div>
<div class="wide"><label for="Medium">Medium *</label><input id="Medium" name="Medium" maxlength="255" required value="<?= e($values['Medium']) ?>" aria-describedby="Medium-help Medium-error" <?= isset($errors['Medium']) ? 'aria-invalid="true"' : '' ?>><p id="Medium-help" class="form-note">For example, “Oil on canvas”.</p><p id="Medium-error" class="field-error"><?= e($errors['Medium'] ?? '') ?></p></div>
</div></fieldset><div class="form-actions"><button class="primary">Save artwork</button> <a href="<?= e($returnUrl) ?>">Cancel</a><span class="form-actions-note">The image and classifications are preserved.</span></div></form>
<?php page_end(); ?>
