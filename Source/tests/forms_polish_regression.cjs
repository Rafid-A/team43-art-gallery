// Read-only database/browser checks on an ownership-verified disposable fixture.
const { configure } = require('./browsing_test_config.cjs');
const { base, db } = configure();
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
let checks = 0;
const check = (value, message) => { assert.ok(value, message); checks++; };
const sql = query => execFileSync('docker', ['exec', '-i', '-e', 'MYSQL_PWD=mypassword', db,
  'mariadb', '-utestuser', 'art', '-N', '-B'], { input: query, encoding: 'utf8' }).trim();
const tables = 'Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects,art_users';

(async () => {
  const original = sql('CHECKSUM TABLE ' + tables);
  const browser = await chromium.launch({ executablePath: process.env.BROWSING_BROWSER });
  try {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + 'art-login.php');
    check(await page.locator('#userid').getAttribute('autocomplete') === 'username', 'username autocomplete');
    check(await page.locator('#password').getAttribute('autocomplete') === 'current-password', 'password autocomplete');
    await page.locator('#password').fill('Secret-visual-check');
    const toggle = page.locator('[data-password-toggle="password"]');
    await toggle.click();
    check(await page.locator('#password').getAttribute('type') === 'text', 'password can be shown');
    check(await toggle.getAttribute('aria-pressed') === 'true', 'shown state announced');
    check(await page.locator('#password').inputValue() === 'Secret-visual-check' && page.url().endsWith('art-login.php'), 'toggle preserves password and does not submit');
    await toggle.press('Enter');
    check(await page.locator('#password').getAttribute('type') === 'password', 'keyboard can hide password');
    check(await toggle.getAttribute('aria-pressed') === 'false', 'hidden state announced');
    await page.goto(base + 'register.php');
    for (const id of ['password', 'confirm']) {
      check(await page.locator('#' + id).getAttribute('autocomplete') === 'new-password', id + ' new-password autocomplete');
      await page.locator('#' + id).fill('New-password-visual');
      await page.locator('[data-password-toggle="' + id + '"]').click();
      check(await page.locator('#' + id).getAttribute('type') === 'text', id + ' independent visibility');
    }
    await page.goto(base + 'art-login.php');
    await page.locator('#userid').fill('user1');
    await page.locator('#password').fill('pass1');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL('**/admin.php');
    check(await page.locator('.admin-overview dd').count() === 3, 'administrator overview grouped');
    check(await page.locator('.admin-overview dd').first().innerText() === sql('SELECT COUNT(*) FROM Paintings'), 'overview reports real count');
    const artist = sql('SELECT MIN(ArtistID) FROM Artists');
    const painting = sql('SELECT MIN(PaintingID) FROM Paintings');
    for (const endpoint of ['art-add.php', 'artist-edit.php?id=' + artist,
      'painting-edit.php?id=' + painting, 'object-add.php?type=galleries']) {
      await page.goto(base + endpoint);
      check(await page.locator('.form-section > legend').count() >= 1, endpoint + ' grouped fields');
      const actions = page.locator('.form-actions');
      check(await actions.count() === 1 && await actions.locator('a').innerText() === 'Cancel', endpoint + ' save/cancel bar');
      await actions.scrollIntoViewIfNeeded();
      check(await actions.evaluate(element => getComputedStyle(element).position === 'sticky'), endpoint + ' sticky actions');
      check(await actions.locator('button.primary').isVisible(), endpoint + ' save stays available');
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), endpoint + ' mobile width');
    }
    await page.goto(base + 'users.php');
    check(await page.locator('.account-badge').count() === 4, 'account states have visible labels');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'account actions fit mobile');
    check(errors.length === 0, 'no browser errors');
    const native = await browser.newContext({ baseURL: base, javaScriptEnabled: false });
    const nativePage = await native.newPage();
    await nativePage.goto(base + 'art-login.php');
    check(await nativePage.locator('[data-password-toggle]').isHidden(), 'no inert password toggle without JavaScript');
    await nativePage.locator('#userid').fill('user2');
    await nativePage.locator('#password').fill('pass2');
    await nativePage.getByRole('button', { name: 'Log in', exact: true }).click();
    await nativePage.waitForURL('**/art-main.php');
    check(await nativePage.locator('.collection').count() === 1, 'native login remains usable');
  } finally {
    await browser.close();
    check(sql('CHECKSUM TABLE ' + tables) === original, 'gallery and accounts unchanged');
  }
  console.log(`Form/account visual regressions passed (${checks} assertions).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
