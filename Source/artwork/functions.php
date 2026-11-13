<?php
require_once __DIR__ . '/config.php';

session_name('team43_gallery');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
]);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; script-src 'self'; style-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function text_length($value)
{
    return preg_match_all('/./us', $value);
}

function input($name, $trim = true)
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) return '';
    return $trim ? trim($value) : $value;
}

function redirect($page)
{
    header('Location: ' . $page, true, 303);
    exit;
}

function require_login()
{
    if (empty($_SESSION['user']))
        redirect('art-login.php');
}

function token()
{
    if (empty($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

    return $_SESSION['csrf'];
}

function require_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Use the page form.');
    }

    if (!hash_equals(token(), input('csrf'))) {
        http_response_code(403);
        exit('Form expired. Go back, refresh, and try again.');
    }
}

function flash($message, $type = 'success')
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function notices()
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<p role="status" class="notice ' . e($f['type']) . '">' . e($f['message']) . '</p>';
    }
}

function db($throwOnFailure = false)
{
    static $pdo;

    if (!$pdo) {
        try {
            $dsn = 'mysql:host=' . DBHOST . ';port=' . DBPORT . ';dbname=' . DBNAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DBUSER, DBPASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            if ($throwOnFailure) throw $ex;
            http_response_code(503);
            exit('The database is unavailable. Please try again later.');
        }
    }

    return $pdo;
}

function page_start($title, $artworksUrl = 'art-main.php')
{
    $current = basename($_SERVER['SCRIPT_NAME']);
    $objectType = is_string($_GET['type'] ?? null) ? $_GET['type'] : 'artists';
    $section = match ($current) {
        'art-main.php', 'artwork.php', 'painting.php', 'art-add.php', 'painting-edit.php', 'painting-delete.php' => 'Artworks',
        'objects.php' => ($_GET['manage'] ?? '') === '1' ? 'Manage' : match ($objectType) {
            'artists' => 'Artists', 'galleries' => 'Galleries',
            'eras', 'genres', 'subjects' => 'Browse', default => 'Manage'
        },
        'object-add.php', 'object-delete.php', 'manage.php', 'integrity.php' => 'Manage',
        'browse.php' => 'Browse', 'account.php' => 'Account', default => ''
    };
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | The Art Library</title>
    <link rel="icon" type="image/jpeg" href="images/art/works/square-medium/019130.jpg">
    <link rel="stylesheet" href="assets/style.css?v=3">
    <link rel="stylesheet" href="assets/motion.css?v=2">
    <link rel="stylesheet" href="assets/navigation.css" media="(prefers-reduced-motion: no-preference)" id="browse-motion" disabled>
    <script src="assets/motion.js"></script>
    <script src="assets/app.js?v=3" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="topbar">
        <a class="brand" href="<?= e($artworksUrl) ?>">
            The Art Library<span>Team 43 · A collection of perspectives</span>
        </a>
        <?php if (!empty($_SESSION['user'])):
            $u = $_SESSION['user']; ?>
        <nav aria-label="Main navigation">
            <span class="account">
                <span class="account-name"><?= e($u['forename'] . ' ' . $u['surname']) ?></span>
                <small>(<?= e($u['username']) ?>)</small>
            </span>
            <?php foreach ([$artworksUrl => 'Artworks', 'objects.php?type=artists' => 'Artists', 'objects.php?type=galleries' => 'Galleries', 'browse.php' => 'Browse', 'manage.php' => 'Manage', 'account.php' => 'Account'] as $href => $label): ?>
            <a href="<?= e($href) ?>" <?= $label === $section ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
            <form action="logout.php" method="post">
                <input type="hidden" name="csrf" value="<?= e(token()) ?>">
                <button class="quiet">Log out</button>
            </form>
        </nav>
        <?php endif; ?>
    </header>
    <main id="main" tabindex="-1">
<?php
}

function page_end()
{
    echo '</main><footer><strong>The Art Library</strong><span>Team 43 · Art, in good company.</span><a href="images/SOURCES.txt">Image credits</a></footer></body></html>';
}

function image_files()
{
    $files = glob(__DIR__ . '/images/art/works/square-medium/*.jpg');

    return array_map(function($file) {
        return basename($file, '.jpg');
    }, $files);
}

function artwork_image_size($filename)
{
    $path = __DIR__ . '/images/art/works/square-medium/' . basename($filename) . '.jpg';
    $size = is_file($path) ? getimagesize($path) : false;

    return $size ? [$size[0], $size[1]] : [640, 480];
}

function description_preview($description)
{
    preg_match('/^.{1,180}/us', $description, $match);
    $preview = $match[0] ?? '';
    $wholeWords = preg_replace('/\s+\S*$/u', '', $preview);

    return ($wholeWords ?: $preview) . '…';
}
