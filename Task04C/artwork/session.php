<?php
/*
 * session.php - starts the session and holds small helpers shared by all pages:
 * output escaping, redirects, flash messages and a CSRF token for the POST forms.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* Escape any value for safe output inside HTML text or attributes. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Send the browser to another page and stop this script. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* Flash messages: stored in the session, shown once on the next page. */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function takeFlashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* One random token per session; every POST form sends it back. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfValid(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/* Read a text field from a POSTed form ('' if missing or not a plain string). */
function postString(string $name): string
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
}

/* Read a positive integer from the query string (or null if missing/invalid). */
function getPositiveInt(string $name): ?int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($value) ? $value : null;
}

/*
 * The Excerpt column of art-small.sql contains a little HTML (<em>Title</em>).
 * Escape everything, then put back only the harmless inline tags.
 */
function excerptHtml(?string $text): string
{
    $safe = e($text);
    return preg_replace('#&lt;(/?)(em|i|strong|b)&gt;#i', '<$1$2>', $safe);
}
