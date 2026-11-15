<?php
// POST id, action=save|remove, csrf, return. Adds or removes one artwork in the
// signed-in user's own collection; the username always comes from the session.
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/saved-works.php';
require_login();
require_post();

$id = positive_id(input('id'));
$action = input('action');
$return = saved_work_return(input('return'), $id);
if (!saved_works_ready(db())) {
    http_response_code(503);
    header('Retry-After: 60');
    exit('My collection is unavailable until the database upgrade (10-saved-works.sql) is completed.');
}
if (!in_array($action, ['save', 'remove'], true)) {
    http_response_code(400);
    exit('Unknown collection action.');
}
$painting = rows('SELECT PaintingID, Title FROM Paintings WHERE PaintingID=?', [$id])->fetch();
if (!$painting) {
    http_response_code(404);
    exit('Artwork not found. It may have been removed.');
}

$username = current_user()['username'];
try {
    if ($action === 'save') {
        // Repeated saves (double submit, two tabs) are harmless.
        rows('INSERT IGNORE INTO art_saved_works (username, PaintingID) VALUES (?, ?)', [$username, $id]);
        flash('“' . $painting['Title'] . '” is saved in your collection.');
    } else {
        rows('DELETE FROM art_saved_works WHERE username=? AND PaintingID=?', [$username, $id]);
        flash('“' . $painting['Title'] . '” was removed from your collection.');
    }
} catch (PDOException $ex) {
    // The log names the event and SQLSTATE only; never the user's data.
    error_log('saved_work_failed action=' . $action . ' painting=' . $id . ' sqlstate=' . $ex->getCode());
    http_response_code(503);
    exit('Your collection could not be updated. Nothing was changed. Please try again.');
}
redirect($return);
