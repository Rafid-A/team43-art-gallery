// Optional, exclusive, ownership-labelled fixture only. No application bypasses.
const { configure } = require('./browsing_test_config.cjs');
const { base, db, web } = configure({ writes: true });
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { spawnSync, execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { randomBytes } = require('node:crypto');
const path = require('node:path');
const assert = require('node:assert/strict');

const marker = 'ed' + randomBytes(8).toString('hex');
const tables = 'Artists,Paintings,Galleries,Eras,Genres,Subjects,PaintingGenres,PaintingSubjects,art_users';
const selected = { Artists: ['FirstName', 'LastName', 'Nationality', 'YearOfBirth', 'YearOfDeath'],
  Paintings: ['Title', 'ArtistID', 'GalleryID', 'YearOfWork', 'Medium'] };
const contexts = [], triggers = new Set();
let checks = 0, artist, painting, deletedArtist, deletedPainting, genre;
function check(ok, message) { assert.ok(ok, message); checks++; }
function sql(query, { root = false, failure = false } = {}) {
  const args = root
    ? ['exec', '-i', db, 'sh', '-c', 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" art -N -B']
    : ['exec', '-i', '-e', 'MYSQL_PWD=mypassword', db, 'mariadb', '-utestuser', 'art', '-N', '-B'];
  const result = spawnSync('docker', args, { input: query, encoding: 'utf8' });
  if (failure) return result;
  if (result.status !== 0) throw Error('Fixture SQL failed: ' + result.stderr);
  return result.stdout.trim();
}
function ownership() {
  configure({ writes: true });
  const inspect = args => JSON.parse(execFileSync('docker', args, { encoding: 'utf8' }))[0];
  const project = inspect(['inspect', db]).Config.Labels['com.docker.compose.project'];
  for (const [container, destination, key] of [[db, '/var/lib/mysql', 'gallery-db'],
    [web, '/var/www/html/artwork/images/uploads', 'gallery-uploads']]) {
    const mount = inspect(['inspect', container]).Mounts.find(m => m.Destination === destination);
    check(mount?.Type === 'volume' && mount.Name === `${project}_${key}`, 'owned ' + key + ' mount');
    const labels = inspect(['volume', 'inspect', mount.Name]).Labels;
    check(labels?.['com.docker.compose.project'] === project && labels?.['com.docker.compose.volume'] === key
      && labels?.['t3.fixture'] === 'issue13-browsing-review' && labels?.['t3.issue'] === '13', 'owned ' + key + ' labels');
  }
}
function row(table, id) {
  const key = table === 'Artists' ? 'ArtistID' : 'PaintingID';
  const columns = sql('SHOW COLUMNS FROM ' + table).split('\n').map(line => line.split('\t')[0]);
  return JSON.parse(sql(`SELECT JSON_OBJECT(${columns.map(c => `'${c}',\`${c}\``).join(',')}) FROM ${table} WHERE ${key}=${id}`));
}
function unchangedOtherColumns(before, after, table) {
  for (const column of Object.keys(before)) if (!selected[table].includes(column))
    assert.deepEqual(after[column], before[column], table + '.' + column + ' preserved');
  checks++;
}
function grantChecks() {
  ownership();
  const before = sql('CHECKSUM TABLE ' + tables);
  const users = () => sql('SELECT Host,User,authentication_string FROM mysql.user ORDER BY Host,User', { root: true });
  const accounts = users();
  const unrelated = () => sql(`SELECT GRANTEE,TABLE_CATALOG,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.USER_PRIVILEGES ORDER BY 1,3;
    SELECT * FROM information_schema.SCHEMA_PRIVILEGES ORDER BY GRANTEE,TABLE_SCHEMA,PRIVILEGE_TYPE;
    SELECT * FROM information_schema.TABLE_PRIVILEGES ORDER BY GRANTEE,TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE;
    SELECT * FROM information_schema.COLUMN_PRIVILEGES WHERE NOT (TABLE_SCHEMA='art' AND PRIVILEGE_TYPE='UPDATE' AND
      ((TABLE_NAME='Artists' AND COLUMN_NAME IN ('FirstName','LastName','Nationality','YearOfBirth','YearOfDeath')) OR
       (TABLE_NAME='Paintings' AND COLUMN_NAME IN ('Title','ArtistID','GalleryID','YearOfWork','Medium'))))
    ORDER BY GRANTEE,TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,PRIVILEGE_TYPE;`, { root: true });
  const oldGrants = unrelated();
  const migration = readFileSync(path.join(__dirname, '../artwork/sql/09-editor-grants.sql'), 'utf8');
  sql(migration, { root: true });
  const grants = () => sql(`SELECT GRANTEE,TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES
    WHERE TABLE_SCHEMA='art' AND PRIVILEGE_TYPE='UPDATE' AND GRANTEE LIKE '''testuser''@%'
    ORDER BY GRANTEE,TABLE_NAME,COLUMN_NAME`, { root: true });
  const once = grants();
  sql(migration, { root: true });
  check(grants() === once, '09 is idempotent');
  const hosts = sql("SELECT Host FROM mysql.user WHERE User='testuser' AND Host IN ('%','localhost','127.0.0.1') ORDER BY Host", { root: true }).split('\n').filter(Boolean);
  const expected = hosts.flatMap(host => Object.entries(selected).flatMap(([table, columns]) =>
    columns.map(column => `'testuser'@'${host}'\t${table}\t${column}`))).sort();
  assert.deepEqual(once.split('\n').filter(Boolean).sort(), expected); checks++;
  check(users() === accounts, '09 creates no users and preserves authentication');
  check(unrelated() === oldGrants, '09 preserves every unrelated privilege');
  check(sql('CHECKSUM TABLE ' + tables) === before, '09 preserves data and account checksums');
  check(readFileSync(path.join(__dirname, '../docker/db.Dockerfile'), 'utf8').includes('COPY artwork/sql/09-editor-grants.sql /docker-entrypoint-initdb.d/09-editor-grants.sql'), 'fresh Docker initialization includes 09');
}
async function context(browser, options = {}) {
  const ctx = await browser.newContext({ baseURL: base, ...options }); contexts.push(ctx); return ctx;
}
async function form(ctx, url) {
  const response = await ctx.request.get(url, { maxRedirects: 0 });
  check(response.status() === 200, url + ' loads');
  const html = await response.text();
  const csrf = html.match(/name="csrf" value="([a-f0-9]{64})"/)?.[1];
  check(!!csrf, 'CSRF token present');
  return { csrf, html };
}
async function login(ctx, user = 'user1', password = 'pass1') {
  const { csrf } = await form(ctx, 'art-login.php');
  const r = await ctx.request.post('authenticate.php', { form: { csrf, userid: user, password }, maxRedirects: 0 });
  check(r.status() === 303 && r.headers().location === (user === 'user1' ? 'admin.php' : 'art-main.php'), 'login ' + user);
}
const url = (kind, id, context = '') => `${kind}-edit.php?id=${id}${context ? '&' + context : ''}`;
async function submit(ctx, endpoint, fields) {
  const { csrf } = await form(ctx, endpoint);
  const body = new URLSearchParams({ csrf, ...fields });
  return ctx.request.post(endpoint, { data: body.toString(), headers: { 'content-type': 'application/x-www-form-urlencoded' }, maxRedirects: 0 });
}
function href(html, label) {
  const match = [...html.matchAll(/<a\b[^>]*href="([^"]+)"[^>]*>([^<]*)<\/a>/g)].find(m => m[2] === label);
  check(!!match, 'link ' + label); return match[1].replaceAll('&amp;', '&');
}
function sameContext(actual, expected) {
  const params = new URL(actual, base).searchParams;
  for (const [key, value] of new URLSearchParams(expected)) check(params.get(key) === value, 'context ' + key);
}
async function reject(ctx, endpoint, fields, field, table, id, errorText) {
  const before = row(table, id);
  const response = await submit(ctx, endpoint, fields);
  const html = await response.text();
  check(response.status() === 422 && html.includes(`id="${field}-error"`) && html.includes(errorText), 'field error ' + field + ': ' + errorText);
  assert.deepEqual(row(table, id), before); checks++;
  check(html.includes('data-has-unsaved-values="true"'), 'failed values marked dirty');
  return html;
}
async function main() {
  ownership();
  const baseline = sql('CHECKSUM TABLE ' + tables);
  let browser;
  try {
    grantChecks();
    ownership();
    // Never update baseline records. Unique sentinels identify exact cleanup rows.
    artist = Number(sql(`INSERT INTO Artists (FirstName,LastName,Nationality,Gender,YearOfBirth,YearOfDeath,Details,ArtistLink)
      VALUES ('Fixture','${marker}','Fixture','X',1900,1950,'${marker}','https://example.com/fixture'); SELECT LAST_INSERT_ID();`));
    const gallery = Number(sql('SELECT GalleryID FROM Galleries ORDER BY GalleryID LIMIT 1'));
    const otherArtist = Number(sql(`SELECT ArtistID FROM Artists WHERE ArtistID<>${artist} ORDER BY ArtistID LIMIT 1`));
    const otherGallery = Number(sql(`SELECT GalleryID FROM Galleries WHERE GalleryID<>${gallery} ORDER BY GalleryID LIMIT 1`));
    painting = Number(sql(`INSERT INTO Paintings (Title,ArtistID,GalleryID,YearOfWork,Medium,Excerpt,Description,ImageFileName)
      VALUES ('${marker}',${artist},${gallery},1901,'Oil','${marker}','${marker}','${marker}image'); SELECT LAST_INSERT_ID();`));
    const initialArtist = row('Artists', artist), initialPainting = row('Paintings', painting);
    for (const [table, key, id, column] of [['Artists','ArtistID',artist,'ArtistID'], ['Artists','ArtistID',artist,'Details'],
      ['Paintings','PaintingID',painting,'PaintingID'], ['Paintings','PaintingID',painting,'ImageFileName'], ['Galleries','GalleryID',gallery,'GalleryName']]) {
      check(sql(`UPDATE ${table} SET ${column}=${column} WHERE ${key}=${id}`, { failure: true }).status !== 0, 'app cannot update ' + table + '.' + column);
    }
    browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSING_BROWSER || undefined });
    const admin = await context(browser), client = await context(browser), anon = await context(browser);
    await login(admin); await login(client, 'user2', 'pass2');
    const aUrl = url('artist', artist), pUrl = url('painting', painting);
    const a = { FirstName: 'Changed', LastName: marker, Nationality: 'Revised', YearOfBirth: '1900', YearOfDeath: '1950' };
    const p = { Title: marker + ' revised', ArtistID: String(artist), GalleryID: String(gallery), YearOfWork: '1902', Medium: 'New medium' };
    for (const [ctx, status] of [[client,403],[anon,303]]) for (const endpoint of [aUrl,pUrl]) {
      check((await ctx.request.get(endpoint,{maxRedirects:0})).status() === status, 'unauthorized GET denied');
      check((await ctx.request.post(endpoint,{form:{...a,...p},maxRedirects:0})).status() === status, 'unauthorized direct POST denied before processing');
    }
    for (const [endpoint, fields] of [[aUrl,a],[pUrl,p]]) {
      check((await admin.request.post(endpoint,{form:fields,maxRedirects:0})).status() === 403, 'editor requires CSRF');
      check((await submit(admin, endpoint, fields)).status() === 303, 'admin valid edit');
      const reopened = await form(admin, endpoint);
      check(reopened.html.includes(fields.FirstName || fields.Title), 'saved edit reopens');
      check((await submit(admin, endpoint, fields)).status() === 303, 'unchanged submission succeeds');
    }
    for (const [table,id,fields] of [['Artists',artist,a],['Paintings',painting,p]]) {
      const saved=row(table,id);
      for (const [field,value] of Object.entries(fields)) check(String(saved[field])===value,'saved selected field '+table+'.'+field);
    }
    await submit(admin,aUrl,{...a,ArtistID:'999999',Gender:'Q',Details:'forged',ArtistLink:'https://example.com/forged'});
    await submit(admin,pUrl,{...p,PaintingID:'999999',ImageFileName:'forged',Description:'forged',Width:'999'});
    unchangedOtherColumns(initialArtist,row('Artists',artist),'Artists');
    unchangedOtherColumns(initialPainting,row('Paintings',painting),'Paintings');
    for (const field of Object.keys(a)) {
      const fields = {...a}; delete fields[field]; fields[field+'[]']='bad';
      const html = await reject(admin,aUrl,fields,field,'Artists',artist,'single text value');
      if (field !== 'LastName') check(html.includes(`value="${marker}"`), 'other submitted values retained');
    }
    for (const field of ['FirstName','LastName','Nationality','YearOfBirth','YearOfDeath'])
      await reject(admin,aUrl,{...a,[field]:'x'.repeat(51)},field,'Artists',artist,field.startsWith('Year')?'whole year':'50 characters');
    await reject(admin,aUrl,{...a,LastName:''},'LastName','Artists',artist,'required');
    for (const year of ['-10001','2101','1900.5','1e3'])
      await reject(admin,aUrl,{...a,YearOfBirth:year},'YearOfBirth','Artists',artist,'whole year');
    const retained = await reject(admin,aUrl,{...a,FirstName:'Keep me',YearOfDeath:'1899'},'YearOfDeath','Artists',artist,'must not precede');
    check(retained.includes('value="Keep me"') && retained.includes('value="1899"'), 'chronology error retains entered values');
    for (const [birth,death] of [['1900','1900'],['',''],['1900','0'],['0','-10000'],['-10000','2100']])
      check((await submit(admin,aUrl,{...a,YearOfBirth:birth,YearOfDeath:death})).status() === 303, 'equal/unknown/year boundary policy ' + birth + '/' + death);
    for (const field of Object.keys(p)) {
      const fields={...p}; delete fields[field]; fields[field+'[]']='bad';
      await reject(admin,pUrl,fields,field,'Paintings',painting,'single text value');
    }
    for (const field of ['Title','Medium']) for (const value of ['', 'x'.repeat(256)])
      await reject(admin,pUrl,{...p,[field]:value},field,'Paintings',painting,'1 to 255');
    for (const value of ['0','2101','1900.5','1e3'])
      await reject(admin,pUrl,{...p,YearOfWork:value},'YearOfWork','Paintings',painting,'year from');
    for (const field of ['ArtistID','GalleryID']) await reject(admin,pUrl,{...p,[field]:'2147483647'},field,'Paintings',painting,'Choose');
    for (const year of ['1','2100']) check((await submit(admin,pUrl,{...p,YearOfWork:year})).status() === 303, 'painting boundary ' + year);
    check((await submit(admin,aUrl,{...a,FirstName:'x'.repeat(50),LastName:'y'.repeat(50),Nationality:'z'.repeat(50)})).status() === 303, 'artist length boundary');
    check((await submit(admin,pUrl,{...p,Title:'x'.repeat(255),Medium:'y'.repeat(255),ArtistID:String(otherArtist),GalleryID:String(otherGallery)})).status() === 303, 'painting lengths and parent changes');
    await submit(admin,aUrl,a); await submit(admin,pUrl,p);
    // Send malformed bytes as a real URL-encoded HTTP request, rather than a Unicode string.
    for (const [endpoint,fields,field,table,id] of [[aUrl,a,'FirstName','Artists',artist],[pUrl,p,'Title','Paintings',painting]]) {
      const before=row(table,id), {csrf}=await form(admin,endpoint);
      const body=new URLSearchParams({csrf,...fields}); body.delete(field);
      const r=await admin.request.post(endpoint,{data:body.toString()+`&${field}=%C3%28`,headers:{'content-type':'application/x-www-form-urlencoded'},maxRedirects:0});
      check(r.status()===422 && (await r.text()).includes('valid UTF-8 text'), 'malformed UTF-8 field error');
      assert.deepEqual(row(table,id),before); checks++;
    }
    const page=await admin.newPage(), errors=[]; page.on('pageerror',e=>errors.push(e.message));
    page.on('dialog',dialog=>dialog.accept());
    await page.goto(base+aUrl);
    await page.locator('#YearOfBirth').fill('1900'); await page.locator('#YearOfDeath').fill('1899');
    check(await page.locator('#YearOfDeath').evaluate(e=>e.validity.customError), 'browser chronology rejects earlier death');
    await page.locator('#YearOfDeath').fill('1900');
    check(await page.locator('#artist-form').evaluate(e=>e.checkValidity()), 'browser accepts equal years');
    await page.locator('#YearOfBirth').fill('0'); await page.locator('#YearOfDeath').fill('-10000');
    check(await page.locator('#artist-form').evaluate(e=>e.checkValidity()), 'browser accepts unknown birth');
    await page.locator('#YearOfBirth').fill('2101');
    check(await page.locator('#YearOfBirth').evaluate(e=>e.validity.rangeOverflow),'artist browser range');
    await page.locator('#YearOfBirth').fill('1900.5');
    check(await page.locator('#YearOfBirth').evaluate(e=>e.validity.stepMismatch),'artist browser whole-year precision');
    await page.locator('#YearOfBirth').fill('1900'); await page.locator('#YearOfDeath').fill('1900');
    await page.locator('#LastName').fill('');
    check(await page.locator('#LastName').evaluate(e=>e.validity.valueMissing),'artist browser required value');
    check(await page.locator('#FirstName').getAttribute('maxlength')==='50','artist browser length limit');
    await page.goto(base+pUrl);
    await page.locator('#YearOfWork').fill('0');
    check(await page.locator('#YearOfWork').evaluate(e=>e.validity.rangeUnderflow),'painting browser range');
    await page.locator('#YearOfWork').fill('1900.5');
    check(await page.locator('#YearOfWork').evaluate(e=>e.validity.stepMismatch),'painting browser whole-year precision');
    await page.locator('#Title').fill('');
    check(await page.locator('#Title').evaluate(e=>e.validity.valueMissing),'painting browser required value');
    check(await page.locator('#Medium').getAttribute('maxlength')==='255','painting browser length limit');
    check(errors.length===0,'editor JS has no runtime errors');
    const nojs=await context(browser,{javaScriptEnabled:false}); await login(nojs);
    await reject(nojs,aUrl,{...a,YearOfDeath:'1899'},'YearOfDeath','Artists',artist,'must not precede');
    check((await submit(nojs,aUrl,a)).status()===303,'valid no-JS save');
    for (const [table,key,id,endpoint,fields] of [['Artists','ArtistID',artist,aUrl,a],['Paintings','PaintingID',painting,pUrl,p]]) {
      const name=marker+table.toLowerCase();
      check(sql(`SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='art' AND TRIGGER_NAME='${name}'`)==='0','trigger unused');
      sql(`DELIMITER //\nCREATE TRIGGER ${name} BEFORE UPDATE ON ${table} FOR EACH ROW BEGIN IF OLD.${key}=${id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned editor failure'; END IF; END//\nDELIMITER ;`,{root:true}); triggers.add(name);
      const before=row(table,id), r=await submit(admin,endpoint,{...fields,FirstName:'Failed',Title:'Failed'});
      check(r.status()===422 && (await r.text()).includes('No records were changed'),'forced UPDATE failure reported');
      assert.deepEqual(row(table,id),before); checks++;
      sql(`DROP TRIGGER ${name}`,{root:true}); triggers.delete(name);
    }
    const directory='type=artists&manage=1&page=2&sort=nationality&dir=desc&directory_type=galleries&directory_manage=1&directory_page=2&directory_sort=city&directory_dir=desc';
    const origin='origin=painting&origin_id='+painting+'&origin_artist='+artist+'&origin_q=fixture&origin_page=3&origin_sort=artist&origin_dir=desc';
    const ctx=directory+'&'+origin;
    const html=await (await admin.request.get('objects.php?id='+artist+'&'+ctx)).text();
    const edit=href(html,'Edit artist'); sameContext(edit,ctx);
    const editForm=await form(admin,edit); sameContext(editForm.html.match(/action="(artist-edit[^\"]+)"/)[1].replaceAll('&amp;','&'),ctx);
    sameContext(href(editForm.html,'Cancel'),ctx);
    sameContext(href(await reject(admin,edit,{...a,LastName:''},'LastName','Artists',artist,'required'),'Cancel'),ctx);
    sameContext((await submit(admin,edit,a)).headers().location,ctx);
    const collection='artist='+artist+'&q=fixture&page=3&sort=artist&dir=desc&directory_type=artists&directory_manage=1&directory_page=2&directory_sort=nationality&directory_dir=desc';
    const detail=await (await admin.request.get('painting.php?id='+painting+'&'+collection)).text();
    const pEdit=href(detail,'Edit artwork'); sameContext(pEdit,collection);
    const pForm=await form(admin,pEdit); sameContext(href(pForm.html,'Cancel'),collection);
    sameContext(pForm.html.match(/action="(painting-edit[^\"]+)"/)[1].replaceAll('&amp;','&'),collection);
    sameContext(href(await reject(admin,pEdit,{...p,Title:''},'Title','Paintings',painting,'1 to 255'),'Cancel'),collection);
    sameContext((await submit(admin,pEdit,p)).headers().location,collection);
    for (const target of ['objects.php?type=artists&id='+artist,'painting.php?id='+painting]) {
      const clientHtml=await (await client.request.get(target)).text();
      check(!clientHtml.includes('artist-edit.php') && !clientHtml.includes('painting-edit.php'),'client has no edit links');
    }
    deletedArtist=Number(sql(`INSERT INTO Artists (LastName) VALUES ('${marker}deleted'); SELECT LAST_INSERT_ID();`));
    deletedPainting=Number(sql(`INSERT INTO Paintings (Title,ArtistID,GalleryID,YearOfWork,Medium,Excerpt,ImageFileName) VALUES ('${marker}deleted',${artist},${gallery},1900,'Oil','${marker}','${marker}deleted'); SELECT LAST_INSERT_ID();`));
    await form(admin,url('artist',deletedArtist)); await form(admin,url('painting',deletedPainting));
    sql(`DELETE FROM Paintings WHERE PaintingID=${deletedPainting} AND Excerpt='${marker}'; DELETE FROM Artists WHERE ArtistID=${deletedArtist} AND LastName='${marker}deleted';`);
    for (const [kind,id,contextQuery,fields,label] of [['artist',deletedArtist,ctx,a,'Return to artists'],['painting',deletedPainting,collection,p,'Return to artworks']]) {
      const endpoint=url(kind,id,contextQuery);
      const missing=await admin.request.get(endpoint,{maxRedirects:0});
      check(missing.status()===404,'deleted editor GET');
      const returnLink=href(await missing.text(),label);
      sameContext(returnLink,kind==='artist'?ctx:'type=artists&manage=1&page=2&sort=nationality&dir=desc');
      check((await admin.request.post(endpoint,{form:fields,maxRedirects:0})).status()===404,'deleted editor direct POST');
    }
    const add=await submit(admin,'object-add.php?type=genres&manage=1',{GenreName:marker,Description:marker,Link:''});
    check(add.status()===303 && add.headers().location.includes('objects.php?'),'shared nonartist add redirects without warning');
    genre=Number(new URL(add.headers().location,base).searchParams.get('id'));
    check(sql(`SELECT GenreName FROM Genres WHERE GenreID=${genre}`)===marker,'own genre inserted');
    // Shared add helpers reject the same optional artist types and chronology.
    const badAdd=await submit(admin,'object-add.php?type=artists',{'FirstName[]':'bad',LastName:marker});
    check(badAdd.status()===422 && (await badAdd.text()).includes('single text value'),'artist add shares typed validation');
    const badChronology=await submit(admin,'object-add.php?type=artists',{...a,YearOfDeath:'1899'});
    check(badChronology.status()===422 && (await badChronology.text()).includes('must not precede'),'artist add shares chronology');
    for (const [endpoint,fields,field] of [['object-add.php?type=artists',a,'Nationality'],
      ['art-add.php',{title:marker,artist_id:String(artist),gallery_id:String(gallery),year:'1900',medium:'Oil',image_source:'existing'},'title']]) {
      const {csrf}=await form(admin,endpoint), body=new URLSearchParams({csrf,...fields}); body.delete(field);
      const r=await admin.request.post(endpoint,{data:body.toString()+`&${field}=%C3%28`,headers:{'content-type':'application/x-www-form-urlencoded'},maxRedirects:0});
      check([200,422].includes(r.status()) && (await r.text()).includes('valid UTF-8 text'),'add shares UTF-8 validation');
    }
    unchangedOtherColumns(initialArtist,row('Artists',artist),'Artists');
    unchangedOtherColumns(initialPainting,row('Paintings',painting),'Paintings');
    console.log(`Editor functional checks passed: ${checks}`);
  } finally {
    for (const ctx of contexts) await ctx.close();
    if (browser) await browser.close();
    ownership();
    for (const name of triggers) sql(`DROP TRIGGER ${name}`,{root:true});
    if (genre) sql(`DELETE FROM Genres WHERE GenreID=${genre} AND GenreName='${marker}'`);
    if (deletedPainting) sql(`DELETE FROM Paintings WHERE PaintingID=${deletedPainting} AND Excerpt='${marker}'`);
    if (painting) sql(`DELETE FROM Paintings WHERE PaintingID=${painting} AND Excerpt='${marker}'`);
    if (deletedArtist) sql(`DELETE FROM Artists WHERE ArtistID=${deletedArtist} AND LastName='${marker}deleted'`);
    if (artist) sql(`DELETE FROM Artists WHERE ArtistID=${artist} AND Details='${marker}'`);
    check(sql('CHECKSUM TABLE '+tables)===baseline,'all gallery/account checksums restored after exact cleanup');
    console.log('Editor cleanup verified; baseline gallery and accounts preserved.');
  }
}
main().catch(error=>{ console.error(error); process.exitCode=1; });
