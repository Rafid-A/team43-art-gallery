<?php
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/uploads.php';
require_admin();

$pdo = db();
$errors = [];
$context = artwork_context($_GET);
$returnUrl = artwork_collection_url($context);
$artists = $pdo->query(
    'SELECT ArtistID, FirstName, LastName FROM Artists ORDER BY LastName'
)->fetchAll();
$galleries = $pdo->query(
    'SELECT GalleryID, GalleryName FROM Galleries ORDER BY GalleryName'
)->fetchAll();
$used = $pdo->query('SELECT ImageFileName FROM Paintings')
    ->fetchAll(PDO::FETCH_COLUMN);
$available = array_values(array_diff(image_files(), $used));
$canUpload = uploads_available();
$fields = [
    'title', 'artist_id', 'gallery_id', 'image_source', 'image', 'year', 'medium', 'excerpt'
];
$v = array_fill_keys($fields, '');
$v['medium'] = 'Oil on canvas';
$v['image_source'] = $canUpload ? 'upload' : 'existing';
$upload = null;

if (upload_request_too_large()) {
    // The whole form was dropped, so there is nothing else to check.
    http_response_code(413);
    $errors['upload'] = upload_too_large_message() . ' Enter the details again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    foreach ($fields as $field)
        $v[$field] = input($field);

    if ($v['title'] === '' || text_length($v['title']) > 255)
        $errors['title'] = 'Enter a title of 1 to 255 characters.';

    if (!in_array($v['artist_id'],
        array_map('strval', array_column($artists, 'ArtistID')), true))
        $errors['artist_id'] = 'Choose an artist.';

    if (!in_array($v['gallery_id'],
        array_map('strval', array_column($galleries, 'GalleryID')), true))
        $errors['gallery_id'] = 'Choose a gallery.';

    if ($v['image_source'] === 'existing') {
        if (!in_array($v['image'], $available, true))
            $errors['image'] = 'Choose an available image. '
                . 'Each filename can be used once.';
    } elseif ($v['image_source'] === 'upload') {
        if (!$canUpload)
            $errors['upload'] = 'Image uploads are unavailable on this server. '
                . 'Choose an image already on the server.';
        else {
            try {
                $upload = decode_upload($_FILES['upload'] ?? null);
            } catch (DomainException $ex) {
                $errors['upload'] = $ex->getMessage();
            }
        }
    } else
        $errors['image_source'] = 'Choose whether to upload an image or use one already on the server.';

    $year = filter_var($v['year'], FILTER_VALIDATE_INT);
    if ($year === false || $year < 1 || $year > 2100)
        $errors['year'] = 'Enter a year from 1 to 2100.';

    if ($v['medium'] === '' || text_length($v['medium']) > 255)
        $errors['medium'] = 'Enter a medium of 1 to 255 characters.';

    if (text_length($v['excerpt']) > 2000)
        $errors['excerpt'] = 'Keep the description under 2001 characters.';

    // A browser cannot keep a chosen file after an error, so ask for it again.
    if ($errors && $upload)
        $errors['upload'] = 'Choose the image file again after correcting the other fields.';

    if (!$errors) {
        $stored = null;
        try {
            // Write the file first under a new name; the painting row only
            // refers to it once the insert commits.
            $image = $upload ? ($stored = store_upload($upload)) : $v['image'];
            $pdo->beginTransaction();
            lock_parent('Artists', 'ArtistID', (int)$v['artist_id'], 'artist');
            lock_parent('Galleries', 'GalleryID', (int)$v['gallery_id'], 'gallery');
            $stmt = $pdo->prepare('INSERT INTO Paintings
                (ArtistID, GalleryID, ImageFileName, Title,
                 YearOfWork, Medium, Excerpt)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $v['artist_id'],
                $v['gallery_id'],
                $image,
                $v['title'],
                $year,
                $v['medium'],
                $v['excerpt']
            ]);
            $pdo->commit();
            flash('Added artwork: ' . $v['title'] . '.');
            redirect($returnUrl);
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['form'] = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($ex->getMessage());
            $errors['form'] = 'Could not add artwork. '
                . 'The image may already be in use. Refresh and try again.';
        } catch (RuntimeException $ex) {
            $errors['form'] = $ex->getMessage() . ' No artwork was added.';
        }
        // A failed save removes only the file this request created.
        if ($errors) {
            discard_upload($stored);
            if ($upload) $errors['upload'] ??= 'Choose the image file again.';
        }
    }
}

page_start('Add artwork', $returnUrl);
?>
<section class="page-heading">
    <div>
        <h1>Add an artwork</h1>
        <p>Upload an image or choose one already on the server, then enter the painting details.</p>
    </div>
    <a href="<?= e($returnUrl) ?>">Back to collection</a>
</section>

<?php if ($errors): ?>
    <div class="notice error" role="alert">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$canUpload): ?>
    <p class="notice">Image uploads are unavailable on this server<?= $available ? '. Choose an image already on the server.' : ', and all images on the server are in use.' ?></p>
<?php endif; ?>

<form action="art-add.php?<?= e(http_build_query($context)) ?>" method="post" class="add-form" enctype="multipart/form-data"
      data-unsaved-warning data-submitting-label="Adding artwork…"
      data-has-unsaved-values="<?= $errors ? 'true' : 'false' ?>">
    <input type="hidden" name="csrf" value="<?= e(token()) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= UPLOAD_MAX_BYTES ?>">
    <p class="form-note">
        All fields are required except the short description.
    </p>
    <div class="form-grid">
        <div class="wide">
            <label for="title">Title</label>
            <input id="title" name="title" value="<?= e($v['title']) ?>"
                   maxlength="255" required
                   <?= isset($errors['title'])
                    ? 'aria-invalid="true" aria-describedby="title-error"'
                    : '' ?>>
            <?php if (isset($errors['title'])): ?>
                <p id="title-error" class="field-error">
                    <?= e($errors['title']) ?>
                </p>
            <?php endif; ?>
        </div>

        <div>
            <label for="artist_id">Artist</label>
            <select id="artist_id" name="artist_id" required
                   <?= isset($errors['artist_id'])
                    ? 'aria-invalid="true" aria-describedby="artist_id-error"'
                    : '' ?>>
                <option value="">Choose an artist</option>
                <?php foreach ($artists as $a): ?>
                    <option value="<?= e($a['ArtistID']) ?>"
                            <?= $v['artist_id'] == $a['ArtistID']
                                ? 'selected' : '' ?>>
                        <?= e(trim($a['FirstName'] . ' ' . $a['LastName'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['artist_id'])): ?>
                <p id="artist_id-error" class="field-error">
                    <?= e($errors['artist_id']) ?>
                </p>
            <?php endif; ?>
        </div>

        <div>
            <label for="gallery_id">Gallery</label>
            <select id="gallery_id" name="gallery_id" required
                   <?= isset($errors['gallery_id'])
                    ? 'aria-invalid="true" aria-describedby="gallery_id-error"'
                    : '' ?>>
                <option value="">Choose a gallery</option>
                <?php foreach ($galleries as $g): ?>
                    <option value="<?= e($g['GalleryID']) ?>"
                            <?= $v['gallery_id'] == $g['GalleryID']
                                ? 'selected' : '' ?>>
                        <?= e($g['GalleryName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['gallery_id'])): ?>
                <p id="gallery_id-error" class="field-error">
                    <?= e($errors['gallery_id']) ?>
                </p>
            <?php endif; ?>
        </div>

        <fieldset class="wide image-source">
            <legend>Image</legend>
            <?php if ($canUpload): ?>
            <label class="choice"><input type="radio" name="image_source" value="upload"
                   <?= $v['image_source'] !== 'existing' ? 'checked' : '' ?>> Upload a new image</label>
            <div class="choice-detail">
                <label for="upload">Image file</label>
                <input id="upload" name="upload" type="file"
                       accept="<?= e(implode(',', array_column(upload_formats(), 2))) ?>"
                       aria-describedby="upload-hint<?= isset($errors['upload']) ? ' upload-error' : '' ?>"
                       <?= isset($errors['upload']) ? 'aria-invalid="true"' : '' ?>>
                <p class="hint" id="upload-hint"><?= e(upload_format_names()) ?>, at most <?= UPLOAD_MAX_BYTES / 1048576 ?> MB and <?= UPLOAD_MAX_SIDE ?> pixels on each side. It is saved as a new JPEG of at most <?= UPLOAD_OUTPUT_SIDE ?> pixels on the longest side, under a name the server chooses.</p>
                <?php if (isset($errors['upload'])): ?>
                    <p id="upload-error" class="field-error"><?= e($errors['upload']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <label class="choice"><input type="radio" name="image_source" value="existing"
                   <?= $v['image_source'] === 'existing' || !$canUpload ? 'checked' : '' ?>> Use an image already on the server</label>
            <div class="choice-detail">
            <label for="image">Image already on server</label>
            <select id="image" name="image"
                    data-image-base="images/art/works/square-medium/"
                   <?= isset($errors['image'])
                    ? 'aria-invalid="true" aria-describedby="image-error"'
                    : '' ?>>
                <option value="">Choose an unused image</option>
                <?php foreach ($available as $image): ?>
                    <option value="<?= e($image) ?>"
                            <?= $v['image'] === $image ? 'selected' : '' ?>>
                        <?= e($image) ?>.jpg
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['image'])): ?>
                <p id="image-error" class="field-error">
                    <?= e($errors['image']) ?>
                </p>
            <?php endif; ?>
            </div>
        </fieldset>

        <div>
            <label for="year">Year of work</label>
            <input id="year" name="year" type="number" min="1" max="2100"
                   value="<?= e($v['year']) ?>" required
                   <?= isset($errors['year'])
                    ? 'aria-invalid="true" aria-describedby="year-error"'
                    : '' ?>>
            <?php if (isset($errors['year'])): ?>
                <p id="year-error" class="field-error">
                    <?= e($errors['year']) ?>
                </p>
            <?php endif; ?>
        </div>

        <figure class="image-preview wide" id="image-preview" hidden>
            <img alt="Selected artwork image" width="640" height="480" hidden>
            <figcaption role="status" aria-live="polite"></figcaption>
        </figure>

        <div class="wide">
            <label for="medium">Medium</label>
            <input id="medium" name="medium" maxlength="255"
                   value="<?= e($v['medium']) ?>" required
                   <?= isset($errors['medium'])
                    ? 'aria-invalid="true" aria-describedby="medium-error"'
                    : '' ?>>
            <?php if (isset($errors['medium'])): ?>
                <p id="medium-error" class="field-error">
                    <?= e($errors['medium']) ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="wide">
            <label for="excerpt">Short description</label>
            <textarea id="excerpt" name="excerpt" maxlength="2000"
                      rows="4"
                   <?= isset($errors['excerpt'])
                    ? 'aria-invalid="true" aria-describedby="excerpt-error"'
                    : '' ?>><?= e($v['excerpt']) ?></textarea>
            <?php if (isset($errors['excerpt'])): ?>
                <p id="excerpt-error" class="field-error">
                    <?= e($errors['excerpt']) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <button class="primary" <?= !$available && !$canUpload ? 'disabled' : '' ?>>
        Add artwork
    </button>
    <a href="<?= e($returnUrl) ?>">Cancel</a>
</form>
<?php page_end(); ?>
