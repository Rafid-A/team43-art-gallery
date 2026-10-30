<?php
/*
 * delete-artwork.php - CS 4485 Task04C Part (3)(c): deletes one artwork.
 * Phuc Tran (pmt220000), Team 43
 *
 * Called by the Delete button on art-main.php (POST only, with the CSRF token).
 * Other tables point at a painting through PaintingID (PaintingGenres,
 * PaintingSubjects, Reviews, OrderDetails), so those rows are deleted first and
 * everything runs in one transaction: either all of it is deleted or nothing is.
 * The picture file stays on the server, so it can be picked again on artwork.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('art-main.php');
}

// Where to go back to: the artist / page the user was looking at.
$returnId   = filter_input(INPUT_POST, 'return_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$returnPage = filter_input(INPUT_POST, 'return_page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$back = is_int($returnId) ? 'art-main.php?id=' . $returnId
      : 'art-main.php' . (is_int($returnPage) && $returnPage > 1 ? '?page=' . $returnPage : '');

if (!csrfValid()) {
    setFlash('error', 'The delete request expired. Please try again.');
    redirect($back);
}

$paintingId = filter_input(INPUT_POST, 'painting_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($paintingId)) {
    setFlash('error', 'No artwork was selected to delete.');
    redirect($back);
}

$pdo = getPDO();
try {
    $pdo->beginTransaction();

    // Lock the painting row and get its title for the message.
    $stmt = $pdo->prepare('SELECT Title FROM Paintings WHERE PaintingID = :id FOR UPDATE');
    $stmt->bindValue(':id', $paintingId, PDO::PARAM_INT);
    $stmt->execute();
    $title = $stmt->fetchColumn();

    if ($title === false) {
        $pdo->rollBack();
        setFlash('error', 'That artwork does not exist (it may already have been deleted).');
        redirect($back);
    }

    // Dependent rows first, then the painting itself.
    $removed = [];
    foreach (['PaintingGenres', 'PaintingSubjects', 'Reviews', 'OrderDetails', 'Paintings'] as $table) {
        // Table names come from the fixed list above, never from user input.
        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE PaintingID = :id");
        $stmt->bindValue(':id', $paintingId, PDO::PARAM_INT);
        $stmt->execute();
        $removed[$table] = $stmt->rowCount();
    }

    $pdo->commit();
} catch (PDOException $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete-artwork.php: ' . $ex->getMessage());
    setFlash('error', 'The artwork could not be deleted (database error). Nothing was changed.');
    redirect($back);
}

$related = $removed['PaintingGenres'] + $removed['PaintingSubjects'] + $removed['Reviews'] + $removed['OrderDetails'];
setFlash('success', "Artwork '$title' deleted"
    . ($related ? " (with $related related genre/subject/review/order rows)." : '.'));
redirect($back);
