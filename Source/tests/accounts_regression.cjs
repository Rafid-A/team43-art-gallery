// Optional, exclusive disposable-fixture test. No application bypass endpoints.
const { configure } = require('./browsing_test_config.cjs');
const { base, db, web } = configure({ writes: true });
const { request } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync, spawnSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { randomBytes, createHash } = require('node:crypto');
const path = require('node:path');
const assert = require('node:assert/strict');

const marker = 'acct_' + randomBytes(6).toString('hex');
const names = new Set();
const contexts = [];
const galleryTables = 'Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects';
const migration = readFileSync(path.join(__dirname, '../artwork/sql/08-account-roles.sql'), 'utf8');
let checks = 0;
function check(value, message) {
  assert.ok(value, message);
  checks++;
}
function sql(query, { root = false, failure = false } = {}) {
  const args = root
    ? ['exec', '-i', db, 'sh', '-c', 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" art -N -B']
    : ['exec', '-i', '-e', 'MYSQL_PWD=mypassword', db, 'mariadb', '-utestuser', 'art', '-N', '-B'];
  const result = spawnSync('docker', args, { input: query, encoding: 'utf8' });
  if (failure) return result;
  if (result.status !== 0) throw Error('Fixture SQL failed: ' + result.stderr);
  return result.stdout.trim();
}
function php(code) {
  return execFileSync('docker', ['exec', '-i', '--user', 'www-data', web, 'php'], {
    input: '<?php ' + code, encoding: 'utf8'
  }).trim();
}
async function context() {
  const ctx = await request.newContext({ baseURL: base });
  contexts.push(ctx);
  return ctx;
}
async function get(ctx, page) {
  return ctx.get(page, { maxRedirects: 0 });
}
async function csrf(ctx, page = 'account.php') {
  const response = await get(ctx, page);
  check(response.status() === 200, page + ' loads');
  const match = (await response.text()).match(/name="csrf" value="([a-f0-9]{64})"/);
  check(!!match, 'form has a CSRF token');
  return match[1];
}
async function sessionId(ctx) {
  const state = await ctx.storageState();
  const cookie = state.cookies.find(c => c.name === 'team43_gallery');
  check(!!cookie && cookie.httpOnly && cookie.sameSite === 'Lax', 'session cookie protections');
  return cookie.value;
}
async function login(ctx, userid, password, destination) {
  const token = await csrf(ctx, 'art-login.php');
  const oldId = await sessionId(ctx);
  const response = await ctx.post('authenticate.php', {
    form: { userid, password, csrf: token }, maxRedirects: 0
  });
  check(response.status() === 303 && response.headers().location === destination, 'login destination for ' + userid);
  if (destination !== 'art-login.php') {
    check(await sessionId(ctx) !== oldId, 'login rotates session ID');
    check(await csrf(ctx) !== token, 'login rotates CSRF');
    const id = await sessionId(ctx);
    const shape = JSON.parse(php(`session_name('team43_gallery'); session_id('${id}'); session_start();
      echo json_encode(['keys'=>array_keys($_SESSION['user']),
        'bound'=>is_string($_SESSION['account_fingerprint'] ?? null) && strlen($_SESSION['account_fingerprint'])===64]);`));
    check(shape.bound, 'login captures server-only fingerprint');
    check(shape.keys.sort().join(',') === 'forename,role,surname,username', 'public account session shape excludes hash and fingerprint');
  }
}
function account(name) {
  return sql(`SELECT CONCAT(role, ':', status) FROM art_users WHERE username='${name}';`);
}
async function register(ctx, name, password = 'fixture-pass-123', extra = {}) {
  names.add(name);
  return ctx.post('register.php', { maxRedirects: 0, form: {
    csrf: await csrf(ctx, 'register.php'), forename: 'Fixture', surname: marker,
    userid: name, password, confirm: password, ...extra
  } });
}
async function change(admin, name, action, value = '') {
  const response = await admin.post('users.php', { maxRedirects: 0, form: {
    csrf: await csrf(admin, 'users.php'), username: name, action, value
  } });
  check(response.status() === 303 && response.headers().location === 'users.php', 'account change redirects');
}
async function denied(ctx, page = 'account.php') {
  const response = await get(ctx, page);
  check(response.status() === 303 && response.headers().location === 'art-login.php', 'stale/anonymous session denied: ' + page);
}

async function accountFlows() {
  configure({ writes: true }); // Recheck live ownership immediately before this mutation suite.
  const beforeAccounts = sql('CHECKSUM TABLE art_users');
  try {
    const anonymous = await context();
    await denied(anonymous, 'admin.php');
    const admin = await context();
    await login(admin, 'user1', 'pass1', 'admin.php');
    const client = await context();
    await login(client, 'user2', 'pass2', 'art-main.php');
    const stableId = await sessionId(client);
    const stableToken = await csrf(client);
    const profile = await get(client, 'account.php?username=user1');
    check((await profile.text()).includes('<dd>user2</dd>'), 'profile uses authenticated identity');
    check(await sessionId(client) === stableId && await csrf(client) === stableToken, 'ordinary requests preserve session and CSRF');
    for (const route of ['admin.php', 'users.php', 'manage.php', 'integrity.php', 'art-add.php',
      'painting-delete.php', 'art-delete.php', 'object-add.php', 'object-delete.php',
      'object-add.php?type=painting-genres', 'object-add.php?type=painting-subjects']) {
      const response = await get(client, route);
      check(response.status() === 403, 'client cannot open ' + route);
      const post = await client.post(route, { form: { csrf: await csrf(client) }, maxRedirects: 0 });
      check(post.status() === 403, 'client cannot submit ' + route);
    }

    const signup = await context();
    const name = marker + '_roles';
    let response = await register(signup, name, undefined, { role: 'admin', status: 'disabled' });
    check(response.status() === 303 && account(name) === 'client:active', 'forged registration role/status ignored');
    const passwordHash = sql(`SELECT password FROM art_users WHERE username='${name}'`);
    check(passwordHash.startsWith('$2y$'), 'registration stores bcrypt');
    response = await register(signup, name.toUpperCase());
    check(response.status() === 422 && (await response.text()).includes('already taken'), 'duplicate registration rejected');
    check(sql(`SELECT password FROM art_users WHERE username='${name}'`) === passwordHash, 'duplicate preserves password');

    for (const [suffix, password, extra, error] of [
      ['nul', 'pass\0word123', {}, 'NUL (null) bytes'],
      ['mismatch', 'valid-password', { confirm: 'different-password' }, 'do not match'],
      ['short', 'short', {}, '8 to 72'],
      ['long', 'p'.repeat(73), {}, '8 to 72'],
      ['names', 'valid-password', { forename: '', surname: 'x'.repeat(33) }, 'First name is required']
    ]) {
      const invalidName = marker + '_' + suffix;
      response = await register(signup, invalidName, password, extra);
      const html = await response.text();
      check(response.status() === 422 && html.includes(error), suffix + ' returns useful validation');
      check(account(invalidName) === '', suffix + ' creates no account');
      check(!/<input[^>]+type="password"[^>]+value="[^"]+"/.test(html), 'password fields stay empty');
      if (suffix === 'nul') check(html.includes(`value="${invalidName}"`) && html.includes('value="Fixture"'), 'invalid registration retains names and ID');
    }
    response = await signup.post('register.php', { form: { userid: marker + '_csrf', password: 'valid-password' }, maxRedirects: 0 });
    check(response.status() === 403 && account(marker + '_csrf') === '', 'missing registration CSRF denied');

    const roleSession = await context();
    await login(roleSession, name, 'fixture-pass-123', 'art-main.php');
    let oldId = await sessionId(roleSession);
    let oldToken = await csrf(roleSession);
    await change(admin, name, 'role', 'admin');
    check(account(name) === 'admin:active', 'promotion persisted');
    check((await get(roleSession, 'admin.php')).status() === 200, 'open session gains current role');
    check(await sessionId(roleSession) !== oldId && await csrf(roleSession) !== oldToken, 'promotion refreshes session and CSRF');
    oldId = await sessionId(roleSession);
    oldToken = await csrf(roleSession);
    await change(admin, name, 'role', 'client');
    check((await get(roleSession, 'admin.php')).status() === 403, 'open session loses old admin role');
    check(await sessionId(roleSession) !== oldId && await csrf(roleSession) !== oldToken, 'demotion refreshes session and CSRF');
    response = await roleSession.post('logout.php', { form: { csrf: oldToken }, maxRedirects: 0 });
    check(response.status() === 403, 'old CSRF rejected after role change');
    oldId = await sessionId(roleSession);
    oldToken = await csrf(roleSession);
    await change(admin, name, 'status', 'disabled');
    await denied(roleSession);
    check(await sessionId(roleSession) !== oldId && await csrf(roleSession, 'art-login.php') !== oldToken, 'disable refreshes session and CSRF');
    await login(await context(), name, 'fixture-pass-123', 'art-login.php');
    await change(admin, name, 'status', 'active');
    await denied(roleSession); // Re-enabling must not restore the old authentication.
    // The denied request above was for account.php, so login returns there.
    await login(roleSession, name, 'fixture-pass-123', 'account.php');
    await change(admin, name, 'delete');
    await denied(roleSession);
    await login(await context(), name, 'fixture-pass-123', 'art-login.php');

    for (const samePassword of [true, false]) {
      const reused = marker + (samePassword ? '_same' : '_different');
      const original = 'original-password';
      check((await register(signup, reused, original)).status() === 303, 'create lifecycle account');
      const originalHash = sql(`SELECT password FROM art_users WHERE username='${reused}'`);
      const stale = [];
      for (let i = 0; i < 3; i++) {
        const ctx = await context();
        await login(ctx, reused, original, 'art-main.php');
        stale.push(ctx);
      }
      await change(admin, reused, 'delete');
      const replacement = samePassword ? original : 'replacement-password';
      check((await register(signup, reused, replacement, { forename: 'Replacement' })).status() === 303, 're-register deleted ID');
      check(sql(`SELECT password FROM art_users WHERE username='${reused}'`) !== originalHash, 're-registration has a new salted credential generation');
      await denied(stale[0]);
      await denied(stale[1], 'admin.php');
      await change(admin, reused, 'role', 'admin');
      await denied(stale[2], 'admin.php'); // First old-cookie request happens after replacement promotion.
      const fresh = await context();
      await login(fresh, reused, replacement, 'admin.php');
      check((await get(fresh, 'admin.php')).status() === 200, 'replacement signs in with its own credentials');
      const html = await (await get(fresh, 'account.php')).text();
      const stored = sql(`SELECT password FROM art_users WHERE username='${reused}'`);
      const fingerprint = createHash('sha256').update(stored).digest('hex');
      check(!html.includes(stored) && !html.includes(fingerprint), 'credential data absent from profile HTML');
      const json = await (await get(fresh, 'suggestions.php?type=artist&prefix=Ma')).text();
      check(!json.includes(stored) && !json.includes(fingerprint), 'credential data absent from JSON');
    }

    // Simulate a pre-fix server session through CLI, never through a web endpoint.
    const legacyId = randomBytes(16).toString('hex');
    const legacyToken = randomBytes(32).toString('hex');
    php(`session_name('team43_gallery'); session_id('${legacyId}'); session_start();
      $_SESSION=['user'=>['username'=>'user1','forename'=>'first1','surname'=>'last1','role'=>'admin'], 'csrf'=>'${legacyToken}']; session_write_close();`);
    const legacy = await request.newContext({ baseURL: base, storageState: { cookies: [{
      name: 'team43_gallery', value: legacyId, domain: new URL(base).hostname, path: '/',
      expires: -1, httpOnly: true, secure: false, sameSite: 'Lax'
    }], origins: [] } });
    contexts.push(legacy);
    await denied(legacy, 'admin.php');
    check(await sessionId(legacy) !== legacyId && await csrf(legacy, 'art-login.php') !== legacyToken, 'legacy session expires with fresh ID/token');
    await login(legacy, 'user1', 'pass1', 'admin.php');
    response = await legacy.post('logout.php', { form: { csrf: await csrf(legacy) }, maxRedirects: 0 });
    check(response.status() === 303, 'logout succeeds');
    await denied(legacy);
    console.log('Account registration, role refresh, disabled/deleted/reused accounts, legacy cookies and logout passed.');
  } finally {
    // Exact names plus a per-run surname ensure cleanup can touch only owned rows.
    for (const name of names) sql(`DELETE FROM art_users WHERE username='${name}' AND surname='${marker}';`);
    check(sql('CHECKSUM TABLE art_users') === beforeAccounts, 'original accounts/names/passwords/roles/status preserved');
  }
}

async function migrationFlows() {
  configure({ writes: true });
  const before = sql('CHECKSUM TABLE art_users');
  const grants = sql("SHOW GRANTS FOR 'testuser'@'%'", { root: true });
  const backup = marker + '_backup';
  let renamed = false;
  function createBaseline(where) {
    sql(`CREATE TABLE art_users LIKE ${backup};
      ALTER TABLE art_users DROP COLUMN role, DROP COLUMN status;
      INSERT INTO art_users (forename,surname,username,password)
        SELECT forename,surname,username,password FROM ${backup} WHERE ${where};`, { root: true });
  }
  const credentials = () => sql('SELECT forename,surname,username,password FROM art_users ORDER BY username');
  try {
    sql(`RENAME TABLE art_users TO ${backup};`, { root: true });
    renamed = true;
    createBaseline("username IN ('user1','user2')");
    const original = credentials();
    sql(migration, { root: true });
    check(account('user1') === 'admin:active' && account('user2') === 'client:active', 'initial upgrade bootstraps only classroom user1');
    check(credentials() === original, 'initial upgrade preserves original names/password hashes');
    sql("UPDATE art_users SET status='disabled' WHERE username='user2';");
    const upgraded = sql('CHECKSUM TABLE art_users');
    sql(migration, { root: true });
    check(sql('CHECKSUM TABLE art_users') === upgraded, 'rerun preserves existing roles/status/passwords');

    sql('ALTER TABLE art_users DROP COLUMN status;', { root: true });
    const partial = sql('CHECKSUM TABLE art_users');
    const refusal = sql(migration, { root: true, failure: true });
    check(refusal.status !== 0 && refusal.stderr.includes('partial or different'), 'partial-schema upgrade refused');
    check(sql('CHECKSUM TABLE art_users') === partial, 'partial-schema refusal changes no account data');

    // This is our replacement table; the untouched original remains under backup.
    sql('DROP TABLE art_users;', { root: true });
    createBaseline("username='user2'");
    const withoutAdmin = credentials();
    sql(migration, { root: true });
    check(credentials() === withoutAdmin && account('user2') === 'client:active', 'initial upgrade without trusted user1 leaves clients unchanged');
    const signup = await context();
    check((await register(signup, 'user1', 'public-user1-password')).status() === 303, 'public user1 registers after initial upgrade');
    const publicState = sql('CHECKSUM TABLE art_users');
    sql(migration, { root: true });
    check(account('user1') === 'client:active', 'no-admin rerun cannot promote later public user1');
    check(sql('CHECKSUM TABLE art_users') === publicState, 'no-admin rerun preserves public credentials/status');
    const client = await context();
    await login(client, 'user1', 'public-user1-password', 'art-main.php');
    check((await get(client, 'admin.php')).status() === 403, 'public user1 remains denied admin access');
    check(sql("SHOW GRANTS FOR 'testuser'@'%'", { root: true }) === grants, 'migration adds no broader privileges');
    console.log('Initial bootstrap, no-admin rerun, partial-schema refusal and scoped-grant migration checks passed.');
  } finally {
    if (renamed) {
      configure({ writes: true });
      sql(`DROP TABLE IF EXISTS art_users; RENAME TABLE ${backup} TO art_users;
        DROP PROCEDURE IF EXISTS migrate_account_roles;`, { root: true });
    }
    check(sql('CHECKSUM TABLE art_users') === before, 'original account table restored exactly after migration probes');
  }
}

(async () => {
  const galleryBefore = sql('CHECKSUM TABLE ' + galleryTables);
  try {
    await accountFlows();
    await migrationFlows();
  } finally {
    for (const ctx of contexts) await ctx.dispose();
    check(sql('CHECKSUM TABLE ' + galleryTables) === galleryBefore, 'all eight baseline gallery checksums preserved');
  }
  console.log(`Account regressions passed (${checks} assertions); original accounts and gallery checksums preserved.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
