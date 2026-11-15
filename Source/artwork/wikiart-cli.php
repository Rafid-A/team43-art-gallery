<?php
// Command-line extraction for the saved WikiArt sources (issue #16).
//
//   php artwork/wikiart-cli.php extract [--source DIR] [--out DIR] [--json]
//
// Reads only the saved files (no network). Prints a summary and every
// diagnostic, or the whole extraction as JSON with --json. With --out it also
// writes extracted.json, import.sql and, for that SQL, the re-encoded images
// in images/. Exit status: 0 usable, 1 the sources have errors, 2 usage.
// The supported import is the administrator page (import.php).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/wikiart.php';

$args = array_slice($argv, 1);
$command = array_shift($args);
$options = ['source' => wikiart_source_dir(), 'out' => null, 'json' => false];
while ($args) {
    $arg = array_shift($args);
    if ($arg === '--json') $options['json'] = true;
    elseif (in_array($arg, ['--source', '--out'], true) && $args) $options[substr($arg, 2)] = array_shift($args);
    else $command = null;
}
if ($command !== 'extract') {
    fwrite(STDERR, "Usage: php artwork/wikiart-cli.php extract [--source DIR] [--out DIR] [--json]\n");
    exit(2);
}

$extraction = wikiart_extract($options['source']);
if ($options['json']) {
    echo json_encode($extraction, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} else {
    echo 'Sources: ', $extraction['source_dir'], "\n";
    echo 'Artists: ', $extraction['counts']['artists'], ', paintings: ', $extraction['counts']['paintings'], "\n";
    foreach ($extraction['artists'] as $artist) {
        echo '- ', $artist['name'], ' (born ', $artist['birth']['iso'] ?? '?', ($artist['death'] ? ', died ' . $artist['death']['iso'] : ''), ')', "\n";
        foreach ($artist['paintings'] as $painting) echo '    ', $painting['title'], ' (', $painting['year'] ?? '?', ')', "\n";
    }
    foreach ($extraction['diagnostics'] as $d)
        echo strtoupper($d['level']), ': ', $d['file'] ? $d['file'] . ': ' : '', $d['message'], "\n";
    echo $extraction['ok'] ? "OK: the sources can be imported.\n" : "FAILED: correct the errors above; nothing can be imported.\n";
}

if ($options['out'] !== null && $extraction['ok']) {
    $out = rtrim($options['out'], '/');
    if (!is_dir($out . '/images') && !mkdir($out . '/images', 0775, true)) {
        fwrite(STDERR, "Could not create $out/images\n");
        exit(1);
    }
    file_put_contents($out . '/extracted.json', json_encode($extraction, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($out . '/import.sql', wikiart_sql($extraction));
    foreach ($extraction['artists'] as $artist) {
        foreach ($artist['paintings'] as $painting) {
            // The same decoding and bounded re-encoding as an administrator upload.
            $image = prepare_upload_image(rtrim($extraction['source_dir'], '/') . '/' . $painting['image']['file']);
            imagejpeg($image, $out . '/images/' . wikiart_sql_image_name($painting) . '.jpg', 88);
        }
    }
    if (!$options['json']) echo "Wrote $out/extracted.json, $out/import.sql and $out/images/\n";
}
exit($extraction['ok'] ? 0 : 1);
