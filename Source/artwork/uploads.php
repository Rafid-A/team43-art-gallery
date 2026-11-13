<?php
// Painting image uploads for administrators (issue #15).
// An upload is decoded with GD and written again as a new JPEG under a
// server-generated name, so nothing from the original file (its name,
// metadata or any extra bytes) is stored. Bundled course images are never
// written or removed here.
const UPLOAD_MAX_BYTES = 5242880;       // 5 MB
const UPLOAD_MAX_SIDE = 6000;           // pixels, checked before decoding
const UPLOAD_MAX_PIXELS = 24000000;     // 24 megapixels
const UPLOAD_OUTPUT_SIDE = 1600;        // longest side of the saved JPEG
const UPLOAD_NAME_PATTERN = '/^upload-[0-9a-f]{32}$/D';

function upload_folder()
{
    return __DIR__ . '/images/uploads';
}

// Formats this server can decode, by IMAGETYPE_* constant.
function upload_formats()
{
    $formats = [];
    if (function_exists('imagecreatefromjpeg')) $formats[IMAGETYPE_JPEG] = ['JPEG', 'imagecreatefromjpeg', 'image/jpeg'];
    if (function_exists('imagecreatefrompng')) $formats[IMAGETYPE_PNG] = ['PNG', 'imagecreatefrompng', 'image/png'];
    if (function_exists('imagecreatefromwebp')) $formats[IMAGETYPE_WEBP] = ['WebP', 'imagecreatefromwebp', 'image/webp'];
    return $formats;
}

function upload_format_names()
{
    $names = array_column(upload_formats(), 0);
    return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' or ' . end($names) : implode('', $names);
}

function uploads_available()
{
    return function_exists('imagejpeg') && isset(upload_formats()[IMAGETYPE_JPEG])
        && is_dir(upload_folder()) && is_writable(upload_folder());
}

function upload_too_large_message()
{
    return 'The image is too large. Upload a file of at most ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB.';
}

// A POST larger than post_max_size arrives with empty $_POST and $_FILES.
function upload_request_too_large()
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

function memory_limit_bytes()
{
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') return PHP_INT_MAX;
    $value = (int)$limit;
    $unit = strtolower(substr($limit, -1));
    return $value * match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
}

// Validates one $_FILES entry and returns the decoded, resized image.
// Throws DomainException with a message for the form; nothing is written.
function decode_upload($file)
{
    if ($file === null) throw new DomainException('Choose an image file to upload.');
    if (!is_array($file) || !isset($file['error'], $file['tmp_name']) || !is_int($file['error']))
        throw new DomainException('Choose one image file to upload.');
    switch ($file['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_NO_FILE: throw new DomainException('Choose an image file to upload.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: throw new DomainException(upload_too_large_message());
        case UPLOAD_ERR_PARTIAL: throw new DomainException('The upload was interrupted. Choose the file again and retry.');
        default:
            error_log('Image upload failed with PHP upload error ' . $file['error']);
            throw new DomainException('The server could not receive the image. Please try again.');
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) throw new DomainException('Choose an image file to upload.');
    return prepare_upload_image($tmp);
}

// Processes a received file through the same validation and GD path for every
// caller. decode_upload() additionally requires PHP's uploaded-file provenance.
function prepare_upload_image($tmp)
{
    $bytes = filesize($tmp);
    if (!$bytes) throw new DomainException('The image file is empty.');
    if ($bytes > UPLOAD_MAX_BYTES) throw new DomainException(upload_too_large_message());

    // The type comes from the file's own bytes, never its name or browser type.
    $info = @getimagesize($tmp);
    $formats = upload_formats();
    if (!$info || !isset($formats[$info[2]]))
        throw new DomainException('Upload a ' . upload_format_names() . ' image. Other file types are not accepted.');
    [$width, $height] = $info;
    [$format, $decoder] = $formats[$info[2]];
    if ($width < 1 || $height < 1 || $width > UPLOAD_MAX_SIDE || $height > UPLOAD_MAX_SIDE || $width * $height > UPLOAD_MAX_PIXELS)
        throw new DomainException('The image must be at most ' . UPLOAD_MAX_SIDE . ' pixels on each side and ' . (UPLOAD_MAX_PIXELS / 1000000) . ' megapixels.');
    // Reserve about 5 bytes per pixel for the source and a bounded output canvas.
    // Resize and release the full source BEFORE rotation: rotating it first would
    // require a second full-size canvas that this budget cannot accommodate.
    // After release, even two output canvases fit within the same reservation.
    if (memory_get_usage() + ($width * $height + UPLOAD_OUTPUT_SIDE ** 2) * 5 > memory_limit_bytes())
        throw new DomainException('This server does not have enough memory for an image that large. Upload a smaller image.');

    // GD fills damaged or cut-off image data with gray and only warns, so
    // any decoder warning means the file is rejected.
    $warnings = 0;
    $ignore = ini_set('gd.jpeg_ignore_warning', '0');
    set_error_handler(function () use (&$warnings) { $warnings++; return true; });
    try {
        $source = $decoder($tmp);
    } finally {
        restore_error_handler();
        if ($ignore !== false) ini_set('gd.jpeg_ignore_warning', $ignore);
    }
    if (!$source || $warnings || imagesx($source) !== $width || imagesy($source) !== $height)
        throw new DomainException('The file could not be read as a ' . $format . ' image. It may be damaged.');

    // Transparent areas become white; the image is never enlarged.
    $scale = min(1, UPLOAD_OUTPUT_SIDE / max($width, $height));
    $outWidth = max(1, (int)round($width * $scale));
    $outHeight = max(1, (int)round($height * $scale));
    $image = @imagecreatetruecolor($outWidth, $outHeight);
    if (!$image || !@imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255))
        || !@imagecopyresampled($image, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height))
        throw new DomainException('The image could not be resized on the server. Upload a smaller image or try again.');
    unset($source);

    // Phone cameras store rotation as EXIF; rotate only the bounded result.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle) {
            $rotated = @imagerotate($image, $angle, 0xffffff);
            if (!$rotated)
                throw new DomainException('The image could not be rotated on the server. Upload a smaller image or try again.');
            $image = $rotated;
        }
    }
    return $image;
}

// Writes every encoded byte and closes the stream even when writing fails.
// imagejpeg() can return true on a full disk; its return value alone is unsafe.
function write_upload_stream($handle, $bytes)
{
    try {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length; $offset += $written) {
            $written = @fwrite($handle, substr($bytes, $offset, 65536));
            if ($written === false || $written === 0)
                throw new RuntimeException('Incomplete image write.');
        }
        if (!@fflush($handle)) throw new RuntimeException('Could not flush image.');
    } finally {
        if (!@fclose($handle)) throw new RuntimeException('Could not close image.');
    }
}

// Writes the image under a new random name and returns that name (without
// .jpg). Mode 'x' creates the file only if the name is unused, so a
// colliding name can never replace another upload.
function store_upload($image)
{
    // decode_upload already bounds this image to 1600px. Encode in memory
    // before opening a file, then check the complete JPEG's actual byte writes.
    ob_start();
    $warnings = 0;
    set_error_handler(function () use (&$warnings) { $warnings++; return true; });
    try {
        $encoded = imagejpeg($image, null, 88);
        $jpeg = ob_get_contents();
    } finally {
        restore_error_handler();
        ob_end_clean();
    }
    if (!$encoded || $warnings || !$jpeg
        || strlen($jpeg) > UPLOAD_OUTPUT_SIDE ** 2 * 4 + 65536
        || !str_ends_with($jpeg, "\xff\xd9"))
        throw new RuntimeException('The image could not be encoded on the server.');

    $folder = upload_folder();
    $handle = false;
    for ($attempt = 0; $attempt < 5 && !$handle; $attempt++) {
        $name = 'upload-' . bin2hex(random_bytes(16));
        $handle = @fopen($folder . '/' . $name . '.jpg', 'xb');
    }
    if (!$handle) {
        error_log('Image upload: could not create a file in ' . $folder);
        throw new RuntimeException('The image could not be saved on the server.');
    }
    try {
        write_upload_stream($handle, $jpeg);
    } catch (Throwable $ex) {
        discard_upload($name);
        error_log('Image upload: could not write ' . $name . '.jpg: ' . $ex->getMessage());
        throw new RuntimeException('The image could not be saved on the server.');
    }
    return $name;
}

// A lost connection can make rollback throw while inTransaction() stays true.
// Cleanup must still run before COMMIT. Once COMMIT was attempted, retain the
// file: the server may have committed even if its reply never reached PHP.
function cleanup_failed_upload($pdo, $name, $commitAttempted)
{
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $ex) {
        error_log('Artwork rollback failed: ' . $ex->getMessage());
    }
    if ($commitAttempted) {
        error_log('Artwork COMMIT outcome uncertain; reconciliation reference: ' . ($name ?? 'existing-image')
            . '. Check Paintings.ImageFileName and the upload file before retrying; retain referenced files, remove only confirmed unreferenced uploads.');
    } else {
        discard_upload($name);
    }
}

// Removes a file this request created, after a failed save. Only names
// produced by store_upload() are accepted.
function discard_upload($name)
{
    if (is_string($name) && preg_match(UPLOAD_NAME_PATTERN, $name))
        @unlink(upload_folder() . '/' . $name . '.jpg');
}
