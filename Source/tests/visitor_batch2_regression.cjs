// Read-only visitor checks. Uses only the existing ownership-verified fixture;
// no record writes, fixture-write opt-in, source rewrites or service lifecycle.
const { base, db, web } = require('./browsing_test_config.cjs').configure();
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const sql = query => execFileSync('docker', ['exec', '-e', 'MYSQL_PWD=mypassword', db,
  'mariadb', '-utestuser', 'art', '-N', '-B', '-e', query], { encoding: 'utf8' }).trim();
const jsonRows = query => sql(query).split(/\r?\n/).filter(Boolean).map(JSON.parse);
const checksums = () => sql('CHECKSUM TABLE Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects,art_users');
const baseline = checksums();

// Independent EXISTS counts do not multiply artworks when assignments repeat.
// These are editorial names, never seed IDs or assumed baseline counters.
const themeRecords = jsonRows(`SELECT JSON_OBJECT('type','subjects','id',s.SubjectID,'name',s.SubjectName,
  'count',(SELECT COUNT(*) FROM Paintings p WHERE EXISTS
    (SELECT 1 FROM PaintingSubjects j WHERE j.PaintingID=p.PaintingID AND j.SubjectID=s.SubjectID)))
  FROM Subjects s WHERE s.SubjectName IN ('Landscape','People') ORDER BY s.SubjectID`)
  .concat(jsonRows(`SELECT JSON_OBJECT('type','genres','id',g.GenreID,'name',g.GenreName,
  'count',(SELECT COUNT(*) FROM Paintings p WHERE EXISTS
    (SELECT 1 FROM PaintingGenres j WHERE j.PaintingID=p.PaintingID AND j.GenreID=g.GenreID)))
  FROM Genres g WHERE g.GenreName='Impressionism' ORDER BY g.GenreID`));
const expected = [['landscape', 'Landscape', 'subjects'], ['people', 'People', 'subjects'], ['impressionism', 'Impressionism', 'genres']]
  .flatMap(([key, name, type]) => {
    const record = themeRecords.find(row => row.type === type && row.name.toLowerCase() === name.toLowerCase() && Number(row.count) > 0);
    return record ? [{ key, ...record }] : [];
  });
assert.equal(expected.length, 3, 'Use the baseline classroom fixture with all three thematic routes');
const members = jsonRows(`SELECT JSON_OBJECT('type','subjects','theme',s.SubjectID,'id',p.PaintingID,
  'file',p.ImageFileName,'title',p.Title,'artist',CONCAT_WS(' ',a.FirstName,a.LastName),
  'year',p.YearOfWork,'credit',p.CopyrightText)
  FROM Subjects s JOIN Paintings p ON EXISTS (SELECT 1 FROM PaintingSubjects j
    WHERE j.SubjectID=s.SubjectID AND j.PaintingID=p.PaintingID)
  LEFT JOIN Artists a ON a.ArtistID=p.ArtistID WHERE s.SubjectName IN ('Landscape','People')`)
  .concat(jsonRows(`SELECT JSON_OBJECT('type','genres','theme',g.GenreID,'id',p.PaintingID,
  'file',p.ImageFileName,'title',p.Title,'artist',CONCAT_WS(' ',a.FirstName,a.LastName),
  'year',p.YearOfWork,'credit',p.CopyrightText)
  FROM Genres g JOIN Paintings p ON EXISTS (SELECT 1 FROM PaintingGenres j
    WHERE j.GenreID=g.GenreID AND j.PaintingID=p.PaintingID)
  LEFT JOIN Artists a ON a.ArtistID=p.ArtistID WHERE g.GenreName='Impressionism'`));
const artwork = jsonRows(`SELECT JSON_OBJECT('id',p.PaintingID,'title',p.Title,
  'artist',CONCAT_WS(' ',a.FirstName,a.LastName)) FROM Paintings p
  LEFT JOIN Artists a ON a.ArtistID=p.ArtistID WHERE p.ImageFileName='019130' ORDER BY p.PaintingID LIMIT 1`)[0];
assert(artwork, 'Use the baseline bundled portrait for mobile reading-order checks');

// Exercise production selection/rendering without deleting or replacing rows.
const helperProbe = String.raw`
require '/var/www/html/artwork/functions.php';
require '/var/www/html/artwork/visitor-themes.php';
$record=['ThemeType'=>'subjects','ThemeID'=>17,'ThemeName'=>'Landscape','WorkCount'=>3];
$candidate=['ThemeType'=>'subjects','ThemeID'=>17,'PaintingID'=>19,'Title'=>'<svg onload="alert(1)">',
  'ImageFileName'=>'missing','YearOfWork'=>null,'CopyrightText'=>'<script>bad</script>',
  'FirstName'=>'<b>Current</b>','LastName'=>'Artist'];
if (visitor_select_themes([],[]) !== []) throw new Exception('Deleted theme');
if (visitor_select_themes([array_replace($record,['WorkCount'=>0])],[]) !== []) throw new Exception('Empty theme');
if (visitor_select_themes([array_replace($record,['ThemeID'=>0])],[]) !== []) throw new Exception('Invalid identity');
$missing=visitor_select_themes([$record],[$candidate],static fn($name)=>null);
if (count($missing)!==1 || $missing[0]['preview']!==null || (int)$missing[0]['WorkCount']!==3) throw new Exception('Missing-media count');
ob_start(); visitor_theme_routes($missing); $html=ob_get_clean();
if (!str_contains($html,'Preview image unavailable.') || !str_contains($html,'3 artworks')
  || !str_contains($html,'objects.php?type=subjects&amp;id=17') || str_contains($html,'<img')) throw new Exception('Missing-media render');
$valid=array_replace($candidate,['PaintingID'=>20,'ImageFileName'=>'valid']);
$source=static fn($name)=>$name==='valid' ? ['url'=>'safe.jpg','width'=>1,'height'=>1,'name'=>'valid','version'=>str_repeat('a',64)] : null;
$selected=visitor_select_themes([$record],[$candidate,$valid],$source);
if ((int)$selected[0]['preview']['PaintingID']!==20) throw new Exception('Later valid candidate');
ob_start(); visitor_theme_routes($selected); $html=ob_get_clean();
if (str_contains($html,'<svg') || str_contains($html,'<script>') || str_contains($html,'<b>Current')
  || !str_contains($html,'&lt;svg') || !str_contains($html,'&lt;script&gt;')) throw new Exception('Caption escaping');
ob_start(); visitor_theme_routes([]); $html=ob_get_clean();
if ($html!=='') throw new Exception('Empty route render');
echo 'Theme selection and render checks passed';`;
assert.match(execFileSync('docker', ['exec', web, 'php', '-r', helperProbe], { encoding: 'utf8' }), /checks passed/);

const errors = [];
const watch = page => {
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error' && /content security policy|refused to (execute|apply)/i.test(message.text())) errors.push(message.text());
  });
};
const fit = async page => assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow');

async function login(page) {
  await page.goto(base + 'art-login.php');
  await page.locator('#userid').fill('user2');
  await page.locator('#password').fill('pass2');
  // Login returns to a page the guest was sent away from, otherwise the client home.
  await Promise.all([page.waitForURL(url => url.href.startsWith(base) && !url.pathname.endsWith('/art-login.php')), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
}

async function themeContent(page) {
  assert.equal(await page.locator('.thematic-discovery').count(), 1);
  assert.deepEqual(await page.locator('[data-theme-route]').evaluateAll(nodes => nodes.map(node => node.dataset.themeRoute)), expected.map(theme => theme.key));
  const result = [];
  for (const theme of expected) {
    const route = page.locator(`[data-theme-route="${theme.key}"]`);
    const href = `objects.php?type=${theme.type}&id=${theme.id}`;
    assert.equal(await route.locator('h3').textContent(), theme.name);
    assert.equal(await route.getAttribute('data-theme-count'), String(theme.count));
    assert.equal(await route.locator('.theme-count').textContent(), `${theme.count} ${Number(theme.count) === 1 ? 'artwork' : 'artworks'}`);
    assert.equal(await route.locator('.theme-route-link').getAttribute('href'), href);
    const image = route.locator('img');
    assert.equal(await image.count(), 1, 'Baseline theme has a useful preview');
    const title = await image.getAttribute('alt');
    const file = decodeURIComponent((await image.getAttribute('src')).split('/').pop().replace(/\.jpg$/, ''));
    const painting = members.find(row => row.type === theme.type && Number(row.theme) === Number(theme.id) && row.title === title && row.file === file);
    assert(painting, 'Preview belongs to this exact subject/genre record');
    const caption = await route.locator('.theme-caption').textContent();
    assert(caption.includes(painting.title));
    assert(caption.includes(painting.artist.trim() || 'Artist not recorded'));
    if (painting.year) assert(caption.includes(String(painting.year)));
    assert(caption.includes(painting.credit || 'See image credits for attribution'));
    result.push({ href, title, count: theme.count });
  }
  return result;
}

async function containedImages(page, selector) {
  for (const image of await page.locator(selector).all()) {
    await image.scrollIntoViewIfNeeded();
    await image.evaluate(node => node.decode());
    const measured = await image.evaluate(node => {
      const image = node.getBoundingClientRect(), parent = node.parentElement.getBoundingClientRect();
      return { fit: getComputedStyle(node).objectFit, width: image.width, height: image.height,
        ratio: node.naturalWidth / node.naturalHeight, inside: image.left >= parent.left - 1 && image.right <= parent.right + 1 && image.top >= parent.top - 1 && image.bottom <= parent.bottom + 1 };
    });
    assert.equal(measured.fit, 'contain');
    assert(measured.width > 0 && measured.height > 0 && measured.inside);
    assert(Math.abs(measured.width / measured.height - measured.ratio) < .02, 'Artwork keeps its aspect ratio');
  }
}

async function detailOrder(page, width) {
  await page.setViewportSize({ width, height: 844 });
  const response = await page.goto(base + `painting.php?id=${artwork.id}`);
  assert.equal(response.status(), 200);
  assert.equal(await page.locator('main h1').count(), 1);
  assert.equal(await page.locator('.painting-heading h1').textContent(), artwork.title);
  assert.equal((await page.locator('.painting-heading .artist-byline').textContent()).trim(), artwork.artist.trim());
  const order = await page.locator('.painting-detail').evaluate(node => {
    const header = node.querySelector('.painting-heading'), figure = node.querySelector('.painting-image'), copy = node.querySelector('.painting-copy');
    const box = element => { const r = element.getBoundingClientRect(); return { left: r.left, top: r.top, right: r.right, bottom: r.bottom }; };
    return { source: [...node.children].map(child => child.className), heading: box(header), image: box(figure), copy: box(copy),
      title: box(header.querySelector('h1')), artist: box(header.querySelector('.artist-byline')) };
  });
  assert.deepEqual(order.source, ['painting-heading', 'painting-image', 'painting-copy'], 'Natural title, image, metadata source order');
  if (width <= 768) {
    assert(order.heading.bottom <= order.image.top, 'Title/artist precede image on mobile');
    assert(order.image.bottom <= order.copy.top, 'Metadata follows mobile image');
    if (width === 390) assert(order.title.top >= 0 && order.artist.bottom <= 844, 'Title and artist are visible on opening at 390x844');
  } else {
    assert(order.image.right <= order.heading.left, 'Desktop image and copy stay side by side');
    assert(Math.abs(order.image.top - order.heading.top) < 2, 'Desktop title aligns with image top');
    assert(order.heading.bottom <= order.copy.top, 'Desktop body follows heading');
  }
  await fit(page);
}

(async () => {
  let browser;
  try {
    browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER || undefined });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const page = await context.newPage(); watch(page);
    assert.equal((await page.goto(base)).status(), 200);
    const publicThemes = await themeContent(page);
    for (const path of ['browse.php', 'art-main.php', `painting.php?id=${artwork.id}`, ...publicThemes.map(theme => theme.href)]) {
      const response = await context.request.get(base + path, { maxRedirects: 0 });
      assert.equal(response.status(), 303, 'Catalogue/detail route remains protected: ' + path);
      assert.equal(response.headers().location, 'art-login.php');
    }
    assert.equal((await context.request.get(base + 'visitor-themes.php')).status(), 403, 'Include-only helper denied');
    for (const width of [320, 390, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 844 });
      await page.goto(base);
      await fit(page);
      await containedImages(page, '.theme-preview img');
      if (width === 1440) {
        const sizes = await page.locator('.theme-preview').evaluateAll(nodes => nodes.map(node => ({ height: node.clientHeight, left: node.getBoundingClientRect().left })));
        assert(sizes[0].height > sizes[1].height && sizes[0].left < sizes[1].left, 'Theme composition has an editorial lead and smaller routes');
      }
    }

    await login(page);
    await page.goto(base + 'browse.php');
    assert.deepEqual(await themeContent(page), publicThemes, 'Home and Browse share current theme metadata/previews');
    assert(await page.locator('.discovery-card').count() > 0, 'Existing discovery cards retained');
    for (const type of ['genres', 'eras', 'subjects']) assert.equal(await page.locator(`.browse-tile[href="objects.php?type=${type}"]`).count(), 1, 'Full library route retained');
    assert(await page.locator('.thematic-discovery').evaluate(node => Boolean(node.compareDocumentPosition(document.querySelector('.discovery')) & Node.DOCUMENT_POSITION_FOLLOWING)));
    for (const theme of publicThemes) assert.equal((await context.request.get(base + theme.href)).status(), 200, 'Exact current theme route opens after login');
    for (const width of [320, 390, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 844 });
      await page.goto(base + 'browse.php');
      await fit(page);
      await containedImages(page, '.theme-preview img');
      await detailOrder(page, width);
    }
    await page.goto(base + 'art-main.php');
    assert.equal(await page.locator('.collection-discovery-link a').getAttribute('href'), 'browse.php#thematic-discovery');
    await detailOrder(page, 390);
    await page.locator('[data-artwork-viewer]').click();
    assert(await page.locator('.artwork-viewer').isVisible(), 'Viewer remains available after heading split');
    await page.keyboard.press('Escape');
    assert(!await page.locator('.artwork-viewer').isVisible());
    assert(await page.locator('[data-artwork-viewer]').evaluate(node => node === document.activeElement), 'Viewer returns focus');

    const nativeContext = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
    const nativePage = await nativeContext.newPage();
    await nativePage.goto(base);
    assert.deepEqual(await themeContent(nativePage), publicThemes, 'Public themes work without JavaScript');
    await login(nativePage);
    await nativePage.goto(base + 'browse.php');
    assert.deepEqual(await themeContent(nativePage), publicThemes, 'Browse themes work without JavaScript');
    await detailOrder(nativePage, 390);
    await nativeContext.close();
    assert.deepEqual(errors, [], 'No JavaScript or CSP errors');
    console.log('Visitor batch 2: current themes/counts/previews, exact protected routes, sparse/missing media, caption escaping, retained Browse library, responsive containment and single-heading reading order passed.');
  } finally {
    if (browser) await browser.close();
    assert.equal(checksums(), baseline, 'Gallery/account checksums unchanged');
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
