<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/subject-schema.php';
require_once __DIR__ . '/era-schema.php';

// Existing Docker volumes do not rerun initialization SQL. Pause before any
// SubjectID query until the explicit upgrade completes; retain login redirects.
if (!empty($_SESSION['user']) && !subject_schema_ready(db())) {
    http_response_code(503);
    header('Retry-After: 60');
    page_start('Database upgrade required');
    echo '<h1>Database upgrade required</h1><p>The gallery is paused until its database upgrade is completed. Ask the person running this local gallery to follow the existing-database upgrade steps in the runtime guide. Existing data has not been reset.</p>';
    page_end();
    exit;
}

function era_identity_ready()
{
    return era_identity_status() === 'ready';
}

function era_identity_status()
{
    static $status = null;
    if ($status === null) $status = era_schema_status(db());
    return $status;
}

// SQL identifiers come only from this registry; request data is always bound.
function library_objects()
{
    return [
        'artists' => ['table' => 'Artists', 'key' => 'ArtistID', 'label' => 'Artists', 'one' => 'artist', 'name' => 'LastName',
            'fields' => ['FirstName' => ['First name', 50], 'LastName' => ['Last name', 50, 'required'], 'Nationality' => ['Nationality', 50], 'Gender' => ['Gender (one character, optional)', 1], 'YearOfBirth' => ['Year of birth', 0, 'year'], 'YearOfDeath' => ['Year of death', 0, 'year'], 'Details' => ['Biography', 20000, 'textarea'], 'ArtistLink' => ['Reference URL', 255, 'url']],
            'dependency' => ['Paintings', 'ArtistID']],
        'galleries' => ['table' => 'Galleries', 'key' => 'GalleryID', 'label' => 'Galleries', 'one' => 'gallery', 'name' => 'GalleryName',
            'fields' => ['GalleryName' => ['Gallery name', 255, 'required'], 'GalleryNativeName' => ['Native name', 255], 'GalleryCity' => ['City', 255], 'GalleryCountry' => ['Country', 255], 'Latitude' => ['Latitude', 0, 'latitude'], 'Longitude' => ['Longitude', 0, 'longitude'], 'GalleryWebSite' => ['Website URL', 255, 'url']],
            'dependency' => ['Paintings', 'GalleryID']],
        'genres' => ['table' => 'Genres', 'key' => 'GenreID', 'label' => 'Genres', 'one' => 'genre', 'name' => 'GenreName',
            'fields' => ['GenreName' => ['Genre name', 50, 'required'], 'Description' => ['Description', 20000, 'textarea'], 'Link' => ['Reference URL', 255, 'url']],
            'dependency' => ['PaintingGenres', 'GenreID']],
        // Unsupported schemas or conflicting identities stay view-only.
        'eras' => era_identity_status() !== 'unsupported'
            ? ['table' => 'Eras', 'key' => 'EraID', 'label' => 'Eras', 'one' => 'era', 'name' => 'EraName', 'order' => 'o.EraID',
                'fields' => ['EraName' => ['Era name', 255, 'required'], 'EraYears' => ['Years (text, for example 1400-1550)', 255]],
                'unique' => ['EraName', 'EraYears']] + (era_identity_ready() ? [] : [
                    'gate' => 'The reserved Era identities conflict with the approved mapping. Eras remain view-only; ask an administrator to inspect the records before any migration. Existing data must be preserved.'])
            : ['table' => 'Eras', 'key' => null, 'label' => 'Eras', 'one' => 'era', 'name' => 'EraName',
                'fields' => ['EraName' => ['Era name', 255], 'EraYears' => ['Years', 255]],
                'gate' => 'An Era identity upgrade is required before adding or deleting records. Ask an administrator to inspect the schema and follow the existing-database upgrade guide. Existing data must be preserved.'],
        'subjects' => ['table' => 'Subjects', 'key' => 'SubjectID', 'label' => 'Subjects', 'one' => 'subject', 'name' => 'SubjectName',
            'fields' => ['SubjectName' => ['Subject name', 255, 'required']],
            'dependency' => ['PaintingSubjects', 'SubjectID']],
        'painting-genres' => ['table' => 'PaintingGenres', 'key' => 'PaintingGenreID', 'label' => 'Artwork genres', 'one' => 'genre assignment', 'name' => 'PaintingGenreID',
            'fields' => ['PaintingID' => ['Artwork', 0, 'painting'], 'GenreID' => ['Genre', 0, 'genre']], 'relationship' => true],
        'painting-subjects' => ['table' => 'PaintingSubjects', 'key' => 'PaintingSubjectID', 'label' => 'Artwork subjects', 'one' => 'subject assignment', 'name' => 'PaintingSubjectID',
            'fields' => ['PaintingID' => ['Artwork', 0, 'painting'], 'SubjectID' => ['Subject', 0, 'subject']], 'relationship' => true],
    ];
}

function rows($sql, $values = [])
{
    $stmt = db()->prepare($sql);
    foreach (array_values($values) as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
    }
    $stmt->execute();
    return $stmt;
}

function get_text($name)
{
    return is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
}

function positive_id($value)
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : $id;
}

function artwork_context(array $source)
{
    $q = is_string($source['q'] ?? null) ? trim($source['q']) : '';
    $length = text_length($q);
    if ($length === false || $length > 255) $q = '';
    return [
        'artist' => positive_id($source['artist'] ?? ''),
        'q' => $q,
        'page' => max(1, positive_id($source['page'] ?? '')),
    ];
}

function artwork_collection_url(array $context)
{
    return 'art-main.php?' . http_build_query(artwork_context($context));
}

function object_title($type, $record)
{
    if ($type === 'artists') return trim(($record['FirstName'] ?? '') . ' ' . $record['LastName']);
    if (in_array($type, ['painting-genres', 'painting-subjects'], true)) {
        $classification = $type === 'painting-genres'
            ? ($record['GenreName'] ?? 'Unresolved genre ID ' . ($record['GenreID'] ?? ''))
            : ($record['SubjectName'] ?? 'Missing subject') . ' · #' . ($record['SubjectID'] ?? '');
        return ($record['Title'] ?? 'Missing artwork') . ' · ' . $classification;
    }
    return $record[library_objects()[$type]['name']] ?? 'Unnamed record';
}

function object_url($type, $record, $manage = false)
{
    $object = library_objects()[$type];
    $query = ['type' => $type];
    if ($object['key']) $query['id'] = $record[$object['key']];
    else {
        $query['name'] = $record[$object['name']] ?? '';
        if ($type === 'eras') $query['years'] = $record['EraYears'] ?? '';
    }
    if ($manage) $query['manage'] = 1;
    return 'objects.php?' . http_build_query($query);
}

function object_select($type)
{
    if ($type === 'artists') return 'SELECT o.*, (SELECT COUNT(*) FROM Paintings p WHERE p.ArtistID=o.ArtistID) AS WorkCount FROM Artists o';
    if ($type === 'galleries') return 'SELECT o.*, (SELECT COUNT(*) FROM Paintings p WHERE p.GalleryID=o.GalleryID) AS WorkCount FROM Galleries o';
    if ($type === 'genres') return 'SELECT o.*, (SELECT COUNT(DISTINCT PaintingID) FROM PaintingGenres j WHERE j.GenreID=o.GenreID) AS WorkCount FROM Genres o';
    if ($type === 'subjects') return 'SELECT o.*, (SELECT COUNT(DISTINCT PaintingID) FROM PaintingSubjects j WHERE j.SubjectID=o.SubjectID) AS WorkCount FROM Subjects o';
    if ($type === 'painting-genres') return 'SELECT o.*, p.Title, g.GenreName FROM PaintingGenres o LEFT JOIN Paintings p ON p.PaintingID=o.PaintingID LEFT JOIN Genres g ON g.GenreID=o.GenreID';
    if ($type === 'painting-subjects') return 'SELECT o.*, p.Title, s.SubjectName FROM PaintingSubjects o LEFT JOIN Paintings p ON p.PaintingID=o.PaintingID LEFT JOIN Subjects s ON s.SubjectID=o.SubjectID';
    return 'SELECT o.* FROM ' . library_objects()[$type]['table'] . ' o';
}

function field_values($object)
{
    $values = $errors = [];
    foreach ($object['fields'] as $name => $field) {
        [$label, $length] = $field;
        $kind = $field[2] ?? 'text';
        $value = input($name);
        $values[$name] = $value === '' ? null : $value;
        if ($kind === 'required' && $value === '') $errors[$name] = $label . ' is required.';
        if ($length && text_length($value) > $length) $errors[$name] = $label . ' must be at most ' . $length . ' characters.';
        if ($value !== '' && $kind === 'url' && !safe_link($value)) $errors[$name] = 'Enter a valid http or https URL.';
        if ($value !== '' && $kind === 'year' && (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < -10000 || (int)$value > 2100)) $errors[$name] = 'Enter a whole year from -10000 to 2100.';
        if (in_array($kind, ['latitude', 'longitude'], true) && $value !== '') {
            $limit = $kind === 'latitude' ? 90 : 180;
            if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > $limit) $errors[$name] = $label . ' must be between -' . $limit . ' and ' . $limit . '.';
        }
        if (in_array($kind, ['painting', 'genre', 'subject'], true)) {
            $values[$name] = positive_id($value);
            if (!$values[$name]) $errors[$name] = 'Choose an existing ' . $label . '.';
        }
    }
    if ($values['YearOfBirth'] !== null && $values['YearOfDeath'] !== null && (int)$values['YearOfDeath'] < (int)$values['YearOfBirth']) $errors['YearOfDeath'] = 'Year of death must not precede year of birth.';
    return [$values, $errors];
}

function artist_edit_fields()
{
    $fields = library_objects()['artists']['fields'];
    return array_intersect_key($fields, array_flip(['FirstName', 'LastName', 'Nationality', 'YearOfBirth', 'YearOfDeath']));
}

function painting_field_errors($values, $artistIds, $galleryIds)
{
    $errors = [];
    if ($values['title'] === '' || text_length($values['title']) > 255) $errors['title'] = 'Enter a title of 1 to 255 characters.';
    if (!in_array($values['artist_id'], $artistIds, true)) $errors['artist_id'] = 'Choose an artist.';
    if (!in_array($values['gallery_id'], $galleryIds, true)) $errors['gallery_id'] = 'Choose a gallery.';
    $year = filter_var($values['year'], FILTER_VALIDATE_INT);
    if ($year === false || $year < 1 || $year > 2100) $errors['year'] = 'Enter a year from 1 to 2100.';
    if ($values['medium'] === '' || text_length($values['medium']) > 255) $errors['medium'] = 'Enter a medium of 1 to 255 characters.';
    if (text_length($values['excerpt'] ?? '') > 2000) $errors['excerpt'] = 'Keep the description under 2001 characters.';
    return $errors;
}

function safe_link($url)
{
    return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
}

function lock_parent($table, $key, $id, $label)
{
    if (!rows("SELECT $key FROM $table WHERE $key = ? FOR UPDATE", [$id])->fetch()) throw new DomainException('The selected ' . $label . ' no longer exists. Refresh and choose again; no assignment was added.');
}

function add_object($type, $values)
{
    $object = library_objects()[$type];
    if (isset($object['gate'])) throw new DomainException($object['gate']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if (in_array($type, ['painting-genres', 'painting-subjects'], true)) {
            // Each parent is validated on its own. Locking the artwork row first also
            // serializes concurrent assignments to it, so the duplicate check below
            // cannot be raced into two identical pairs. That check is a plain read on
            // purpose: FOR UPDATE there takes gap locks that deadlock inserts for other
            // artworks, and its snapshot starts after these locks, so it sees any
            // assignment committed by the previous lock holder.
            lock_parent('Paintings', 'PaintingID', $values['PaintingID'], 'artwork');
            $classification = $type === 'painting-genres' ? ['Genres', 'GenreID', $values['GenreID'], 'genre'] : ['Subjects', 'SubjectID', $values['SubjectID'], 'subject'];
            [$table, $key, $classificationId, $label] = $classification;
            lock_parent($table, $key, $classificationId, $label);
            $assignmentTable = $type === 'painting-genres' ? 'PaintingGenres' : 'PaintingSubjects';
            $assignmentKey = $type === 'painting-genres' ? 'GenreID' : 'SubjectID';
            $assignmentId = $type === 'painting-genres' ? $values['GenreID'] : $values['SubjectID'];
            if (rows("SELECT {$assignmentKey} FROM {$assignmentTable} WHERE PaintingID=? AND {$assignmentKey}=?", [$values['PaintingID'], $assignmentId])->fetch()) throw new DomainException('This artwork already has that ' . $label . '.');
        }
        if (isset($object['unique'])) {
            // Locking read over the table, so two identical submissions cannot both pass.
            $where = implode(' AND ', array_map(fn($column) => "COALESCE($column, '') = ?", $object['unique']));
            if (rows('SELECT ' . $object['key'] . ' FROM ' . $object['table'] . " WHERE $where FOR UPDATE", array_map(fn($column) => (string)($values[$column] ?? ''), $object['unique']))->fetch())
                throw new DomainException('That ' . $object['one'] . ' already exists with the same name and years; no record was added.');
        }
        $columns = implode(', ', array_keys($object['fields']));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        rows('INSERT INTO ' . $object['table'] . " ($columns) VALUES ($placeholders)", array_values($values));
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function delete_object($type, $id)
{
    $object = library_objects()[$type];
    if (!$object['key'] || isset($object['gate'])) throw new DomainException($object['gate'] ?? 'This record type cannot be deleted.');
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $record = rows('SELECT * FROM ' . $object['table'] . ' WHERE ' . $object['key'] . '=? FOR UPDATE', [$id])->fetch();
        if (!$record) throw new DomainException('Record not found. It may already have been removed.');
        if (isset($object['dependency'])) {
            [$table, $column] = $object['dependency'];
            if (rows("SELECT $column FROM $table WHERE $column=? LIMIT 1 FOR UPDATE", [$id])->fetch()) throw new DomainException('This ' . $object['one'] . ' is still referenced. Remove its artwork assignments first; no records were changed.');
        }
        rows('DELETE FROM ' . $object['table'] . ' WHERE ' . $object['key'] . '=?', [$id]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function artwork_cards($paintings, array $context = [])
{
    $context = artwork_context($context);
    if (!$paintings) { echo '<p class="empty">No artworks here yet. Try another selection or add an artwork.</p>'; return; }
    echo '<div class="collection">';
    foreach ($paintings as $index => $painting) {
        $name = trim(($painting['FirstName'] ?? '') . ' ' . ($painting['LastName'] ?? '')) ?: 'Unknown artist';
        $detailUrl = 'painting.php?' . http_build_query(['id' => (int)$painting['PaintingID']] + $context);
        echo '<article class="art-card"><a class="art-image" href="' . e($detailUrl) . '">';
        artwork_image($painting, $index < 3);
        echo '</a><div class="art-copy"><h2><a href="' . e($detailUrl) . '">' . e($painting['Title']) . '</a></h2><p class="artist-name">' . e($name) . '</p><p class="art-meta">' . e($painting['YearOfWork'] ?: 'Year unknown') . '</p></div></article>';
    }
    echo '</div>';
}

function artwork_image($painting, $eager = false)
{
    $file = basename($painting['ImageFileName'] ?? '');
    if ($file === '' || !is_file(__DIR__ . '/images/art/works/square-medium/' . $file . '.jpg')) {
        echo '<span class="image-missing">Image unavailable<br><small>' . e($painting['Title']) . '</small></span>'; return;
    }
    [$width, $height] = artwork_image_size($file);
    echo '<img src="images/art/works/square-medium/' . e(rawurlencode($file)) . '.jpg" alt="' . e($painting['Title']) . '" width="' . $width . '" height="' . $height . '" loading="' . ($eager ? 'eager' : 'lazy') . '" decoding="async">';
}

function related_paintings($type, $id)
{
    $conditions = ['artists' => 'p.ArtistID=?', 'galleries' => 'p.GalleryID=?', 'genres' => 'EXISTS (SELECT 1 FROM PaintingGenres j WHERE j.PaintingID=p.PaintingID AND j.GenreID=?)', 'subjects' => 'EXISTS (SELECT 1 FROM PaintingSubjects j WHERE j.PaintingID=p.PaintingID AND j.SubjectID=?)'];
    if (!isset($conditions[$type])) return [];
    return rows('SELECT p.*, a.FirstName, a.LastName FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID WHERE ' . $conditions[$type] . ' ORDER BY p.YearOfWork, p.PaintingID', [$id])->fetchAll();
}
