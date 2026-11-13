// Run only against an already-started, exclusive disposable browsing fixture.
// No lifecycle operations; cleanup removes only exact owned rows and image bytes.
const { configure } = require('./browsing_test_config.cjs');
const { base, web, db } = configure({ writes: true });
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { existsSync } = require('node:fs');
const assert = require('node:assert/strict');
const marker = 'viewer' + randomBytes(8).toString('hex');
const needle = marker + '%_!';
const browserPath = process.env.BROWSING_BROWSER || [
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe'
].find(existsSync);
assert.ok(browserPath && /msedge(?:\.exe)?$/i.test(browserPath), 'Use installed Edge via BROWSING_BROWSER');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function inspect(args) { return JSON.parse(execFileSync('docker', args, { encoding: 'utf8' }))[0]; }
function ownership() {
  configure({ writes: true });
  const project = inspect(['inspect', web]).Config.Labels['com.docker.compose.project'];
  for (const [container, destination, key] of [[db, '/var/lib/mysql', 'gallery-db'], [web, '/var/www/html/artwork/images/uploads', 'gallery-uploads']]) {
    const mount = inspect(['inspect', container]).Mounts.find(m => m.Destination === destination);
    check(mount?.Type === 'volume' && mount.Name === project + '_' + key, 'exclusive named ' + key);
    const labels = inspect(['volume', 'inspect', mount.Name]).Labels;
    check(labels?.['t3.fixture'] === 'issue13-browsing-review' && labels?.['t3.issue'] === '13'
      && labels?.['com.docker.compose.project'] === project && labels?.['com.docker.compose.volume'] === key, 'owned volume ' + key);
  }
}
function php(code, options = []) {
  const result = spawnSync('docker', ['exec', '-i', '--user', 'www-data', web, 'php', '-d', 'memory_limit=128M', ...options], {
    input: '<?php ' + code, encoding: 'utf8', timeout: 60000
  });
  if (result.error || result.status !== 0 || result.stderr.trim()) throw Error('Fixture PHP: ' + (result.error || '') + result.stderr + result.stdout);
  return result.stdout.trim();
}
function payload(value) { return "json_decode(base64_decode('" + Buffer.from(JSON.stringify(value)).toString('base64') + "'),true)"; }
function baseline() {
  return JSON.parse(php(`require '/var/www/html/artwork/functions.php'; $pdo=db(true); $out=[];
    foreach(['Artists','Paintings','Galleries','Eras','Genres','Subjects','PaintingGenres','PaintingSubjects'] as $t)
      $out[$t]=$pdo->query('CHECKSUM TABLE '.$t)->fetch(PDO::FETCH_NUM)['1'];
    foreach(['/var/www/html/artwork/images/uploads','/var/www/html/artwork/images/art/works/square-medium'] as $root) {
      $files=[]; foreach(glob($root.'/*.jpg') as $f) $files[basename($f)]=hash_file('sha256',$f); ksort($files); $out[$root]=$files;
    } echo json_encode($out);`));
}
function create() {
  return JSON.parse(php(`require '/var/www/html/artwork/functions.php'; $x=${payload({ marker, needle })};
    $pdo=db(true); $pdo->beginTransaction(); $out=['artists'=>[],'paintings'=>[],'files'=>[]];
    try {
      foreach(['A','A','Z'] as $first) {
        $s=$pdo->prepare('INSERT INTO Artists (FirstName,LastName) VALUES (?,?)'); $s->execute([$first,$x['marker']]); $out['artists'][]=(int)$pdo->lastInsertId();
      }
      foreach([[1600,1000],[64,32],[0,0],[0,1]] as $i=>[$w,$h]) {
        $name='upload-'.bin2hex(random_bytes(16)); $path='/var/www/html/artwork/images/uploads/'.$name.'.jpg';
        if($w) { $im=imagecreatetruecolor($w,$h); imagefill($im,0,0,imagecolorallocate($im,40,85,65));
          imagefilledrectangle($im,0,0,intdiv($w,2),intdiv($h,2),imagecolorallocate($im,230,200,160)); imagejpeg($im,$path,88); unset($im); }
        elseif($h) file_put_contents($path,substr(file_get_contents('/var/www/html/artwork/images/uploads/'.$out['files'][0]['name'].'.jpg'),0,2048));
        else file_put_contents($path,'<?php echo "not an image";');
        $out['files'][]=['name'=>$name,'hash'=>hash_file('sha256',$path),'width'=>$w,'height'=>$h];
      }
      for($i=0;$i<29;$i++) {
        $title=$i===28 ? $x['marker'].' outside filter' : $x['needle'].' '.str_pad((string)intdiv($i,2),2,'0',STR_PAD_LEFT);
        $artist=$i===0 ? null : $out['artists'][$i%3];
        $name=$out['files'][0]['name'];
        if($i>0) {
          $name='upload-'.bin2hex(random_bytes(16)); $path='/var/www/html/artwork/images/uploads/'.$name.'.jpg';
          if(!copy('/var/www/html/artwork/images/uploads/'.$out['files'][0]['name'].'.jpg',$path)) throw new Exception('owned image copy failed');
          $out['files'][]=['name'=>$name,'hash'=>hash_file('sha256',$path),'width'=>1600,'height'=>1000];
        }
        $s=$pdo->prepare('INSERT INTO Paintings (Title,ArtistID,ImageFileName) VALUES (?,?,?)');
        $s->execute([$title,$artist,$name]);
        $out['paintings'][]=['id'=>(int)$pdo->lastInsertId(),'title'=>$title,'artist'=>$artist,'file'=>$name];
      }
      $pdo->commit(); echo json_encode($out);
    } catch(Throwable $e) { $pdo->rollBack(); foreach($out['files'] as $f) unlink('/var/www/html/artwork/images/uploads/'.$f['name'].'.jpg'); throw $e; }`));
}
function cleanup(owned) {
  ownership();
  php(`require '/var/www/html/artwork/functions.php'; $x=${payload({ ...owned, marker })}; $pdo=db(true); $pdo->beginTransaction();
    try {
      foreach($x['paintings'] as $p) { $s=$pdo->prepare('DELETE FROM Paintings WHERE PaintingID=? AND Title=? AND ArtistID <=> ? AND ImageFileName=?');
        $s->execute([$p['id'],$p['title'],$p['artist'],$p['file']]); if($s->rowCount()!==1) throw new Exception('owned painting cleanup mismatch'); }
      foreach($x['artists'] as $id) { $s=$pdo->prepare('DELETE FROM Artists WHERE ArtistID=? AND LastName=?'); $s->execute([$id,$x['marker']]); if($s->rowCount()!==1) throw new Exception('owned artist cleanup mismatch'); }
      $pdo->commit();
    } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
    foreach($x['files'] as $f) { $path='/var/www/html/artwork/images/uploads/'.$f['name'].'.jpg';
      if(!preg_match('/^upload-[a-f0-9]{32}$/D',$f['name']) || is_link($path) || !hash_equals($f['hash'],hash_file('sha256',$path))) throw new Exception('owned image cleanup mismatch'); unlink($path); }`);
}
async function login(ctx) {
  const response = await ctx.request.get(base + 'art-login.php');
  const csrf = (await response.text()).match(/name="csrf" value="([a-f0-9]{64})"/)?.[1];
  check(csrf, 'CSRF login');
  const result = await ctx.request.post(base + 'authenticate.php', { form: { csrf, userid: 'user1', password: 'pass1' }, maxRedirects: 0 });
  assert.equal(result.status(), 303);
}
const directory = { directory_type: 'artists', directory_manage: '1', directory_page: '2', directory_sort: 'nationality', directory_dir: 'desc' };
function detailUrl(id, context = {}) { return base + 'painting.php?' + new URLSearchParams({ id, q: needle, artist: 0, sort: 'title', dir: 'asc', page: 1, ...directory, ...context }); }
async function navigation(page, owned) {
  for (const sort of ['title', 'artist']) for (const dir of ['asc', 'desc']) for (const artist of [0, owned.artists[0]]) {
    const order = JSON.parse(php(`require '/var/www/html/artwork/functions.php'; $x=${payload({ needle, artist })};
      $s=db()->prepare('SELECT p.PaintingID FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID WHERE LOCATE(?,p.Title)>0'
        .($x['artist'] ? ' AND p.ArtistID=?' : '') . ' ORDER BY ${sort === 'title' ? 'p.Title' : 'a.LastName ' + dir + ', a.FirstName'} ${dir}, p.PaintingID ASC');
      $s->execute($x['artist'] ? [$x['needle'],$x['artist']] : [$x['needle']]); echo json_encode($s->fetchAll(PDO::FETCH_COLUMN));`)).map(Number);
    // Boundaries, ties, NULL artist and the 24/25 cross-page transition.
    for (const i of [...new Set([0, 1, 23, 24, order.length - 1])].filter(i => i < order.length)) {
      await page.goto(detailUrl(order[i], { sort, dir, artist, page: Math.floor(i / 24) + 1 }));
      for (const [rel, j] of [['prev', i - 1], ['next', i + 1]]) {
        const links = page.locator('.painting-neighbors').first().locator(`a[rel=${rel}]`);
        if (j < 0 || j >= order.length) assert.equal(await links.count(), 0);
        else {
          const url = new URL(await links.getAttribute('href'), base);
          assert.equal(Number(url.searchParams.get('id')), order[j], sort + '/' + dir + '/' + rel);
          assert.equal(Number(url.searchParams.get('page')), Math.floor(j / 24) + 1, 'neighbor collection page');
          for (const [key, value] of Object.entries({ sort, dir, q: needle, artist: String(artist), ...directory })) assert.equal(url.searchParams.get(key), value);
          checks++;
        }
      }
    }
  }
  await page.goto(detailUrl(owned.paintings[28].id));
  assert.equal(await page.locator('.painting-neighbors a').count(), 0, 'current painting excluded by literal filter');
  await page.goto(detailUrl(owned.paintings[23].id));
  await page.locator('.painting-neighbors').first().locator('a[rel=next]').click();
  assert.equal(new URL(page.url()).searchParams.get('page'), '2');
  const back = new URL(await page.locator('.breadcrumbs a').first().getAttribute('href'), base);
  assert.equal(back.searchParams.get('page'), '2');
  for (const [key, value] of Object.entries(directory)) assert.equal(back.searchParams.get(key), value);
  const directoryBack = new URL(await page.locator('.breadcrumbs a').nth(1).getAttribute('href'), base);
  assert.equal(directoryBack.searchParams.get('page'), '2');
  assert.equal(directoryBack.searchParams.get('sort'), 'nationality');
}
async function media(ctx, page, owned) {
  const source = owned.files[0];
  const url = (file, w, v = file.hash) => base + 'artwork-media.php?' + new URLSearchParams({ name: file.name, w, v });
  // Public image access matches the existing raw public images, even logged out.
  const publicCtx = await ctx.browser().newContext();
  try {
    for (const w of [160, 320, 480, 640, 960, 1280, 1600]) {
      const response = await publicCtx.request.get(url(source, w));
      assert.equal(response.status(), 200);
      assert.match(response.headers()['content-type'], /^image\/jpeg/);
      assert.match(response.headers()['cache-control'], /immutable/);
      assert.equal(response.headers()['x-content-type-options'], 'nosniff');
      const bytes = await response.body();
      check(bytes[0] === 255 && bytes[1] === 216 && bytes.at(-2) === 255 && bytes.at(-1) === 217, 'complete JPEG ' + w);
      const size = JSON.parse(php(`$i=getimagesizefromstring(base64_decode('${bytes.toString('base64')}')); echo json_encode([$i[0],$i[1]]);`));
      assert.deepEqual(size, [w, Math.round(1000 * w / 1600)]);
      const cached = await publicCtx.request.get(url(source, w), { headers: { 'If-None-Match': response.headers().etag } });
      assert.equal(cached.status(), 304);
    }
    const tiny = await publicCtx.request.get(url(owned.files[1], 1600));
    const tinySize = JSON.parse(php(`$i=getimagesizefromstring(base64_decode('${(await tiny.body()).toString('base64')}')); echo json_encode([$i[0],$i[1]]);`));
    assert.deepEqual(tinySize, [64, 32], 'route never upscales');
    // Change only our own disposable image, then restore its exact original bytes.
    const small = owned.files[1];
    const original = php(`$x=${payload(small)}; $p='/var/www/html/artwork/images/uploads/'.$x['name'].'.jpg';
      if(!hash_equals($x['hash'],hash_file('sha256',$p))) throw new Exception('owned image changed'); echo base64_encode(file_get_contents($p));`);
    let changed;
    try {
      changed = php(`$x=${payload(small)}; $p='/var/www/html/artwork/images/uploads/'.$x['name'].'.jpg';
        $bytes=base64_decode('${original}'); $comment='${marker}';
        $bytes=substr($bytes,0,2)."\\xff\\xfe".pack('n',strlen($comment)+2).$comment.substr($bytes,2);
        file_put_contents($p,$bytes); echo hash_file('sha256',$p);`);
      assert.notEqual(changed, small.hash);
      // Fresh content key is uncached. A busy 128M request must fail gracefully
      // before GD decoding, without changing the web server memory setting.
      const bounded = php(`require '/var/www/html/artwork/artwork-media.php';
        $_SERVER['REQUEST_METHOD']='GET'; $_GET=${payload({ name: small.name, w: '320' })}; $_GET['v']='${changed}';
        $headroom=str_repeat('x',112*1048576); ob_start(); artwork_media_response(); $body=ob_get_clean();
        $set=artwork_media_srcset(artwork_media_source('${small.name}'));
        unset($headroom); echo json_encode([http_response_code(),$body,$set]);`);
      assert.deepEqual(JSON.parse(bounded), [404, 'Image unavailable.', 'images/uploads/' + small.name + '.jpg 64w'], 'bounded 128M refusal keeps raw-image fallback');
      const noGd = php(`require '/var/www/html/artwork/artwork-media.php';
        echo artwork_media_srcset(artwork_media_source('${small.name}'));`, ['-d', 'disable_functions=imagecreatefromstring']);
      assert.equal(noGd, 'images/uploads/' + small.name + '.jpg 64w', 'no-GD installations retain working raw images');
      assert.equal((await publicCtx.request.get(url(small, 320))).status(), 404, 'changed bytes invalidate old version');
      assert.equal((await publicCtx.request.get(url(small, 320, changed))).status(), 200, 'changed bytes get a new derivative');
    } finally {
      php(`$x=${payload(small)}; $p='/var/www/html/artwork/images/uploads/'.$x['name'].'.jpg';
        if(!hash_equals('${changed || small.hash}',hash_file('sha256',$p))) throw new Exception('owned replacement changed');
        file_put_contents($p,base64_decode('${original}'));`);
    }
    for (const query of ['name[]=x&w=320&v=' + source.hash, 'name=../functions&w=320&v=' + source.hash,
      'name=..%2Fimages%2Fuploads%2Fx&w=320&v=' + source.hash, 'name=%00&w=320&v=' + source.hash,
      'name=' + source.name + '&w[]=320&v=' + source.hash, 'name=' + source.name + '&w=321&v=' + source.hash,
      'name=' + source.name + '&w=320&v[]=x', 'name=' + source.name + '&w=320&v=' + source.hash + '&path=functions.php']) {
      const response = await publicCtx.request.get(base + 'artwork-media.php?' + query);
      assert.equal(response.status(), 400, query);
      assert.doesNotMatch(await response.text(), /<html|<\?php/i);
    }
    for (const file of [owned.files[2], owned.files[3], { name: 'missing-' + marker, hash: source.hash }]) assert.equal((await publicCtx.request.get(url(file, 320))).status(), 404);
    assert.equal((await publicCtx.request.get(url(source, 320, '0'.repeat(64)))).status(), 404, 'stale content version');
    assert.equal((await publicCtx.request.post(url(source, 320))).status(), 405);
    const head = await publicCtx.request.head(url(source, 320)); assert.equal(head.status(), 200); assert.equal((await head.body()).length, 0);
  } finally { await publicCtx.close(); }
  await page.goto(detailUrl(owned.paintings[0].id));
  const detailImage = page.locator('.painting-image img');
  assert.match(await detailImage.getAttribute('src'), new RegExp('images/uploads/' + source.name + '\\.jpg$'));
  assert.match(await detailImage.getAttribute('srcset'), /artwork-media\.php/);
  check(!(await page.locator('.viewer-stage img').getAttribute('srcset')), 'viewer uses full real source');
  await page.goto(base + 'art-main.php?' + new URLSearchParams({ q: needle }));
  check(await page.locator('.art-image img').first().getAttribute('srcset'), 'responsive thumbnails');
}
async function viewer(page, owned) {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  for (const width of [320, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 800 });
    await page.goto(detailUrl(owned.paintings[23].id));
    const trigger = page.locator('[data-artwork-viewer]');
    await trigger.focus(); await trigger.press('Enter');
    const dialog = page.locator('#artwork-viewer');
    await assert.doesNotReject(() => dialog.waitFor({ state: 'visible' }));
    assert.equal(await page.locator('[data-viewer-close]').evaluate(el => el === document.activeElement), true);
    const stage = page.locator('.viewer-stage');
    await page.waitForFunction(() => document.querySelector('[data-viewer-status]').textContent.includes('Fit'));
    await stage.focus(); await stage.press('1');
    assert.equal(await page.locator('[data-viewer-zoom=in]').isDisabled(), true);
    await stage.press('+');
    const atActual = await stage.locator('img').evaluate(el => new DOMMatrix(getComputedStyle(el).transform).a);
    assert.ok(atActual <= 1 && atActual > 0.999, 'actual source-pixel ceiling');
    for (let i = 0; i < 20; i++) await stage.press('ArrowLeft');
    const bounds = await stage.evaluate(el => {
      const image = el.querySelector('img'), matrix = new DOMMatrix(getComputedStyle(image).transform);
      return { offset: matrix.e + image.naturalWidth / 2, bound: Math.max(0, (image.naturalWidth - el.clientWidth) / 2), rect: image.getBoundingClientRect().width, source: image.naturalWidth };
    });
    assert.ok(Math.abs(bounds.offset) <= bounds.bound + 0.1, 'pan is clamped');
    assert.ok(bounds.rect <= bounds.source + 0.1, 'no displayed detail exceeds source pixels');
    const box = await stage.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2); await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 - 100, box.y + box.height / 2 - 80); await page.mouse.up();
    const afterDrag = await stage.locator('img').getAttribute('style');
    check(afterDrag.includes('scale(1)'), 'drag preserves zoom');
    await page.locator('[data-viewer-fit]').click();
    check((await page.locator('[data-viewer-status]').textContent()).includes('Fit'), 'fit reset');
    const fitState = await stage.locator('img').getAttribute('style');
    await stage.focus(); await stage.press('ArrowDown');
    assert.equal(await stage.locator('img').getAttribute('style'), fitState, 'fit cannot pan past edges');
    const reduced = await dialog.evaluate(el => ({ transform: getComputedStyle(el).transform, transition: getComputedStyle(el).transitionDuration }));
    assert.equal(reduced.transform, 'none', 'reduced motion dialog has no transform');
    assert.equal(reduced.transition, '0s', 'keyboard dialog is instant');
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#artwork-viewer').open
      && document.activeElement.matches('[data-artwork-viewer]')
      && !document.documentElement.classList.contains('viewer-open'));
    check(await page.evaluate(() => !document.documentElement.classList.contains('viewer-open')), 'Escape returns focus and unlocks scroll');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'responsive detail ' + width);
  }
  await page.locator('[data-artwork-viewer]').click();
  await page.locator('.viewer-neighbors a[rel=next]').click();
  assert.equal(new URL(page.url()).searchParams.get('page'), '2', 'viewer ordinary navigation crosses collection page');
  assert.equal(await page.locator('#artwork-viewer').evaluate(el => el.open), false);
  assert.deepEqual(errors, [], 'no viewer runtime errors');
}
async function touch(browser, owned) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
  try {
    await login(ctx); const page = await ctx.newPage(); await page.goto(detailUrl(owned.paintings[0].id));
    await page.locator('[data-artwork-viewer]').tap();
    await page.waitForFunction(() => document.querySelector('[data-viewer-status]').textContent.includes('Fit'));
    const stage = page.locator('.viewer-stage'); const box = await stage.boundingBox();
    const cdp = await ctx.newCDPSession(page);
    const x = box.x + box.width / 2, y = box.y + box.height / 2;
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x - 20, y, id: 1 }, { x: x + 20, y, id: 2 }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x - 100, y, id: 1 }, { x: x + 100, y, id: 2 }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    const scale = await stage.locator('img').evaluate(el => new DOMMatrix(getComputedStyle(el).transform).a);
    check(scale <= 1 && scale > 0.3, 'real touch pinch zoom and pixel ceiling');
    await page.locator('[data-viewer-fit]').tap();
    check((await page.locator('[data-viewer-status]').textContent()).includes('Fit'), 'touch reset');
    await cdp.detach();
  } finally { await ctx.close(); }
}

(async () => {
  ownership(); const before = baseline(); let owned, browser;
  try {
    owned = create(); browser = await chromium.launch({ executablePath: browserPath, headless: true });
    const ctx = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 1024, height: 800 } });
    await login(ctx); const page = await ctx.newPage();
    await navigation(page, owned); await media(ctx, page, owned); await viewer(page, owned); await touch(browser, owned);
    const nojs = await browser.newContext({ javaScriptEnabled: false });
    await login(nojs); const plain = await nojs.newPage(); await plain.goto(detailUrl(owned.paintings[0].id));
    const raw = await plain.locator('[data-artwork-viewer]').getAttribute('href');
    check(raw.endsWith('images/uploads/' + owned.files[0].name + '.jpg'), 'no-JS image link');
    assert.equal((await nojs.request.get(base + raw)).status(), 200);
    check(await plain.locator('.painting-neighbors a').count(), 'no-JS adjacent ordinary links');
    await nojs.close(); await ctx.close();
    console.log('Viewer/media regression passed (' + checks + ' checks plus strict assertions).');
  } finally {
    if (browser) await browser.close();
    if (owned) cleanup(owned);
    assert.deepEqual(baseline(), before, 'all existing gallery rows and original image bytes preserved');
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
