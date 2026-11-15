<?php
// Offline WikiArt extraction and import (issue #16).
//
// Extraction reads only the saved pages and images listed in a manifest
// (import/wikiart/sources/manifest.json). Pages are parsed as data with
// DOMDocument: no script in a saved page is ever run, nothing is fetched, and
// a path in the manifest that leaves the source folder is refused. Every
// file's SHA-256 must match the manifest, so a changed or damaged file is
// reported instead of imported.
//
// The import adds artists and paintings in one transaction. Each saved source
// has a key (its WikiArt path) recorded in art_import_sources (migration 11),
// so importing again adds nothing twice. An artist already in the gallery
// with the same first name, last name and year of birth (Edouard Manet) is
// linked, never changed; the same goes for a painting with the same artist,
// title and year. Images go through the upload pipeline from issue #15.
// This file only defines functions; .htaccess denies it on the web.

const WIKIART_SITE = 'https://www.wikiart.org';
const WIKIART_LOCK = 'team43_wikiart_import';
const WIKIART_TABLE_COMMENT = 'team43 WikiArt import provenance v1';
const WIKIART_MAX_PAGE_BYTES = 2097152;   // 2 MB
const WIKIART_MAX_IMAGE_BYTES = 5242880;  // 5 MB, the upload limit
const WIKIART_MAX_BIOGRAPHY = 20000;      // the artist form's limit
const WIKIART_SUPPORTS = ['canvas', 'panel', 'wood', 'paper', 'cardboard', 'board', 'copper', 'linen'];
const WIKIART_COUNTRIES = [
    'American' => 'USA', 'Austrian' => 'Austria', 'Belgian' => 'Belgium', 'British' => 'England', 'Danish' => 'Denmark',
    'Dutch' => 'Netherlands', 'English' => 'England', 'Flemish' => 'Belgium', 'French' => 'France', 'German' => 'Germany',
    'Greek' => 'Greece', 'Italian' => 'Italy', 'Norwegian' => 'Norway', 'Russian' => 'Russia', 'Spanish' => 'Spain', 'Swiss' => 'Switzerland',
];

function wikiart_source_dir(): string
{
    $dir = getenv('WIKIART_SOURCE_DIR');
    return is_string($dir) && $dir !== '' ? $dir : dirname(__DIR__) . '/import/wikiart/sources';
}

// The provenance table from migration 11, checked like the other upgrades.
function wikiart_import_ready(PDO $pdo): bool
{
    $table = $pdo->query("SELECT table_comment FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'art_import_sources'
          AND table_type = 'BASE TABLE' AND engine = 'InnoDB'")->fetchColumn();
    if ($table !== WIKIART_TABLE_COMMENT) return false;
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'art_import_sources'")->fetchAll(PDO::FETCH_COLUMN);
    return !array_diff(['SourceKey', 'RecordType', 'ArtistID', 'PaintingID', 'Outcome', 'SourceURL', 'SnapshotFile',
        'SnapshotSHA256', 'ImageSHA256', 'Extracted', 'ImportedBy', 'ImportedAt'], $columns);
}

// ---------------------------------------------------------------- extraction

final class WikiartSources
{
    public array $diagnostics = [];
    public array $manifest = [];
    private string $root;

    public function __construct(public string $dir)
    {
        $this->root = (string)realpath($dir);
    }

    public function error(string $message, ?string $file = null): void
    {
        $this->diagnostics[] = ['level' => 'error', 'file' => $file, 'message' => $message];
    }

    public function warning(string $message, ?string $file = null): void
    {
        $this->diagnostics[] = ['level' => 'warning', 'file' => $file, 'message' => $message];
    }

    public function failed(): bool
    {
        return (bool)array_filter($this->diagnostics, fn($d) => $d['level'] === 'error');
    }

    // Resolves a manifest path inside the source folder, or reports why not.
    public function path(?string $relative, string $what): ?string
    {
        if (!is_string($relative) || $relative === '') {
            $this->error('The manifest does not name the ' . $what . '.');
            return null;
        }
        if ($this->root === '' || str_contains($relative, "\0") || str_contains($relative, '://') || str_starts_with($relative, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relative) || str_contains($relative, '\\')) {
            $this->error('The ' . $what . ' path is not inside the source folder, so it was not read.', $relative);
            return null;
        }
        $path = realpath($this->root . '/' . $relative);
        if ($path === false || !is_file($path)) {
            $this->error('The ' . $what . ' is missing.', $relative);
            return null;
        }
        if (!str_starts_with($path, $this->root . DIRECTORY_SEPARATOR)) {
            $this->error('The ' . $what . ' path is not inside the source folder, so it was not read.', $relative);
            return null;
        }
        return $path;
    }

    // Reads a listed file and checks it against the manifest's SHA-256.
    public function read(?string $relative, string $what, int $limit): ?array
    {
        $path = $this->path($relative, $what);
        if ($path === null) return null;
        $size = filesize($path);
        if ($size === false || $size === 0) {
            $this->error('The ' . $what . ' is empty.', $relative);
            return null;
        }
        if ($size > $limit) {
            $this->error('The ' . $what . ' is larger than ' . intdiv($limit, 1048576) . ' MB.', $relative);
            return null;
        }
        $bytes = file_get_contents($path);
        $expected = $this->manifest['files'][$relative]['sha256'] ?? null;
        if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/D', $expected)) {
            $this->error('The manifest has no SHA-256 for the ' . $what . '.', $relative);
            return null;
        }
        $actual = hash('sha256', $bytes);
        if (!hash_equals($expected, $actual)) {
            $this->error('The ' . $what . ' has changed since it was saved (its SHA-256 does not match the manifest).', $relative);
            return null;
        }
        return ['path' => $path, 'file' => $relative, 'bytes' => $bytes, 'sha256' => $actual];
    }
}

// Parses saved HTML as data. libxml builds a tree; scripts are inert text.
function wikiart_xpath(string $html): ?DOMXPath
{
    if (!mb_check_encoding($html, 'UTF-8')) return null;
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return $loaded ? new DOMXPath($dom) : null;
}

function wikiart_clean($text): string
{
    $text = str_replace(["\u{00A0}", "\u{200B}"], [' ', ''], (string)$text);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function wikiart_first(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DOMNode
{
    $nodes = $xpath->query($query, $context);
    return $nodes && $nodes->length ? $nodes->item(0) : null;
}

function wikiart_value(DOMXPath $xpath, string $query, ?DOMNode $context = null): string
{
    $node = wikiart_first($xpath, $query, $context);
    return $node ? wikiart_clean($node->nodeValue) : '';
}

// Text of an element with <br> as line breaks; blank lines separate paragraphs.
function wikiart_paragraphs(DOMNode $node): string
{
    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->tagName) === 'br') $text .= "\n";
        elseif ($child instanceof DOMElement) $text .= wikiart_paragraphs($child);
        else $text .= $child->nodeValue;
    }
    $paragraphs = array_filter(array_map('wikiart_clean', preg_split('/\n\s*\n/u', str_replace(["\r\n", "\r"], "\n", $text))), 'strlen');
    return implode("\n\n", $paragraphs);
}

// The values of a "Label:" row in a WikiArt information list.
function wikiart_row(DOMXPath $xpath, DOMNode $list, string $label): ?DOMElement
{
    foreach ($xpath->query('.//li[s]', $list) as $li) {
        if (rtrim(wikiart_clean($xpath->query('s', $li)->item(0)->nodeValue), ':') === $label) return $li;
    }
    return null;
}

function wikiart_row_text(DOMXPath $xpath, DOMNode $list, string $label): string
{
    $li = wikiart_row($xpath, $list, $label);
    if (!$li) return '';
    $text = '';
    foreach ($li->childNodes as $child) {
        if (!($child instanceof DOMElement && strtolower($child->tagName) === 's')) $text .= $child->textContent;
    }
    return wikiart_clean($text);
}

function wikiart_row_links(DOMXPath $xpath, DOMNode $list, string $label): array
{
    $li = wikiart_row($xpath, $list, $label);
    if (!$li) return [];
    return array_values(array_filter(array_map(fn($a) => wikiart_clean($a->textContent), iterator_to_array($xpath->query('.//a', $li))), 'strlen'));
}

// "January 23, 1832" -> 1832-01-23. Returns null for anything else.
function wikiart_date(string $text): ?array
{
    $date = DateTimeImmutable::createFromFormat('!F j, Y', $text);
    if (!$date || $date->format('F j, Y') !== $text) return null;
    return ['text' => $text, 'iso' => $date->format('Y-m-d'), 'year' => (int)$date->format('Y')];
}

function wikiart_path_of(string $url): string
{
    if (str_starts_with($url, WIKIART_SITE . '/')) return substr($url, strlen(WIKIART_SITE));
    if (str_starts_with($url, 'https://wikiart.org/')) return substr($url, strlen('https://wikiart.org'));
    return $url;
}

// Text the gallery tables can store: valid UTF-8 without 4-byte characters
// (the course tables use MySQL's 3-byte utf8), within the column length.
function wikiart_check_text(WikiartSources $sources, string $file, string $label, string $value, int $max, bool $required = true): void
{
    if ($value === '') {
        if ($required) $sources->error($label . ' was not found on the saved page.', $file);
        return;
    }
    if (!mb_check_encoding($value, 'UTF-8')) $sources->error($label . ' is not valid UTF-8 text.', $file);
    elseif (preg_match('/[\x{10000}-\x{10FFFF}]/u', $value)) $sources->error($label . ' contains emoji or other characters the gallery tables cannot store.', $file);
    elseif (mb_strlen($value, 'UTF-8') > $max) $sources->error($label . ' is longer than ' . $max . ' characters.', $file);
}

// A JPEG that decodes completely. Returns [width, height] or reports why not.
function wikiart_check_image(WikiartSources $sources, array $file, string $what): ?array
{
    $info = @getimagesizefromstring($file['bytes']);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) {
        $sources->error('The ' . $what . ' is not a JPEG image.', $file['file']);
        return null;
    }
    if (function_exists('imagecreatefromjpeg')) {
        // As for uploads: libjpeg only warns about cut-off data unless told not to ignore it.
        $warnings = 0;
        $ignore = ini_set('gd.jpeg_ignore_warning', '0');
        set_error_handler(function () use (&$warnings) { $warnings++; return true; });
        try {
            $image = imagecreatefromjpeg($file['path']);
        } finally {
            restore_error_handler();
            if ($ignore !== false) ini_set('gd.jpeg_ignore_warning', $ignore);
        }
        if (!$image || $warnings || imagesx($image) !== $info[0] || imagesy($image) !== $info[1]) {
            $sources->error('The ' . $what . ' could not be read completely. It may be damaged or cut off.', $file['file']);
            return null;
        }
    }
    return [$info[0], $info[1]];
}

function wikiart_medium(array $media): ?string
{
    if (!$media) return null;
    $media = array_map(fn($m) => mb_strtolower($m, 'UTF-8'), $media);
    if (count($media) === 2 && in_array($media[1], WIKIART_SUPPORTS, true))
        return ucfirst($media[0]) . ' on ' . $media[1];
    return ucfirst(implode(', ', $media));
}

function wikiart_parse_artist(WikiartSources $sources, array $entry): ?array
{
    $file = $sources->read($entry['page'] ?? null, 'artist page', WIKIART_MAX_PAGE_BYTES);
    if (!$file) return null;
    $f = $file['file'];
    $xpath = wikiart_xpath($file['bytes']);
    if (!$xpath) {
        $sources->error('The artist page could not be parsed as UTF-8 HTML.', $f);
        return null;
    }
    $url = (string)($entry['url'] ?? '');
    $canonical = wikiart_value($xpath, '//link[@rel="canonical"]/@href');
    if ($canonical !== $url) {
        $sources->error('The artist page is for ' . ($canonical ?: 'an unknown address') . ', not ' . $url . ' as the manifest says.', $f);
        return null;
    }
    $info = wikiart_first($xpath, '//div[@itemprop="artist"][contains(concat(" ", normalize-space(@class), " "), " wiki-layout-artist-info ")]');
    if (!$info) {
        $sources->error('The artist information block was not found. The page may be incomplete or not a WikiArt artist page.', $f);
        return null;
    }
    $name = wikiart_value($xpath, './/meta[@itemprop="name"]/@content', $info) ?: wikiart_value($xpath, './/article/h3', $info);
    $parts = preg_split('/\s+/u', $name);
    $last = count($parts) > 1 ? array_pop($parts) : $name;
    $first = implode(' ', $parts);
    $birthText = wikiart_value($xpath, './/*[@itemprop="birthDate"]', $info);
    $deathText = wikiart_value($xpath, './/*[@itemprop="deathDate"]', $info);
    $birth = wikiart_date($birthText);
    $death = $deathText === '' ? null : wikiart_date($deathText);
    if ($birthText === '') $sources->error('The birth date was not found on the artist page.', $f);
    elseif (!$birth) $sources->error('The birth date "' . $birthText . '" is not a full date such as January 23, 1832.', $f);
    if ($deathText !== '' && !$death) $sources->error('The death date "' . $deathText . '" is not a full date such as April 30, 1883.', $f);
    if ($birth && $death && $death['iso'] < $birth['iso']) $sources->error('The death date is before the birth date.', $f);
    $nationalities = array_values(array_filter(array_map(fn($n) => wikiart_clean($n->nodeValue), iterator_to_array($xpath->query('.//*[@itemprop="nationality"]', $info))), 'strlen'));
    $nationality = $nationalities[0] ?? '';
    $country = WIKIART_COUNTRIES[$nationality] ?? $nationality;
    if ($nationality !== '' && !isset(WIKIART_COUNTRIES[$nationality]))
        $sources->warning('Nationality "' . $nationality . '" has no country name in the importer, so it is stored as written.', $f);
    $bioNode = wikiart_first($xpath, './/div[@id="info-tab-description"]//p[@itemprop="description"][normalize-space()]', $info)
        ?? wikiart_first($xpath, './/div[@id="info-tab-wikipediaArticle"]/p[normalize-space()][1]', $info);
    $biography = $bioNode ? wikiart_paragraphs($bioNode) : '';
    $wikipedia = '';
    $wikiRow = wikiart_row($xpath, $info, 'Wikipedia');
    if ($wikiRow) $wikipedia = wikiart_value($xpath, './/a/@href', $wikiRow);
    if ($wikipedia === '') $wikipedia = wikiart_value($xpath, './/meta[@itemprop="sameAs"]/@content', $info);
    $wikipedia = preg_replace('#^http://#', 'https://', $wikipedia);

    wikiart_check_text($sources, $f, 'The artist name', $name, 101);
    wikiart_check_text($sources, $f, 'The first name', $first, 50, false);
    wikiart_check_text($sources, $f, 'The last name', $last, 50);
    wikiart_check_text($sources, $f, 'The nationality', $country, 50, false);
    wikiart_check_text($sources, $f, 'The biography', $biography, WIKIART_MAX_BIOGRAPHY);
    wikiart_check_text($sources, $f, 'The Wikipedia link', $wikipedia, 255, false);
    if ($wikipedia !== '' && !filter_var($wikipedia, FILTER_VALIDATE_URL)) $sources->error('The Wikipedia link is not a valid address.', $f);

    // Paintings this page lists, by WikiArt path (links and the page's data attributes).
    $listed = [];
    foreach ($xpath->query('//a/@href') as $href) $listed[wikiart_path_of($href->nodeValue)] = true;
    foreach ($xpath->query('//@ng-init') as $data) {
        if (preg_match_all('#"paintingUrl"\s*:\s*"(/en/[^"]+)"#', $data->nodeValue, $matches))
            foreach ($matches[1] as $path) $listed[$path] = true;
    }
    return [
        'key' => (string)($entry['key'] ?? ''), 'url' => $url, 'page' => $f, 'page_sha256' => $file['sha256'],
        'name' => $name, 'native_name' => wikiart_value($xpath, './/*[@itemprop="additionalName"]', $info),
        'first_name' => $first, 'last_name' => $last,
        'birth' => $birth ? $birth + ['place' => wikiart_value($xpath, './/*[@itemprop="birthPlace"]', $info)] : null,
        'death' => $death ? $death + ['place' => wikiart_value($xpath, './/*[@itemprop="deathPlace"]', $info)] : null,
        'nationality' => ['text' => $nationality, 'country' => $country],
        'movements' => wikiart_row_links($xpath, $info, 'Art Movement'),
        'biography' => $biography, 'wikipedia_url' => $wikipedia,
        'paintings' => [], '_listed' => $listed,
    ];
}

function wikiart_parse_painting(WikiartSources $sources, array $artist, array $entry): ?array
{
    $file = $sources->read($entry['page'] ?? null, 'painting page', WIKIART_MAX_PAGE_BYTES);
    $image = $sources->read($entry['image'] ?? null, 'painting image', WIKIART_MAX_IMAGE_BYTES);
    $reference = isset($entry['reference_image']) ? $sources->read($entry['reference_image'], 'reference thumbnail', WIKIART_MAX_IMAGE_BYTES) : null;
    if (!$file) return null;
    $f = $file['file'];
    $url = (string)($entry['url'] ?? '');
    if (!str_starts_with($url, $artist['url'] . '/')) {
        $sources->error('The painting address ' . $url . ' is not under its artist ' . $artist['url'] . '.', $f);
        return null;
    }
    if (!isset($artist['_listed'][wikiart_path_of($url)]))
        $sources->error('The painting is not listed on the saved artist page, so it cannot be traced to that page.', $f);
    $xpath = wikiart_xpath($file['bytes']);
    if (!$xpath) {
        $sources->error('The painting page could not be parsed as UTF-8 HTML.', $f);
        return null;
    }
    $canonical = wikiart_value($xpath, '//link[@rel="canonical"]/@href');
    if ($canonical !== $url) {
        $sources->error('The painting page is for ' . ($canonical ?: 'an unknown address') . ', not ' . $url . ' as the manifest says.', $f);
        return null;
    }
    $article = wikiart_first($xpath, '(//article[h3])[1]');
    if (!$article) {
        $sources->error('The painting information block was not found. The page may be incomplete.', $f);
        return null;
    }
    $title = wikiart_value($xpath, 'h3', $article);
    $creator = wikiart_path_of(wikiart_value($xpath, './/*[@itemprop="creator"]//a/@href', $article));
    if ($creator !== wikiart_path_of($artist['url'])) $sources->error('The painting page names a different artist (' . ($creator ?: 'none') . ').', $f);
    $dateText = wikiart_value($xpath, './/*[@itemprop="dateCreated"]', $article);
    $year = preg_match('/\b(\d{3,4})\b/', $dateText, $m) ? (int)$m[1] : null;
    if ($year === null || $year < 1 || $year > 2100) $sources->error('The year of the painting was not found on the saved page.', $f);
    $media = wikiart_row_links($xpath, $article, 'Media');
    $medium = wikiart_medium($media);
    $location = wikiart_value($xpath, './/li[contains(@class, "dictionary-values-gallery")]/span/@title', $article) ?: wikiart_row_text($xpath, $article, 'Location');
    $galleryName = ($location === '' || preg_match('/^private collection/i', $location)) ? null : wikiart_clean(explode(',', $location)[0]);
    $dimensions = wikiart_row_text($xpath, $article, 'Dimensions');
    $height = $width = null;
    if ($dimensions !== '') {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)\s*cm$/', $dimensions, $d)) {
            $height = (int)round((float)$d[1]);
            $width = (int)round((float)$d[2]);
        } else $sources->warning('Dimensions "' . $dimensions . '" are not in "height x width cm" form and are left out.', $f);
    }
    // The image file must be the one this page shows: the manifest records the
    // address each file was saved from.
    $imageUrl = wikiart_value($xpath, '//img[@itemprop="image"]/@src');
    $savedFrom = is_string($entry['image'] ?? null) ? ($sources->manifest['files'][$entry['image']]['url'] ?? null) : null;
    if ($image && $imageUrl !== $savedFrom)
        $sources->error('The saved image is not the image shown on this painting page.', $entry['image']);
    $description = wikiart_value($xpath, '//meta[@name="description"]/@content');
    $excerpt = wikiart_clean(preg_replace('/\s*Find more\b.*$/su', '', $description));
    $copyright = wikiart_value($xpath, '//a[contains(concat(" ", normalize-space(@class), " "), " copyright ")]');

    wikiart_check_text($sources, $f, 'The painting title', $title, 255);
    wikiart_check_text($sources, $f, 'The medium', (string)$medium, 255, false);
    wikiart_check_text($sources, $f, 'The description', $excerpt, 2000, false);
    wikiart_check_text($sources, $f, 'The copyright', $copyright, 200, false);
    wikiart_check_text($sources, $f, 'The location', $location, 255, false);
    $size = $image ? wikiart_check_image($sources, $image, 'painting image') : null;
    $referenceSize = $reference ? wikiart_check_image($sources, $reference, 'reference thumbnail') : null;
    return [
        'key' => (string)($entry['key'] ?? ''), 'url' => $url, 'page' => $f, 'page_sha256' => $file['sha256'],
        'title' => $title, 'date_text' => $dateText, 'year' => $year,
        'styles' => wikiart_row_links($xpath, $article, 'Style'), 'genres' => wikiart_row_links($xpath, $article, 'Genre'),
        'media' => $media, 'medium' => $medium, 'location' => $location ?: null, 'gallery_name' => $galleryName,
        'dimensions_text' => $dimensions ?: null, 'height_cm' => $height, 'width_cm' => $width,
        'excerpt' => $excerpt ?: null, 'copyright' => $copyright ?: null,
        'image' => $image && $size ? ['file' => $image['file'], 'sha256' => $image['sha256'], 'url' => $imageUrl, 'width' => $size[0], 'height' => $size[1]] : null,
        'reference_image' => $reference && $referenceSize ? ['file' => $reference['file'], 'sha256' => $reference['sha256'], 'width' => $referenceSize[0], 'height' => $referenceSize[1]] : null,
    ];
}

// Reads the manifest and every listed file. The result is plain data: artists
// with their paintings, the diagnostics, and whether the sources are usable.
function wikiart_extract(string $dir): array
{
    $sources = new WikiartSources($dir);
    $artists = [];
    $manifestPath = $sources->path('manifest.json', 'manifest');
    $manifest = $manifestPath ? json_decode((string)file_get_contents($manifestPath), true) : null;
    if ($manifestPath && !is_array($manifest)) $sources->error('The manifest is not valid JSON.', 'manifest.json');
    elseif (is_array($manifest) && (($manifest['format'] ?? null) !== 1 || !is_array($manifest['artists'] ?? null) || !is_array($manifest['files'] ?? null)))
        $sources->error('The manifest is not in the expected format (format 1 with artists and files).', 'manifest.json');
    elseif (is_array($manifest)) {
        $sources->manifest = $manifest;
        $keys = $images = [];
        foreach ($manifest['artists'] as $entry) {
            $artist = is_array($entry) ? wikiart_parse_artist($sources, $entry) : null;
            if (!$artist) continue;
            if (!preg_match('#^wikiart:/en/[a-z0-9-]+$#D', $artist['key']) || $artist['key'] !== 'wikiart:' . wikiart_path_of($artist['url']))
                $sources->error('The artist key does not match its WikiArt address.', $artist['page']);
            foreach ($entry['paintings'] ?? [] as $paintingEntry) {
                $painting = is_array($paintingEntry) ? wikiart_parse_painting($sources, $artist, $paintingEntry) : null;
                if (!$painting) continue;
                if ($painting['key'] !== 'wikiart:' . wikiart_path_of($painting['url']))
                    $sources->error('The painting key does not match its WikiArt address.', $painting['page']);
                if (isset($keys[$painting['key']])) $sources->error('The painting ' . $painting['url'] . ' is listed twice.', $painting['page']);
                if ($painting['image'] && isset($images[$painting['image']['sha256']])) $sources->error('Two paintings use the same image file.', $painting['image']['file']);
                $keys[$painting['key']] = true;
                if ($painting['image']) $images[$painting['image']['sha256']] = true;
                $artist['paintings'][] = $painting;
            }
            unset($artist['_listed']);
            $artists[] = $artist;
        }
        $expected = $manifest['expected'] ?? [];
        $paintingsPerArtist = (int)($expected['paintings_per_artist'] ?? 0);
        if (isset($expected['artists']) && count($manifest['artists']) !== (int)$expected['artists'])
            $sources->error('The manifest lists ' . count($manifest['artists']) . ' artists; ' . (int)$expected['artists'] . ' are expected.', 'manifest.json');
        foreach ($manifest['artists'] as $entry) {
            $count = is_array($entry['paintings'] ?? null) ? count($entry['paintings']) : 0;
            if ($paintingsPerArtist && $count !== $paintingsPerArtist)
                $sources->error(($entry['url'] ?? 'An artist') . ' has ' . $count . ' paintings listed; ' . $paintingsPerArtist . ' are expected.', 'manifest.json');
        }
        $artistKeys = array_column($artists, 'key');
        if (count($artistKeys) !== count(array_unique($artistKeys))) $sources->error('An artist is listed twice.', 'manifest.json');
    }
    return [
        'format' => 1,
        'source_dir' => $dir,
        'manifest_sha256' => $manifestPath ? hash_file('sha256', $manifestPath) : null,
        'ok' => !$sources->failed(),
        'counts' => ['artists' => count($artists), 'paintings' => array_sum(array_map(fn($a) => count($a['paintings']), $artists))],
        'diagnostics' => $sources->diagnostics,
        'artists' => $artists,
    ];
}

// Column values for the gallery tables, shared by the import and the SQL.
function wikiart_artist_row(array $artist): array
{
    return [
        'FirstName' => $artist['first_name'] !== '' ? $artist['first_name'] : null, 'LastName' => $artist['last_name'],
        'Nationality' => $artist['nationality']['country'] !== '' ? $artist['nationality']['country'] : null,
        'Gender' => null, // WikiArt does not state it; the course default would be a guess
        'YearOfBirth' => $artist['birth']['year'] ?? 0, 'YearOfDeath' => $artist['death']['year'] ?? 0,
        'Details' => $artist['biography'], 'ArtistLink' => $artist['wikipedia_url'] !== '' ? $artist['wikipedia_url'] : null,
    ];
}

function wikiart_painting_row(array $painting): array
{
    return [
        'Title' => $painting['title'], 'YearOfWork' => $painting['year'],
        'Width' => $painting['width_cm'], 'Height' => $painting['height_cm'], 'Medium' => $painting['medium'],
        'Excerpt' => $painting['excerpt'],
        'CopyrightText' => ($painting['copyright'] ?: 'Copyright not stated') . '. Image: WikiArt',
    ];
}

// What is kept in art_import_sources.Extracted for one record.
function wikiart_provenance(array $record, ?array $artist = null): string
{
    $kept = $record;
    unset($kept['paintings']);
    if ($artist) $kept['artist_key'] = $artist['key'];
    return json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------- import

function wikiart_artist_match(PDO $pdo, array $artist, bool $lock = false): ?int
{
    $stmt = $pdo->prepare('SELECT ArtistID FROM Artists WHERE FirstName <=> ? AND LastName = ? AND YearOfBirth = ?' . ($lock ? ' FOR UPDATE' : ''));
    $row = wikiart_artist_row($artist);
    $stmt->execute([$row['FirstName'], $row['LastName'], $row['YearOfBirth']]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 1)
        throw new DomainException($artist['name'] . ' matches more than one existing artist (#' . implode(', #', $ids) . '). Nothing was imported.');
    return $ids ? (int)$ids[0] : null;
}

function wikiart_painting_match(PDO $pdo, int $artistId, array $painting, bool $lock = false): ?int
{
    $stmt = $pdo->prepare('SELECT PaintingID FROM Paintings WHERE ArtistID = ? AND Title = ? AND YearOfWork = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$artistId, $painting['title'], $painting['year']]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 1)
        throw new DomainException($painting['title'] . ' matches more than one existing artwork (#' . implode(', #', $ids) . '). Nothing was imported.');
    return $ids ? (int)$ids[0] : null;
}

// An existing library record with exactly this name, or null.
function wikiart_named_id(PDO $pdo, string $table, string $key, string $column, ?string $name): ?int
{
    if ($name === null || $name === '') return null;
    $stmt = $pdo->prepare("SELECT $key FROM $table WHERE $column = ?");
    $stmt->execute([$name]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : null;
}

function wikiart_known(PDO $pdo, string $key, bool $lock = false): ?array
{
    $stmt = $pdo->prepare('SELECT RecordType, ArtistID, PaintingID, Outcome FROM art_import_sources WHERE SourceKey = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$key]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Read-only: what an import would do now, for the preview.
function wikiart_plan(PDO $pdo, array $extraction): array
{
    $plan = [];
    foreach ($extraction['artists'] as $artist) {
        $known = wikiart_known($pdo, $artist['key']);
        $match = $known ? null : wikiart_artist_match($pdo, $artist);
        $artistId = $known ? (int)$known['ArtistID'] : $match;
        $entry = ['artist' => $artist, 'status' => wikiart_status($known, $match), 'id' => $artistId, 'paintings' => []];
        foreach ($artist['paintings'] as $painting) {
            $pKnown = wikiart_known($pdo, $painting['key']);
            $pMatch = (!$pKnown && $artistId) ? wikiart_painting_match($pdo, $artistId, $painting) : null;
            $entry['paintings'][] = [
                'painting' => $painting, 'status' => wikiart_status($pKnown, $pMatch),
                'id' => $pKnown ? (int)$pKnown['PaintingID'] : $pMatch,
                'gallery_id' => wikiart_named_id($pdo, 'Galleries', 'GalleryID', 'GalleryName', $painting['gallery_name']),
                'genre_id' => wikiart_named_id($pdo, 'Genres', 'GenreID', 'GenreName', $painting['styles'][0] ?? null),
            ];
        }
        $plan[] = $entry;
    }
    return $plan;
}

// new: will be added; matches: an existing record will be linked;
// imported / linked: already recorded in art_import_sources.
function wikiart_status(?array $known, ?int $match): string
{
    if ($known) return $known['Outcome'] === 'matched' ? 'linked' : 'imported';
    return $match ? 'matches' : 'new';
}

function wikiart_plan_counts(array $plan): array
{
    $counts = ['new' => 0, 'matches' => 0, 'imported' => 0, 'linked' => 0];
    foreach ($plan as $entry) {
        $counts[$entry['status']]++;
        foreach ($entry['paintings'] as $p) $counts[$p['status']]++;
    }
    return $counts;
}

function wikiart_record_source(PDO $pdo, string $type, array $record, ?int $artistId, ?int $paintingId, string $outcome, ?array $artist, ?string $by): void
{
    $stmt = $pdo->prepare('INSERT INTO art_import_sources
        (SourceKey, RecordType, ArtistID, PaintingID, Outcome, SourceURL, SnapshotFile, SnapshotSHA256, ImageSHA256, Extracted, ImportedBy)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$record['key'], $type, $artistId, $paintingId, $outcome, $record['url'], $record['page'], $record['page_sha256'],
        $record['image']['sha256'] ?? null, wikiart_provenance($record, $artist), $by]);
}

// Imports everything not yet imported, in one transaction. Throws
// DomainException with a message for the page when nothing was changed.
// $store turns a source image path into a stored image name (tests and the
// CLI pass the upload pipeline; see import.php).
function wikiart_import(PDO $pdo, array $extraction, ?string $by, callable $store, callable $discard): array
{
    if (!$extraction['ok']) throw new DomainException('The saved sources have errors, so nothing was imported. Correct the files listed below first.');
    if (!wikiart_import_ready($pdo)) throw new DomainException('The import needs database upgrade 11 (art_import_sources). Nothing was imported.');
    if ((int)$pdo->query("SELECT GET_LOCK('" . WIKIART_LOCK . "', 0)")->fetchColumn() !== 1)
        throw new DomainException('Another import is running. Wait for it to finish, then refresh this page.');
    $stored = [];
    $commitAttempted = false;
    $summary = ['artists_created' => 0, 'artists_matched' => 0, 'paintings_created' => 0, 'paintings_matched' => 0, 'already_imported' => 0, 'genre_links' => 0, 'created' => []];
    try {
        $pdo->beginTransaction();
        foreach ($extraction['artists'] as $artist) {
            $known = wikiart_known($pdo, $artist['key'], true);
            if ($known) {
                $artistId = (int)$known['ArtistID'];
                $summary['already_imported']++;
            } elseif ($artistId = wikiart_artist_match($pdo, $artist, true)) {
                wikiart_record_source($pdo, 'artist', $artist, $artistId, null, 'matched', null, $by);
                $summary['artists_matched']++;
            } else {
                $row = wikiart_artist_row($artist);
                $pdo->prepare('INSERT INTO Artists (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
                    ->execute(array_values($row));
                $artistId = (int)$pdo->lastInsertId();
                wikiart_record_source($pdo, 'artist', $artist, $artistId, null, 'created', null, $by);
                $summary['artists_created']++;
                $summary['created'][] = ['type' => 'artist', 'id' => $artistId, 'name' => $artist['name']];
            }
            foreach ($artist['paintings'] as $painting) {
                if (wikiart_known($pdo, $painting['key'], true)) {
                    $summary['already_imported']++;
                    continue;
                }
                if ($paintingId = wikiart_painting_match($pdo, $artistId, $painting, true)) {
                    wikiart_record_source($pdo, 'painting', $painting, null, $paintingId, 'matched', $artist, $by);
                    $summary['paintings_matched']++;
                    continue;
                }
                $name = $store(rtrim($extraction['source_dir'], '/') . '/' . $painting['image']['file']);
                $stored[] = $name;
                $row = wikiart_painting_row($painting) + [
                    'ArtistID' => $artistId, 'ImageFileName' => $name,
                    'GalleryID' => wikiart_named_id($pdo, 'Galleries', 'GalleryID', 'GalleryName', $painting['gallery_name']),
                ];
                $pdo->prepare('INSERT INTO Paintings (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
                    ->execute(array_values($row));
                $paintingId = (int)$pdo->lastInsertId();
                $genreId = wikiart_named_id($pdo, 'Genres', 'GenreID', 'GenreName', $painting['styles'][0] ?? null);
                if ($genreId) {
                    $pdo->prepare('INSERT INTO PaintingGenres (PaintingID, GenreID) VALUES (?, ?)')->execute([$paintingId, $genreId]);
                    $summary['genre_links']++;
                }
                wikiart_record_source($pdo, 'painting', $painting, null, $paintingId, 'created', $artist, $by);
                $summary['paintings_created']++;
                $summary['created'][] = ['type' => 'painting', 'id' => $paintingId, 'name' => $painting['title']];
            }
        }
        $commitAttempted = true;
        if (!$pdo->commit()) throw new RuntimeException('The import commit failed.');
        return $summary;
    } catch (Throwable $ex) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Throwable $rollback) {
            error_log('WikiArt import rollback failed: ' . $rollback->getMessage());
        }
        if ($commitAttempted) {
            error_log('WikiArt import COMMIT outcome uncertain; reconciliation references: ' . implode(', ', $stored)
                . '. Check art_import_sources and Paintings.ImageFileName before retrying; remove only uploads confirmed unreferenced.');
        } else {
            foreach ($stored as $name) $discard($name);
        }
        throw $ex;
    } finally {
        try {
            $pdo->query("SELECT RELEASE_LOCK('" . WIKIART_LOCK . "')");
        } catch (Throwable $ex) {
            error_log('WikiArt import lock release failed: ' . $ex->getMessage());
        }
    }
}

// ---------------------------------------------------------------- SQL output

// A string literal that reads the same with or without NO_BACKSLASH_ESCAPES:
// quotes are doubled, and text with a backslash or control character is
// written as UTF-8 hex instead.
function wikiart_sql_value($value): string
{
    if ($value === null) return 'NULL';
    if (is_int($value)) return (string)$value;
    $value = (string)$value;
    if (preg_match('/[\\\\\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) return "CONVERT(X'" . bin2hex($value) . "' USING utf8mb4)";
    return "'" . str_replace("'", "''", $value) . "'";
}

// The image name the SQL route uses: generated from the image's SHA-256, in
// the same upload-<32 hex> form the uploads folder accepts.
function wikiart_sql_image_name(array $painting): string
{
    return 'upload-' . substr(hash('sha256', $painting['key'] . "\n" . $painting['image']['sha256']), 0, 32);
}

function wikiart_sql(array $extraction): string
{
    $q = 'wikiart_sql_value';
    $out = [];
    $out[] = '-- Insertion SQL for the saved WikiArt sources (issue #16), written by artwork/wikiart-cli.php.';
    $out[] = '-- Manifest SHA-256: ' . $extraction['manifest_sha256'];
    $out[] = '-- Review before running, as a database administrator, with other writers paused. It needs';
    $out[] = '-- upgrade 11 (art_import_sources) and the JPEG files from images/ copied into';
    $out[] = '-- artwork/images/uploads/ first. Each source is added once: a source already in';
    $out[] = '-- art_import_sources is skipped, and an existing artist (same first name, last name';
    $out[] = '-- and year of birth) or artwork (same artist, title and year) is linked, not changed.';
    $out[] = '-- The administrator import page does the same with images re-encoded on the server.';
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'START TRANSACTION;';
    foreach ($extraction['artists'] as $artist) {
        $row = wikiart_artist_row($artist);
        $out[] = '';
        $out[] = '-- Artist: ' . $artist['name'] . ', born ' . ($artist['birth']['text'] ?? '?') . ' (' . ($artist['birth']['iso'] ?? '?') . ')'
            . ($artist['death'] ? ', died ' . $artist['death']['text'] . ' (' . $artist['death']['iso'] . ')' : '');
        $out[] = '-- Source: ' . $artist['url'] . ' (' . $artist['page'] . ', SHA-256 ' . $artist['page_sha256'] . ')';
        $out[] = 'SET @known := (SELECT ArtistID FROM art_import_sources WHERE SourceKey = ' . $q($artist['key']) . ');';
        $out[] = 'SET @match := (SELECT ArtistID FROM Artists WHERE FirstName <=> ' . $q($row['FirstName']) . ' AND LastName = ' . $q($row['LastName']) . ' AND YearOfBirth = ' . $q($row['YearOfBirth']) . ');';
        $out[] = 'INSERT INTO Artists (' . implode(', ', array_keys($row)) . ')';
        $out[] = '  SELECT ' . implode(', ', array_map($q, $row)) . ' FROM DUAL WHERE @known IS NULL AND @match IS NULL;';
        $out[] = 'SET @artist := COALESCE(@known, @match, LAST_INSERT_ID());';
        $out[] = 'INSERT INTO art_import_sources (SourceKey, RecordType, ArtistID, PaintingID, Outcome, SourceURL, SnapshotFile, SnapshotSHA256, ImageSHA256, Extracted, ImportedBy)';
        $out[] = '  SELECT ' . $q($artist['key']) . ", 'artist', @artist, NULL, IF(@match IS NULL, 'created', 'matched'), " . $q($artist['url']) . ', ' . $q($artist['page']) . ', ' . $q($artist['page_sha256']) . ', NULL, '
            . $q(wikiart_provenance($artist)) . ", 'sql' FROM DUAL WHERE @known IS NULL;";
        foreach ($artist['paintings'] as $painting) {
            $prow = wikiart_painting_row($painting);
            $image = wikiart_sql_image_name($painting);
            $out[] = '-- Painting: ' . $painting['title'] . ' (' . $painting['year'] . '), ' . $painting['url'] . '; image ' . $painting['image']['file'] . ' -> ' . $image . '.jpg';
            $out[] = 'SET @known := (SELECT PaintingID FROM art_import_sources WHERE SourceKey = ' . $q($painting['key']) . ');';
            $out[] = 'SET @match := (SELECT PaintingID FROM Paintings WHERE ArtistID = @artist AND Title = ' . $q($prow['Title']) . ' AND YearOfWork = ' . $q($prow['YearOfWork']) . ');';
            $out[] = 'SET @gallery := (SELECT MIN(GalleryID) FROM Galleries WHERE GalleryName = ' . $q($painting['gallery_name']) . ' HAVING COUNT(*) = 1);';
            $out[] = 'SET @genre := (SELECT MIN(GenreID) FROM Genres WHERE GenreName = ' . $q($painting['styles'][0] ?? null) . ' HAVING COUNT(*) = 1);';
            $columns = array_merge(array_keys($prow), ['ArtistID', 'ImageFileName', 'GalleryID']);
            $out[] = 'INSERT INTO Paintings (' . implode(', ', $columns) . ')';
            $out[] = '  SELECT ' . implode(', ', array_map($q, array_values($prow))) . ', @artist, ' . $q($image) . ', @gallery FROM DUAL WHERE @known IS NULL AND @match IS NULL;';
            $out[] = 'SET @created := (@known IS NULL AND @match IS NULL);';
            $out[] = 'SET @painting := COALESCE(@known, @match, LAST_INSERT_ID());';
            $out[] = 'INSERT INTO PaintingGenres (PaintingID, GenreID) SELECT @painting, @genre FROM DUAL WHERE @created AND @genre IS NOT NULL;';
            $out[] = 'INSERT INTO art_import_sources (SourceKey, RecordType, ArtistID, PaintingID, Outcome, SourceURL, SnapshotFile, SnapshotSHA256, ImageSHA256, Extracted, ImportedBy)';
            $out[] = '  SELECT ' . $q($painting['key']) . ", 'painting', NULL, @painting, IF(@match IS NULL, 'created', 'matched'), " . $q($painting['url']) . ', ' . $q($painting['page']) . ', '
                . $q($painting['page_sha256']) . ', ' . $q($painting['image']['sha256']) . ', ' . $q(wikiart_provenance($painting, $artist)) . ", 'sql' FROM DUAL WHERE @known IS NULL;";
        }
    }
    $out[] = '';
    $out[] = 'COMMIT;';
    return implode("\n", $out) . "\n";
}
