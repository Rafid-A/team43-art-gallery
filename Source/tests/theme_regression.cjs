// Shared-theme checks use existing records and classroom sessions only.
// Ownership verification is read-only; no fixture writes or lifecycle actions.
const { base, db } = require('./browsing_test_config.cjs').configure();
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const sql = query => execFileSync('docker', ['exec', '-e', 'MYSQL_PWD=mypassword', db,
  'mariadb', '-utestuser', 'art', '-N', '-B', '-e', query], { encoding: 'utf8' }).trim();
const checksums = () => sql('CHECKSUM TABLE Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects,art_users');
const fixture = JSON.parse(sql(`SELECT JSON_OBJECT('painting', PaintingID, 'artist', ArtistID)
  FROM Paintings WHERE ImageFileName='019130' AND ArtistID IS NOT NULL ORDER BY PaintingID LIMIT 1`));
const widths = [320, 390, 768, 1024, 1440];
const expected = {
  // Warm gallery palette: original colours with the newer tokens mapped onto it.
  ink: 'rgb(40, 44, 39)', muted: 'rgb(98, 101, 92)', paper: 'rgb(250, 248, 243)',
  surface: 'rgb(238, 236, 228)', accent: 'rgb(63, 85, 63)', hover: 'rgb(46, 64, 46)',
  white: 'rgb(255, 255, 255)', wall: 'rgb(230, 225, 212)', focus: 'rgb(23, 99, 138)',
};
const errors = [];
const shells = new Map();
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions++; };
const equal = (actual, value, message) => { assert.deepEqual(actual, value, message); assertions++; };

function contrast(a, b) {
  const luminance = color => {
    const values = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(value => {
      const channel = value / 255;
      return channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4;
    });
    return values[0] * .2126 + values[1] * .7152 + values[2] * .0722;
  };
  const values = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (values[0] + .05) / (values[1] + .05);
}

async function paint(locator) {
  return locator.evaluate(node => {
    const style = getComputedStyle(node);
    let parent = node;
    let background = style.backgroundColor;
    while ((background === 'rgba(0, 0, 0, 0)' || background === 'transparent') && parent.parentElement) {
      parent = parent.parentElement;
      background = getComputedStyle(parent).backgroundColor;
    }
    return { color: style.color, background, border: style.borderTopColor, outline: style.outlineColor,
      outlineStyle: style.outlineStyle, outlineWidth: parseFloat(style.outlineWidth), font: style.fontFamily };
  });
}

async function fit(page, label) {
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), label + ': no horizontal overflow');
}

async function primaryStates(page, label) {
  const action = page.locator('a.primary, button.primary:not(:disabled), .landing-primary').first();
  if (!await action.count()) return;
  await page.mouse.move(0, 0);
  let state = await paint(action);
  equal(state.background, expected.accent, label + ': primary default');
  equal(state.color, expected.white, label + ': white action label');
  check(contrast(state.color, state.background) >= 4.5, label + ': primary text contrast');
  await action.hover();
  state = await paint(action);
  equal(state.background, expected.hover, label + ': primary hover');
  check(contrast(state.color, state.background) >= 4.5, label + ': hover text contrast');
  await page.mouse.move(0, 0);
  await page.keyboard.press('Tab');
  await action.focus();
  state = await paint(action);
  equal(state.outline, expected.focus, label + ': shared keyboard focus');
  check(state.outlineStyle === 'solid' && state.outlineWidth >= 3, label + ': visible focus outline');
  check(contrast(state.outline, expected.paper) >= 3, label + ': focus contrast');
}

async function shell(page, role, width, label) {
  const result = await page.evaluate(() => {
    const body = getComputedStyle(document.body);
    const header = document.querySelector('.topbar');
    const brand = document.querySelector('.brand');
    const footer = document.querySelector('footer');
    const nav = header.querySelector('nav');
    const style = getComputedStyle(header);
    const bounds = node => {
      const rect = node.getBoundingClientRect();
      return { left: rect.left, width: rect.width, padding: getComputedStyle(node).paddingLeft };
    };
    return { color: body.color, background: body.backgroundColor, font: body.fontFamily,
      pageWidth: body.getPropertyValue('--page-width').trim(), brand: brand.textContent.trim(),
      footer: footer.querySelector('strong').textContent.trim(),
      header: { ...bounds(header), height: header.getBoundingClientRect().height,
        top: style.paddingTop, bottom: style.paddingBottom, font: getComputedStyle(brand).fontFamily,
        size: getComputedStyle(brand).fontSize, weight: getComputedStyle(brand).fontWeight,
        navGap: getComputedStyle(nav).gap },
      main: bounds(document.querySelector('main')), footerBounds: bounds(footer),
      // Display type only: section legends are headings, control-group legends are field labels.
      type: [...document.querySelectorAll('h1, h2, h3, .form-section > legend, .artist-byline, .admin-overview dd, footer strong')]
        .filter(node => node.getClientRects().length).map(node => ({ font: getComputedStyle(node).fontFamily,
          weight: getComputedStyle(node).fontWeight })),
    };
  });
  equal(result.color, expected.ink, label + ': shared text');
  equal(result.background, expected.paper, label + ': shared paper');
  check(result.font.startsWith('system-ui'), label + ': shared sans body family');
  equal(result.pageWidth, '1440px', label + ': shared maximum width');
  equal(result.brand, 'Art Gallery', label + ': header brand');
  equal(result.footer, 'Art Gallery', label + ': footer brand');
  equal(await page.locator('.brand span, footer > span').count(), 0, label + ': no obsolete tagline');
  check(result.type.length > 0 && result.type.every(type => type.font.startsWith('Georgia') && Number(type.weight) === 400), label + ': consistent serif display typography');
  equal(result.header.left, result.main.left, label + ': header/main alignment');
  equal(result.header.width, result.main.width, label + ': header/main width');
  equal(result.main, result.footerBounds, label + ': footer/main alignment and gutters');
  check(result.main.width <= 1440, label + ': shell width ceiling');
  const key = role + ':' + width;
  if (shells.has(key)) equal(result.header, shells.get(key), label + ': header matches other ' + role + ' pages');
  else shells.set(key, result.header);
  await fit(page, label);
  const samples = await page.locator('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), select, textarea, .hint, .form-note, .breadcrumbs, .art-meta, dt, .landing-access').evaluateAll(nodes =>
    nodes.filter(node => node.getClientRects().length).map(node => {
      const style = getComputedStyle(node);
      let parent = node;
      let background = style.backgroundColor;
      while (background === 'rgba(0, 0, 0, 0)' && parent.parentElement) {
        parent = parent.parentElement;
        background = getComputedStyle(parent).backgroundColor;
      }
      return { tag: node.tagName, color: style.color, background, border: style.borderTopColor };
    }));
  for (const sample of samples) {
    check(contrast(sample.color, sample.background) >= 4.5, label + ': ' + sample.tag + ' text contrast');
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(sample.tag)) check(contrast(sample.border, sample.background) >= 3, label + ': field boundary contrast');
  }
  const surfaces = await page.locator('.art-image, .painting-image, .artist-thumbnail, .browse-tile, .login-intro, .landing-artist').evaluateAll(nodes =>
    nodes.filter(node => node.getClientRects().length).map(node => getComputedStyle(node).backgroundColor));
  check(surfaces.every(color => color === expected.surface), label + ': quiet artwork/control surfaces share the palette');
  const visibleActions = await page.locator('.account-actions button, .account-actions > a').evaluateAll(nodes =>
    nodes.filter(node => node.getClientRects().length).map(node => {
      const box = node.getBoundingClientRect();
      return box.left >= 0 && box.right <= innerWidth && box.width >= 44 && box.height >= 44;
    }));
  check(visibleActions.every(Boolean), label + ': account actions fit and retain touch targets');
}

async function navigation(page, role, width, label) {
  if (role === 'public') {
    const links = page.locator('.public-navigation > a');
    equal(await links.allTextContents(), ['Home', 'Collection', 'Log in', 'Register'], label + ': public navigation');
    for (const link of await links.all()) check(await link.isVisible(), label + ': public link visible');
    return;
  }
  const menu = page.locator('.site-menu');
  const toggle = menu.locator('summary');
  if (width <= 1024 && !await menu.evaluate(node => node.open)) await toggle.click();
  const links = page.locator('#main-navigation > a');
  check(await page.locator('#main-navigation > a[href="account.php"]').isVisible(), label + ': account navigation available');
  equal(await page.locator('#main-navigation > a[href="admin.php"]').count(), role === 'admin' ? 1 : 0, label + ': role-aware navigation');
  for (const link of await links.all()) check(await link.isVisible(), label + ': authenticated link visible');
  check(await page.locator('#main-navigation button').isVisible(), label + ': logout available');
  await fit(page, label + ' menu');
  if (width <= 1024) {
    const state = await paint(toggle);
    check(contrast(state.border, state.background) >= 3, label + ': menu boundary contrast');
    await page.keyboard.press('Escape');
    check(!await menu.evaluate(node => node.open), label + ': Escape closes mobile navigation');
  }
}

async function pages(page, role, routes) {
  for (const route of routes) {
    const response = await page.goto(base + route);
    equal(response.status(), 200, role + ' ' + route + ': successful page');
    for (const width of widths) {
      const label = role + ' ' + (route || 'landing') + ' at ' + width;
      await page.setViewportSize({ width, height: 900 });
      await page.waitForFunction(() => {
        const menu = document.querySelector('.site-menu');
        return !menu || menu.open === !matchMedia('(max-width: 1024px)').matches;
      });
      await shell(page, role, width, label);
      await navigation(page, role, width, label);
      if (await page.locator('.form-actions').count()) {
        await page.locator('.form-actions').scrollIntoViewIfNeeded();
        equal(await page.locator('.form-actions').evaluate(node => getComputedStyle(node).position), 'sticky', label + ': sticky form actions');
        check(await page.locator('.form-actions button.primary').isVisible(), label + ': save action available');
      }
    }
    await primaryStates(page, role + ' ' + route);
    const artWall = page.locator('.landing-art-wall, .featured-image').first();
    if (await artWall.count()) equal((await paint(artWall)).background, expected.wall, role + ' ' + route + ': shared artwork wall');
    if (route === 'art-main.php') {
      const lookup = page.locator('#artist-lookup');
      await lookup.fill('a');
      const option = page.locator('#artist-lookup-suggestions [role=option]').first();
      await option.waitFor();
      await lookup.press('ArrowDown');
      const active = page.locator('#artist-lookup-suggestions [aria-selected="true"]');
      const value = await paint(active);
      equal(value.background, expected.surface, 'Suggestion selection uses the shared surface');
      check(contrast(value.color, value.background) >= 4.5, 'Suggestion selection text contrast');
      await lookup.press('Escape');
    }
    if (route.startsWith('painting.php')) {
      await page.locator('[data-artwork-viewer]').click();
      const viewer = page.locator('.artwork-viewer');
      check(await viewer.isVisible(), 'Viewer opens');
      const state = await paint(viewer);
      equal(state.background, expected.ink, 'Viewer retains dark surface');
      check(contrast(state.color, state.background) >= 4.5, 'Viewer foreground contrast');
      for (const control of await viewer.locator('button, .viewer-caption, .viewer-neighbors a').all()) {
        const value = await paint(control);
        check(contrast(value.color, value.background) >= 4.5, 'Viewer control/caption contrast');
      }
      await page.keyboard.press('Escape');
      await viewer.waitFor({ state: 'hidden' });
      check(!await viewer.isVisible(), 'Viewer closes with Escape');
    }
  }
}

async function login(page, user, password) {
  await page.goto(base + 'art-login.php');
  await page.locator('#userid').fill(user);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL(/\/(art-main|admin)\.php$/),
    page.getByRole('button', { name: 'Log in', exact: true }).click(),
  ]);
}

(async () => {
  const original = checksums();
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER || undefined });
  try {
    for (const role of ['public', 'client', 'admin']) {
      const context = await browser.newContext({ viewport: { width: 390, height: 900 }, reducedMotion: 'reduce' });
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      if (role !== 'public') await login(page, role === 'admin' ? 'user1' : 'user2', role === 'admin' ? 'pass1' : 'pass2');
      const routes = role === 'public' ? ['', 'art-login.php', 'register.php'] : role === 'client'
        ? ['', 'art-main.php', `painting.php?id=${fixture.painting}`, 'objects.php?type=artists',
          `objects.php?type=artists&id=${fixture.artist}`, 'objects.php?type=galleries', 'browse.php', 'account.php']
        : ['', 'admin.php', 'manage.php', 'users.php', 'account.php', 'art-add.php',
          `painting-edit.php?id=${fixture.painting}`, `artist-edit.php?id=${fixture.artist}`, 'object-add.php?type=galleries'];
      await pages(page, role, routes);
      await context.close();
    }
    equal(errors, [], 'No browser errors');
  } finally {
    await browser.close();
    equal(checksums(), original, 'Gallery and accounts remain unchanged');
  }
  console.log(`Shared Art Gallery theme regressions passed (${assertions} assertions). No database rows were changed.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
