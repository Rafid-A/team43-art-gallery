<?php
// www10: list every book in the table, with a Delete button per row.
require __DIR__ . '/config.php';

$books = db()->query('SELECT id, title, author, isbn, publisher, year, price
                      FROM books ORDER BY id')->fetchAll();

// One-line status message passed back from add/delete via the query string.
$messages = [
    'added'    => 'Book added.',
    'deleted'  => 'Book deleted.',
    'notfound' => 'That book no longer exists.',
];
$msg = $messages[$_GET['msg'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>www10 - Book List</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <h1>Classic Books</h1>
    <nav><a href="view-books.php" class="active">View books</a> <a href="add-book.php">Add a book</a></nav>
</header>
<main>
    <?php if ($msg !== ''): ?>
        <p class="flash"><?= h($msg) ?></p>
    <?php endif; ?>

    <p><?= count($books) ?> book(s) in table <code>www10db.books</code>.</p>

    <table>
        <thead>
        <tr>
            <th>ID</th><th>Title</th><th>Author</th><th>ISBN</th>
            <th>Publisher</th><th>Year</th><th class="num">Price</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $b): ?>
            <tr>
                <td><?= h($b['id']) ?></td>
                <td><?= h($b['title']) ?></td>
                <td><?= h($b['author']) ?></td>
                <td><?= h($b['isbn']) ?></td>
                <td><?= h($b['publisher']) ?></td>
                <td><?= h($b['year']) ?></td>
                <td class="num">$<?= h(number_format((float) $b['price'], 2)) ?></td>
                <td>
                    <!-- Deleting changes data, so it is a POST, not a link. -->
                    <form method="post" action="delete-book.php"
                          onsubmit="return confirm('Delete this book?');">
                        <input type="hidden" name="id" value="<?= h($b['id']) ?>">
                        <button type="submit" class="danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$books): ?>
            <tr><td colspan="8" class="empty">No books yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</main>
</body>
</html>
