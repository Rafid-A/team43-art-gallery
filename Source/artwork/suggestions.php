<?php
// GET ?type=title|artist&prefix=...: 200 {results:[{id,label,url}]},
// or {error:{code,message}} with 400, 401, 405, or 503. Empty prefix returns [].
// Literal prefix under the database collation; maximum 255 Unicode characters,
// 10 results, ordered by matching field then stable ID (and first name for artists).
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/subject-schema.php';
header('Content-Type: application/json; charset=utf-8');
function suggestion_response($status, $body) {
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}
function suggestion_error($status, $code, $message) {
    suggestion_response($status, ['error' => ['code' => $code, 'message' => $message]]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    suggestion_error(405, 'method_not_allowed', 'Use GET for suggestions.');
}
if (empty($_SESSION['user']['username'])) suggestion_error(401, 'login_required', 'Please log in to use suggestions.');
// Confirm the account is still active before answering; roles need migration 08.
try {
    $accountsReady = account_schema_ready(db(true));
} catch (Throwable $ex) {
    error_log('Suggestions unavailable: ' . $ex->getMessage());
    suggestion_error(503, 'unavailable', 'Suggestions are temporarily unavailable. Please try again.');
}
if (!$accountsReady) suggestion_error(503, 'unavailable', 'Suggestions are unavailable until the database upgrade is complete.');
if (!current_user()) suggestion_error(401, 'login_required', 'Please log in to use suggestions.');
session_write_close();
$type = $_GET['type'] ?? null;
$prefix = $_GET['prefix'] ?? null;
if (!is_string($type) || !in_array($type, ['title', 'artist'], true) || !is_string($prefix)) {
    suggestion_error(400, 'invalid_request', 'Choose a suggestion type and provide a prefix.');
}
$prefix = trim($prefix);
$length = text_length($prefix);
if ($length === false || $length > 255) suggestion_error(400, 'invalid_request', 'Prefix must be valid UTF-8 and at most 255 characters.');
try {
    $pdo = db(true);
    if (!subject_schema_ready($pdo)) suggestion_error(503, 'unavailable', 'Suggestions are unavailable until the database upgrade is complete.');
    if ($prefix === '') suggestion_response(200, ['results' => []]);
    $pattern = strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    if ($type === 'artist') {
        $stmt = $pdo->prepare("SELECT ArtistID AS id, FirstName, LastName FROM Artists WHERE LastName LIKE ? ESCAPE '!' ORDER BY LastName, FirstName, ArtistID LIMIT 10");
    } else {
        $stmt = $pdo->prepare("SELECT PaintingID AS id, Title FROM Paintings WHERE Title LIKE ? ESCAPE '!' ORDER BY Title, PaintingID LIMIT 10");
    }
    $stmt->execute([$pattern]);
    $results = [];
    foreach ($stmt as $row) {
        $id = (int)$row['id'];
        $results[] = ['id' => $id, 'label' => $type === 'artist' ? trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? '')) : $row['Title'],
            'url' => $type === 'artist' ? 'objects.php?type=artists&id=' . $id : 'painting.php?id=' . $id];
    }
    suggestion_response(200, ['results' => $results]);
} catch (Throwable $ex) {
    error_log('Suggestions unavailable: ' . $ex->getMessage());
    suggestion_error(503, 'unavailable', 'Suggestions are temporarily unavailable. Please try again.');
}
