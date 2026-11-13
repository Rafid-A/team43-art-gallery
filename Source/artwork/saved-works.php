<?php
// "My collection": artworks a signed-in user has saved. Table from migration 10.
// Without that upgrade the feature is hidden and its endpoint answers 503;
// the rest of the gallery keeps working.

function saved_works_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns c
        JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
        WHERE c.table_schema = DATABASE() AND c.table_name = 'art_saved_works'
          AND t.table_type = 'BASE TABLE' AND t.engine = 'InnoDB'")->fetchAll(PDO::FETCH_COLUMN);
    return $ready = !array_diff(['username', 'PaintingID', 'SavedAt'], $columns);
}

function saved_work_exists($username, $paintingId): bool
{
    return (bool)rows('SELECT 1 FROM art_saved_works WHERE username=? AND PaintingID=?', [$username, $paintingId])->fetchColumn();
}

function saved_works($username): array
{
    return rows('SELECT p.*, a.FirstName, a.LastName FROM art_saved_works s
        JOIN Paintings p ON p.PaintingID=s.PaintingID LEFT JOIN Artists a ON a.ArtistID=p.ArtistID
        WHERE s.username=? ORDER BY s.SavedAt DESC, s.PaintingID DESC', [$username])->fetchAll();
}

// Only same-site pages this feature links back to; everything else falls back.
function saved_work_return($value, $paintingId): string
{
    if (is_string($value) && preg_match('/\A(?:painting\.php\?[A-Za-z0-9_.%+=&-]{1,2000}|account\.php(?:#my-collection)?)\z/D', $value)) return $value;
    return 'painting.php?id=' . (int)$paintingId;
}

function saved_work_form($paintingId, $saved, $title, $return, $compact = false)
{
    $label = $saved ? 'Remove from my collection' : 'Save to my collection';
    $class = $compact ? 'quiet' : ($saved ? '' : 'primary');
?>
<form class="saved-work-form" action="save-work.php" method="post">
    <input type="hidden" name="csrf" value="<?= e(token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$paintingId ?>">
    <input type="hidden" name="action" value="<?= $saved ? 'remove' : 'save' ?>">
    <input type="hidden" name="return" value="<?= e($return) ?>">
    <button class="<?= $class ?>" aria-label="<?= e($label . ': ' . $title) ?>"><?= e($label) ?></button>
</form>
<?php
}
