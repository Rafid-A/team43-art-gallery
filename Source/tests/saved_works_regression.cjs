// "My collection" (migration 10, save-work.php, account.php). Optional,
// exclusive, ownership-labelled fixture only; restores every row it touches.
const { configure } = require('./browsing_test_config.cjs');
const { base, db } = configure({ writes: true });
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { spawnSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const others = 'Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects,art_users';
let checks = 0;
function check(ok, message) { assert.ok(ok, message); checks++; }
function sql(query, { root = false } = {}) {
  const args = root
    ? ['exec', '-i', db, 'sh', '-c', 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" art -N -B']
    : ['exec', '-i', '-e', 'MYSQL_PWD=mypassword', db, 'mariadb', '-utestuser', 'art', '-N', '-B'];
  const result = spawnSync('docker', args, { input: query, encoding: 'utf8' });
  if (result.status !== 0) throw Error('Fixture SQL failed: ' + result.stderr);
  return result.stdout.trim();
}
const savedRows = user => sql(`SELECT PaintingID FROM art_saved_works WHERE username='${user}' ORDER BY PaintingID`);
const csrfOf = html => (html.match(/name="csrf" value="([0-9a-f]{64})"/) || [])[1];

async function signIn(browser, user, password) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const login = await context.request.get(base + 'art-login.php');
  const response = await context.request.post(base + 'authenticate.php', {
    form: { csrf: csrfOf(await login.text()), userid: user, password }, maxRedirects: 0 });
  check(response.status() === 303 && !/art-login/.test(response.headers().location), user + ' signs in');
  return context;
}
async function post(context, form, csrf) {
  return context.request.post(base + 'save-work.php', { form: csrf === undefined ? form : { csrf, ...form }, maxRedirects: 0 });
}

(async () => {
  const migration = readFileSync(path.join(__dirname, '../artwork/sql/10-saved-works.sql'), 'utf8');
  sql(migration, { root: true });
  const definition = sql('SHOW CREATE TABLE art_saved_works', { root: true });
  sql(migration, { root: true });
  check(sql('SHOW CREATE TABLE art_saved_works', { root: true }) === definition, '10 is idempotent');
  check(/ON DELETE CASCADE ON UPDATE CASCADE/.test(definition) && /REFERENCES `Paintings`/.test(definition), '10 links users and paintings');

  const before = sql('CHECKSUM TABLE ' + others);
  const original = sql("SELECT username, PaintingID, SavedAt FROM art_saved_works WHERE username IN ('user1','user2')", { root: true });
  const [first, second] = sql('SELECT PaintingID FROM Paintings ORDER BY PaintingID LIMIT 2').split('\n').map(Number);
  const title = sql('SELECT Title FROM Paintings WHERE PaintingID=' + first);
  const missing = Number(sql('SELECT MAX(PaintingID) + 1000 FROM Paintings'));
  sql("DELETE FROM art_saved_works WHERE username IN ('user1','user2')", { root: true });
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER });
  try {
    // Logged-out visitors cannot save or see a profile.
    const guest = await browser.newContext();
    const guestPost = await post(guest, { id: String(first), action: 'save', return: 'account.php' }, 'x'.repeat(64));
    check(guestPost.status() === 303 && /art-login\.php$/.test(guestPost.headers().location), 'guest save redirects to login');
    check((await guest.request.get(base + 'art-main.php', { maxRedirects: 0 })).status() === 303, 'collection still requires login');
    check(savedRows('user2') === '', 'guest saved nothing');
    await guest.close();

    const client = await signIn(browser, 'user2', 'pass2');
    const detailUrl = base + 'painting.php?id=' + first + '&q=a+b&page=1';
    let page = await (await client.request.get(detailUrl)).text();
    check(page.includes('>Save to my collection</button>'), 'artwork page offers save');
    const csrf = csrfOf(page);
    const returnUrl = (page.match(/name="return" value="([^"]+)"/) || [])[1].replaceAll('&amp;', '&');
    check(returnUrl.startsWith('painting.php?id=' + first) && returnUrl.includes('q=a+b'), 'save returns to the same view');

    // Method, CSRF, action and painting checks never change data.
    check((await client.request.get(base + 'save-work.php', { maxRedirects: 0 })).status() === 405, 'GET is refused');
    check((await post(client, { id: String(first), action: 'save' })).status() === 403, 'missing CSRF is refused');
    check((await post(client, { id: String(first), action: 'save' }, 'f'.repeat(64))).status() === 403, 'wrong CSRF is refused');
    check((await post(client, { id: String(first), action: 'delete-all' }, csrf)).status() === 400, 'unknown action is refused');
    check((await post(client, { id: String(missing), action: 'save' }, csrf)).status() === 404, 'missing artwork is refused');
    check((await post(client, { id: '0 OR 1=1', action: 'save' }, csrf)).status() === 404, 'malformed id is refused');
    check(savedRows('user2') === '', 'refused requests saved nothing');

    const saved = await post(client, { id: String(first), action: 'save', return: returnUrl }, csrf);
    check(saved.status() === 303 && saved.headers().location === returnUrl, 'save redirects back');
    check(savedRows('user2') === String(first), 'save stores one row for the session user');
    check((await post(client, { id: String(first), action: 'save', return: returnUrl }, csrf)).status() === 303
      && savedRows('user2') === String(first), 'repeated save is harmless');
    page = await (await client.request.get(detailUrl)).text();
    check(page.includes('>Remove from my collection</button>') && page.includes('is saved in your collection'), 'artwork page shows saved state');
    await post(client, { id: String(second), action: 'save', username: 'user1' }, csrf);
    check(savedRows('user2') === first + '\n' + second && savedRows('user1') === '', 'username comes only from the session');

    for (const target of ['https://example.com/', '//example.com/', 'painting.php?id=1\r\nX: y', 'admin.php', 'account.php#x', 'javascript:alert(1)']) {
      const response = await post(client, { id: String(first), action: 'save', return: target }, csrf);
      check(response.headers().location === 'painting.php?id=' + first, 'unsafe return falls back: ' + JSON.stringify(target));
    }

    const account = await (await client.request.get(base + 'account.php')).text();
    check(account.includes('id="my-collection-title">My collection') && account.includes(title.replaceAll('&', '&amp;')), 'profile lists saved work');
    check((account.match(/class="art-card"/g) || []).length === 2 && account.includes('<dd>2</dd>'), 'profile shows both saved works and count');

    // Other accounts see only their own list.
    const admin = await signIn(browser, 'user1', 'pass1');
    const adminAccount = await (await admin.request.get(base + 'account.php')).text();
    check(!adminAccount.includes('class="art-card"') && adminAccount.includes('not saved any artworks'), 'other user has an empty list');
    const adminCsrf = csrfOf(adminAccount);
    await post(admin, { id: String(first), action: 'remove', username: 'user2' }, adminCsrf);
    check(savedRows('user2') === first + '\n' + second, "another user cannot remove someone else's save");
    await admin.close();

    // Responsive check of the populated profile.
    const phone = await browser.newPage({ viewport: { width: 320, height: 740 } });
    await phone.context().addCookies((await client.storageState()).cookies);
    await phone.goto(base + 'account.php');
    check(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'profile has no horizontal overflow at 320px');
    const buttons = await phone.locator('.saved-work-form button').evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().height));
    check(buttons.length === 2 && buttons.every(h => h >= 44), 'remove buttons keep 44px touch targets');
    await phone.close();

    const removed = await post(client, { id: String(second), action: 'remove', return: 'account.php#my-collection' }, csrf);
    check(removed.headers().location === 'account.php#my-collection' && savedRows('user2') === String(first), 'remove from profile works');

    // Before migration 10 the feature is hidden and the endpoint pauses.
    sql('RENAME TABLE art_saved_works TO art_saved_works_paused', { root: true });
    try {
      page = await (await client.request.get(detailUrl)).text();
      check(!page.includes('saved-work-form') && page.includes('<h1>'), 'artwork page works without the table');
      check((await (await client.request.get(base + 'account.php')).text()).includes('unavailable until the database upgrade'), 'profile explains the pending upgrade');
      check((await post(client, { id: String(first), action: 'save' }, csrf)).status() === 503, 'endpoint pauses without the table');
    } finally {
      sql('RENAME TABLE art_saved_works_paused TO art_saved_works', { root: true });
    }

    // Deleting an account removes its saved entries.
    sql(`INSERT INTO art_users (forename, surname, username, password) VALUES ('Tmp','Saved','sw_tmp_user','x');
      INSERT INTO art_saved_works (username, PaintingID) VALUES ('sw_tmp_user', ${first});
      DELETE FROM art_users WHERE username='sw_tmp_user';`, { root: true });
    check(savedRows('sw_tmp_user') === '', 'account deletion cascades');
    await client.close();
  } finally {
    await browser.close();
    sql("DELETE FROM art_saved_works WHERE username IN ('user1','user2','sw_tmp_user')", { root: true });
    for (const line of original.split('\n').filter(Boolean)) {
      const [user, id, at] = line.split('\t');
      sql(`INSERT INTO art_saved_works VALUES ('${user}', ${Number(id)}, '${at}')`, { root: true });
    }
  }
  check(sql('CHECKSUM TABLE ' + others) === before, 'other tables unchanged');
  console.log(`Saved works: migration, save/remove, own-list isolation, CSRF/method/input refusals, safe returns, profile list, 320px layout, pre-upgrade pause and cascade passed (${checks} checks).`);
})().catch(error => { console.error(error); process.exit(1); });
