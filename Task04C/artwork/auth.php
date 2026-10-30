<?php
/*
 * auth.php - session guard. Every protected page (art-main.php, artwork.php,
 * delete-artwork.php) includes this first. A visitor who has not logged in is
 * sent to the login page; otherwise $currentUser holds the logged-in user.
 */
require_once __DIR__ . '/session.php';

if (empty($_SESSION['user'])) {
    redirect('art-login.php?required=1');
}

$currentUser = $_SESSION['user'];   // ['id', 'userid', 'firstname', 'lastname']
