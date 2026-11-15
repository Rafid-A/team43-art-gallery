<?php
require_once __DIR__ . '/library.php';
require_admin();
require_post();

$id = filter_var(input('painting_id'), FILTER_VALIDATE_INT);
$context = artwork_context($_POST);
$returnUrl = artwork_collection_url($context);
if (!$id || $id < 1) {
    flash('Choose a valid artwork.', 'error');
    redirect($returnUrl);
}

$pdo = db();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT Title FROM Paintings WHERE PaintingID = ? FOR UPDATE');
    $stmt->execute([$id]);
    $painting = $stmt->fetch();

    if (!$painting)
        throw new RuntimeException('Artwork not found. It may already have been deleted.');

    $stmt = $pdo->prepare('SELECT PaintingID FROM OrderDetails WHERE PaintingID = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0)
        throw new RuntimeException('This artwork is in an order and cannot be deleted.');

    foreach (['PaintingGenres', 'PaintingSubjects', 'Reviews'] as $table) {
        $stmt = $pdo->prepare("DELETE FROM $table WHERE PaintingID = ?");
        $stmt->execute([$id]);
    }

    $stmt = $pdo->prepare('DELETE FROM Paintings WHERE PaintingID = ?');
    $stmt->execute([$id]);
    $pdo->commit();
    flash('Deleted artwork: ' . $painting['Title'] . '.');
} catch (RuntimeException $ex) {
    if ($pdo->inTransaction())
        $pdo->rollBack();

    if ($ex instanceof PDOException) {
        error_log($ex->getMessage());
        flash('The artwork could not be deleted. Please try again.', 'error');
    } else
        flash($ex->getMessage(), 'error');
}

redirect($returnUrl);
