<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/landing-content.php';
require_once __DIR__ . '/visitor-themes.php';

$user = null;
try {
    // Resolve the connection before current_user(), so a failed connection
    // cannot re-enter the authenticated shell while rendering this error.
    $pdo = db(true);
    $user = current_user();
    $works = landing_artworks($pdo);
    $themes = visitor_themes($pdo);
    $strip = landing_strip_artworks($pdo, array_keys($works));
} catch (Throwable $error) {
    error_log('Landing unavailable: ' . $error->getMessage());
    http_response_code(503);
    header('Retry-After: 60');
    page_start('Collection unavailable', 'art-main.php', ['landing' => true, 'user' => null]);
    echo '<section class="landing-unavailable"><h1>The collection is unavailable</h1><p>Artworks cannot be loaded right now. Please try again later.</p><a class="button landing-primary" href="index.php">Try again</a></section>';
    page_end();
    exit;
}

$featured = array_slice($works, 0, 3, true);
$requested = is_string($_GET['work'] ?? null) ? $_GET['work'] : '';
$selected = isset($featured[$requested]) ? $requested : (string)array_key_first($featured);
$highlights = array_slice($works, 3, 2, true);
// The artist route follows an available current record, never a seed ID.
$spotlight = $works['017060'] ?? $works['017040'] ?? null;
if (!$spotlight || !(int)$spotlight['ArtistID']) {
    $spotlight = null;
    foreach ($works as $work) if ((int)$work['ArtistID']) { $spotlight = $work; break; }
}
page_start('Home', 'art-main.php', ['landing' => true, 'user' => $user]);
?>
<section class="landing-hero" aria-labelledby="landing-title">
    <div class="landing-intro" data-landing-arrival>
        <h1 id="landing-title">Art Gallery</h1>
        <p>Paintings from the collection, with their artists, galleries, eras, genres, and subjects.</p>
        <a class="button landing-primary" href="art-main.php">Explore the collection</a>
        <?php if (!$user): ?><p class="landing-access">A first look is open to everyone.<br><a href="art-login.php">Log in</a> or <a href="register.php">create an account</a> for artwork details and the full collection.</p><?php else: ?><p class="landing-access">Welcome back, <?= e($user['forename']) ?>. <a href="<?= e(home_url($user)) ?>"><?= $user['role'] === 'admin' ? 'Open administration' : 'Continue exploring' ?></a></p><?php endif; ?>
    </div>
    <?php require __DIR__ . '/landing-feature.php'; ?>
</section>

<?php if (count($strip) >= 4): ?>
<section class="landing-strip" aria-labelledby="landing-strip-title" data-landing-strip>
    <div class="landing-strip-heading"><h2 id="landing-strip-title">Across the collection</h2><p>One painting from each of <?= count($strip) ?> artists, earliest first.</p><button type="button" class="quiet" data-strip-toggle hidden>Pause rotation</button></div>
    <div class="landing-strip-window">
        <ul class="landing-strip-track" role="list">
        <?php foreach ($strip as $work): ?>
            <li><figure><a class="landing-strip-image" href="painting.php?id=<?= (int)$work['PaintingID'] ?>"><?php landing_image($work, false, false, '240px'); ?></a><figcaption><?= e($work['Title']) ?><span><?= e(landing_artist_name($work)) ?><?php if ($work['YearOfWork']): ?>, <?= e($work['YearOfWork']) ?><?php endif; ?></span></figcaption></figure></li>
        <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if ($highlights): ?>
<section class="landing-highlights" aria-labelledby="landing-highlights-title">
    <div class="landing-section-heading"><h2 id="landing-highlights-title">Selected artworks</h2><p>A selection from the collection.</p></div>
    <div class="landing-selection">
    <?php foreach ($highlights as $work): ?>
        <figure class="landing-highlight">
            <a class="landing-highlight-image" href="painting.php?id=<?= (int)$work['PaintingID'] ?>"><?php landing_image($work); ?></a>
            <figcaption><h3><a href="painting.php?id=<?= (int)$work['PaintingID'] ?>"><?= e($work['Title']) ?></a></h3><p><?= e(landing_artist_name($work)) ?><?php if ($work['YearOfWork']): ?>, <?= e($work['YearOfWork']) ?><?php endif; ?></p><small><?= e($work['CopyrightText'] ?: 'See image credits for attribution') ?></small></figcaption>
        </figure>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($spotlight): $artistUrl = 'objects.php?' . http_build_query(['type' => 'artists', 'id' => (int)$spotlight['ArtistID']]); ?>
<section class="landing-artist" aria-labelledby="landing-artist-title">
    <div class="landing-artist-copy"><p class="landing-kicker">Featured artist</p><h2 id="landing-artist-title"><?= e(landing_artist_name($spotlight)) ?></h2>
        <?php if ($spotlight['YearOfBirth'] || $spotlight['YearOfDeath'] || $spotlight['Nationality']): ?><p class="landing-artist-facts"><?php if ($spotlight['YearOfBirth'] && $spotlight['YearOfDeath']): ?><?= e($spotlight['YearOfBirth']) ?>–<?= e($spotlight['YearOfDeath']) ?><?php elseif ($spotlight['YearOfBirth']): ?>Born <?= e($spotlight['YearOfBirth']) ?><?php elseif ($spotlight['YearOfDeath']): ?>Died <?= e($spotlight['YearOfDeath']) ?><?php endif; ?><?php if ($spotlight['Nationality']): ?> <span><?= e($spotlight['Nationality']) ?></span><?php endif; ?></p><?php endif; ?>
        <?php if ($biography = landing_biography($spotlight)): ?><p><?= e($biography) ?></p><?php else: ?><p>No biography has been recorded for this artist.</p><?php endif; ?>
        <a class="landing-text-link" href="<?= e($artistUrl) ?>">View the artist and their works</a>
    </div>
    <figure><a class="landing-artist-image" href="<?= e($artistUrl) ?>"><?php landing_image($spotlight); ?></a><figcaption><?= e($spotlight['Title']) ?><?php if ($spotlight['YearOfWork']): ?>, <?= e($spotlight['YearOfWork']) ?><?php endif; ?><br><small><?= e($spotlight['CopyrightText'] ?: 'See image credits for attribution') ?></small></figcaption></figure>
</section>
<?php endif; ?>

<?php visitor_theme_routes($themes); ?>

<section class="landing-invitation" aria-labelledby="landing-invitation-title"><h2 id="landing-invitation-title">The full collection</h2><div><a class="button landing-primary" href="art-main.php">Explore the collection</a><?php if (!$user): ?><a href="register.php">Create your account</a><?php else: ?><a href="account.php">Your account</a><?php endif; ?></div></section>
<?php page_end(); ?>
