// Review fixes: emoji rejection on utf8mb3 library tables, worst-case text
// wrapping, the log-in notice with return to the requested page, the guest
// brand link, the rotating strip (Task 8) and navigation underline motion.
// Optional, exclusive, ownership-labelled fixture only; restores what it adds.
const { configure } = require('./browsing_test_config.cjs');
const { base, db, web } = configure({ writes: true });
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { spawnSync, execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

let checks = 0;
const check = (ok, message) => { assert.ok(ok, message); checks++; };
function sql(query) {
  const r = spawnSync('docker', ['exec', '-i', db, 'sh', '-c', 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" art -N -B'], { input: query, encoding: 'utf8' });
  if (r.status !== 0) throw Error('Fixture SQL failed: ' + r.stderr);
  return r.stdout.trim();
}
const csrfOf = html => (html.match(/name="csrf" value="([0-9a-f]{64})"/) || [])[1];
const tables = 'Artists,Paintings,Galleries,Genres,Eras,Subjects,PaintingGenres,PaintingSubjects,art_users,art_saved_works';

async function signIn(context, user, password) {
  const login = await context.request.get(base + 'art-login.php');
  return context.request.post(base + 'authenticate.php', { form: { csrf: csrfOf(await login.text()), userid: user, password }, maxRedirects: 0 });
}

(async () => {
  const before = sql('CHECKSUM TABLE ' + tables);
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER });
  const added = { artists: [], paintings: [], files: [] };
  try {
    // 1. Emoji cannot be stored in the course's utf8mb3 tables: refuse, never save "??".
    const admin = await browser.newContext();
    check((await signIn(admin, 'user1', 'pass1')).status() === 303, 'admin signs in');
    const artist = sql('SELECT CONCAT_WS("|", ArtistID, IFNULL(FirstName,""), LastName, IFNULL(Nationality,""), IFNULL(YearOfBirth,""), IFNULL(YearOfDeath,"")) FROM Artists WHERE LastName <> "" ORDER BY ArtistID LIMIT 1').split('|');
    const artistRow = () => sql('SELECT CONCAT_WS("|", FirstName, LastName, Nationality, YearOfBirth, YearOfDeath) FROM Artists WHERE ArtistID=' + artist[0]);
    const artistBefore = artistRow();
    let page = await (await admin.request.get(base + 'artist-edit.php?id=' + artist[0])).text();
    let response = await admin.request.post(base + 'artist-edit.php?id=' + artist[0], { form: { csrf: csrfOf(page), FirstName: 'Claude 🎨', LastName: artist[2], Nationality: artist[3], YearOfBirth: artist[4], YearOfDeath: artist[5] }, maxRedirects: 0 });
    check(response.status() === 422 && (await response.text()).includes('First name cannot contain emoji'), 'artist editor refuses emoji with a clear message');
    check(artistRow() === artistBefore, 'artist record unchanged');

    const painting = sql('SELECT CONCAT_WS("|", PaintingID, ArtistID, GalleryID, YearOfWork, Medium) FROM Paintings WHERE ArtistID > 0 AND GalleryID > 0 AND YearOfWork > 0 AND Medium <> "" ORDER BY PaintingID LIMIT 1').split('|');
    const paintingRow = () => sql('SELECT CONCAT_WS("|", Title, Medium) FROM Paintings WHERE PaintingID=' + painting[0]);
    const paintingBefore = paintingRow();
    page = await (await admin.request.get(base + 'painting-edit.php?id=' + painting[0])).text();
    response = await admin.request.post(base + 'painting-edit.php?id=' + painting[0], { form: { csrf: csrfOf(page), Title: 'Starry 🌙 night', ArtistID: painting[1], GalleryID: painting[2], YearOfWork: painting[3], Medium: painting[4] }, maxRedirects: 0 });
    check(response.status() === 422 && (await response.text()).includes('The title cannot contain emoji'), 'painting editor refuses emoji');
    check(paintingRow() === paintingBefore, 'painting record unchanged');

    const genres = sql('SELECT COUNT(*) FROM Genres');
    page = await (await admin.request.get(base + 'object-add.php?type=genres')).text();
    response = await admin.request.post(base + 'object-add.php?type=genres', { form: { csrf: csrfOf(page), GenreName: 'Pop 🎨 art', Description: '', Link: '' }, maxRedirects: 0 });
    check(response.status() === 422 && sql('SELECT COUNT(*) FROM Genres') === genres, 'directory add refuses emoji');

    // The browser shows the same rule before submitting.
    const ui = await browser.newPage({ storageState: await admin.storageState() });
    await ui.goto(base + 'artist-edit.php?id=' + artist[0]);
    await ui.fill('#Nationality', 'Française 😀');
    check(await ui.locator('#Nationality').evaluate(el => el.validity.customError && /emoji/.test(el.validationMessage)), 'client check flags emoji');
    await ui.fill('#Nationality', 'Française ★ Ünïcødé');
    check(await ui.locator('#Nationality').evaluate(el => el.validity.valid), 'client check accepts accented and BMP symbols');
    await ui.close();

    // 2. Worst-case text wraps instead of widening any page.
    for (let i = 0; i < 2; i++) {
      const name = 'review-wc-' + Date.now() + '-' + i;
      execFileSync('docker', ['exec', web, 'cp', '/var/www/html/artwork/images/art/works/square-medium/019130.jpg', `/var/www/html/artwork/images/uploads/${name}.jpg`]);
      added.files.push(name);
    }
    const longWord = 'Wolfeschlegelsteinhausenbergerdorffxxxxxxxxxxxxxxx';
    const longTitle = 'Supercalifragilisticexpialidocious'.repeat(8).slice(0, 255);
    added.artists.push(Number(sql(`INSERT INTO Artists (FirstName, LastName, Nationality) VALUES ('Ünïcødé', '${longWord}', 'محمد العربي'); SELECT LAST_INSERT_ID();`)));
    for (const [title, file] of [[longTitle, added.files[0]], ['ب', added.files[1]]])
      added.paintings.push(Number(sql(`INSERT INTO Paintings (ArtistID, ImageFileName, Title, YearOfWork, Medium) VALUES (${added.artists[0]}, '${file}', '${title}', -500, 'Oil'); SELECT LAST_INSERT_ID();`)));
    sql(`INSERT INTO art_saved_works (username, PaintingID) VALUES ('user1', ${added.paintings[0]})`);
    const wide = await browser.newPage({ storageState: await admin.storageState() });
    for (const width of [320, 768, 1440]) {
      await wide.setViewportSize({ width, height: 900 });
      for (const route of ['art-main.php?q=Supercali', 'painting.php?id=' + added.paintings[0], 'objects.php?type=artists&id=' + added.artists[0], 'account.php']) {
        await wide.goto(base + route);
        const overflow = await wide.evaluate(() => document.documentElement.scrollWidth - innerWidth);
        check(overflow <= 0, `${route} fits at ${width}px (overflow ${overflow})`);
      }
    }
    // Counts of exactly one read as singular.
    await wide.setViewportSize({ width: 1280, height: 900 });
    await wide.goto(base + 'art-main.php?q=Supercali');
    check(/of 1 painting(?!s)/.test(await wide.locator('.result-count').textContent()), 'collection count is singular for one painting');
    let counts = '';
    for (let directoryPage = 1; directoryPage <= 20; directoryPage++) {
      const html = await (await wide.context().request.get(base + 'objects.php?type=artists&page=' + directoryPage)).text();
      counts += (html.match(/>\d+ artworks?\b/g) || []).join(' ');
      if (!html.includes('rel="next"')) break;
    }
    check(/>1 artwork(?!s)/.test(counts) && !/>1 artworks/.test(counts), 'directory says "1 artwork", never "1 artworks"');
    await wide.close();
    await admin.close();

    // 3. Signed-out visitors are told to log in, then returned to the page.
    const guest = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gp = await guest.newPage();
    const target = `painting.php?id=${painting[0]}&q=a+b&page=1`;
    await gp.goto(base + target);
    check(gp.url().endsWith('art-login.php'), 'guest is sent to log in');
    check(/Please log in to view that page/.test(await gp.locator('.notice').textContent()), 'log-in notice explains why');
    check(await gp.locator('.brand').getAttribute('href') === 'index.php', 'guest brand link goes Home, not to a login wall');
    await gp.fill('#userid', 'user2'); await gp.fill('#password', 'pass2');
    await Promise.all([gp.waitForURL('**/painting.php*'), gp.getByRole('button', { name: 'Log in', exact: true }).click()]);
    check(gp.url() === base + target, 'login returns to the requested page with its context');
    await gp.goto(base + 'art-login.php');
    check(gp.url().endsWith('art-main.php'), 'signed-in visit to log in still goes home');
    await guest.close();
    const fresh = await browser.newContext();
    check((await signIn(fresh, 'user2', 'pass2')).headers().location === 'art-main.php', 'without a pending page, login goes to the role home');
    const posted = await fresh.request.post(base + 'logout.php', { form: { csrf: csrfOf(await (await fresh.request.get(base + 'account.php')).text()) }, maxRedirects: 0 });
    check(posted.status() === 303, 'logged out');
    await fresh.request.post(base + 'save-work.php', { form: { id: painting[0], action: 'save' }, maxRedirects: 0 });
    check((await signIn(fresh, 'user2', 'pass2')).headers().location === 'art-main.php', 'a POST is never remembered as a return page');
    await fresh.close();

    // 4. The rotating strip (Task 8) and navigation motion.
    for (const reducedMotion of ['no-preference', 'reduce']) {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion });
      const home = await ctx.newPage();
      await home.goto(base + 'index.php');
      await home.locator('[data-landing-strip]').scrollIntoViewIfNeeded();
      await home.mouse.move(2, 2);
      const x = () => home.evaluate(() => new DOMMatrix(getComputedStyle(document.querySelector('.landing-strip-track')).transform).m41);
      const start = await x(); await home.waitForTimeout(700); const moved = (await x()) - start;
      const state = await home.evaluate(() => { const t = document.querySelector('.landing-strip-track');
        return { originals: t.querySelectorAll('li:not([data-strip-clone])').length, clones: t.querySelectorAll('[data-strip-clone][aria-hidden="true"]').length,
          focusableClones: t.querySelectorAll('[data-strip-clone] a:not([tabindex="-1"])').length, toggle: document.querySelector('[data-strip-toggle]').checkVisibility() }; });
      check(state.originals >= 4 && state.clones === state.originals && state.focusableClones === 0, reducedMotion + ': one hidden, unfocusable copy of the strip');
      if (reducedMotion === 'reduce') {
        check(moved === 0 && !state.toggle, 'reduced motion: still strip, no control needed');
      } else {
        check(moved < -5, 'strip shifts leftward on its own');
        await home.locator('[data-strip-toggle]').click(); await home.mouse.move(2, 2);
        const paused = await x(); await home.waitForTimeout(500);
        check(await x() === paused && (await home.locator('[data-strip-toggle]').textContent()) === 'Resume rotation', 'Pause control stops the strip');
        await home.locator('.landing-strip-image').first().focus();
        check(await home.evaluate(() => getComputedStyle(document.querySelector('.landing-strip-track')).animationPlayState) === 'paused', 'keyboard focus pauses the strip');
        check(await home.locator('.landing-strip-image').first().evaluate(link => {
          const item = link.getBoundingClientRect(), view = link.closest('.landing-strip-window').getBoundingClientRect();
          return item.left >= view.left - 1 && item.right <= view.right + 1;
        }), 'a focused work is brought into view, even the first after the track has moved');
      }
      await ctx.close();
    }
    const nav = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    check((await signIn(nav, 'user2', 'pass2')).status() === 303, 'client signs in for navigation');
    const np = await nav.newPage();
    await np.goto(base + 'browse.php');
    await np.mouse.move(400, 400); await np.mouse.move(420, 420);
    const scale = sel => np.locator(sel).evaluate(el => new DOMMatrix(getComputedStyle(el, '::after').transform).a);
    check(await scale('#main-navigation a[aria-current]') === 1, 'current page underline is drawn');
    check(await scale('#main-navigation a[href="art-main.php"]') === 0, 'other links have no underline at rest');
    await np.locator('#main-navigation a[href="art-main.php"]').hover(); await np.waitForTimeout(400);
    check(await scale('#main-navigation a[href="art-main.php"]') === 1, 'hover grows the underline');
    await nav.close();
  } finally {
    await browser.close();
    if (added.paintings.length) sql(`DELETE FROM art_saved_works WHERE PaintingID IN (${added.paintings}); DELETE FROM Paintings WHERE PaintingID IN (${added.paintings});`);
    if (added.artists.length) sql(`DELETE FROM Artists WHERE ArtistID IN (${added.artists})`);
    for (const name of added.files) execFileSync('docker', ['exec', web, 'rm', '-f', `/var/www/html/artwork/images/uploads/${name}.jpg`]);
  }
  check(sql('CHECKSUM TABLE ' + tables) === before, 'all gallery and account tables restored');
  console.log(`Review fixes: emoji refusal (server and browser), worst-case wrapping at 320/768/1440px, log-in notice and return, guest brand link, rotating strip with pause/focus/reduced motion, navigation underline passed (${checks} checks).`);
})().catch(error => { console.error(error); process.exit(1); });
