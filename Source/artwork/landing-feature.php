<?php // Server-rendered links also select works without JavaScript. ?>
<div class="landing-feature" id="featured-art" data-landing-feature data-landing-arrival>
<?php if ($featured): ?>
    <div class="landing-art-wall">
    <?php foreach ($featured as $name => $work): $name = (string)$name; ?>
        <a class="landing-feature-image" data-feature-image="<?= e($name) ?>" href="painting.php?id=<?= (int)$work['PaintingID'] ?>" <?= $name !== $selected ? 'hidden' : '' ?>><?php landing_image($work, $name === $selected, true); ?><span class="landing-image-failure" hidden>Image unavailable. You can still explore this artwork in the collection.</span></a>
    <?php endforeach; ?>
    </div>
    <div class="landing-feature-notes" aria-live="polite" aria-atomic="true">
    <?php foreach ($featured as $name => $work): $name = (string)$name; ?>
        <div data-feature-caption="<?= e($name) ?>" <?= $name !== $selected ? 'hidden' : '' ?>><h2><a href="painting.php?id=<?= (int)$work['PaintingID'] ?>"><?= e($work['Title']) ?></a></h2><p><?= e(landing_artist_name($work)) ?><?php if ($work['YearOfWork']): ?>, <?= e($work['YearOfWork']) ?><?php endif; ?></p><small><?= e($work['CopyrightText'] ?: 'See image credits for attribution') ?></small></div>
    <?php endforeach; ?>
    </div>
    <nav class="landing-art-choices" aria-label="Featured artworks">
    <?php foreach ($featured as $name => $work): $name = (string)$name; ?>
        <a data-feature-choice="<?= e($name) ?>" href="?work=<?= e($name) ?>#featured-art" <?= $name === $selected ? 'aria-current="true"' : '' ?>><span><?= e($work['Title']) ?></span><span class="landing-choice-mark" aria-hidden="true">+</span></a>
    <?php endforeach; ?>
    </nav>
<?php else: ?>
    <div class="landing-art-wall landing-art-empty"><h2>There is always<br>more to discover.</h2><p>Featured artworks will appear here when they are available.</p><a href="art-main.php">Visit the collection</a></div>
<?php endif; ?>
</div>
