// Read-only visitor motion checks. Uses only the existing ownership-verified
// fixture; no record writes, fixture-write opt-in or service lifecycle.
const { base, db } = require('./browsing_test_config.cjs').configure();
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const sql = query => execFileSync('docker', ['exec', '-e', 'MYSQL_PWD=mypassword', db,
  'mariadb', '-utestuser', 'art', '-N', '-B', '-e', query], { encoding: 'utf8' }).trim();
const checksums = () => sql('CHECKSUM TABLE Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects,art_users');
const baseline = checksums();
const artwork = JSON.parse(sql(`SELECT JSON_OBJECT('id', PaintingID, 'title', Title) FROM Paintings
  WHERE ImageFileName='019130' ORDER BY PaintingID LIMIT 1`));
assert(artwork, 'Use the baseline bundled artwork for viewer checks');

const errors = [];
const watch = page => {
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error' && /content security policy|refused to (execute|apply)/i.test(message.text())) errors.push(message.text());
  });
};

// The probe listeners only record browser events and storage. They never alter
// navigation, so a missing native transition still leaves ordinary links intact.
const instrument = context => context.addInitScript(() => {
  const write = (key, value) => { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch {} };
  addEventListener('pageswap', event => write('motion-swap', {
    vt: Boolean(event.viewTransition), url: event.activation?.entry?.url || null }));
  addEventListener('pagereveal', event => {
    write('motion-reveal', { vt: Boolean(event.viewTransition),
      from: window.navigation?.activation?.from?.url || null });
    if (!event.viewTransition) return;
    requestAnimationFrame(() => write('motion-animations',
      document.getAnimations({ subtree: true }).map(animation => ({
        name: animation.animationName || null,
        pseudo: animation.effect?.pseudoElement || null,
      }))));
  });
});

async function markers(page) {
  return page.evaluate(() => {
    const read = key => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch { return null; } };
    return { swap: read('motion-swap'), reveal: read('motion-reveal'),
      animations: read('motion-animations') || [] };
  });
}
async function clearMarkers(page) {
  await page.evaluate(() => ['motion-swap', 'motion-reveal', 'motion-animations']
    .forEach(key => { try { sessionStorage.removeItem(key); } catch {} }));
}
// Input-mode detection runs on the next pointer event; under load a fixed
// sleep can sample before it lands. Wait (bounded) for the positive signal.
async function pointerMode(page) {
  await page.waitForFunction(() => document.documentElement.classList.contains('pointer-mode'), null, { timeout: 3000 }).catch(() => {});
  return mode(page);
}
async function mode(page) {
  return page.evaluate(() => ({
    pointer: document.documentElement.classList.contains('pointer-mode'),
    keyboard: document.documentElement.classList.contains('keyboard-mode'),
    navDisabled: document.querySelector('#browse-motion')?.disabled ?? null,
    stored: (() => { try { return sessionStorage.getItem('library-input-mode'); } catch { return 'denied'; } })(),
  }));
}
async function login(page, user, password, destination) {
  await page.goto(base + 'art-login.php');
  await page.locator('#userid').fill(user);
  await page.locator('#password').fill(password);
  // Login returns to a page a guest step was sent away from (covered by
  // review_fixes_regression.cjs); these motion checks then continue from the
  // role's home, as before.
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/art-login.php')),
    page.getByRole('button', { name: 'Log in', exact: true }).click()]);
  if (!page.url().endsWith('/' + destination)) await page.goto(base + destination);
}

async function openRoute(page) {
  const link = page.locator('.theme-route-link').first();
  await link.scrollIntoViewIfNeeded();
  await link.locator('img').evaluate(image => image.decode());
  return link;
}
async function hoverRoute(page) {
  const link = await openRoute(page);
  const box = await link.boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + Math.min(100, box.height / 2));
}
async function imageStyle(page) {
  return page.evaluate(() => {
    const image = document.querySelector('.theme-route-link img');
    const link = document.querySelector('.theme-route-link');
    const style = getComputedStyle(image);
    return { opacity: Number(style.opacity), transform: style.transform,
      linkTransform: getComputedStyle(link).transform,
      transition: style.transitionProperty + ' ' + style.transitionDuration };
  });
}

async function nativeTransition(page, support) {
  await login(page, 'user2', 'pass2', 'art-main.php');
  await page.goto(base + 'browse.php');
  await page.mouse.move(120, 120);
  const before = await pointerMode(page);
  assert(before.pointer && before.stored === 'pointer', 'Pointer navigation mode persists across documents');
  if (!support) {
    assert.equal(before.navDisabled, true, 'Unsupported browsers keep normal instant navigation');
  } else {
    assert.equal(before.navDisabled, false, 'Public pointer navigation opts into native transitions');
  }

  await clearMarkers(page);
  const source = page.url();
  const link = await openRoute(page);
  const destination = await link.getAttribute('href');
  await Promise.all([page.waitForURL('**/objects.php**'), link.click()]);
  assert.equal(page.url(), new URL(destination, base).href);
  if (support) {
    const recorded = await markers(page);
    assert.equal(recorded.swap?.vt, true, 'Native pageswap hands over a view transition');
    assert.equal(recorded.reveal?.vt, true, 'Native pagereveal hands over a view transition');
    assert(recorded.animations.some(animation => animation.name === 'page-in' &&
      animation.pseudo === '::view-transition-new(library-page)'), 'Destination page fades in');
    assert(recorded.animations.some(animation => animation.name === 'page-out' &&
      animation.pseudo === '::view-transition-old(library-page)'), 'Source page fades out');
  } else {
    const recorded = await markers(page);
    assert(!recorded.swap?.vt && !recorded.reveal?.vt, 'No unsupported transition is attempted');
  }
  assert.equal((await mode(page)).navDisabled, !support, 'Public destination keeps the supported transition state');

  await page.goBack();
  await page.waitForURL('**/browse.php**');
  assert.equal(page.url(), source, 'The browser owns history and anchors');
  return destination;
}

async function keyboardInstant(page) {
  await page.keyboard.press('Tab');
  const modeNow = await mode(page);
  assert(modeNow.keyboard && !modeNow.pointer, 'Keyboard input selects instant mode');
  assert.equal(modeNow.navDisabled, true, 'Keyboard navigation disables transition opt-in');
  await clearMarkers(page);
  const link = page.locator('.theme-route-link').first();
  await link.focus();
  await Promise.all([page.waitForURL('**/objects.php**'), page.keyboard.press('Enter')]);
  const recorded = await markers(page);
  assert(!recorded.swap?.vt && !recorded.reveal?.vt, 'Keyboard navigation never starts a page transition');
  assert(!recorded.animations.some(animation => animation.name === 'page-in' || animation.name === 'page-out'),
    'Keyboard navigation has no page fade');
  assert.equal((await mode(page)).keyboard, true, 'Keyboard mode persists to the destination');
}

async function touchInput(page, context) {
  const point = await page.evaluate(() => {
    for (const y of [380, 460, 520, 300]) {
      const element = document.elementFromPoint(5, y);
      if (element && !element.closest('a, button, summary, input, select')) return { x: 5, y };
    }
    return { x: 2, y: 2 };
  });
  const client = await context.newCDPSession(page);
  await client.send('Input.dispatchTouchEvent',
    { type: 'touchStart', touchPoints: [{ ...point, radiusX: 2, radiusY: 2, force: 1 }] });
  await page.waitForTimeout(60);
  await client.send('Input.dispatchTouchEvent', { type: 'touchCancel', touchPoints: [] });
  await client.detach();
}

async function forcedStyle(page, context, selector, pseudo, read) {
  const client = await context.newCDPSession(page);
  await client.send('DOM.enable');
  await client.send('CSS.enable');
  const { root } = await client.send('DOM.getDocument');
  const { nodeId } = await client.send('DOM.querySelector', { nodeId: root.nodeId, selector });
  await client.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: pseudo });
  await page.waitForTimeout(260);
  const result = await read();
  await client.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] });
  await page.waitForTimeout(260);
  await client.detach();
  return result;
}

(async () => {
  let browser;
  try {
    browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER || undefined });
    const support = await (async () => {
      const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const page = await context.newPage();
      await page.goto(base);
      const value = await page.evaluate(() => ({
        pageswap: 'onpageswap' in window,
        pagereveal: 'onpagereveal' in window,
        crossDocument: CSS.supports('view-transition-name', 'none'),
        navigationApi: typeof navigation !== 'undefined' && Boolean(navigation.activation),
      }));
      await context.close();
      return value;
    })();
    const native = support.pageswap && support.pagereveal && support.crossDocument;
    console.log('Native cross-document view transitions: ' + (native ? 'supported' : 'not supported') +
      ' (pageswap=' + support.pageswap + ', pagereveal=' + support.pagereveal +
      ', view-transition CSS=' + support.crossDocument + ', navigation API=' + support.navigationApi + ')');

    // Defaults and server-visible content. A fresh context has no stored mode.
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await instrument(context);
    const page = await context.newPage();
    watch(page);
    await page.goto(base);
    const initial = await mode(page);
    assert(initial.keyboard && !initial.pointer, 'A fresh visit starts in instant keyboard mode');
    assert.equal(initial.navDisabled, true, 'A fresh visit has no page transition opt-in');
    assert.equal(await page.locator('.theme-route-link').count(), 3, 'Visible content is server-rendered');
    assert(await page.locator('main').evaluate(main => main.getBoundingClientRect().height > 0));
    const tokens = await page.evaluate(() => {
      const style = getComputedStyle(document.documentElement);
      return ['--duration-press', '--duration-reveal', '--duration-viewer', '--ease-out']
        .map(name => style.getPropertyValue(name).trim());
    });
    assert.deepEqual(tokens, ['150ms', '180ms', '220ms', 'cubic-bezier(0.23, 1, 0.32, 1)'], 'Motion tokens are preserved');

    // Fine-pointer hover is a separate, subtle acknowledgement. Art cards fade
    // only their image; neither the painting nor the card scales.
    await page.mouse.move(80, 80);
    await page.waitForTimeout(60);
    const pointer = await mode(page);
    assert(pointer.pointer && pointer.stored === 'pointer', 'Fine-pointer movement selects pointer mode');
    assert.equal(pointer.navDisabled, !native, 'Pointer mode enables transitions only when supported');
    await hoverRoute(page);
    await page.waitForTimeout(260);
    const hovered = await imageStyle(page);
    assert(Math.abs(hovered.opacity - .92) < .02, 'Fine-pointer hover fades the artwork slightly');
    assert.equal(hovered.transform, 'none', 'Hover never scales the painting');
    assert.equal(hovered.linkTransform, 'none', 'Hover never scales the card');
    assert.match(hovered.transition, /opacity 0\.18s/, 'Hover uses the reveal token');
    await page.mouse.move(20, 20);
    await page.waitForTimeout(260);
    assert.equal((await imageStyle(page)).opacity, 1, 'Hover fade clears');

    // A keyboard action cancels effects and returns to instant behavior.
    await hoverRoute(page);
    await page.waitForTimeout(260);
    assert(Math.abs((await imageStyle(page)).opacity - .92) < .02);
    await page.keyboard.press('Tab');
    const keyboard = await mode(page);
    assert(keyboard.keyboard && !keyboard.pointer, 'Keyboard input switches modes immediately');
    assert.equal(keyboard.navDisabled, true, 'Keyboard mode removes transition opt-in');
    assert.equal((await imageStyle(page)).opacity, 1, 'Keyboard input cancels an active hover fade');
    assert.match((await imageStyle(page)).transition, /none|0s/, 'Keyboard interactions have no transition');

    // Native page changes, ordinary history, and the instant keyboard path.
    await nativeTransition(page, native);
    await page.goto(base + 'browse.php');
    await keyboardInstant(page);

    // Reduced motion is instant even when pointer input would otherwise animate.
    const reduced = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
    await instrument(reduced);
    const reducedPage = await reduced.newPage();
    watch(reducedPage);
    await login(reducedPage, 'user2', 'pass2', 'art-main.php');
    await reducedPage.goto(base + 'browse.php');
    await reducedPage.mouse.move(80, 80);
    await reducedPage.waitForTimeout(60);
    assert.equal((await mode(reducedPage)).navDisabled, true, 'Reduced motion disables page transitions');
    await hoverRoute(reducedPage);
    await reducedPage.waitForTimeout(200);
    const reducedStyle = await imageStyle(reducedPage);
    assert.equal(reducedStyle.opacity, 1, 'Reduced motion removes hover fades');
    assert.match(reducedStyle.transition, /none|0s/, 'Reduced motion has no image transition');
    await clearMarkers(reducedPage);
    const reducedLink = await openRoute(reducedPage);
    const reducedDestination = await reducedLink.getAttribute('href');
    await Promise.all([reducedPage.waitForURL('**/objects.php**'), reducedLink.click()]);
    assert.equal(reducedPage.url(), new URL(reducedDestination, base).href);
    const reducedMarkers = await markers(reducedPage);
    assert(!reducedMarkers.swap?.vt && !reducedMarkers.reveal?.vt, 'Reduced motion skips native page transitions');
    assert(!reducedMarkers.animations.some(animation => animation.name === 'page-in' || animation.name === 'page-out'));

    await reducedPage.goto(base + `painting.php?id=${artwork.id}`);
    await reducedPage.locator('[data-artwork-viewer]').click();
    assert(await reducedPage.locator('.artwork-viewer').isVisible());
    const reducedViewer = await reducedPage.locator('.artwork-viewer').evaluate(viewer => {
      const style = getComputedStyle(viewer);
      return { transform: style.transform, transition: style.transitionProperty + ' ' + style.transitionDuration };
    });
    assert.equal(reducedViewer.transform, 'none', 'Reduced motion keeps the viewer untransformed');
    assert.match(reducedViewer.transition, /none|0s/, 'Reduced motion removes viewer transitions');
    await reducedPage.keyboard.press('Escape');
    await reduced.close();

    // A preference change during an active effect cancels it immediately. The
    // pointer mode remains, but transition opt-in returns only with motion.
    await page.goto(base);
    await page.mouse.move(80, 80);
    await page.waitForTimeout(60);
    await hoverRoute(page);
    await page.waitForTimeout(260);
    assert(Math.abs((await imageStyle(page)).opacity - .92) < .02);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.waitForTimeout(120);
    assert.equal((await imageStyle(page)).opacity, 1, 'A live reduced-motion change cancels the fade');
    assert.equal((await mode(page)).navDisabled, true, 'A live reduced-motion change removes opt-in');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.waitForTimeout(120);
    assert.equal((await mode(page)).navDisabled, !native, 'Motion preference changes restore the supported state');

    // Touch has no hover affordance; a deliberate press uses only :active and
    // still never scales the painting.
    const touch = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
    const touchPage = await touch.newPage();
    watch(touchPage);
    await touchPage.goto(base);
    await touchInput(touchPage, touch);
    await touchPage.waitForTimeout(40);
    const touchMode = await mode(touchPage);
    assert(touchMode.pointer && touchMode.stored === 'pointer', 'Touch pointerdown selects pointer mode');
    assert.equal(await touchPage.evaluate(() => matchMedia('(hover: hover) and (pointer: fine)').matches), false);
    const touchLink = await openRoute(touchPage);
    assert.equal((await touchLink.count()), 1);
    const hoverOnly = await forcedStyle(touchPage, touch, '.theme-route-link', ['hover'], () => imageStyle(touchPage));
    assert.equal(hoverOnly.opacity, 1, 'Touch devices never receive fine-pointer hover fades');
    assert.equal(hoverOnly.transform, 'none');
    const pressed = await forcedStyle(touchPage, touch, '.theme-route-link', ['active'], () => imageStyle(touchPage));
    assert(Math.abs(pressed.opacity - .84) < .02, 'Touch press fades the artwork');
    assert.equal(pressed.transform, 'none', 'Touch press never scales the painting');
    assert.equal(pressed.linkTransform, 'none', 'Touch press never scales the card');
    assert.match(pressed.transition, /opacity 0\.18s/, 'Touch press uses the reveal token');
    assert.equal((await imageStyle(touchPage)).opacity, 1, 'Press feedback clears after release');
    await touch.close();

    // Management screens and mode URLs never opt in, on either side.
    const management = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const managementPage = await management.newPage();
    watch(managementPage);
    await login(managementPage, 'user1', 'pass1', 'admin.php');
    await managementPage.mouse.move(80, 80);
    await managementPage.waitForTimeout(60);
    assert.equal((await mode(managementPage)).navDisabled, true, 'Admin pages never opt into transitions');
    await managementPage.goto(base + 'browse.php?manage=1');
    await managementPage.mouse.move(80, 80);
    await managementPage.waitForTimeout(60);
    assert.equal((await mode(managementPage)).navDisabled, true, 'Management mode never opts into transitions');
    await managementPage.goto(base + 'art-main.php');
    assert.equal((await mode(managementPage)).navDisabled, !native, 'Public routes resume transition eligibility');
    await management.close();

    // Denied storage and missing APIs leave ordinary navigation untouched.
    const denied = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await denied.addInitScript(() => {
      Object.defineProperty(Storage.prototype, 'setItem', {
        configurable: true,
        value() { throw new DOMException('denied', 'SecurityError'); },
      });
    });
    const deniedPage = await denied.newPage();
    watch(deniedPage);
    await deniedPage.goto(base);
    await deniedPage.mouse.move(80, 80);
    const deniedMode = await pointerMode(deniedPage);
    assert(deniedMode.pointer && deniedMode.stored === null, 'Denied storage cannot persist pointer mode: ' + JSON.stringify(deniedMode));
    assert.equal(deniedMode.navDisabled, true, 'Denied storage keeps normal navigation');
    await Promise.all([deniedPage.waitForURL('**/art-login.php'), (await openRoute(deniedPage)).click()]);
    await denied.close();

    const missing = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await instrument(missing);
    await missing.addInitScript(() => {
      delete window.onpageswap;
      delete window.onpagereveal;
      delete window.navigation;
    });
    const missingPage = await missing.newPage();
    watch(missingPage);
    await missingPage.goto(base);
    assert.equal(await missingPage.evaluate(() => 'onpageswap' in window || 'onpagereveal' in window), false);
    await missingPage.mouse.move(80, 80);
    const missingMode = await pointerMode(missingPage);
    assert(missingMode.pointer && missingMode.navDisabled, 'Missing page-transition APIs keep normal navigation');
    await Promise.all([missingPage.waitForURL('**/art-login.php'), (await openRoute(missingPage)).click()]);
    // Run the same authenticated destination and history assertions with APIs
    // absent, so fallback coverage cannot silently depend on native support.
    await nativeTransition(missingPage, false);
    await missing.close();

    // Viewer gestures and landing composition are unchanged by the page layer.
    await page.setViewportSize({ width: 320, height: 480 });
    await page.goto(base + `painting.php?id=${artwork.id}`);
    await page.mouse.move(40, 40);
    await page.locator('[data-artwork-viewer]').click();
    await page.locator('.artwork-viewer').waitFor({ state: 'visible' });
    await page.waitForFunction(() => {
      const image = document.querySelector('.viewer-stage img');
      return image?.complete && image.naturalWidth > 0 && !document.querySelector('[data-viewer-zoom="in"]').disabled;
    });
    const beforeZoom = await page.locator('.viewer-stage img').evaluate(image => image.style.transform);
    await page.locator('[data-viewer-zoom="in"]').click();
    const afterZoom = await page.locator('.viewer-stage img').evaluate(image => image.style.transform);
    assert.notEqual(afterZoom, beforeZoom, 'Viewer zoom transform still changes');
    await page.locator('[data-viewer-actual]').click();
    const beforeDrag = await page.locator('.viewer-stage img').evaluate(image => image.style.transform);
    const stage = await page.locator('.viewer-stage').boundingBox();
    await page.mouse.move(stage.x + stage.width / 2, stage.y + stage.height / 2);
    await page.mouse.down();
    await page.mouse.move(stage.x + stage.width / 2 + 60, stage.y + stage.height / 2);
    await page.mouse.up();
    const afterDrag = await page.locator('.viewer-stage img').evaluate(image => image.style.transform);
    assert.notEqual(afterDrag, beforeDrag, 'Viewer drag transform still changes');
    assert(await page.locator('.artwork-viewer').isVisible(), 'Viewer remains open after a gesture');
    await page.keyboard.press('Escape');
    assert(await page.locator('[data-artwork-viewer]').evaluate(node => node === document.activeElement));

    const landing = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const landingPage = await landing.newPage();
    watch(landingPage);
    await landingPage.goto(base);
    assert(await landingPage.locator('[data-landing-arrival]').count() > 0, 'First-visit composition is present');
    await landingPage.waitForTimeout(700);
    assert.equal(await landingPage.locator('[data-landing-arrival]').evaluateAll(nodes =>
      nodes.every(node => getComputedStyle(node).opacity === '1')), true, 'First-visit composition settles visible');
    assert(await landingPage.locator('[data-feature-choice]').count() > 1, 'User-selected feature remains available');
    const choice = landingPage.locator('[data-feature-choice]').nth(1);
    const choiceKey = await choice.getAttribute('data-feature-choice');
    await choice.click();
    await landingPage.waitForTimeout(300);
    assert.equal(await landingPage.locator('[data-feature-image]:not([hidden])').getAttribute('data-feature-image'), choiceKey,
      'Feature crossfade still selects the chosen artwork');
    assert.equal(await landingPage.locator('.landing-fade-ghost').count(), 0, 'Feature crossfade cleans up its ghost');
    assert.equal(new URL(landingPage.url()).pathname, new URL(base).pathname, 'Feature selection stays on the landing');
    await landing.close();

    assert.deepEqual(errors, [], 'No JavaScript or CSP errors');
    console.log('Visitor motion: native transition support reported separately; pointer persistence, page fade/fallback, ' +
      'hover/touch separation, keyboard and reduced-motion instant paths, management exclusion, storage/API fallbacks, ' +
      'viewer transforms and landing composition passed.');
  } finally {
    if (browser) await browser.close();
    assert.equal(checksums(), baseline, 'Gallery/account checksums unchanged');
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
