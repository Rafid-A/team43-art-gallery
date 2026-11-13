<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/account-schema.php';

session_name('team43_gallery');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
]);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob:; script-src 'self'; style-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

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
    if (!current_user())
        redirect('art-login.php');
}

// The signed-in account is read again on every request, so a role change,
// a disabled account or a deleted/replaced account applies to sessions already open.
function current_user()
{
    static $loaded = false;
    if ($loaded) return $_SESSION['user'] ?? null;
    $loaded = true;

    $username = $_SESSION['user']['username'] ?? null;
    if (!is_string($username) || $username === '') return null;

    $pdo = db();
    require_account_schema($pdo);
    $stmt = $pdo->prepare('SELECT forename, surname, username, password, role, status FROM art_users WHERE username = ?');
    $stmt->execute([$username]);
    $account = $stmt->fetch();

    // Only login captures this server-side fingerprint. A new salted hash
    // means a new credential generation, even if a username/password is reused.
    $fingerprint = $_SESSION['account_fingerprint'] ?? null;
    if (!$account || $account['status'] !== 'active' || !is_string($fingerprint)
        || !hash_equals(hash('sha256', $account['password']), $fingerprint)) {
        unset($_SESSION['user'], $_SESSION['account_fingerprint']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        flash('Your session ended because this account changed or is no longer active. Please sign in again.', 'error');
        return null;
    }

    // A privilege change gets a new session ID and form token.
    if (($_SESSION['user']['role'] ?? null) !== $account['role']) {
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    unset($account['password'], $account['status']);
    $_SESSION['user'] = $account;
    return $account;
}

function is_admin()
{
    return (current_user()['role'] ?? null) === 'admin';
}

// Every write and administration endpoint calls this on the server;
// hiding a link is never the only protection.
function require_admin()
{
    require_login();
    if (is_admin()) return;

    http_response_code(403);
    page_start('Administrators only');
    echo '<section class="confirmation"><p class="eyebrow">Access</p><h1>Administrators only</h1><p>Your client account can browse the collection and view your profile. Changes to the library and to accounts need an administrator.</p><a href="art-main.php">Return to artworks</a></section>';
    page_end();
    exit;
}

function home_url(array $user)
{
    return ($user['role'] ?? null) === 'admin' ? 'admin.php' : 'art-main.php';
}

function role_label($role)
{
    return $role === 'admin' ? 'Administrator' : 'Client';
}

// Roles decide what a request may do, so the gallery fails closed until
// migration 08 has added them.
function require_account_schema(PDO $pdo)
{
    if (account_schema_ready($pdo)) return;

    unset($_SESSION['user'], $_SESSION['account_fingerprint']);
    http_response_code(503);
    header('Retry-After: 60');
    page_start('Database upgrade required');
    echo '<h1>Database upgrade required</h1><p>Accounts need the client and administrator roles before anyone can log in. Ask the person running this local gallery to follow the existing-database upgrade steps in the runtime guide. Existing data has not been reset.</p>';
    page_end();
    exit;
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
        'art-main.php', 'artwork.php', 'painting.php', 'art-add.php', 'painting-delete.php' => 'Artworks',
        'objects.php' => ($_GET['manage'] ?? '') === '1' ? 'Manage' : match ($objectType) {
            'artists' => 'Artists', 'galleries' => 'Galleries',
            'eras', 'genres', 'subjects' => 'Browse', default => 'Manage'
        },
        'object-add.php', 'object-delete.php', 'manage.php', 'integrity.php' => 'Manage',
        'browse.php' => 'Browse', 'account.php' => 'Account', 'admin.php' => 'Admin', 'users.php' => 'Users', default => ''
    };
    $u = current_user();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | The Art Library</title>
    <link rel="icon" type="image/jpeg" href="images/art/works/square-medium/019130.jpg">
    <link rel="stylesheet" href="assets/style.css?v=6">
    <link rel="stylesheet" href="assets/motion.css?v=2">
    <link rel="stylesheet" href="assets/navigation.css" media="(prefers-reduced-motion: no-preference)" id="browse-motion" disabled>
    <script src="assets/motion.js"></script>
    <script src="assets/app.js?v=4" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="topbar">
        <a class="brand" href="<?= e($artworksUrl) ?>">
            The Art Library<span>Team 43 · A collection of perspectives</span>
        </a>
        <?php if ($u):
            $links = [$artworksUrl => 'Artworks', 'objects.php?type=artists' => 'Artists', 'objects.php?type=galleries' => 'Galleries', 'browse.php' => 'Browse'];
            if ($u['role'] === 'admin') $links += ['admin.php' => 'Admin', 'manage.php' => 'Manage', 'users.php' => 'Users'];
            $links['account.php'] = 'Account'; ?>
        <nav aria-label="Main navigation">
            <span class="account">
                <span class="account-name"><?= e($u['forename'] . ' ' . $u['surname']) ?></span>
                <small>(<?= e($u['username']) ?> · <?= e(role_label($u['role'])) ?>)</small>
            </span>
            <?php foreach ($links as $href => $label): ?>
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

// Bundled course images first, then administrator uploads. Both are stored
// as <ImageFileName>.jpg, and the stored name is only ever used as a basename.
function artwork_image_file($filename)
{
    $name = basename((string)$filename);
    if ($name === '' || $name === '.' || $name === '..') return null;
    foreach (['images/art/works/square-medium/', 'images/uploads/'] as $folder) {
        if (is_file(__DIR__ . '/' . $folder . $name . '.jpg'))
            return ['path' => __DIR__ . '/' . $folder . $name . '.jpg', 'url' => $folder . rawurlencode($name) . '.jpg'];
    }
    return null;
}

function artwork_image_size($filename)
{
    $file = artwork_image_file($filename);
    $size = $file ? getimagesize($file['path']) : false;

    return $size ? [$size[0], $size[1]] : [640, 480];
}

function description_preview($description)
{
    preg_match('/^.{1,180}/us', $description, $match);
    $preview = $match[0] ?? '';
    $wholeWords = preg_replace('/\s+\S*$/u', '', $preview);

    return ($wholeWords ?: $preview) . '…';
}
