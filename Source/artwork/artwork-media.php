<?php
// Public image bytes, matching the gallery's existing public image access.
// No session, database, caller-supplied path, or writes to original media.
const ARTWORK_MEDIA_WIDTHS = [160, 320, 480, 640, 960, 1280, 1600];
const ARTWORK_MEDIA_MAX_BYTES = 5242880;

function artwork_media_source($name)
{
    static $sources = [];
    if (!is_string($name) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,95}\z/D', $name)) return null;
    if (array_key_exists($name, $sources)) return $sources[$name];
    $sources[$name] = null;
    $imageRoot = realpath(__DIR__ . '/images');
    if (!$imageRoot) return null;
    foreach (['images/art/works/square-medium/', 'images/uploads/'] as $folder) {
        $root = realpath(__DIR__ . '/' . $folder);
        if (!$root || !str_starts_with($root . DIRECTORY_SEPARATOR, $imageRoot . DIRECTORY_SEPARATOR)) continue;
        $candidate = $root . DIRECTORY_SEPARATOR . $name . '.jpg';
        $path = realpath($candidate);
        if (!$path || is_link($candidate) || dirname($path) !== $root || !is_file($path)) continue;
        $bytes = @filesize($path);
        $info = @getimagesize($path);
        if (!$bytes || $bytes > ARTWORK_MEDIA_MAX_BYTES || !$info || $info[2] !== IMAGETYPE_JPEG
            || min($info[0], $info[1]) < 1 || max($info[0], $info[1]) > 6000 || $info[0] * $info[1] > 24000000) return null;
        $version = @hash_file('sha256', $path);
        if (!$version) return null;
        return $sources[$name] = ['path' => $path, 'url' => $folder . rawurlencode($name) . '.jpg',
            'width' => $info[0], 'height' => $info[1], 'version' => $version, 'name' => $name];
    }
    return null;
}

function artwork_media_can_resize(array $source, $width)
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled') || !function_exists('imagejpeg')) return false;
    $outWidth = min((int)$width, $source['width']);
    $outHeight = max(1, (int)round($source['height'] * $outWidth / $source['width']));
    $limit = trim((string)ini_get('memory_limit'));
    $bytes = (int)$limit * match (strtolower(substr($limit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    $budget = $limit === '-1' ? 128 * 1048576 : min(128 * 1048576, $bytes);
    return memory_get_usage(true) + ARTWORK_MEDIA_MAX_BYTES * 2
        + ($source['width'] * $source['height'] + $outWidth * $outHeight) * 5 + 8 * 1048576 <= $budget;
}

function artwork_media_srcset(array $source)
{
    $set = [];
    foreach (ARTWORK_MEDIA_WIDTHS as $width) {
        if ($width >= $source['width']) break;
        if (!artwork_media_can_resize($source, $width)) continue;
        $set[] = 'artwork-media.php?' . http_build_query(['name' => $source['name'], 'w' => $width, 'v' => $source['version']]) . ' ' . $width . 'w';
    }
    // The actual source is always the ceiling; never invent a larger candidate.
    $set[] = $source['url'] . ' ' . $source['width'] . 'w';
    return implode(', ', $set);
}

function artwork_media_error($status)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $status === 400 ? 'Invalid image request.' : 'Image unavailable.';
}

function artwork_media_response()
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        artwork_media_error(405);
        return;
    }
    $name = $_GET['name'] ?? null;
    $width = $_GET['w'] ?? null;
    $version = $_GET['v'] ?? null;
    if (!is_string($name) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,95}\z/D', $name)
        || !is_string($width) || !in_array($width, array_map('strval', ARTWORK_MEDIA_WIDTHS), true)
        || !is_string($version) || !preg_match('/\A[0-9a-f]{64}\z/D', $version)
        || array_diff(array_keys($_GET), ['name', 'w', 'v'])) {
        artwork_media_error(400); return;
    }
    $source = artwork_media_source($name);
    if (!$source || !hash_equals($source['version'], $version)) { artwork_media_error(404); return; }
    $outWidth = min((int)$width, $source['width']);
    $outHeight = max(1, (int)round($source['height'] * $outWidth / $source['width']));
    // Content-addressed, finite derivatives live only in ephemeral PHP temp.
    // Atomic writes allow concurrent image requests; a missing cache is harmless.
    $cacheRoot = sys_get_temp_dir() . '/gallery-art-media-v2';
    $key = hash('sha256', $version . ':' . $outWidth . ':jpeg-85-v2');
    $cache = $cacheRoot . '/' . $key . '.jpg';
    $cached = !is_link($cacheRoot) && !is_link($cache) && is_file($cache);
    if (!$cached) {
        if (!artwork_media_can_resize($source, $outWidth)) {
            artwork_media_error(404); return;
        }
        $input = @file_get_contents($source['path'], false, null, 0, ARTWORK_MEDIA_MAX_BYTES + 1);
        if ($input === false || !hash_equals($version, hash('sha256', $input))
            || !str_starts_with($input, "\xff\xd8")
            || !str_ends_with(rtrim($input, "\0\t\n\r "), "\xff\xd9")) { artwork_media_error(404); return; }
        // Decode real bytes and fail closed on GD's damaged-JPEG warnings.
        $warnings = 0;
        $ignore = ini_set('gd.jpeg_ignore_warning', '0');
        set_error_handler(function () use (&$warnings) { $warnings++; return true; });
        try { $image = imagecreatefromstring($input); }
        finally { restore_error_handler(); if ($ignore !== false) ini_set('gd.jpeg_ignore_warning', $ignore); }
        unset($input);
        if (!$image || $warnings || imagesx($image) !== $source['width'] || imagesy($image) !== $source['height']) {
            artwork_media_error(404); return;
        }
        $resized = imagecreatetruecolor($outWidth, $outHeight);
        if (!$resized || !imagecopyresampled($resized, $image, 0, 0, 0, 0, $outWidth, $outHeight, $source['width'], $source['height'])) {
            artwork_media_error(404); return;
        }
        unset($image);
        ob_start();
        $encoded = imagejpeg($resized, null, 85);
        $output = ob_get_clean();
        unset($resized);
        if (!$encoded || !$output || strlen($output) > ARTWORK_MEDIA_MAX_BYTES) { artwork_media_error(404); return; }
        if (!is_link($cacheRoot) && (is_dir($cacheRoot) || @mkdir($cacheRoot, 0700))) {
            $tmp = @tempnam($cacheRoot, 'media-');
            if ($tmp !== false) {
                if (@file_put_contents($tmp, $output) === strlen($output)) @rename($tmp, $cache);
                if (is_file($tmp)) @unlink($tmp);
            }
        }
    }
    $etag = '"' . $key . '"';
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return; }
    // Load bounded cached bytes before the body, so cache eviction cannot turn
    // an otherwise valid image response into warnings or an empty 200.
    if ($cached) {
        $output = @file_get_contents($cache, false, null, 0, ARTWORK_MEDIA_MAX_BYTES + 1);
        if (!$output || strlen($output) > ARTWORK_MEDIA_MAX_BYTES) { artwork_media_error(404); return; }
    }
    header('Content-Length: ' . strlen($output));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') return;
    echo $output;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) artwork_media_response();
