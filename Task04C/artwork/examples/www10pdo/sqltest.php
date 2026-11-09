<?php // sqltest.php
require_once 'login.php';

try {
    $pdo = new PDO($attr, $user, $pass, $opts);
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int) $e->getCode());
}

echo <<<_END
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Book Records</title>
  <script src="../sample-forms.js" defer></script>
</head>
<body>
<main>
_END;

if (isset($_POST['delete']) && isset($_POST['isbn'])) {
    $isbn = sanitize_post_value($pdo, 'isbn');
    $query = "DELETE FROM classics WHERE isbn=$isbn";
    $result = $pdo->query($query);
}

if (
    isset($_POST['author']) &&
    isset($_POST['title']) &&
    isset($_POST['category']) &&
    isset($_POST['year']) &&
    isset($_POST['isbn'])
) {
    $author = sanitize_post_value($pdo, 'author');
    $title = sanitize_post_value($pdo, 'title');
    $category = sanitize_post_value($pdo, 'category');
    $year = sanitize_post_value($pdo, 'year');
    $isbn = sanitize_post_value($pdo, 'isbn');

    $query = "INSERT INTO classics VALUES" .
        "($author, $title, $category, $year, $isbn)";
    $result = $pdo->query($query);
}

echo <<<_END
  <form action="sqltest.php" method="post" data-pending="Adding…"><pre>
    <label for="book-author">Author</label> <input type="text" id="book-author" name="author"
           autocomplete="off">
     <label for="book-title">Title</label> <input type="text" id="book-title" name="title"
           autocomplete="off">
  <label for="book-category">Category</label> <input type="text" id="book-category" name="category"
           autocomplete="off">
      <label for="book-year">Year</label> <input type="number" id="book-year" name="year"
           autocomplete="off" inputmode="numeric" step="1">
      <label for="book-isbn">ISBN</label> <input type="text" id="book-isbn" name="isbn"
           autocomplete="off" spellcheck="false">
           <input type="submit" value="ADD RECORD">
  </pre></form>
_END;

$query = "SELECT * FROM classics";
$result = $pdo->query($query);

while ($row = $result->fetch()) {
    $r0 = htmlspecialchars((string) $row['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r1 = htmlspecialchars((string) $row['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r2 = htmlspecialchars((string) $row['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r3 = htmlspecialchars((string) $row['year'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r4 = htmlspecialchars((string) $row['isbn'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<_END
  <pre>
    Author $r0
     Title $r1
  Category $r2
      Year $r3
      ISBN $r4
  </pre>
  <form action="sqltest.php" method="post"
        data-confirm="Delete “{$r1}”?" data-pending="Deleting…">
  <input type='hidden' name='delete' value='yes'>
  <input type='hidden' name='isbn' value='$r4'>
  <input type='submit' value='DELETE RECORD'></form>
_END;
}

echo '</main></body></html>';

function sanitize_post_value($pdo, $var) {
    return $pdo->quote($_POST[$var]);
}
?>
