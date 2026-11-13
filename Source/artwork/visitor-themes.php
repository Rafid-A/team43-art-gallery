<?php
// Include-only presentation: no directly executable partial page.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/artwork-media.php';

function visitor_theme_definitions()
{
    return [
        'landscape' => ['type' => 'subjects', 'name' => 'Landscape', 'label' => 'Subject'],
        'people' => ['type' => 'subjects', 'name' => 'People', 'label' => 'Subject'],
        'impressionism' => ['type' => 'genres', 'name' => 'Impressionism', 'label' => 'Movement'],
    ];
}

// Keep selection independent of PDO so sparse records and missing media can be
// checked without changing collection rows. A theme remains useful without art.
function visitor_select_themes(array $records, array $candidates, ?callable $imageSource = null)
{
    $imageSource ??= 'artwork_media_source';
    $themes = [];
    foreach (visitor_theme_definitions() as $key => $definition) {
        foreach ($records as $record) {
            if (($record['ThemeType'] ?? '') !== $definition['type']
                || strcasecmp((string)($record['ThemeName'] ?? ''), $definition['name']) !== 0
                || (int)($record['ThemeID'] ?? 0) < 1 || (int)($record['WorkCount'] ?? 0) < 1) continue;
            $record['key'] = $key;
            $record['label'] = $definition['label'];
            $record['preview'] = null;
            foreach ($candidates as $painting) {
                if (($painting['ThemeType'] ?? '') !== $record['ThemeType']
                    || (int)($painting['ThemeID'] ?? 0) !== (int)$record['ThemeID']
                    || (int)($painting['PaintingID'] ?? 0) < 1
                    || trim((string)($painting['Title'] ?? '')) === '') continue;
                $source = $imageSource((string)($painting['ImageFileName'] ?? ''));
                if (!$source) continue;
                $painting['image'] = $source;
                $record['preview'] = $painting;
                break;
            }
            $themes[] = $record;
            break;
        }
    }
    return $themes;
}

function visitor_themes(PDO $pdo)
{
    $definitions = visitor_theme_definitions();
    $names = array_column($definitions, 'name');
    $stmt = $pdo->prepare("SELECT 'subjects' AS ThemeType, s.SubjectID AS ThemeID,
        s.SubjectName AS ThemeName, COUNT(DISTINCT p.PaintingID) AS WorkCount
        FROM Subjects s JOIN PaintingSubjects j ON j.SubjectID=s.SubjectID
        JOIN Paintings p ON p.PaintingID=j.PaintingID WHERE s.SubjectName IN (?, ?)
        GROUP BY s.SubjectID, s.SubjectName
        UNION ALL
        SELECT 'genres', g.GenreID, g.GenreName, COUNT(DISTINCT p.PaintingID)
        FROM Genres g JOIN PaintingGenres j ON j.GenreID=g.GenreID
        JOIN Paintings p ON p.PaintingID=j.PaintingID WHERE g.GenreName=?
        GROUP BY g.GenreID, g.GenreName");
    $stmt->execute($names);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$records) return [];

    // Fetch candidates together, rather than trusting MIN(id) to have a file or
    // querying once per theme. Duplicate relationship rows do not repeat art.
    $stmt = $pdo->prepare("SELECT DISTINCT 'subjects' AS ThemeType, s.SubjectID AS ThemeID,
        p.PaintingID, p.Title, p.ImageFileName, p.YearOfWork, p.CopyrightText, a.FirstName, a.LastName
        FROM Subjects s JOIN PaintingSubjects j ON j.SubjectID=s.SubjectID
        JOIN Paintings p ON p.PaintingID=j.PaintingID LEFT JOIN Artists a ON a.ArtistID=p.ArtistID
        WHERE s.SubjectName IN (?, ?)
        UNION ALL
        SELECT DISTINCT 'genres', g.GenreID, p.PaintingID, p.Title, p.ImageFileName,
        p.YearOfWork, p.CopyrightText, a.FirstName, a.LastName
        FROM Genres g JOIN PaintingGenres j ON j.GenreID=g.GenreID
        JOIN Paintings p ON p.PaintingID=j.PaintingID LEFT JOIN Artists a ON a.ArtistID=p.ArtistID
        WHERE g.GenreName=? ORDER BY PaintingID, ThemeID");
    $stmt->execute($names);
    return visitor_select_themes($records, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function visitor_theme_routes(array $themes, $headingId = 'theme-heading')
{
    if (!$themes) return;
    ?>
<section class="thematic-discovery" id="thematic-discovery" aria-labelledby="<?= e($headingId) ?>">
    <div class="theme-heading"><h2 id="<?= e($headingId) ?>">Subjects and movements</h2><p>Artworks grouped by subject or movement.</p></div>
    <div class="theme-routes" data-theme-total="<?= count($themes) ?>">
    <?php foreach ($themes as $theme): $preview = $theme['preview']; ?>
        <article class="theme-route" data-theme-route="<?= e($theme['key']) ?>" data-theme-count="<?= (int)$theme['WorkCount'] ?>">
            <a class="theme-route-link" href="objects.php?<?= e(http_build_query(['type' => $theme['ThemeType'], 'id' => (int)$theme['ThemeID']])) ?>">
                <div class="theme-preview">
                <?php if ($preview): $source = $preview['image']; ?>
                    <img src="<?= e($source['url']) ?>" srcset="<?= e(artwork_media_srcset($source)) ?>" sizes="(max-width: 767px) 88vw, 44vw" alt="<?= e($preview['Title']) ?>" width="<?= (int)$source['width'] ?>" height="<?= (int)$source['height'] ?>" loading="lazy" decoding="async">
                <?php else: ?><span>Explore the artworks.<br>Preview image unavailable.</span><?php endif; ?>
                </div>
                <div class="theme-copy"><p class="theme-type"><?= e($theme['label']) ?></p><h3><?= e($theme['ThemeName']) ?></h3><p class="theme-count"><?= (int)$theme['WorkCount'] ?> <?= (int)$theme['WorkCount'] === 1 ? 'artwork' : 'artworks' ?></p>
                <?php if ($preview): ?><p class="theme-caption"><?= e($preview['Title']) ?><?php if ($preview['YearOfWork']): ?>, <?= e($preview['YearOfWork']) ?><?php endif; ?><span><?= e(trim(($preview['FirstName'] ?? '') . ' ' . ($preview['LastName'] ?? '')) ?: 'Artist not recorded') ?></span><small><?= e($preview['CopyrightText'] ?: 'See image credits for attribution') ?></small></p><?php endif; ?>
                </div>
            </a>
        </article>
    <?php endforeach; ?>
    </div>
</section>
    <?php
}
