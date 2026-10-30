<?php
/*
 * art-main.php - CS 4485 Task04C Part (3): main page of the Art Gallery.
 * Phuc Tran (pmt220000), Team 43
 *
 * Based on Lab 14 Exercise 14.11 (artist list on the left, paintings on the right,
 * prepared statements), extended with:
 *   (a) view  - "All artworks" (24 per page, newest first) or one artist's artworks (?id=)
 *   (c) delete - a red Delete button on every artwork (POST to delete-artwork.php)
 *   (b) add   - the Add Artwork button in the top bar opens artwork.php (Bonus)
 * Only logged-in users get here (auth.php).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$artistId    = getPositiveInt('id');           // selected artist, or null for all artworks
$page        = getPositiveInt('page') ?? 1;
$highlightId = $_SESSION['highlight'] ?? null;  // painting just added (shown with a green edge)
unset($_SESSION['highlight']);

$artists = [];
$paintings = [];
$selectedArtist = null;
$totalPaintings = 0;
$totalPages = 1;
$dbError = null;

try {
    $pdo = getPDO();

    /* Left column: every artist (not only the first 30 as in Lab 14) with a painting count. */
    $artists = $pdo->query(
        'SELECT a.ArtistID, a.FirstName, a.LastName, COUNT(p.PaintingID) AS NumPaintings
           FROM Artists a LEFT JOIN Paintings p ON p.ArtistID = a.ArtistID
          GROUP BY a.ArtistID, a.FirstName, a.LastName
          ORDER BY a.LastName, a.FirstName'
    )->fetchAll();
    $totalPaintings = (int) $pdo->query('SELECT COUNT(*) FROM Paintings')->fetchColumn();

    $select = 'SELECT p.PaintingID, p.ArtistID, p.ImageFileName, p.Title, p.YearOfWork,
                      p.Medium, p.Width, p.Height, p.Excerpt,
                      a.FirstName, a.LastName, g.GalleryName, g.GalleryCity
                 FROM Paintings p
                 LEFT JOIN Artists a   ON a.ArtistID  = p.ArtistID
                 LEFT JOIN Galleries g ON g.GalleryID = p.GalleryID';

    if ($artistId !== null) {
        /* (a) one artist's artworks - the Exercise 14.11 prepared statement */
        $stmt = $pdo->prepare('SELECT ArtistID, FirstName, LastName, Nationality, YearOfBirth, YearOfDeath
                                 FROM Artists WHERE ArtistID = :id');
        $stmt->bindValue(':id', $artistId, PDO::PARAM_INT);
        $stmt->execute();
        $selectedArtist = $stmt->fetch() ?: null;

        $stmt = $pdo->prepare($select . ' WHERE p.ArtistID = :id ORDER BY p.YearOfWork, p.Title');
        $stmt->bindValue(':id', $artistId, PDO::PARAM_INT);
        $stmt->execute();
        $paintings = $stmt->fetchAll();
    } else {
        /* (a) all artworks, newest first, PER_PAGE per page */
        $totalPages = max(1, (int) ceil($totalPaintings / PER_PAGE));
        $page = min($page, $totalPages);
        $stmt = $pdo->prepare($select . ' ORDER BY p.PaintingID DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();
        $paintings = $stmt->fetchAll();
    }
} catch (PDOException $ex) {
    error_log('art-main.php: ' . $ex->getMessage());
    $dbError = 'Could not read the art database. Is MySQL running and is art-small.sql imported?';
}

/* Link to this page keeping the current artist / page, used by the pagination. */
function pageLink(int $page): string
{
    return 'art-main.php?page=' . $page;
}

/* Lab 14's outputSinglePainting(), now with artist, details and a Delete button. */
function outputSinglePainting(array $row, ?int $artistId, int $page, ?int $highlightId): void
{
    $artistName = trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? ''));
    $details = [];   // already-escaped pieces: year, medium, size
    if (!empty($row['YearOfWork'])) $details[] = e($row['YearOfWork']);
    if (!empty($row['Medium']))     $details[] = e($row['Medium']);
    if (!empty($row['Width']) && !empty($row['Height'])) {
        $details[] = (int) $row['Width'] . ' &times; ' . (int) $row['Height'] . ' cm';
    }
    $isNew = $highlightId !== null && (int) $row['PaintingID'] === (int) $highlightId;
    ?>
    <div class="item painting<?= $isNew ? ' new-painting' : '' ?>" id="painting-<?= (int) $row['PaintingID'] ?>">
       <div class="image">
          <img src="<?= e(IMAGE_DIR . '/' . $row['ImageFileName'] . '.jpg') ?>" alt="<?= e($row['Title']) ?>"
               width="150" height="150">
       </div>
       <div class="content">
          <h4 class="header"><?= e($row['Title']) ?>
             <?php if ($isNew): ?><span class="ui green mini label">NEW</span><?php endif; ?>
          </h4>
          <div class="meta">
             <a href="art-main.php?id=<?= (int) $row['ArtistID'] ?>"><?= e($artistName ?: 'Unknown artist') ?></a><?php if ($details): ?><span class="dot">&middot;</span><?= implode(' &middot; ', $details) ?><?php endif; ?>
          </div>
          <p class="description"><?= excerptHtml($row['Excerpt']) ?></p>
          <div class="extra">
             <?php if (!empty($row['GalleryName'])): ?>
             <span class="gallery-name"><i class="university icon"></i><?= e($row['GalleryName']) ?><?= $row['GalleryCity'] ? ', ' . e($row['GalleryCity']) : '' ?></span>
             <?php endif; ?>
             <!-- (c) delete: POST form, the browser asks for confirmation first -->
             <form class="delete-form" method="post" action="delete-artwork.php"
                   data-title="<?= e($row['Title']) ?>"
                   onsubmit="return confirm('Delete the artwork &quot;' + this.dataset.title + '&quot;?');">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="painting_id" value="<?= (int) $row['PaintingID'] ?>">
                <input type="hidden" name="return_id" value="<?= $artistId ?? '' ?>">
                <input type="hidden" name="return_page" value="<?= $page ?>">
                <button type="submit" class="ui red small button delete-button">
                   <i class="trash alternate icon"></i> Delete
                </button>
             </form>
             <span class="painting-id">#<?= (int) $row['PaintingID'] ?> &middot; <?= e($row['ImageFileName']) ?>.jpg</span>
          </div>
       </div>
    </div>
    <?php
}

$activePage = 'main';
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Art Gallery - Artworks</title>
   <link href="semantic/semantic.css" rel="stylesheet">
   <link href="style.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>

<main class="ui container gallery-main">
   <?php if ($dbError): ?>
   <div class="ui negative message"><?= e($dbError) ?></div>
   <?php endif; ?>

   <div class="ui grid">
      <!-- Left: artist list (Exercise 14.11's outputArtists(), all artists) -->
      <div class="four wide column">
         <div class="ui segment artist-panel">
            <h4 class="ui header">Artists</h4>
            <div class="ui fluid vertical small menu artist-list">
               <a class="item<?= $artistId === null ? ' active' : '' ?>" href="art-main.php">
                  <strong>All artworks</strong>
                  <span class="ui label"><?= $totalPaintings ?></span>
               </a>
               <?php foreach ($artists as $a): ?>
               <a class="item<?= $artistId === (int) $a['ArtistID'] ? ' active' : '' ?>"
                  href="art-main.php?id=<?= (int) $a['ArtistID'] ?>">
                  <span class="artist-name"><?= e($a['LastName']) ?><?= $a['FirstName'] ? '<span class="first-name">, ' . e($a['FirstName']) . '</span>' : '' ?></span>
                  <span class="ui label"><?= (int) $a['NumPaintings'] ?></span>
               </a>
               <?php endforeach; ?>
            </div>
         </div>
      </div>

      <!-- Right: the artworks -->
      <div class="twelve wide column">
         <div class="ui segment">
            <div class="list-heading">
               <?php if ($artistId !== null): ?>
                  <?php if ($selectedArtist): ?>
                  <h2 class="ui header">
                     <?= e(trim($selectedArtist['FirstName'] . ' ' . $selectedArtist['LastName'])) ?>
                     <div class="sub header">
                        <?= e($selectedArtist['Nationality']) ?>
                        <?php if ($selectedArtist['YearOfBirth']): ?>
                           (<?= (int) $selectedArtist['YearOfBirth'] ?>&ndash;<?= (int) $selectedArtist['YearOfDeath'] ?: '' ?>)
                        <?php endif; ?>
                        &middot; <?= count($paintings) ?> artwork<?= count($paintings) === 1 ? '' : 's' ?>
                     </div>
                  </h2>
                  <?php else: ?>
                  <h2 class="ui header">Unknown artist</h2>
                  <?php endif; ?>
               <?php else: ?>
                  <h2 class="ui header">
                     All artworks
                     <div class="sub header"><?= $totalPaintings ?> artworks &middot; newest first &middot; page <?= $page ?> of <?= $totalPages ?></div>
                  </h2>
               <?php endif; ?>
               <a class="ui basic green button" href="artwork.php<?= $artistId !== null ? '?artist=' . $artistId : '' ?>">
                  <i class="plus icon"></i> Add Artwork
               </a>
            </div>

            <?php if (!$paintings && !$dbError): ?>
            <div class="ui message">No artworks to show<?= $artistId !== null ? ' for this artist' : '' ?>.
               <a href="artwork.php<?= $artistId !== null ? '?artist=' . $artistId : '' ?>">Add one</a>.</div>
            <?php endif; ?>

            <div class="ui divided items">
               <?php foreach ($paintings as $row) outputSinglePainting($row, $artistId, $page, $highlightId); ?>
            </div>

            <?php if ($artistId === null && $totalPages > 1): ?>
            <div class="pagination-wrap">
               <div class="ui pagination menu">
                  <a class="item<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= pageLink(max(1, $page - 1)) ?>">&laquo; Prev</a>
                  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                  <a class="item<?= $i === $page ? ' active' : '' ?>" href="<?= pageLink($i) ?>"><?= $i ?></a>
                  <?php endfor; ?>
                  <a class="item<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= pageLink(min($totalPages, $page + 1)) ?>">Next &raquo;</a>
               </div>
            </div>
            <?php endif; ?>
         </div>
      </div>
   </div>
</main>
</body>
</html>
