const { base, db } = require('./browsing_test_config.cjs').configure({ writes: true });
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

function sql(query) {
  return execFileSync('docker', ['exec', '-e', 'MYSQL_PWD=mypassword', db, 'mariadb', '-utestuser', 'art', '-N', '-B', '-e', query], { encoding: 'utf8' }).trim();
}
function schemaSql(query) {
  // Administrative access is confined to the verified disposable DB container.
  return execFileSync('docker', ['exec', '-i', db, 'sh', '-c', 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" art -N -B'], { input: query, encoding: 'utf8' }).trim();
}
const tables = 'Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects';
const context = { artist: '0', q: '', page: '2', sort: 'artist', dir: 'desc' };
const collection = new URL('art-main.php?' + new URLSearchParams(context), base).href;
function assertContext(href, id) {
  const url = new URL(href, base);
  for (const [key, value] of Object.entries(context)) assert.equal(url.searchParams.get(key), value, `${url.href}: ${key}`);
  if (id) assert.equal(url.searchParams.get('id'), String(id));
}
async function login(page) {
  await page.goto(base + 'art-login.php');
  await page.locator('#userid').fill('user1');
  await page.locator('#password').fill('pass1');
  await Promise.all([page.waitForURL('**/admin.php'), page.locator('form button').click()]);
  await page.goto(base + 'art-main.php');
}

(async () => {
  const before = sql('CHECKSUM TABLE ' + tables);
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER });
  const ownedAssignments = [];
  let legacyRenamed = false;
  const errors = [];
  try {
    const ctx = await browser.newContext({ reducedMotion: 'no-preference' });
    const page = await ctx.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await page.goto(collection);
    await page.locator('#q').fill('A');
    await page.locator('#q-suggestions [role=option]').first().waitFor();
    await page.locator('#q').press('ArrowDown');
    await page.locator('#q').press('Enter');
    await page.waitForURL('**/painting.php?*');
    assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'));
    await page.goto(collection);
    await page.locator('#artist-lookup').fill('Man');
    await page.locator('#artist-lookup-suggestions [role=option]').first().click();
    await page.waitForURL('**/objects.php?*');
    assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'));
    await page.getByRole('link', { name: 'Add artist', exact: true }).click();
    await page.getByRole('link', { name: 'Cancel', exact: true }).click();
    assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'));
    console.log('Motion-enabled suggestion destinations and return context passed');

    await page.goto(collection);
    const paintingHref = await page.locator('.art-image').first().getAttribute('href');
    const paintingId = Number(new URL(paintingHref, base).searchParams.get('id'));
    assert(Number.isSafeInteger(paintingId) && paintingId > 0);
    await page.goto(new URL(paintingHref, base).href);
    const relatedLinks = await page.locator('.artist-byline a, .metadata a[href*="type=galleries"], .classification-list a[href*="type=genres&"], .classification-list a[href*="type=subjects&"]').evaluateAll(links => links.map(link => link.href));
    assert(relatedLinks.length >= 4, 'Expected artist, gallery and classifications on the fixture painting');
    for (const href of relatedLinks) {
      await page.goto(href);
      assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'), paintingId);
      const relatedArtwork = page.locator('.related .art-image').first();
      if (await relatedArtwork.count()) {
        await relatedArtwork.click();
        await page.waitForURL('**/painting.php?*');
        assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'));
        const directoryBack = new URL(await page.locator('.breadcrumbs a').nth(1).getAttribute('href'), base);
        assert.equal(directoryBack.searchParams.get('type'), new URL(href).searchParams.get('type'));
        await page.goto(directoryBack.href);
        assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'));
      }
    }
    for (const origin of ['collection', 'painting']) {
      const query = new URLSearchParams({type:'artists', manage:'1', page:'2', sort:'name', dir:'asc', origin});
      if (origin === 'painting') query.set('origin_id', String(paintingId));
      for (const [key, value] of Object.entries(context)) query.set('origin_' + key, value);
      await page.goto(base + 'objects.php?' + query);
      await page.locator('#directory-sort').selectOption('nationality');
      await page.locator('#directory-dir').selectOption('desc');
      await page.getByRole('button', { name:'Apply', exact:true }).click();
      assert.equal(new URL(page.url()).searchParams.get('page'), null, 'Sort submissions reset the directory page');
      assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'), origin === 'painting' ? paintingId : undefined);
    }
    const directoryView = {type:'artists', manage:'1', page:'2', sort:'nationality', dir:'desc'};
    await page.goto(base + 'objects.php?' + new URLSearchParams(directoryView));
    const artistWithWorks = await page.locator('.directory-row').evaluateAll(links => links.find(link => /[1-9]\d* artworks/.test(link.lastElementChild.textContent))?.href);
    assert(artistWithWorks, 'Expected a related-artwork fixture on directory page two');
    await page.goto(artistWithWorks);
    await page.locator('.related .art-image').first().click();
    await page.waitForURL('**/painting.php?*');
    const directoryBack = new URL(await page.locator('.breadcrumbs a').nth(1).getAttribute('href'), base);
    for (const [key, value] of Object.entries(directoryView)) assert.equal(directoryBack.searchParams.get(key), value);
    await page.locator('.artist-byline a').click();
    await page.locator('.breadcrumbs a').first().click();
    const roundTrip = new URL(await page.locator('.breadcrumbs a').nth(1).getAttribute('href'), base);
    for (const [key, value] of Object.entries(directoryView)) assert.equal(roundTrip.searchParams.get(key), value);
    for (let hop = 0; hop < 2; hop++) {
      await page.locator('.artist-byline a').click();
      await page.locator('.related .art-image').first().click();
      await page.waitForURL('**/painting.php?*');
      const repeatedReturn = new URL(await page.locator('.breadcrumbs a').nth(1).getAttribute('href'), base);
      for (const [key, value] of Object.entries(directoryView)) assert.equal(repeatedReturn.searchParams.get(key), value, `related hop ${hop}: ${key}`);
    }
    console.log('Related records, directory-to-artwork returns and origin-preserving sort submissions passed');

    for (const [type, table, key, parentTable, parentKey, label] of [
      ['painting-genres', 'PaintingGenres', 'PaintingGenreID', 'Genres', 'GenreID', 'genre'],
      ['painting-subjects', 'PaintingSubjects', 'PaintingSubjectID', 'Subjects', 'SubjectID', 'subject'],
    ]) {
      const assignmentView = {type, manage:'1', page:'2', sort:'classification', dir:'desc'};
      await page.goto(base + 'objects.php?' + new URLSearchParams(assignmentView));
      await page.locator('.directory-row').first().click();
      const metadataLinks = await page.locator('.metadata a').evaluateAll(links => links.map(link => link.href));
      assert.equal(metadataLinks.length, 2);
      for (const href of metadataLinks) {
        await page.goto(href);
        const back = new URL(await page.locator('.breadcrumbs a').filter({hasText:'Artwork ' + (label === 'genre' ? 'genres' : 'subjects')}).first().getAttribute('href'), base);
        for (const [key, value] of Object.entries(assignmentView)) assert.equal(back.searchParams.get(key), value);
      }
      const parentId = Number(sql(`SELECT ${parentKey} FROM ${parentTable} WHERE ${parentKey} NOT IN (SELECT ${parentKey} FROM ${table} WHERE PaintingID=${paintingId}) ORDER BY ${parentKey} LIMIT 1`));
      assert(parentId > 0, 'No unused classification fixture');
      await page.goto(new URL(paintingHref, base).href);
      await page.getByRole('link', { name: 'Assign ' + label, exact: true }).click();
      assert.equal(await page.locator('#PaintingID').inputValue(), String(paintingId));
      assertContext(await page.getByRole('link', { name: 'Cancel', exact: true }).getAttribute('href'), paintingId);
      await page.locator('#' + parentKey).selectOption(String(parentId));
      await page.locator('#form button').click();
      await page.waitForURL('**/painting.php?*');
      assertContext(page.url(), paintingId);
      const id = Number(sql(`SELECT ${key} FROM ${table} WHERE PaintingID=${paintingId} AND ${parentKey}=${parentId}`));
      assert(id > 0);
      ownedAssignments.push({ table, key, id, paintingId, parentKey, parentId });
      await page.locator(`a[href*="objects.php?"][href*="type=${type}"][href*="id=${id}&"]`).click();
      assertContext(await page.locator('.breadcrumbs a').first().getAttribute('href'), paintingId);
      await page.getByRole('link', { name: 'Remove assignment', exact: true }).click();
      assertContext(await page.getByRole('link', { name: 'Keep record', exact: true }).getAttribute('href'), paintingId);
      await page.getByRole('button', { name: 'Confirm removal', exact: true }).click();
      await page.waitForURL('**/painting.php?*');
      assertContext(page.url(), paintingId);
      assert.equal(sql(`SELECT COUNT(*) FROM ${table} WHERE ${key}=${id}`), '0');
    }
    console.log('Both painting assignment add/detail/keep/remove return flows passed');

    await page.goto(new URL(paintingHref, base).href);
    await page.setViewportSize({width:568, height:320});
    const viewerTrigger = page.locator('[data-artwork-viewer]');
    await viewerTrigger.focus();
    await viewerTrigger.press('Enter');
    await page.locator('#artwork-viewer[open]').waitFor();
    assert((await page.locator('.viewer-stage').boundingBox()).height >= 96);
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#artwork-viewer').open);
    await page.waitForFunction(() => document.activeElement === document.querySelector('[data-artwork-viewer]'));
    await page.getByRole('link', {name:'Skip to content'}).focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.activeElement === document.querySelector('#main'));
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    console.log('Short-viewport viewer, Escape focus return and skip-link regression passed');

    const touch = await browser.newContext({ hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } });
    const touchPage = await touch.newPage();
    await login(touchPage);
    await touchPage.locator('#q').fill('A');
    await touchPage.locator('#q-suggestions [role=option]').first().tap();
    await touchPage.waitForURL('**/painting.php?*');
    await touchPage.goto(base + 'art-main.php');
    await touchPage.locator('#artist-lookup').fill('Man');
    await touchPage.locator('#artist-lookup-suggestions [role=option]').first().tap();
    await touchPage.waitForURL('**/objects.php?*');
    await touch.close();
    console.log('Touch selection passed for both suggestion types');

    // This schema probe is confined to the ownership-verified disposable DB.
    // Retain the original table intact and restore it even if an assertion fails.
    assert.equal(sql("SHOW TABLES LIKE 'Eras_browsing_probe'"), '', 'Unknown pre-existing backup table');
    schemaSql('RENAME TABLE Eras TO Eras_browsing_probe;');
    legacyRenamed = true;
    schemaSql('CREATE TABLE Eras AS SELECT EraName, EraYears FROM Eras_browsing_probe;');
    for (const sort of ['name', 'years']) for (const dir of ['asc', 'desc']) {
      await page.goto(base + `objects.php?type=eras&sort=${sort}&dir=${dir}`);
      const columns = sort === 'name' ? `EraName ${dir}, EraYears ASC` : `EraYears ${dir}, EraName ASC`;
      const expected = sql('SELECT EraName FROM Eras ORDER BY ' + columns).split('\n');
      assert.deepEqual(await page.locator('.directory-row h2').allTextContents(), expected);
      assert.equal(await page.getByRole('link', { name: 'Add era', exact: true }).count(), 0);
      await page.locator('.directory-row').first().click();
      const back = new URL(await page.locator('.breadcrumbs a').last().getAttribute('href'), base);
      assert.equal(back.searchParams.get('sort'), sort);
      assert.equal(back.searchParams.get('dir'), dir);
    }
    schemaSql('DROP TABLE Eras;');
    schemaSql('RENAME TABLE Eras_browsing_probe TO Eras;');
    legacyRenamed = false;
    console.log('Legacy Era sorting, detail context and view-only behavior passed');
    assert.deepEqual(errors, [], 'Unhandled motion-enabled browser errors');
  } finally {
    await browser.close();
    for (const row of ownedAssignments) sql(`DELETE FROM ${row.table} WHERE ${row.key}=${row.id} AND PaintingID=${row.paintingId} AND ${row.parentKey}=${row.parentId}`);
    if (legacyRenamed) {
      schemaSql('DROP TABLE IF EXISTS Eras;');
      schemaSql('RENAME TABLE Eras_browsing_probe TO Eras;');
    }
    assert.equal(sql('CHECKSUM TABLE ' + tables), before, 'Baseline data changed');
    console.log('All eight baseline table checksums preserved');
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
