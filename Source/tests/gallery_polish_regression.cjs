// Run only after the parent prepares an exclusive, labelled browsing fixture.
// Uses existing records and session preferences; performs no database mutations.
const { base, db } = require('./browsing_test_config.cjs').configure();
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const sql = query => execFileSync('docker', ['exec', '-e', 'MYSQL_PWD=mypassword', db,
  'mariadb', '-utestuser', 'art', '-N', '-B', '-e', query], { encoding: 'utf8' }).trim();
const fixture = JSON.parse(sql(`SELECT JSON_OBJECT('artist', a.ArtistID, 'title', p.Title,
  'name', CONCAT_WS(' ', a.FirstName, a.LastName), 'birth', a.YearOfBirth, 'death', a.YearOfDeath,
  'details', a.Details, 'works', (SELECT COUNT(*) FROM Paintings w WHERE w.ArtistID=a.ArtistID))
  FROM Paintings p JOIN Artists a ON a.ArtistID=p.ArtistID
  WHERE COALESCE(p.ImageFileName, '') <> '' ORDER BY p.PaintingID LIMIT 1`));
const collection = context => base + 'art-main.php?' + new URLSearchParams(context);
const directory = { directory_type: 'artists', directory_manage: '0', directory_page: '2', directory_sort: 'nationality', directory_dir: 'desc' };
const errors = [];

async function login(page) {
  await page.goto(base + 'art-login.php');
  await page.locator('#userid').fill(process.env.BROWSING_USER || 'user2');
  await page.locator('#password').fill(process.env.BROWSING_PASSWORD || 'pass2');
  await Promise.all([
    page.waitForURL(/\/(art-main|admin)\.php$/),
    page.getByRole('button', { name: 'Log in', exact: true }).click(),
  ]);
  await page.goto(base + 'art-main.php');
}
async function goto(page, url) {
  await page.goto(url);
  await page.waitForLoadState('load');
}
async function assertFit(page) {
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow');
}
function assertQuery(href, expected) {
  const query = new URL(href, base).searchParams;
  for (const [key, value] of Object.entries(expected)) assert.equal(query.get(key), String(value), key);
}
async function checkImages(page, selector) {
  const images = page.locator(selector);
  assert(await images.count() > 0, `${selector} should include existing art`);
  for (let index = 0; index < await images.count(); index++) {
    const image = images.nth(index);
    await image.scrollIntoViewIfNeeded();
    await image.evaluate(node => node.decode());
    assert(await image.evaluate(node => node.naturalWidth > 0 && getComputedStyle(node).objectFit === 'contain'));
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER || undefined });
  try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => {
      if (message.type() === 'error' && /content security policy|refused to (execute|apply)/i.test(message.text())) errors.push(message.text());
    });
    await login(page);

    const menu = page.locator('.site-menu');
    const menuToggle = menu.locator('summary');
    assert.equal(await menu.getAttribute('open'), null);
    assert.equal(await menuToggle.getAttribute('aria-expanded'), 'false');
    assert.equal(await page.locator('#main-navigation a').first().isVisible(), false);
    await menuToggle.focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('.site-menu summary').getAttribute('aria-expanded') === 'true');
    await page.keyboard.press('Tab');
    assert(await page.evaluate(() => document.querySelector('#main-navigation').contains(document.activeElement)));
    await page.keyboard.press('Escape');
    assert.equal(await menu.getAttribute('open'), null);
    assert(await menuToggle.evaluate(node => node === document.activeElement));

    const feature = page.locator('[data-featured-artwork]');
    assert.equal(await feature.count(), 1);
    const firstArt = page.locator('.featured-image img');
    await firstArt.evaluate(node => node.decode());
    const box = await firstArt.boundingBox();
    assert(box.y >= 0 && box.y + Math.min(box.height, 80) < 844, 'Real artwork is visible in the initial phone viewport');
    assert(await page.locator('.topbar').evaluate(node => node.getBoundingClientRect().height < 110));
    assert.equal(await page.locator('.collection-filters').getAttribute('open'), null);
    assert(await page.locator('#q').isVisible());
    await assertFit(page);

    const filtersToggle = page.locator('.collection-filters summary');
    await filtersToggle.click();
    await page.locator('#artist').selectOption(String(fixture.artist));
    await page.locator('#collection-sort').selectOption('artist:desc');
    assert.equal(await page.locator('#sort').inputValue(), 'artist');
    assert.equal(await page.locator('#dir').inputValue(), 'desc');
    assert.equal(await page.locator('#collection-sort').getAttribute('name'), null, 'Only canonical sort fields submit');
    await page.locator('#artist-lookup').fill('a');
    await page.locator('#artist-lookup-suggestions [role=option]').first().waitFor();
    await page.locator('#artist-lookup').press('Escape');
    assert(await page.locator('.collection-filters').evaluate(node => node.open), 'Suggestion Escape leaves filters open');
    await page.locator('#artist-lookup').press('Escape');
    assert.equal(await page.locator('.collection-filters').getAttribute('open'), null);
    assert(await filtersToggle.evaluate(node => node === document.activeElement));
    await filtersToggle.click();
    await Promise.all([page.waitForURL('**/art-main.php?*'), page.getByRole('button', { name: 'Apply filters', exact: true }).click()]);
    assertQuery(page.url(), { artist: fixture.artist, sort: 'artist', dir: 'desc' });
    assert.equal(await feature.count(), 0, 'Filtered views have no featured opening');

    const filtered = { artist: String(fixture.artist), q: fixture.title, page: '1', sort: 'artist', dir: 'desc', ...directory };
    await goto(page, collection(filtered));
    assert.equal(await page.locator('.filter-chip').count(), 2);
    assertQuery(await page.locator('[data-remove-filter=q]').getAttribute('href'), { ...filtered, q: '' });
    assertQuery(await page.locator('[data-remove-filter=artist]').getAttribute('href'), { ...filtered, artist: '0' });
    await page.locator('[data-remove-filter=q]').click();
    assertQuery(page.url(), { ...filtered, q: '' });
    assert.equal(await page.locator('.filter-chip').count(), 1);
    await goto(page, collection({ ...filtered, q: 'no-such-artwork-polish-' + Date.now() }));
    assert(await page.getByRole('heading', { name: 'No matching artworks' }).isVisible());
    assertQuery(await page.getByRole('link', { name: 'View all artworks', exact: true }).getAttribute('href'), { ...filtered, artist: '0', q: '' });
    await page.getByRole('link', { name: 'View all artworks', exact: true }).click();
    assert(await page.locator('.art-card').count() > 0);

    await page.getByRole('button', { name: 'Compact', exact: true }).click();
    assert.equal(await page.locator('[data-collection-view]').getAttribute('data-density'), 'compact');
    assert.equal(await page.getByRole('button', { name: 'Compact', exact: true }).getAttribute('aria-pressed'), 'true');
    assert.equal(await page.locator('.art-card').first().evaluate(node => getComputedStyle(node.parentElement).gridTemplateColumns.split(' ').length), 2);
    await page.reload();
    assert.equal(await page.locator('[data-collection-view]').getAttribute('data-density'), 'compact');
    await page.getByRole('button', { name: 'Comfortable', exact: true }).click();
    assert.equal(await page.locator('.art-card').first().evaluate(node => getComputedStyle(node.parentElement).gridTemplateColumns.split(' ').length), 1);

    // A different sort/page URL must not borrow this view's return position.
    const longView = { artist: '0', q: '', page: '2', sort: 'artist', dir: 'desc', ...directory };
    await goto(page, collection(longView));
    assert.equal(await feature.count(), 0, 'Page two has a compact heading');
    assert(await page.locator('.art-card').count() >= 8, 'Fixture needs at least 32 artworks for scroll and page coverage');
    const previous = page.locator('.pagination [rel=prev]');
    assertQuery(await previous.getAttribute('href'), { ...longView, page: '1' });
    assert.equal(new URL(await previous.getAttribute('href'), page.url()).hash, '#collection-results');
    assert.match(await previous.getAttribute('aria-label'), /page 1 of/);
    const target = page.locator('.art-image').nth(6);
    await target.scrollIntoViewIfNeeded();
    const expectedY = await page.evaluate(() => scrollY);
    assert(expectedY > 200);
    await target.click();
    await page.waitForURL('**/painting.php?*');
    await page.locator('.breadcrumbs a').first().click();
    await page.waitForURL('**/art-main.php?*');
    await page.waitForFunction(y => Math.abs(scrollY - y) < 4, expectedY);
    assertQuery(page.url(), longView);
    await goto(page, collection({ ...longView, sort: 'title' }));
    assert.equal(await page.evaluate(() => scrollY), 0, 'Exact view keys keep positions separate');

    await page.setViewportSize({ width: 1440, height: 1000 });
    await goto(page, base + 'objects.php?type=artists');
    await checkImages(page, '.artist-thumbnail img');
    assert.equal(await page.locator('.artist-years').count(), await page.locator('.directory-row').count());
    assert.match(await page.locator('.directory-row').first().textContent(), /\d+ artworks/);
    await goto(page, base + `objects.php?type=artists&id=${fixture.artist}`);
    await checkImages(page, '.artist-profile-work img');
    assert.equal(await page.locator('.artist-life').count(), 1);
    assert.equal(await page.locator('.artist-story .prose').count(), fixture.details ? 1 : 0, 'Biography uses only recorded text');
    assert.equal(await page.locator('#artist-artworks .art-card').count(), Number(fixture.works));
    if (fixture.birth) assert((await page.locator('.artist-life').textContent()).includes(String(fixture.birth)));
    if (fixture.death) assert((await page.locator('.artist-life').textContent()).includes(String(fixture.death)));
    await goto(page, base + 'browse.php');
    await checkImages(page, '.discovery-card img');
    const discoveryLinks = await page.locator('.discovery-card').evaluateAll(nodes => nodes.map(node => node.href));
    for (const href of discoveryLinks) {
      const url = new URL(href);
      assert.equal(url.origin, new URL(base).origin);
      assert(url.pathname.endsWith('/objects.php'));
      assert(['artists', 'subjects', 'genres'].includes(url.searchParams.get('type')));
      assert(Number(url.searchParams.get('id')) > 0);
      const response = await page.request.get(href);
      assert.equal(response.status(), 200);
    }
    for (const width of [320, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 844 });
      await goto(page, base + 'art-main.php');
      await assertFit(page);
      if (width > 1024) assert(await page.locator('#main-navigation a').first().isVisible());
      await goto(page, base + 'objects.php?type=artists');
      await assertFit(page);
      await goto(page, base + 'browse.php');
      await assertFit(page);
    }

    // Blocked sessionStorage must not break navigation, density or suggestions.
    const blocked = await browser.newContext({ storageState: await context.storageState(), viewport: { width: 390, height: 844 } });
    await blocked.addInitScript(() => {
      Object.defineProperty(window, 'sessionStorage', { get() { throw new DOMException('Denied', 'SecurityError'); } });
    });
    const blockedPage = await blocked.newPage();
    blockedPage.on('pageerror', error => errors.push(error.message));
    await goto(blockedPage, base + 'art-main.php');
    await blockedPage.getByRole('button', { name: 'Compact', exact: true }).click();
    assert.equal(await blockedPage.locator('[data-collection-view]').getAttribute('data-density'), 'compact');
    await blockedPage.locator('.site-menu summary').click();
    assert(await blockedPage.locator('#main-navigation a').first().isVisible());
    await blocked.close();

    // Server rendering retains native forms, links and disclosure interaction.
    const native = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState(), viewport: { width: 390, height: 844 } });
    const nativePage = await native.newPage();
    await goto(nativePage, base + 'art-main.php');
    assert(await nativePage.locator('#sort').isVisible());
    assert(await nativePage.locator('#dir').isVisible());
    assert.equal(await nativePage.locator('[data-density-controls]').isVisible(), false);
    assert.equal(await nativePage.locator('#main-navigation a').first().isVisible(), false);
    await nativePage.locator('.site-menu summary').click();
    assert(await nativePage.locator('#main-navigation a').first().isVisible());
    await nativePage.locator('.site-menu summary').click();
    await nativePage.locator('#q').fill(fixture.title);
    await nativePage.locator('#sort').selectOption('artist');
    await nativePage.locator('#dir').selectOption('desc');
    await Promise.all([nativePage.waitForURL('**/art-main.php?*'), nativePage.getByRole('button', { name: 'View artworks', exact: true }).click()]);
    assertQuery(nativePage.url(), { q: fixture.title, sort: 'artist', dir: 'desc' });
    assert(await nativePage.locator('.art-card').count() > 0);
    await native.close();
    assert.deepEqual(errors, [], 'No page errors or CSP errors');
    console.log('Gallery polish: menu, mobile opening, filters, context chips, density, scroll returns, artist profiles, discovery, storage denial and native fallbacks passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
