<?php
/*
 * config.php - database connection settings (same art DB and testuser account as Lab 14).
 * CS 4485 Task04C, Phuc Tran (pmt220000), Team 43
 *
 * host=localhost makes PDO connect through the local MySQL socket, which works
 * both under XAMPP Apache and with PHP's built-in server.
 */
define('DBHOST', 'localhost');
define('DBNAME', 'art');
define('DBUSER', 'testuser');
define('DBPASS', 'mypassword');
define('DBCONNSTRING', 'mysql:host=' . DBHOST . ';dbname=' . DBNAME . ';charset=utf8mb4');

// Folder (relative to this folder) holding the artwork pictures the pages show.
define('IMAGE_DIR', 'images/art/works/square-medium');
// Size of the square-medium pictures in the Lab 14 image set (150 x 150).
define('IMAGE_SIZE', 150);
// Largest picture a user may upload on the Add Artwork page.
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);
// Artworks per page on the "All artworks" view of art-main.php.
define('PER_PAGE', 24);
