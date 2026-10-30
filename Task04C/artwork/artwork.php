<?php
/*
 * artwork.php - CS 4485 Task04C Part (3)(b) Bonus: Add Artwork page.
 * Phuc Tran (pmt220000), Team 43
 *
 * GET  shows the form.  POST (the ADD button) validates it and inserts a new row
 * into Paintings with a prepared statement, then returns to art-main.php with a
 * flash message.  The picture is either
 *   - a picture already on the server (in images/art/works/square-medium) that no
 *     painting uses yet, e.g. the picture of an artwork that was deleted, or
 *   - an uploaded JPEG/PNG, which is cropped/resized to 150 x 150 with GD and saved
 *     in images/art/works/square-medium under a new unique name.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$pdo = getPDO();

/* ---------- data for the select lists ---------- */

function loadArtists(PDO $pdo): array
{
    return $pdo->query('SELECT ArtistID, FirstName, LastName FROM Artists ORDER BY LastName, FirstName')
               ->fetchAll(PDO::FETCH_UNIQUE);   // keyed by ArtistID
}

function loadGalleries(PDO $pdo): array
{
    return $pdo->query('SELECT GalleryID, GalleryName, GalleryCity FROM Galleries ORDER BY GalleryName')
               ->fetchAll(PDO::FETCH_UNIQUE);   // keyed by GalleryID
}

/* Names (without .jpg) of picture files on the server that no painting uses. */
function unusedImages(PDO $pdo): array
{
    $used = array_flip($pdo->query('SELECT ImageFileName FROM Paintings')->fetchAll(PDO::FETCH_COLUMN));
    $free = [];
    foreach (glob(__DIR__ . '/' . IMAGE_DIR . '/*.jpg') ?: [] as $path) {
        $name = basename($path, '.jpg');
        if (preg_match('/^[A-Za-z0-9_-]{1,50}$/', $name) && !isset($used[$name])) {
            $free[] = $name;
        }
    }
    sort($free);
    return $free;
}

/*
 * New unique picture name for an upload, in the style of the Lab 14 files:
 * 3-digit artist ID + 3-digit number, after the artist's highest one
 * (e.g. 118070 after Bruegel's 118030 ... 118060).
 */
function newImageName(PDO $pdo, int $artistId): string
{
    $prefix = sprintf('%03d', $artistId);
    $highest = 0;
    $stmt = $pdo->prepare('SELECT ImageFileName FROM Paintings WHERE ImageFileName LIKE :pattern');
    $stmt->bindValue(':pattern', $prefix . '___');
    $stmt->execute();
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach (glob(__DIR__ . '/' . IMAGE_DIR . '/' . $prefix . '???.jpg') ?: [] as $path) {
        $names[] = basename($path, '.jpg');   // picture files on disk count too
    }
    foreach ($names as $name) {
        if (preg_match('/^' . $prefix . '(\d{3})$/', $name, $m)) {
            $highest = max($highest, (int) $m[1]);
        }
    }
    $next = (intdiv($highest, 10) + 1) * 10;
    if ($next <= 999) {
        return $prefix . sprintf('%03d', $next);
    }
    return 'upload_' . bin2hex(random_bytes(6));   // artist already has 99 pictures
}

/*
 * Check an uploaded picture and save it as IMAGE_DIR/<name>.jpg (150 x 150).
 * Returns an error message, or null when the file was saved.
 */
function saveUploadedImage(array $file, string $name): ?string
{
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'The picture is too large (at most ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB).';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'The picture could not be uploaded. Please try again.';
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return 'The picture is too large (at most ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB).';
    }
    // Look at the file's contents, not its name, to decide what it is.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return 'The picture must be a JPEG (.jpg) or PNG image.';
    }
    $target = __DIR__ . '/' . IMAGE_DIR . '/' . $name . '.jpg';

    if (!function_exists('imagecreatetruecolor')) {
        // No GD extension: keep the JPEG as it is (the page shows it at 150 x 150).
        if ($info[2] !== IMAGETYPE_JPEG) {
            return 'Only JPEG pictures can be used on this server.';
        }
        return move_uploaded_file($file['tmp_name'], $target) ? null : 'The picture could not be saved.';
    }

    $src = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($file['tmp_name']) : @imagecreatefrompng($file['tmp_name']);
    if (!$src) {
        return 'The picture file is damaged or not a real image.';
    }
    // Centre square crop, resized to IMAGE_SIZE x IMAGE_SIZE like the square-medium set.
    $w = imagesx($src);
    $h = imagesy($src);
    $side = min($w, $h);
    $dst = imagecreatetruecolor(IMAGE_SIZE, IMAGE_SIZE);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // white behind PNG transparency
    imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2),
                       IMAGE_SIZE, IMAGE_SIZE, $side, $side);
    $ok = imagejpeg($dst, $target, 90);
    return $ok ? null : 'The picture could not be saved on the server.';
}

/* ---------- form handling ---------- */

$errors = [];
$fieldErrors = [];   // field name => true, to colour the field red
$form = [
    'title' => '', 'artist_id' => (string) (getPositiveInt('artist') ?? ''), 'year' => '',
    'medium' => 'Oil on canvas', 'width' => '', 'height' => '', 'gallery_id' => '',
    'excerpt' => '', 'image' => '',
];

try {
    $artists   = loadArtists($pdo);
    $galleries = loadGalleries($pdo);
    $freeImages = unusedImages($pdo);
} catch (PDOException $ex) {
    error_log('artwork.php: ' . $ex->getMessage());
    exit('The database is not available. Is MySQL running and is art-small.sql imported?');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $unused) {
        $form[$key] = trim(postString($key));
    }
    $upload = $_FILES['upload'] ?? null;
    $hasUpload = is_array($upload) && isset($upload['error']) && is_int($upload['error'])
                 && $upload['error'] !== UPLOAD_ERR_NO_FILE;

    if (!csrfValid()) {
        $errors[] = 'The form expired. Please submit it again.';
    }

    // Title: required, at most 255 characters.
    if ($form['title'] === '') {
        $errors[] = 'Title is required.';
        $fieldErrors['title'] = true;
    } elseif (mb_strlen($form['title']) > 255) {
        $errors[] = 'Title must be at most 255 characters.';
        $fieldErrors['title'] = true;
    }

    // Artist: required, must be one of the artists in the list.
    $artistId = filter_var($form['artist_id'], FILTER_VALIDATE_INT);
    if ($artistId === false || !isset($artists[$artistId])) {
        $errors[] = 'Please choose the artist.';
        $fieldErrors['artist_id'] = true;
    }

    // Year: optional whole number.
    $year = null;
    if ($form['year'] !== '') {
        $year = filter_var($form['year'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000, 'max_range' => (int) date('Y')]]);
        if ($year === false) {
            $errors[] = 'Year must be a whole number between 1000 and ' . date('Y') . '.';
            $fieldErrors['year'] = true;
        }
    }

    if (mb_strlen($form['medium']) > 255) {
        $errors[] = 'Medium must be at most 255 characters.';
        $fieldErrors['medium'] = true;
    }

    // Width / height in cm: optional positive whole numbers.
    $size = [];
    foreach (['width' => 'Width', 'height' => 'Height'] as $key => $label) {
        $size[$key] = null;
        if ($form[$key] !== '') {
            $size[$key] = filter_var($form[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
            if ($size[$key] === false) {
                $errors[] = "$label must be a whole number of centimetres (1 - 10000).";
                $fieldErrors[$key] = true;
            }
        }
    }

    // Gallery: optional, but must exist when chosen.
    $galleryId = null;
    if ($form['gallery_id'] !== '') {
        $galleryId = filter_var($form['gallery_id'], FILTER_VALIDATE_INT);
        if ($galleryId === false || !isset($galleries[$galleryId])) {
            $errors[] = 'Please choose a gallery from the list.';
            $fieldErrors['gallery_id'] = true;
        }
    }

    if (mb_strlen($form['excerpt']) > 5000) {
        $errors[] = 'Description must be at most 5000 characters.';
        $fieldErrors['excerpt'] = true;
    }

    // Picture: an upload wins; otherwise one of the unused pictures on the server.
    if (!$hasUpload) {
        if ($form['image'] === '') {
            $errors[] = 'Please choose a picture on the server or upload one.';
            $fieldErrors['image'] = true;
        } elseif (!in_array($form['image'], $freeImages, true)) {
            // Only names from our own list are accepted (no paths, no pictures in use).
            $errors[] = 'That picture is not available. Please choose another one.';
            $fieldErrors['image'] = true;
        }
    }

    if (!$errors) {
        $imageName = $form['image'];
        $savedUpload = null;
        if ($hasUpload) {
            $imageName = newImageName($pdo, $artistId);
            $uploadError = saveUploadedImage($upload, $imageName);
            if ($uploadError !== null) {
                $errors[] = $uploadError;
                $fieldErrors['upload'] = true;
            } else {
                $savedUpload = __DIR__ . '/' . IMAGE_DIR . '/' . $imageName . '.jpg';
            }
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO Paintings (ArtistID, GalleryID, ImageFileName, Title, YearOfWork,
                                            Width, Height, Medium, Excerpt, Description)
                     VALUES (:artist, :gallery, :image, :title, :year,
                             :width, :height, :medium, :excerpt, :description)'
                );
                $stmt->bindValue(':artist',  $artistId, PDO::PARAM_INT);
                $stmt->bindValue(':gallery', $galleryId, $galleryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':image',   $imageName);
                $stmt->bindValue(':title',   $form['title']);
                $stmt->bindValue(':year',    $year, $year === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':width',   $size['width'], $size['width'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':height',  $size['height'], $size['height'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':medium',  $form['medium'] === '' ? null : $form['medium'],
                                 $form['medium'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':excerpt', $form['excerpt'] === '' ? null : $form['excerpt'],
                                 $form['excerpt'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':description', $form['excerpt'] === '' ? null : $form['excerpt'],
                                 $form['excerpt'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->execute();
                $newId = (int) $pdo->lastInsertId();

                setFlash('success', "Artwork '{$form['title']}' added.");
                $_SESSION['highlight'] = $newId;
                redirect('art-main.php?id=' . $artistId . '#painting-' . $newId);
            } catch (PDOException $ex) {
                if ($savedUpload !== null && is_file($savedUpload)) {
                    unlink($savedUpload);   // do not leave an orphan picture behind
                }
                if ($ex->getCode() === '23000') {   // UNIQUE(ImageFileName) violated
                    $errors[] = 'That picture is already used by another artwork. Please choose another one.';
                    $fieldErrors['image'] = true;
                } else {
                    error_log('artwork.php insert: ' . $ex->getMessage());
                    $errors[] = 'The artwork could not be saved (database error).';
                }
                $freeImages = unusedImages($pdo);
            }
        }
    }
}

/* Small helpers for the form markup. */
function fieldClass(array $fieldErrors, string $name, string $extra = ''): string
{
    return trim($extra . ' field' . (isset($fieldErrors[$name]) ? ' error' : ''));
}

$activePage = 'add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Art Gallery - Add Artwork</title>
   <link href="semantic/semantic.css" rel="stylesheet">
   <link href="style.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>

<main class="ui container gallery-main">
   <div class="ui segment add-segment">
      <h2 class="ui header">
         <i class="plus square outline icon"></i>
         <div class="content">
            Add Artwork
            <div class="sub header">Add a new painting to the Paintings table. Fields marked * are required.</div>
         </div>
      </h2>

      <?php if ($errors): ?>
      <div class="ui negative message" id="form-errors">
         <div class="header">Please correct the following:</div>
         <ul class="list">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
         </ul>
      </div>
      <?php endif; ?>

      <form class="ui form<?= $errors ? ' error' : '' ?>" method="post" action="artwork.php"
            enctype="multipart/form-data" autocomplete="off" novalidate>
         <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
         <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_BYTES ?>">

         <div class="ui stackable grid">
            <div class="ten wide column">
               <div class="<?= fieldClass($fieldErrors, 'title', 'required') ?>">
                  <label for="title">Title</label>
                  <input type="text" id="title" name="title" maxlength="255" value="<?= e($form['title']) ?>"
                         placeholder="e.g. The Hay Wain" required>
               </div>

               <div class="two fields">
                  <div class="<?= fieldClass($fieldErrors, 'artist_id', 'required') ?>">
                     <label for="artist_id">Artist</label>
                     <select id="artist_id" name="artist_id" class="ui dropdown" required>
                        <option value="">-- Choose the artist --</option>
                        <?php foreach ($artists as $id => $a): ?>
                        <option value="<?= (int) $id ?>"<?= (string) $id === $form['artist_id'] ? ' selected' : '' ?>>
                           <?= e($a['LastName'] . ($a['FirstName'] ? ', ' . $a['FirstName'] : '')) ?>
                        </option>
                        <?php endforeach; ?>
                     </select>
                  </div>
                  <div class="<?= fieldClass($fieldErrors, 'gallery_id') ?>">
                     <label for="gallery_id">Gallery</label>
                     <select id="gallery_id" name="gallery_id" class="ui dropdown">
                        <option value="">-- None / unknown --</option>
                        <?php foreach ($galleries as $id => $g): ?>
                        <option value="<?= (int) $id ?>"<?= (string) $id === $form['gallery_id'] ? ' selected' : '' ?>>
                           <?= e($g['GalleryName'] . ($g['GalleryCity'] ? ' (' . $g['GalleryCity'] . ')' : '')) ?>
                        </option>
                        <?php endforeach; ?>
                     </select>
                  </div>
               </div>

               <div class="four fields">
                  <div class="<?= fieldClass($fieldErrors, 'year') ?>">
                     <label for="year">Year</label>
                     <input type="number" id="year" name="year" min="1000" max="<?= date('Y') ?>"
                            value="<?= e($form['year']) ?>" placeholder="e.g. 1821">
                  </div>
                  <div class="<?= fieldClass($fieldErrors, 'medium', 'six wide') ?>">
                     <label for="medium">Medium</label>
                     <input type="text" id="medium" name="medium" maxlength="255" value="<?= e($form['medium']) ?>">
                  </div>
                  <div class="<?= fieldClass($fieldErrors, 'width') ?>">
                     <label for="width">Width (cm)</label>
                     <input type="number" id="width" name="width" min="1" max="10000" value="<?= e($form['width']) ?>">
                  </div>
                  <div class="<?= fieldClass($fieldErrors, 'height') ?>">
                     <label for="height">Height (cm)</label>
                     <input type="number" id="height" name="height" min="1" max="10000" value="<?= e($form['height']) ?>">
                  </div>
               </div>

               <div class="<?= fieldClass($fieldErrors, 'excerpt') ?>">
                  <label for="excerpt">Description (excerpt)</label>
                  <textarea id="excerpt" name="excerpt" rows="4" maxlength="5000"
                            placeholder="A short description shown under the title"><?= e($form['excerpt']) ?></textarea>
               </div>
            </div>

            <div class="six wide column">
               <div class="ui secondary segment picture-box">
                  <h4 class="ui header">Picture <span class="required-star">*</span></h4>
                  <div class="<?= fieldClass($fieldErrors, 'image') ?>">
                     <label for="image">Picture already on the server</label>
                     <select id="image" name="image" class="ui dropdown">
                        <?php if ($freeImages): ?>
                        <option value="">-- Choose a picture --</option>
                        <?php foreach ($freeImages as $img): ?>
                        <option value="<?= e($img) ?>"<?= $img === $form['image'] ? ' selected' : '' ?>><?= e($img) ?>.jpg</option>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <option value="">(no unused pictures - upload one below)</option>
                        <?php endif; ?>
                     </select>
                     <small class="hint"><?= count($freeImages) ?> picture<?= count($freeImages) === 1 ? '' : 's' ?>
                        in <?= e(IMAGE_DIR) ?> not used by any artwork (e.g. from a deleted artwork).</small>
                  </div>
                  <div class="picture-preview">
                     <img id="preview" src="" alt="Preview" width="150" height="150" hidden>
                     <div id="no-preview" class="no-preview"><i class="image outline big icon"></i><br>No picture chosen</div>
                  </div>
                  <div class="ui horizontal divider">or</div>
                  <div class="<?= fieldClass($fieldErrors, 'upload') ?>">
                     <label for="upload">Upload a new picture</label>
                     <input type="file" id="upload" name="upload" accept="image/jpeg,image/png">
                     <small class="hint">JPEG or PNG, up to <?= MAX_UPLOAD_BYTES / 1024 / 1024 ?> MB. Saved as a
                        150&times;150 square in <?= e(IMAGE_DIR) ?>. An upload is used instead of the list.</small>
                  </div>
               </div>
            </div>
         </div>

         <div class="ui divider"></div>
         <div class="form-actions">
            <a class="ui large basic button" href="art-main.php">Cancel</a>
            <button type="submit" class="ui huge green button add-big-button" id="add-submit">
               <i class="plus icon"></i> ADD
            </button>
         </div>
      </form>
   </div>
</main>

<script>
// Show the chosen picture (from the list or the file being uploaded) before adding.
(function () {
   var select = document.getElementById('image');
   var upload = document.getElementById('upload');
   var preview = document.getElementById('preview');
   var none = document.getElementById('no-preview');
   function show(src) {
      preview.hidden = !src;
      none.hidden = !!src;
      if (src) preview.src = src;
   }
   function fromSelect() {
      show(select.value ? '<?= e(IMAGE_DIR) ?>/' + encodeURIComponent(select.value) + '.jpg' : '');
   }
   select.addEventListener('change', fromSelect);
   upload.addEventListener('change', function () {
      if (upload.files && upload.files[0]) show(URL.createObjectURL(upload.files[0]));
      else fromSelect();
   });
   fromSelect();
})();
</script>
</body>
</html>
