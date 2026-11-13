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

// The course gallery tables use MySQL's 3-byte utf8 (utf8mb3). Characters
// beyond the Basic Multilingual Plane, such as most emoji, would be stored as
// "?" without an error, so library forms reject them before saving.
function gallery_storable_text($value)
{
    return !preg_match('/[\x{10000}-\x{10FFFF}]/u', $value);
}

function gallery_unstorable_message($label)
{
    return $label . ' cannot contain emoji or other special symbols. Remove them and try again.';
}

// "1 artwork", "2 artworks": counts shown to people agree with their number.
function count_label($count, $one, $many)
{
    return (int)$count . ' ' . ((int)$count === 1 ? $one : $many);
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
    if (current_user()) return;
    // Remember a page someone opened while signed out, so login can return
    // there. Only same-folder GET pages are kept; never a host or scheme.
    $target = basename($_SERVER['SCRIPT_NAME']) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && safe_return_page($target)) $_SESSION['after_login'] = $target;
    // Keep a more specific notice, such as an ended session.
    if (empty($_SESSION['flash'])) flash('Please log in to view that page. You will return to it after logging in.', 'info');
    redirect('art-login.php');
}

function safe_return_page($target)
{
    return is_string($target) && preg_match('/\A[a-z0-9-]+\.php(?:\?[A-Za-z0-9_.%+=&;:,\[\]~-]{0,2000})?\z/D', $target)
        && !in_array(strtok($target, '?'), ['art-login.php', 'register.php', 'authenticate.php', 'logout.php'], true);
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

function page_start($title, $artworksUrl = 'art-main.php', array $options = [])
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
        'index.php' => 'Home', 'browse.php' => 'Browse', 'account.php' => 'Account', 'admin.php' => 'Admin', 'users.php' => 'Users', default => ''
    };
    // Public pages may have already resolved a user, including a safe error
    // response. Reuse the result instead of reconnecting while rendering it.
    $u = array_key_exists('user', $options) ? $options['user'] : current_user();
    $landing = !empty($options['landing']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | Art Gallery</title>
    <link rel="icon" type="image/jpeg" href="images/art/works/square-medium/019130.jpg">
    <link rel="stylesheet" href="assets/style.css?v=11">
    <link rel="stylesheet" href="assets/forms.css?v=2">
    <link rel="stylesheet" href="assets/motion.css?v=6">
    <link rel="stylesheet" href="assets/navigation.css?v=2" media="(prefers-reduced-motion: no-preference)" id="browse-motion" disabled>
    <script src="assets/motion.js?v=5"></script>
    <script src="assets/app.js?v=7" defer></script>
    <?php if ($landing): ?>
    <meta name="description" content="Discover paintings and artists in Art Gallery, from intimate portraits to gardens in bloom.">
    <link rel="stylesheet" href="assets/landing.css?v=6">
    <script src="assets/landing.js?v=4" defer></script>
    <?php endif; ?>
</head>
<body<?= $landing ? ' class="landing-page"' : '' ?>>
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="topbar">
        <a class="brand" href="<?= e($u ? $artworksUrl : 'index.php') ?>">
            Art Gallery
        </a>
        <?php if ($u):
            $links = [$artworksUrl => 'Artworks', 'objects.php?type=artists' => 'Artists', 'objects.php?type=galleries' => 'Galleries', 'browse.php' => 'Browse'];
            if ($u['role'] === 'admin') $links += ['admin.php' => 'Admin', 'manage.php' => 'Manage', 'users.php' => 'Users'];
            $links['account.php'] = 'Account';
            $links['index.php'] = 'Home'; ?>
        <details class="site-menu" data-mobile-disclosure>
        <summary aria-controls="main-navigation">Menu</summary>
        <nav id="main-navigation" aria-label="Main navigation">
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
        </details>
        <?php else: ?>
        <nav class="public-navigation" aria-label="Main navigation">
            <a href="index.php" <?= $section === 'Home' ? 'aria-current="page"' : '' ?>>Home</a>
            <a href="<?= e($artworksUrl) ?>">Collection</a>
            <a href="art-login.php" <?= $current === 'art-login.php' ? 'aria-current="page"' : '' ?>>Log in</a>
            <a href="register.php" <?= $current === 'register.php' ? 'aria-current="page"' : '' ?>>Register</a>
        </nav>
        <?php endif; ?>
    </header>
    <main id="main" tabindex="-1">
<?php
}

function page_end()
{
    echo '</main><footer><strong>Art Gallery</strong><a href="images/SOURCES.txt">Image credits</a></footer></body></html>';
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
