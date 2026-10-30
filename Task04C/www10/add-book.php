<?php
// www10: show the "add a book" form (GET) and insert the book (POST).
require __DIR__ . '/config.php';

$fields = ['title', 'author', 'isbn', 'publisher', 'year', 'price'];
$book   = array_fill_keys($fields, '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and trim the submitted values.
    foreach ($fields as $f) {
        $book[$f] = trim((string) ($_POST[$f] ?? ''));
    }

    // Server-side validation.
    if ($book['title'] === '')     $errors['title']     = 'Title is required.';
    if ($book['author'] === '')    $errors['author']    = 'Author is required.';
    if ($book['publisher'] === '') $errors['publisher'] = 'Publisher is required.';
    if (!preg_match('/^[0-9Xx-]{10,17}$/', $book['isbn'])) {
        $errors['isbn'] = 'ISBN must be 10-17 characters (digits, dashes, X).';
    }
    $year = filter_var($book['year'], FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1000, 'max_range' => (int) date('Y')]]);
    if ($year === false) $errors['year'] = 'Year must be a whole number up to ' . date('Y') . '.';
    $price = filter_var($book['price'], FILTER_VALIDATE_FLOAT);
    if ($price === false || $price < 0 || $price > 9999.99) {
        $errors['price'] = 'Price must be a number from 0 to 9999.99.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare('INSERT INTO books (title, author, isbn, publisher, year, price)
                                   VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$book['title'], $book['author'], $book['isbn'],
                            $book['publisher'], $year, $price]);
            // Post/Redirect/Get: go back to the list so a refresh won't re-submit.
            header('Location: view-books.php?msg=added');
            exit;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {      // duplicate key (ISBN is UNIQUE)
                $errors['isbn'] = 'A book with this ISBN already exists.';
            } else {
                throw $e;
            }
        }
    }
}

// Small helper to print a field's error message, if any.
function err(array $errors, string $f): string
{
    return isset($errors[$f]) ? '<span class="error">' . h($errors[$f]) . '</span>' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>www10 - Add a Book</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <h1>Classic Books</h1>
    <nav><a href="view-books.php">View books</a> <a href="add-book.php" class="active">Add a book</a></nav>
</header>
<main>
    <h2>Add a book</h2>
    <?php if ($errors): ?>
        <p class="flash bad">Please fix the errors below.</p>
    <?php endif; ?>

    <form method="post" action="add-book.php" class="book-form">
        <label>Title <input type="text" name="title" maxlength="200" value="<?= h($book['title']) ?>"></label>
        <?= err($errors, 'title') ?>
        <label>Author <input type="text" name="author" maxlength="120" value="<?= h($book['author']) ?>"></label>
        <?= err($errors, 'author') ?>
        <label>ISBN <input type="text" name="isbn" maxlength="17" placeholder="978-0000000000" value="<?= h($book['isbn']) ?>"></label>
        <?= err($errors, 'isbn') ?>
        <label>Publisher <input type="text" name="publisher" maxlength="120" value="<?= h($book['publisher']) ?>"></label>
        <?= err($errors, 'publisher') ?>
        <label>Year <input type="number" name="year" value="<?= h($book['year']) ?>"></label>
        <?= err($errors, 'year') ?>
        <label>Price ($) <input type="number" name="price" step="0.01" min="0" value="<?= h($book['price']) ?>"></label>
        <?= err($errors, 'price') ?>
        <div class="actions">
            <button type="submit">Add book</button>
            <a href="view-books.php">Cancel</a>
        </div>
    </form>
</main>
</body>
</html>
