<?php
// www10: delete one book by id. Only accepts POST from the list page.
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed: use the Delete button on the book list.');
}

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    exit('Invalid book id.');
}

$stmt = db()->prepare('DELETE FROM books WHERE id = ?');
$stmt->execute([$id]);

// rowCount() tells us whether a row was actually removed.
header('Location: view-books.php?msg=' . ($stmt->rowCount() ? 'deleted' : 'notfound'));
exit;
