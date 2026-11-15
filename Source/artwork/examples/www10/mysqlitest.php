<?php // mysqlitest.php
require_once 'login.php';
$connection = new mysqli(
    $db_hostname,
    $db_username,
    $db_password,
    $db_database
);

if ($connection->connect_error)
    die($connection->connect_error);

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
    $isbn = get_post($connection, 'isbn');
    $query = "DELETE FROM classics WHERE isbn='$isbn'";
    $result = $connection->query($query);

    if (!$result)
        echo "DELETE failed: $query<br>" .
            $connection->error . "<br><br>";
}

if (
    isset($_POST['author']) &&
    isset($_POST['title']) &&
    isset($_POST['category']) &&
    isset($_POST['year']) &&
    isset($_POST['isbn'])
) {
    $author = get_post($connection, 'author');
    $title = get_post($connection, 'title');
    $category = get_post($connection, 'category');
    $year = get_post($connection, 'year');
    $isbn = get_post($connection, 'isbn');
    $query = "INSERT INTO classics VALUES" .
        "('$author', '$title', '$category', '$year', '$isbn')";
    $result = $connection->query($query);

    if (!$result)
        echo "INSERT failed: $query<br>" .
            $connection->error . "<br><br>";
}

echo <<<_END
  <form action="mysqlitest.php" method="post" data-pending="Adding…"><pre>
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
$result = $connection->query($query);

if (!$result)
    die("Database access failed: " . $connection->error);

$rows = $result->num_rows;

for ($j = 0; $j < $rows; ++$j) {
    $result->data_seek($j);
    $row = $result->fetch_array(MYSQLI_NUM);
    $r0 = htmlspecialchars((string) $row[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r1 = htmlspecialchars((string) $row[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r2 = htmlspecialchars((string) $row[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r3 = htmlspecialchars((string) $row[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r4 = htmlspecialchars((string) $row[4], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<_END
  <pre>
    Author $r0
     Title $r1
  Category $r2
      Year $r3
      ISBN $r4
  </pre>
  <form action="mysqlitest.php" method="post"
        data-confirm="Delete “{$r1}”?" data-pending="Deleting…">
  <input type="hidden" name="delete" value="yes">
  <input type="hidden" name="isbn" value="$r4">
  <input type="submit" value="DELETE RECORD"></form>
_END;
}

$result->close();
$connection->close();
echo '</main></body></html>';

function get_post($connection, $var) {
    return $connection->real_escape_string($_POST[$var]);
}
?>
