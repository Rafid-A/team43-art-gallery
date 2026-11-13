<?php
require_once __DIR__ . '/artwork-media.php';

// Stable bundled filenames identify the selection; captions and attribution
// always come from the current records, never from assumed seed IDs.
const LANDING_ARTWORK_FILES = ['019130', '016010', '017040', '106010', '017060'];

function landing_select_artworks(array $records, ?callable $imageSource = null)
{
    $imageSource ??= 'artwork_media_source';
    $byFile = [];
    foreach ($records as $record) {
        $name = (string)($record['ImageFileName'] ?? '');
        if (!in_array($name, LANDING_ARTWORK_FILES, true) || isset($byFile[$name])
            || (int)($record['PaintingID'] ?? 0) < 1 || trim((string)($record['Title'] ?? '')) === '') continue;
        $source = $imageSource($name);
        if (!$source) continue;
        $record['image'] = $source;
        $byFile[$name] = $record;
    }
    $selected = [];
    foreach (LANDING_ARTWORK_FILES as $name) if (isset($byFile[$name])) $selected[$name] = $byFile[$name];
    return $selected;
}

function landing_artworks(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT p.PaintingID, p.Title, p.ImageFileName, p.YearOfWork, p.CopyrightText,
        a.ArtistID, a.FirstName, a.LastName, a.YearOfBirth, a.YearOfDeath, a.Nationality, a.Details
        FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID
        WHERE p.ImageFileName IN (?, ?, ?, ?, ?) ORDER BY p.PaintingID');
    $stmt->execute(LANDING_ARTWORK_FILES);
    return landing_select_artworks($stmt->fetchAll());
}

// The rotating strip (Task 8): one work per artist, in date order, skipping the
// featured works and any record whose image file is missing or unusable.
function landing_strip_artworks(PDO $pdo, array $exclude, int $limit = 14)
{
    $rows = $pdo->query("SELECT p.PaintingID, p.Title, p.ImageFileName, p.YearOfWork, a.ArtistID, a.FirstName, a.LastName
        FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID
        WHERE COALESCE(p.ImageFileName, '') <> '' ORDER BY p.YearOfWork IS NULL, p.YearOfWork, p.PaintingID LIMIT 400")->fetchAll();
    $works = $artists = [];
    foreach ($rows as $row) {
        $artist = (int)($row['ArtistID'] ?? 0);
        if (count($works) >= $limit || in_array($row['ImageFileName'], $exclude, true) || ($artist && isset($artists[$artist]))) continue;
        $source = artwork_media_source($row['ImageFileName']);
        if (!$source) continue;
        $row['image'] = $source;
        $works[] = $row;
        if ($artist) $artists[$artist] = true;
    }
    return $works;
}

function landing_artist_name(array $work)
{
    return trim(($work['FirstName'] ?? '') . ' ' . ($work['LastName'] ?? '')) ?: 'Unknown artist';
}

function landing_image(array $work, $eager = false, $hero = false, $sizes = null)
{
    $source = $work['image'];
    echo '<img src="' . e($source['url']) . '" srcset="' . e(artwork_media_srcset($source))
        . '" sizes="' . ($sizes ?? ($hero ? '(max-width: 767px) 88vw, (max-width: 1199px) 48vw, 647px' : '(max-width: 767px) 88vw, 42vw'))
        . '" alt="' . e($work['Title']) . '" width="' . (int)$source['width'] . '" height="' . (int)$source['height']
        . '" loading="' . ($eager ? 'eager' : 'lazy') . '" decoding="async"' . ($eager ? ' fetchpriority="high"' : '') . '>';
}

function landing_biography(array $work)
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($work['Details'] ?? ''))) ?? '');
    if ($text === '') return '';
    if (text_length($text) <= 320) return $text;
    preg_match('/^.{1,320}/us', $text, $match);
    return preg_replace('/\s+\S*$/u', '', $match[0] ?? '') . '…';
}
