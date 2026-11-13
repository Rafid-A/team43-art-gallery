// Read-only against an exclusive, ownership-verified disposable fixture.
// No database mutations, container lifecycle, or fixture-write opt-in required.
const { base, db, web } = require('./browsing_test_config.cjs').configure();
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');

const sql = query => execFileSync('docker', ['exec', '-e', 'MYSQL_PWD=mypassword', db,
  'mariadb', '-utestuser', 'art', '-N', '-B', '-e', query], { encoding: 'utf8' }).trim();
const works = sql(`SELECT JSON_OBJECT('id', p.PaintingID, 'file', p.ImageFileName,
  'title', p.Title, 'artist', CONCAT_WS(' ', a.FirstName, a.LastName), 'year', p.YearOfWork)
  FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID
  WHERE p.ImageFileName IN ('019130','016010','017040','106010','017060')
  ORDER BY FIELD(p.ImageFileName,'019130','016010','017040','106010','017060'), p.PaintingID`)
  .split(/\r?\n/).filter(Boolean).map(row => JSON.parse(row));
assert(works.length >= 3, 'Use the baseline classroom fixture with its bundled selection');
const errors = [];
const watch = page => {
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error' && /content security policy|refused to (execute|apply)/i.test(message.text())) errors.push(message.text());
  });
};
const fit = async page => assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow');
const activeImage = page => page.locator('[data-feature-image]:not([hidden])');
const activeCaption = page => page.locator('[data-feature-caption]:not([hidden])');

async function login(page, user, password, destination) {
  await page.goto(base + 'art-login.php');
  await page.locator('#userid').fill(user);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL(base + destination),
    page.getByRole('button', { name: 'Log in', exact: true }).click(),
  ]);
}

async function assertSelection(page, file) {
  const work = works.find(work => work.file === file);
  assert(work, `Expected database record for ${file}`);
  assert.equal(await activeImage(page).count(), 1);
  assert.equal(await activeImage(page).getAttribute('data-feature-image'), file);
  assert.equal(await activeImage(page).getAttribute('href'), `painting.php?id=${work.id}`);
  assert.equal(await activeCaption(page).count(), 1);
  assert.equal(await activeCaption(page).getAttribute('data-feature-caption'), file);
  assert.equal(await activeCaption(page).locator('h2').textContent(), work.title);
  assert.equal(await activeCaption(page).locator('h2 a').getAttribute('href'), `painting.php?id=${work.id}`);
  assert((await activeCaption(page).textContent()).includes(work.artist));
  assert.equal(await page.locator('[data-feature-choice][aria-current]').count(), 1);
  assert.equal(await page.locator('[data-feature-choice][aria-current]').getAttribute('data-feature-choice'), file);
  assert(await page.locator('[data-feature-image][hidden], [data-feature-caption][hidden]').evaluateAll(nodes => nodes.every(node => node.getClientRects().length === 0)), 'Inactive content is hidden from interaction');
}

async function waitSelection(page, file) {
  await page.locator(`[data-feature-image="${file}"]:not([hidden])`).waitFor({ state: 'visible' });
  await assertSelection(page, file);
  assert.equal(await page.locator('[data-feature-choice][aria-busy]').count(), 0, 'Completed selections release pending state');
}

// Hold both responsive and original requests, rather than assuming an image
// cache miss or a fixed network speed. Each context starts with a cold cache.
async function slowImageChecks(browser) {
  const [a, b, c] = works.map(work => work.file);
  async function setup(width = 1440) {
    const context = await browser.newContext({ reducedMotion: 'no-preference', viewport: { width, height: 1000 } });
    const gates = new Map([b, c].map(file => [file, { requests: 0, waiting: [], action: null }]));
    await context.addInitScript(() => {
      sessionStorage.setItem('library-landing-seen', 'true');
      window.captionAnimationCalls = 0;
      const animate = Element.prototype.animate;
      Element.prototype.animate = function (...args) {
        if (this.matches('[data-feature-caption]')) window.captionAnimationCalls++;
        return animate.apply(this, args);
      };
    });
    await context.route('**/*', async route => {
      const url = new URL(route.request().url());
      // Baseline proof is per browser context; never overwrite source/fixture.
      if (process.env.LANDING_SCRIPT_OVERRIDE && url.pathname.endsWith('/assets/landing.js')) {
        return route.fulfill({ contentType: 'text/javascript', body: readFileSync(process.env.LANDING_SCRIPT_OVERRIDE, 'utf8') });
      }
      const file = [...gates.keys()].find(file => url.pathname.endsWith(`/${file}.jpg`)
        || (url.pathname.endsWith('/artwork-media.php') && url.searchParams.get('name') === file));
      if (!file) return route.continue();
      const gate = gates.get(file);
      gate.requests++;
      if (!gate.action) await new Promise(resolve => gate.waiting.push(resolve));
      return gate.action === 'abort' ? route.abort() : route.continue();
    });
    const release = (file, action = 'continue') => {
      const gate = gates.get(file);
      gate.action = action;
      gate.waiting.splice(0).forEach(resolve => resolve());
    };
    const page = await context.newPage();
    watch(page);
    await page.goto(base + '#featured-art', { waitUntil: 'domcontentloaded' });
    await activeImage(page).locator('img').evaluate(image => image.decode());
    const choose = file => page.locator(`[data-feature-choice="${file}"]`).click();
    const noMotion = async () => {
      assert.equal(await page.locator('.landing-fade-ghost').count(), 0, 'No ghost survives cancellation');
      assert.equal(await page.locator('[data-landing-feature]').evaluate(node => node.getAnimations({ subtree: true }).length), 0);
    };
    return { context, page, gates, release, choose, noMotion };
  }

  // The slow target must start fetching while hidden and leave the complete
  // old artwork/caption/destination/URL usable beyond the 200ms fade duration.
  let test = await setup(390);
  try {
    const { page, gates, release, choose } = test;
    const initialURL = page.url();
    const historyLength = await page.evaluate(() => history.length);
    const titleLink = page.locator(`[data-feature-caption="${b}"] h2 a`);
    const title = await titleLink.textContent();
    // Valid titles can contain a long word. Keep the pending status within
    // the phone viewport without changing any fixture records.
    await titleLink.evaluate(node => { node.textContent = 'W'.repeat(255); });
    await choose(b);
    await page.waitForTimeout(350);
    await assertSelection(page, a);
    assert.equal(page.url(), initialURL, 'Pending selection preserves the current shareable URL');
    assert(gates.get(b).requests > 0, 'Hidden lazy target is proactively requested');
    assert.equal(await page.locator(`[data-feature-image="${b}"] img`).evaluate(image => image.naturalWidth), 0);
    assert.match(await page.getByRole('status').textContent(), /Loading/);
    await fit(page);
    await test.noMotion();
    await titleLink.evaluate((node, text) => { node.textContent = text; }, title);
    release(b);
    await waitSelection(page, b);
    assert.equal(new URL(page.url()).searchParams.get('work'), b);
    assert.equal(new URL(page.url()).hash, '#featured-art');
    assert.equal(await page.evaluate(() => history.length), historyLength);
    assert.equal(await page.getByRole('status').textContent(), '');
    assert.equal(await page.evaluate(() => window.captionAnimationCalls), 1);
    await page.waitForTimeout(250);
    await test.noMotion();
  } finally { await test.context.close(); }

  // A -> B -> C, with latest C completing before superseded B. B's late load
  // must never change the selected work or restart a fade.
  test = await setup();
  try {
    const { page, release, choose } = test;
    await choose(b);
    await choose(c);
    await page.waitForTimeout(350);
    await assertSelection(page, a);
    release(c);
    await waitSelection(page, c);
    release(b);
    await page.locator(`[data-feature-image="${b}"] img`).evaluate(image => image.decode());
    await page.waitForTimeout(250);
    await assertSelection(page, c);
    assert.equal(new URL(page.url()).searchParams.get('work'), c);
    assert.equal(await page.evaluate(() => window.captionAnimationCalls), 1, 'Only the latest completion animates');
    await test.noMotion();
  } finally { await test.context.close(); }

  // Pending failure commits an explicit usable fallback with matching links,
  // then an already-loaded keyboard choice completes in the same event turn.
  test = await setup();
  try {
    const { page, release, choose } = test;
    await choose(b);
    release(b, 'abort');
    await waitSelection(page, b);
    assert(await activeImage(page).locator('.landing-image-failure').isVisible());
    assert(await activeImage(page).locator('img').evaluate(image => image.hidden));
    assert.equal(new URL(page.url()).searchParams.get('work'), b);
    assert.match(await page.getByRole('status').textContent(), /unavailable/i);
    await page.evaluate(file => {
      document.querySelector(`[data-feature-choice="${file}"]`).click();
      if (document.querySelector('[data-feature-image]:not([hidden])').dataset.featureImage !== file) throw Error('Ready keyboard choice was deferred');
    }, a);
    await assertSelection(page, a);
    assert.equal(await page.evaluate(() => window.captionAnimationCalls), 0);
    await test.noMotion();
  } finally { await test.context.close(); }

  // Keyboard activity and preference changes suppress animation without losing
  // the pending choice. Leaving the document invalidates its late callbacks.
  for (const cancel of ['keyboard', 'preference', 'pagehide']) {
    test = await setup();
    try {
      const { page, release, choose } = test;
      await choose(b);
      if (cancel === 'keyboard') await page.keyboard.press('Tab');
      if (cancel === 'preference') {
        await page.evaluate(() => {
          window.testMotionPreferenceChanged = false;
          matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', () => {
            window.testMotionPreferenceChanged = true;
          }, { once: true });
        });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.waitForFunction(() => window.testMotionPreferenceChanged);
      }
      if (cancel === 'pagehide') await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
      await assertSelection(page, a);
      await test.noMotion();
      release(b);
      await page.locator(`[data-feature-image="${b}"] img`).evaluate(image => image.decode());
      if (cancel === 'pagehide') await assertSelection(page, a);
      else await waitSelection(page, b);
      assert.equal(await page.getByRole('status').textContent(), '');
      assert.equal(await page.evaluate(() => window.captionAnimationCalls), 0);
      await test.noMotion();
      if (cancel !== 'pagehide') {
        // Keyboard/reduced-motion can still choose a genuinely uncached work.
        if (cancel === 'keyboard') {
          await page.locator(`[data-feature-choice="${c}"]`).focus();
          await page.keyboard.press('Enter');
        } else await choose(c);
        await page.waitForTimeout(350);
        await assertSelection(page, b);
        release(c);
        await waitSelection(page, c);
        assert.equal(await page.evaluate(() => window.captionAnimationCalls), 0);
        await test.noMotion();
        // Cached selection must remain synchronous for both instant paths.
        await page.evaluate(({ file, detail }) => {
          document.querySelector(`[data-feature-choice="${file}"]`).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail }));
          if (document.querySelector('[data-feature-image]:not([hidden])').dataset.featureImage !== file) throw Error('Ready choice was deferred');
        }, { file: b, detail: cancel === 'preference' ? 1 : 0 });
        await assertSelection(page, b);
      }
    } finally { await test.context.close(); }
  }
}

// Exercise missing rows, missing real media and an empty render through the
// same pure selector/template, without deleting any shared fixture records.
const selectionCheck = String.raw`
require '/var/www/html/artwork/functions.php';
require '/var/www/html/artwork/landing-content.php';
$record = ['PaintingID'=>77, 'ImageFileName'=>'106010', 'Title'=>'Current record'];
if (landing_select_artworks([]) !== []) throw new Exception('Empty selection');
if (landing_select_artworks([$record], static fn($name) => null) !== []) throw new Exception('Missing image');
$selectedRows = landing_select_artworks([$record, $record], static fn($name) => ['url'=>'x', 'width'=>1, 'height'=>1]);
if (count($selectedRows) !== 1 || $selectedRows['106010']['Title'] !== 'Current record') throw new Exception('Sparse selection');
if (landing_select_artworks([['PaintingID'=>77,'ImageFileName'=>'unknown','Title'=>'No']], static fn($name)=>[]) !== []) throw new Exception('Unknown filename');
$featured = []; $selected = ''; ob_start(); require '/var/www/html/artwork/landing-feature.php'; $html = ob_get_clean();
if (!str_contains($html, 'Featured artworks will appear here') || !str_contains($html, 'art-main.php') || str_contains($html, 'data-feature-choice=')) throw new Exception('Empty render');
echo 'Selection and empty-state checks passed';`;
assert.match(execFileSync('docker', ['exec', web, 'php', '-r', selectionCheck], { encoding: 'utf8' }), /checks passed/);
const sourceSizes = JSON.parse(execFileSync('docker', ['exec', web, 'php', '-r',
  `require '/var/www/html/artwork/landing-content.php'; $out=[]; foreach(LANDING_ARTWORK_FILES as $name){$source=artwork_media_source($name); if($source) $out[$name]=[$source['width'],$source['height']];} echo json_encode($out);`],
  { encoding: 'utf8' }));

// Force only this read-only PHP process's connection to fail. Neither the
// database nor the web service is stopped or reconfigured.
const unavailable = execFileSync('docker', ['exec', '-e', 'DB_HOST=127.0.0.1', '-e', 'DB_PORT=1', web, 'php', '-r',
  `$_SERVER['SCRIPT_NAME']='/artwork/index.php'; register_shutdown_function(static function(){echo '\nLANDING_STATUS='.http_response_code();}); require '/var/www/html/artwork/index.php';`],
  { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
assert.match(unavailable, /LANDING_STATUS=503/);
assert.match(unavailable, /Artworks cannot be loaded right now/);
assert.doesNotMatch(unavailable, /SQLSTATE|Stack trace|PDOException|Fatal error/);

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER || undefined });
  try {
    await slowImageChecks(browser);
    if (process.env.LANDING_SLOW_IMAGE_ONLY === '1') {
      assert.deepEqual(errors, []);
      console.log('Gated landing slow-image regression checks passed. No database rows were changed.');
      return;
    }
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    watch(page);
    const response = await page.goto(base);
    assert.equal(response.status(), 200);
    assert.equal(page.url(), base);
    assert.equal(await page.locator('#landing-title').textContent(), 'Art Gallery');
    assert.match(response.headers()['content-security-policy'], /script-src 'self'; style-src 'self'/);
    assert.equal(await page.locator('script:not([src]), style').count(), 0);
    assert.equal(await page.locator('.public-navigation a').count(), 4);
    assert.match(await page.locator('.landing-access').textContent(), /Log in.*create an account.*artwork details/);
    const first = works[0].file;
    await assertSelection(page, first);
    await activeImage(page).locator('img').evaluate(image => image.decode());
    for (const width of [320, 390, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 844 });
      await fit(page);
      if (width === 390) {
        const artwork = await activeImage(page).locator('img').boundingBox();
        const action = await page.locator('.landing-intro .landing-primary').boundingBox();
        assert(artwork.y < 844 && artwork.y + Math.min(80, artwork.height) <= 844, 'Artwork appears in the initial phone viewport');
        assert(action.y + action.height <= 844, 'Primary action appears in the initial phone viewport');
      }
      // naturalWidth is density-corrected for responsive w-descriptors and can
      // change during resize. Compare the rendered art to the actual original
      // JPEG's pixel ceiling, obtained independently from the fixture file.
      await activeImage(page).locator('img').evaluate(image => image.decode());
      assert(await activeImage(page).locator('img').evaluate((image, size) => {
        const rect = image.getBoundingClientRect();
        return getComputedStyle(image).objectFit === 'contain' && rect.width <= size[0] + 1 && rect.height <= size[1] + 1;
      }, sourceSizes[first]), 'Artwork stays within the original raster dimensions');
    }
    await page.setViewportSize({ width: 390, height: 844 });
    const choices = page.locator('[data-feature-choice]');
    for (let index = 0; index < await choices.count(); index++) {
      await choices.nth(index).click();
      const file = await choices.nth(index).getAttribute('data-feature-choice');
      await waitSelection(page, file);
      await activeImage(page).locator('img').evaluate(image => image.decode());
      assert(await activeImage(page).locator('img').evaluate(image => image.naturalWidth > 0 && !image.hidden));
      assert.equal(await activeImage(page).locator('.landing-image-failure').isVisible(), false, 'Hidden lazy images load when selected');
      for (const width of [320, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 844 });
        await activeImage(page).locator('img').evaluate(image => image.decode());
        assert(await activeImage(page).locator('img').evaluate((image, size) => {
          const rect = image.getBoundingClientRect();
          return rect.width <= size[0] + 1 && rect.height <= size[1] + 1;
        }, sourceSizes[file]), `Source pixel ceiling for ${file} at ${width}`);
        await fit(page);
      }
    }
    await page.setViewportSize({ width: 390, height: 844 });
    const second = await choices.nth(1).getAttribute('data-feature-choice');
    await choices.nth(1).focus();
    await page.keyboard.press('Enter');
    await assertSelection(page, second);
    assert.equal(await page.locator('.landing-fade-ghost').count(), 0, 'Keyboard/reduced-motion choice is instant');
    assert.equal(await activeCaption(page).evaluate(node => node.getAnimations().length), 0, 'Reduced-motion caption is instant');
    assert(await choices.nth(1).evaluate(link => link === document.activeElement), 'Selection retains focus');
    assert.equal(new URL(page.url()).searchParams.get('work'), second);
    // Invalid shapes never enter a query or replace the real selection.
    await page.goto(base + '?work[]=unknown');
    await assertSelection(page, first);
    assert(!((await page.locator('body').textContent()).includes('Warning:')));
    for (const path of ['art-main.php', `painting.php?id=${works[0].id}`, 'objects.php?type=artists', 'browse.php', 'account.php', 'admin.php', 'manage.php', 'users.php', 'art-add.php', `painting-edit.php?id=${works[0].id}`, 'artist-edit.php?id=19', 'object-add.php?type=artists']) {
      const protectedResponse = await context.request.get(base + path, { maxRedirects: 0 });
      assert.equal(protectedResponse.status(), 303, path);
      assert.equal(protectedResponse.headers().location, 'art-login.php', path);
    }
    for (const helper of ['landing-content.php', 'landing-feature.php']) assert.equal((await context.request.get(base + helper)).status(), 403, helper);
    const root = await context.request.get(new URL('/', base).href, { maxRedirects: 0 });
    assert.equal(root.status(), 302);
    assert.equal(root.headers().location, 'artwork/');
    await page.goto(base);
    await page.locator('.landing-intro .landing-primary').click();
    assert.equal(page.url(), base + 'art-login.php');
    for (const path of ['art-login.php', 'register.php']) {
      await page.goto(base + path);
      for (const width of [320, 390]) { await page.setViewportSize({ width, height: 844 }); await fit(page); }
    }
    await login(page, 'user2', 'pass2', 'art-main.php');
    await page.goto(base);
    assert.equal(await page.locator('.public-navigation').count(), 0);
    assert.equal(await page.locator('#main-navigation a[href="account.php"]').count(), 1);
    assert.equal(await page.locator('#main-navigation a[href="admin.php"]').count(), 0);
    assert.equal(await page.locator('#main-navigation a[href="index.php"]').count(), 1);
    assert.equal(await page.locator('.brand').getAttribute('href'), 'art-main.php');
    await page.locator('.landing-intro .landing-primary').click();
    assert.match(page.url(), /art-main\.php$/);
    const admin = await browser.newContext({ reducedMotion: 'reduce' });
    const adminPage = await admin.newPage();
    watch(adminPage);
    await login(adminPage, 'user1', 'pass1', 'admin.php');
    await adminPage.goto(base);
    assert.equal(await adminPage.locator('#main-navigation a[href="admin.php"]').count(), 1);
    assert.equal(await adminPage.getByRole('link', { name: 'Open administration', exact: true }).getAttribute('href'), 'admin.php');
    await admin.close();
    await context.close();

    const native = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 844 } });
    const nativePage = await native.newPage();
    await nativePage.goto(base);
    assert(await nativePage.locator('#landing-title').isVisible());
    const nativeChoice = nativePage.locator('[data-feature-choice]').nth(2);
    const nativeFile = await nativeChoice.getAttribute('data-feature-choice');
    await nativeChoice.click();
    await assertSelection(nativePage, nativeFile);
    await fit(nativePage);
    await activeImage(nativePage).click();
    assert.equal(nativePage.url(), base + 'art-login.php');
    await native.close();

    const keyboardEntry = await browser.newContext({ reducedMotion: 'no-preference' });
    await keyboardEntry.addInitScript(() => {
      window.landingArrivalCalls = 0;
      const animate = Element.prototype.animate;
      Element.prototype.animate = function (...args) {
        if (this.matches('[data-landing-arrival]')) window.landingArrivalCalls++;
        return animate.apply(this, args);
      };
    });
    const keyboardEntryPage = await keyboardEntry.newPage();
    watch(keyboardEntryPage);
    await keyboardEntryPage.goto(base + 'art-login.php');
    await keyboardEntryPage.locator('.public-navigation a[href="index.php"]').focus();
    await Promise.all([
      keyboardEntryPage.waitForURL(base + 'index.php'),
      keyboardEntryPage.keyboard.press('Enter'),
    ]);
    assert.equal(await keyboardEntryPage.evaluate(() => window.landingArrivalCalls), 0, 'First internal keyboard entry is instant');
    await keyboardEntry.close();

    const quota = await browser.newContext({ reducedMotion: 'no-preference' });
    await quota.addInitScript(() => {
      window.landingArrivalCalls = 0;
      const animate = Element.prototype.animate;
      Element.prototype.animate = function (...args) {
        if (this.matches('[data-landing-arrival]')) window.landingArrivalCalls++;
        return animate.apply(this, args);
      };
      const setItem = Storage.prototype.setItem;
      Storage.prototype.setItem = function (key, value) {
        if (key === 'library-landing-seen') throw new DOMException('Quota exceeded', 'QuotaExceededError');
        return setItem.call(this, key, value);
      };
    });
    const quotaPage = await quota.newPage();
    watch(quotaPage);
    await quotaPage.goto(base);
    assert.equal(await quotaPage.evaluate(() => sessionStorage.getItem('library-landing-seen')), null, 'Storage reads remain available');
    assert.equal(await quotaPage.evaluate(() => window.landingArrivalCalls), 0, 'Denied storage write skips arrival');
    await quotaPage.reload();
    assert.equal(await quotaPage.evaluate(() => window.landingArrivalCalls), 0, 'Quota failure cannot repeat arrival');
    await quota.close();

    const animated = await browser.newContext({ reducedMotion: 'no-preference', viewport: { width: 1440, height: 1000 } });
    await animated.addInitScript(() => {
      window.captionAnimationCalls = 0;
      const animate = Element.prototype.animate;
      Element.prototype.animate = function (...args) {
        if (this.matches('[data-feature-caption]')) window.captionAnimationCalls++;
        return animate.apply(this, args);
      };
    });
    const animatedPage = await animated.newPage();
    watch(animatedPage);
    await animatedPage.goto(base);
    await activeImage(animatedPage).locator('img').evaluate(image => image.decode());
    await animatedPage.waitForTimeout(500);
    await animatedPage.locator('[data-feature-choice]').nth(1).click();
    await waitSelection(animatedPage, second);
    assert.equal(await animatedPage.evaluate(() => window.captionAnimationCalls), 1, 'Pointer choice fades the current caption');
    await animatedPage.evaluate(() => {
      const links = [...document.querySelectorAll('[data-feature-choice]')];
      for (const index of [2, 0, 1, 2, 0]) links[index].dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1, button: 0 }));
    });
    await assertSelection(animatedPage, first);
    await animatedPage.waitForTimeout(250);
    assert.equal(await animatedPage.locator('.landing-fade-ghost').count(), 0, 'Superseded fades clean up');
    await animatedPage.waitForTimeout(500);
    await assertSelection(animatedPage, first); // no autoplay
    await animatedPage.locator('[data-feature-choice]').nth(2).focus();
    const captionCalls = await animatedPage.evaluate(() => window.captionAnimationCalls);
    await animatedPage.keyboard.press('Enter');
    await waitSelection(animatedPage, works[2].file);
    assert.equal(await animatedPage.locator('.landing-fade-ghost').count(), 0, 'Keyboard is instant even with motion enabled');
    assert.equal(await animatedPage.evaluate(() => window.captionAnimationCalls), captionCalls, 'Keyboard caption does not animate');
    await animatedPage.reload();
    assert.equal(await animatedPage.locator('[data-landing-arrival]').evaluateAll(nodes => nodes.flatMap(node => node.getAnimations()).length), 0, 'Session arrival does not repeat');
    await animated.close();

    const failed = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 390, height: 844 } });
    await failed.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith(`/square-medium/${first}.jpg`) || (url.pathname.endsWith('/artwork-media.php') && url.searchParams.get('name') === first)) return route.abort();
      return route.continue();
    });
    const failedPage = await failed.newPage();
    watch(failedPage);
    await failedPage.goto(base);
    assert(await activeImage(failedPage).locator('.landing-image-failure').isVisible());
    assert(await activeCaption(failedPage).isVisible());
    await failedPage.locator('[data-feature-choice]').nth(1).click();
    await waitSelection(failedPage, second);
    await activeImage(failedPage).locator('img').evaluate(image => image.decode());
    await fit(failedPage);
    await failed.close();
    assert.deepEqual(errors, []);
    console.log('Public landing regression checks passed. No database rows were changed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
