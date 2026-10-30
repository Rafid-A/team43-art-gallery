<?php
// Database connection settings for the www10 books app.
// host=localhost connects through the local MySQL/MariaDB socket.
define('DB_DSN',  'mysql:host=localhost;dbname=www10db;charset=utf8mb4');
define('DB_USER', 'testuser');
define('DB_PASS', 'mypassword');

// Return a shared PDO connection (exceptions on error, real prepared statements).
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// Shorthand for HTML-escaping output.
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
