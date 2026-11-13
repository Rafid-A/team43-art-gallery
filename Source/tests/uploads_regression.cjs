// Optional exclusive disposable-fixture suite; no web bypasses or new dependencies.
const { configure } = require('./browsing_test_config.cjs');
const { base, db, web } = configure({ writes: true });
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const assert = require('node:assert/strict');

const marker = 'up' + randomBytes(8).toString('hex');
const trigger = marker + 'reject';
const tables = 'Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects,art_users';
const ownedFiles = new Set();
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function inspect(args) { return JSON.parse(execFileSync('docker', args, { encoding: 'utf8' }))[0]; }
function ownership() {
  configure({ writes: true });
  const container = inspect(['inspect', web]);
  const project = container.Config.Labels['com.docker.compose.project'];
  // Require both exact Compose volume identities; no bind-mounted upload folder.
  for (const [id, destination, key] of [[db, '/var/lib/mysql', 'gallery-db'],
    [web, '/var/www/html/artwork/images/uploads', 'gallery-uploads']]) {
    const mount = inspect(['inspect', id]).Mounts.find(m => m.Destination === destination);
    check(mount?.Type === 'volume' && mount.Name === `${project}_${key}`, 'owned ' + key + ' mount');
    const labels = inspect(['volume', 'inspect', mount.Name]).Labels;
    check(labels?.['com.docker.compose.project'] === project && labels?.['com.docker.compose.volume'] === key
      && labels?.['t3.fixture'] === 'issue13-browsing-review' && labels?.['t3.issue'] === '13', 'owned ' + key + ' labels');
  }
}
function sql(query, root = false) {
  const args = root
    ? ['exec', '-i', db, 'sh', '-c', 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" art -N -B']
    : ['exec', '-i', '-e', 'MYSQL_PWD=mypassword', db, 'mariadb', '-utestuser', 'art', '-N', '-B'];
  const r = spawnSync('docker', args, { input: query, encoding: 'utf8' });
  if (r.status !== 0) throw Error('Fixture SQL failed: ' + r.stderr);
  return r.stdout.trim();
}
function php(code, result = false) {
  const r = spawnSync('docker', ['exec', '-i', '--user', 'www-data', web, 'php'], {
    input: '<?php ' + code, encoding: 'utf8'
  });
  if (r.status !== 0) throw Error('Fixture PHP failed: ' + r.stderr + r.stdout);
  return result ? r : r.stdout.trim();
}
function uploads() {
  return JSON.parse(php(`$out=[]; $root='/var/www/html/artwork/images/uploads'; foreach(scandir($root) as $name) {
    $f=$root.'/'.$name; if(is_file($f)) $out[$name]=hash_file('sha256',$f); } ksort($out); echo json_encode((object)$out);`));
}
function bundledChecksum() {
  return php(`$out=[]; $root='/var/www/html/artwork/images/art/works/square-medium';
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f)
      if($f->isFile()) $out[$f->getPathname()]=hash_file('sha256',$f->getPathname());
    ksort($out); echo hash('sha256',json_encode($out));`);
}
async function token(ctx, page = 'art-add.php') {
  const response = await ctx.request.get(page, { maxRedirects: 0 });
  check(response.status() === 200, page + ' accessible');
  const html = await response.text();
  const csrf = html.match(/name="csrf" value="([a-f0-9]{64})"/)?.[1];
  check(!!csrf, 'CSRF present');
  return { csrf, html };
}
async function login(ctx, user = 'user1', password = 'pass1') {
  const { csrf } = await token(ctx, 'art-login.php');
  const response = await ctx.request.post('authenticate.php', {
    form: { csrf, userid: user, password }, maxRedirects: 0
  });
  check(response.status() === 303 && response.headers().location === (user === 'user1' ? 'admin.php' : 'art-main.php'), 'login ' + user);
}
function probeStreams() {
  const r = php(`require '/var/www/html/artwork/uploads.php';
    function must($ok) { if(!$ok) throw new Exception('stream regression'); }
    $full=fopen('/dev/full','wb'); $failed=false;
    try { write_upload_stream($full,str_repeat('x',131072)); } catch(RuntimeException $e) { $failed=true; }
    must($failed && !is_resource($full));
    class ShortUploadStream {
      public $context; public static $bytes=''; public static $fail=false;
      function stream_open($path,$mode,$options,&$opened) {return true;}
      function stream_write($data) {
        if(self::$fail && strlen(self::$bytes)>=14) return 0;
        $n=min(7,strlen($data)); self::$bytes.=substr($data,0,$n); return $n;
      }
      function stream_flush() {return true;}
      function stream_close() {}
    }
    stream_wrapper_register('uploadshort',ShortUploadStream::class);
    write_upload_stream(fopen('uploadshort://test','wb'),str_repeat('abc',100));
    must(ShortUploadStream::$bytes===str_repeat('abc',100));
    ShortUploadStream::$bytes=''; ShortUploadStream::$fail=true; $failed=false;
    try {write_upload_stream(fopen('uploadshort://test','wb'),str_repeat('abc',100));}
    catch(RuntimeException $e) {$failed=true;} must($failed);
    echo 'checked writes passed';`);
  check(r === 'checked writes passed', 'full sink and partial/zero writes handled');
}
function probeRollback() {
  const r = php(`require '/var/www/html/artwork/functions.php'; require '/var/www/html/artwork/uploads.php';
    $pdo=db(true); $pdo->beginTransaction(); $id=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $killer=new PDO('mysql:host='.DBHOST.';port='.DBPORT.';dbname='.DBNAME,DBUSER,DBPASS);
    $killer->exec('KILL CONNECTION '.$id);
    $name=store_upload(imagecreatetruecolor(20,20));
    try {
      cleanup_failed_upload($pdo,$name,false);
      if(file_exists(upload_folder().'/'.$name.'.jpg')) throw new Exception('precommit orphan');
      $name=store_upload(imagecreatetruecolor(20,20));
      cleanup_failed_upload($pdo,$name,true);
      if(!file_exists(upload_folder().'/'.$name.'.jpg')) throw new Exception('uncertain file deleted');
      echo 'rollback and uncertain retention passed';
    } finally { discard_upload($name); }`, true);
  check(r.stdout.includes('rollback and uncertain retention passed'), 'lost real connection cannot bypass cleanup; uncertain commit retains file');
  check(r.stderr.includes('Artwork rollback failed:') && r.stderr.includes('reconciliation reference: upload-')
    && r.stderr.includes('before retrying'), 'rollback and reconciliation diagnostics');
}

(async () => {
  ownership();
  const original = sql('CHECKSUM TABLE ' + tables);
  const originalUploads = uploads();
  const originalBundled = bundledChecksum();
  let browser, triggerCreated = false;
  try {
    probeStreams();
    probeRollback();
    const fixtures = JSON.parse(php(`$im=imagecreatetruecolor(48,32); imagefill($im,0,0,imagecolorallocate($im,40,100,180));
      $out=[]; foreach(['jpeg','png','webp'] as $ext) {ob_start(); ('image'.$ext)($im); $out[$ext]=base64_encode(ob_get_clean());}
      echo json_encode($out);`));
    const files = Object.fromEntries(Object.entries(fixtures).map(([ext, data]) => [ext, Buffer.from(data, 'base64')]));
    browser = await chromium.launch({ executablePath: process.env.BROWSING_BROWSER });
    const admin = await browser.newContext({ baseURL: base });
    const client = await browser.newContext({ baseURL: base });
    const anonymous = await browser.newContext({ baseURL: base });
    await login(admin);
    await login(client, 'user2', 'pass2');
    const { csrf, html } = await token(admin);
    const option = name => html.match(new RegExp(`<select[^>]*name="${name}"[\\s\\S]*?<option value="([^"]+)"`))?.[1];
    const fields = { csrf, artist_id: option('artist_id'), gallery_id: option('gallery_id'),
      image_source: 'upload', year: '2000', medium: 'Oil on canvas', excerpt: marker };
    check(!!fields.artist_id && !!fields.gallery_id && !!option('image'), 'parent and unused bundled choices available');
    async function submit(ctx, suffix, buffer = files.jpeg, name = '../../original.php', mimeType = 'image/jpeg', extra = {}) {
      return ctx.request.post('art-add.php', { multipart: {
        ...fields, title: marker + suffix, upload: { name, mimeType, buffer }, ...extra
      }, maxRedirects: 0 });
    }
    const rows = () => sql(`SELECT PaintingID,ImageFileName,Title FROM Paintings WHERE Excerpt='${marker}' AND Title LIKE '${marker}%' ORDER BY PaintingID`)
      .split('\n').filter(Boolean).map(line => {const [id, file, title] = line.split('\t'); return { id, file, title };});

    for (const [ctx, status] of [[client, 403], [anonymous, 303]]) {
      const get = await ctx.request.get('art-add.php', { maxRedirects: 0 });
      check(get.status() === status, 'nonadmin GET denied');
      check((await submit(ctx, 'denied')).status() === status, 'nonadmin multipart POST denied');
    }
    check((await submit(admin, 'csrf', files.jpeg, 'x.jpg', 'image/jpeg', { csrf: 'x' })).status() === 403, 'CSRF required');
    for (const ext of ['jpeg', 'png', 'webp']) {
      const response = await submit(admin, ext, Buffer.concat([files[ext], Buffer.from('<?php never-retain-this ?>')]), '../../untrusted.php', 'application/octet-stream');
      check(response.status() === 303, 'valid content accepted regardless of name/type: ' + ext);
    }
    const validRows = rows();
    check(validRows.length === 3, 'one row per supported image');
    for (const row of validRows) {
      check(/^upload-[a-f0-9]{32}$/.test(row.file), 'generated basename');
      ownedFiles.add(row.file);
      const response = await client.request.get(`images/uploads/${row.file}.jpg`);
      const bytes = await response.body();
      check(response.status() === 200 && response.headers()['content-type'].startsWith('image/jpeg')
        && bytes.subarray(0, 2).equals(Buffer.from([255, 216])) && bytes.subarray(-2).equals(Buffer.from([255, 217]))
        && !bytes.includes(Buffer.from('never-retain-this')), 'client sees complete stripped JPEG');
      const detail = await (await client.request.get('painting.php?id=' + row.id)).text();
      check(detail.includes(`images/uploads/${row.file}.jpg`), 'detail uses upload helper');
    }
    const saved = uploads();
    for (const [suffix, data] of [['invalid', Buffer.from('not an image')], ['damaged', files.jpeg.subarray(0, files.jpeg.length - 70)],
      ['empty', Buffer.alloc(0)], ['oversize', Buffer.alloc(5242881)]]) {
      const response = await submit(admin, suffix, data);
      check(response.status() === 200 && (await response.text()).includes('notice error'), suffix + ' produces form error');
      check(rows().length === 3 && JSON.stringify(uploads()) === JSON.stringify(saved), suffix + ' adds no row/file');
    }

    // Trigger affects only this run's exact title, using fixture-root DDL only.
    check(sql(`SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='art' AND TRIGGER_NAME='${trigger}'`) === '0', 'trigger name unused');
    sql(`DELIMITER //
      CREATE TRIGGER ${trigger} BEFORE INSERT ON Paintings FOR EACH ROW
      BEGIN IF NEW.Title='${marker}fail' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned upload fixture failure'; END IF; END//
      DELIMITER ;`, true);
    triggerCreated = true;
    const failed = await submit(admin, 'fail');
    check(failed.status() === 200 && (await failed.text()).includes('Could not add artwork.'), 'failed INSERT is reported');
    check(rows().length === 3 && JSON.stringify(uploads()) === JSON.stringify(saved), 'failed INSERT cleans only its new upload');
    sql(`DROP TRIGGER ${trigger}`, true);
    triggerCreated = false;

    const parallel = await Promise.all([0, 1, 2].map(async i => {
      const ctx = await browser.newContext({ baseURL: base });
      try { await login(ctx); const t = await token(ctx); return (await submit(ctx, 'parallel' + i, files.png, 'same.png', 'image/png', { csrf: t.csrf })).status(); }
      finally { await ctx.close(); }
    }));
    check(parallel.every(status => status === 303), 'separate-session parallel uploads succeed');
    check(rows().length === 6 && new Set(rows().map(row => row.file)).size === 6, 'parallel names distinct');

    await admin.addInitScript(() => {
      window.previewUrls = { created: [], revoked: [] };
      const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
      URL.createObjectURL = file => {const url = create(file); window.previewUrls.created.push(url); return url;};
      URL.revokeObjectURL = url => {window.previewUrls.revoked.push(url); return revoke(url);};
    });
    const page = await admin.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const response = await page.goto(base + 'art-add.php');
    const csp = response.headers()['content-security-policy'];
    check(csp.includes("img-src 'self' blob:;") && csp.includes("script-src 'self';") && !csp.includes('unsafe-'), 'only image CSP permits blobs');
    const dirty = () => page.evaluate(() => !window.dispatchEvent(new Event('beforeunload', { cancelable: true })));
    check(!(await dirty()) && !(await dirty()), 'untouched default form stable with empty File');
    await page.locator('#title').fill('Changed title');
    check(await dirty(), 'text field changes still warn');
    await page.locator('#title').fill('');
    check(!(await dirty()), 'text reset restores clean form');
    let dialogs = 0;
    page.on('dialog', async dialog => { dialogs++; await dialog.accept(); });
    await page.getByRole('link', { name: 'Back to collection' }).click();
    await page.waitForURL('**/art-main.php?**');
    check(dialogs === 0, 'untouched Back link has no unsaved warning');
    await page.goto(base + 'art-add.php');
    const input = page.locator('#upload'), preview = page.locator('#image-preview img');
    for (const ext of ['jpeg', 'png', 'webp']) {
      await input.setInputFiles({ name: `selected.${ext}`, mimeType: `image/${ext}`, buffer: files[ext] });
      await preview.waitFor({ state: 'visible' });
      await page.waitForFunction(() => document.querySelector('#image-preview img').naturalWidth === 48);
      check(await dirty(), 'selected file alone marks form dirty: ' + ext);
      check((await page.locator('#image-preview figcaption').textContent()).includes(`selected.${ext}`), 'selected preview caption: ' + ext);
    }
    check(await page.evaluate(() => previewUrls.created.slice(0, -1).every(url => previewUrls.revoked.includes(url))), 'replacement URLs released');
    await input.setInputFiles({ name: 'broken.png', mimeType: 'image/png', buffer: Buffer.from('bad') });
    await page.waitForFunction(() => document.querySelector('#image-preview figcaption').textContent.includes('Preview unavailable'));
    check(await preview.isHidden() && !(await preview.getAttribute('src')), 'error hides image and clears source');
    check(await page.evaluate(() => previewUrls.created.every(url => previewUrls.revoked.includes(url))), 'error URL released');
    await input.setInputFiles([]);
    check(await page.locator('#image-preview').isHidden() && !(await dirty()), 'clearing restores clean default and hides preview');
    await page.locator('[name=image_source][value=existing]').check();
    await page.locator('#image').selectOption(option('image'));
    await preview.waitFor({ state: 'visible' });
    check((await preview.getAttribute('src')).includes('images/art/works/square-medium/'), 'existing bundled preview works');
    await page.locator('[name=image_source][value=upload]').check();
    check(await page.locator('#image-preview').isHidden(), 'mode change clears bundled preview');
    await input.setInputFiles({ name: 'selected.png', mimeType: 'image/png', buffer: files.png });
    await preview.waitFor({ state: 'visible' });
    await page.locator('[name=image_source][value=existing]').check();
    await preview.waitFor({ state: 'visible' });
    check(await page.evaluate(() => previewUrls.created.every(url => previewUrls.revoked.includes(url))), 'source switch releases upload URL');
    await page.locator('[name=image_source][value=upload]').check();
    await preview.waitFor({ state: 'visible' });
    // Pending submit suppresses warnings without sending this intentionally held request.
    await page.evaluate(() => document.querySelector('form.add-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    check(!(await dirty()) && await page.locator('form.add-form').getAttribute('aria-busy') === 'true', 'pending submission suppresses warning');
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    check(await dirty() && !(await page.locator('form.add-form').getAttribute('aria-busy')), 'pageshow restores pending state and warning');
    await input.setInputFiles([]);
    await page.locator('[name=image_source][value=existing]').check();
    await page.locator('#image').selectOption('');
    await page.locator('[name=image_source][value=upload]').check();
    for (const width of [320, 768, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'controls fit viewport ' + width);
    }
    check(errors.length === 0, 'no browser script errors');
    // Save after preview checks: the fixture may have only one unused image.
    const existing = await admin.request.post('art-add.php', { form: { ...fields,
      title: marker + 'existing', image_source: 'existing', image: option('image') }, maxRedirects: 0 });
    check(existing.status() === 303 && rows().length === 7, 'existing bundled image still saves');
    check(rows().find(row => row.title === marker + 'existing')?.file === option('image'), 'existing basename stored without upload');
    console.log('Upload writes, rollback, formats, authorization, previews, dirty state and parallel uploads passed.');
  } finally {
    if (browser) await browser.close();
    ownership();
    if (triggerCreated) sql(`DROP TRIGGER ${trigger}`, true);
    // Recover even rows created just before an assertion failed. Exact run marker
    // and database-recorded generated basename establish ownership before unlink.
    const data = sql(`SELECT PaintingID,ImageFileName FROM Paintings WHERE Excerpt='${marker}' AND Title LIKE '${marker}%'`);
    for (const line of data.split('\n').filter(Boolean)) {
      const [id, file] = line.split('\t');
      if (/^upload-[a-f0-9]{32}$/.test(file) && !Object.hasOwn(originalUploads, file + '.jpg')) ownedFiles.add(file);
      sql(`DELETE FROM Paintings WHERE PaintingID=${Number(id)} AND Excerpt='${marker}' AND Title LIKE '${marker}%'`);
    }
    for (const file of ownedFiles) {
      check(/^upload-[a-f0-9]{32}$/.test(file) && !Object.hasOwn(originalUploads, file + '.jpg'), 'cleanup file ownership');
      php(`require '/var/www/html/artwork/uploads.php'; discard_upload('${file}');`);
    }
    check(sql('CHECKSUM TABLE ' + tables) === original, 'all gallery and account baseline checksums preserved');
    check(JSON.stringify(uploads()) === JSON.stringify(originalUploads), 'baseline upload files preserved; no leftovers');
    check(bundledChecksum() === originalBundled, 'bundled images unchanged');
  }
  console.log(`Upload regressions passed (${checks} assertions). COMMIT ambiguity retention is tested through the helper; no lost-COMMIT network fault is simulated.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
