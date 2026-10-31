<?php
// Check HTTP, PHP, application credentials and initialized tables together.
require __DIR__ . '/artwork/config.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = new PDO(
        'mysql:host=' . DBHOST . ';port=' . DBPORT . ';dbname=' . DBNAME,
        DBUSER,
        DBPASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );
    $pdo->query('SELECT PaintingID FROM Paintings LIMIT 1');
    $pdo->query('SELECT username FROM art_users LIMIT 1');
    echo "ready\n";
} catch (Throwable $error) {
    http_response_code(503);
    echo "not ready\n";
}
