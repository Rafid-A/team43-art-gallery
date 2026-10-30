<?php
/*
 * db.php - returns one shared PDO connection to the art database.
 * Every query in the Art Gallery goes through a PDO prepared statement.
 */
require_once __DIR__ . '/config.php';

function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DBCONNSTRING, DBUSER, DBPASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // throw on any SQL error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,                   // real server-side prepares
        ]);
    }
    return $pdo;
}
