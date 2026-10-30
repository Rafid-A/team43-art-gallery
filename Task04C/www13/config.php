<?php
// Database connection settings for the www13 login exercise.
define('DB_DSN',  'mysql:host=localhost;dbname=www13db;charset=utf8mb4');
define('DB_USER', 'testuser');
define('DB_PASS', 'mypassword');

// Returns a shared PDO connection (exceptions on error, no emulated prepares).
function getDb(): PDO
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

// Show times (e.g. the log-in time) in Dallas local time.
date_default_timezone_set('America/Chicago');

// HTML-escape helper.
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
