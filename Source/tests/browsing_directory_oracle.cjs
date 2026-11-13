require('./browsing_test_config.cjs').configure();
﻿// Read-only regression against the explicitly selected disposable runtime.
// Set BROWSING_PLAYWRIGHT, BROWSING_BROWSER, BROWSING_DB_CONTAINER and BROWSING_URL.
const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const { execFileSync } = require('child_process');
const assert = require('assert').strict;
const base = process.env.BROWSING_URL || 'http://localhost:18134/artwork/';
const container = process.env.BROWSING_DB_CONTAINER;
if (!container) throw Error('Set BROWSING_DB_CONTAINER to the disposable DB container');
function sql(q) { return execFileSync('docker',['exec','-e','MYSQL_PWD=mypassword',container,'mariadb','-utestuser','art','-N','-B','-e',q],{encoding:'utf8'}).trim().split('\n').filter(Boolean); }
const cases = {
 artists:['Artists','ArtistID','o.LastName, o.FirstName','o.Nationality'],
 galleries:['Galleries','GalleryID','o.GalleryName','o.GalleryCity'],
 eras:['Eras','EraID','o.EraName','o.EraYears'],
 genres:['Genres','GenreID','o.GenreName','o.GenreID'],
 subjects:['Subjects','SubjectID','o.SubjectName','o.SubjectID'],
 'painting-genres':['PaintingGenres o LEFT JOIN Paintings p ON p.PaintingID=o.PaintingID LEFT JOIN Genres g ON g.GenreID=o.GenreID','PaintingGenreID','p.Title','g.GenreName'],
 'painting-subjects':['PaintingSubjects o LEFT JOIN Paintings p ON p.PaintingID=o.PaintingID LEFT JOIN Subjects s ON s.SubjectID=o.SubjectID','PaintingSubjectID','p.Title','s.SubjectName']
};
const keys = {artists:['name','nationality'],galleries:['name','city'],eras:['name','years'],genres:['name','id'],subjects:['name','id'],'painting-genres':['artwork','classification'],'painting-subjects':['artwork','classification']};
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSING_BROWSER});const page=await browser.newPage();try {
 await page.goto(base+'art-login.php'); await page.locator('#userid').fill('user1'); await page.locator('#password').fill('pass1'); await page.locator('button[type=submit], form button').first().click(); await page.waitForURL('**/admin.php'); await page.goto(base+'art-main.php');
 for(const [type,[table,id,...fields]] of Object.entries(cases)) {
  const from=table.includes(' JOIN ')?table:table+' o'; const count=Number(sql(`SELECT COUNT(*) FROM ${from}`)[0]);
  for (let i=0;i<fields.length;i++) for(const dir of ['asc','desc']) for(const requested of [1,999999]) {
   const last=Math.max(1,Math.ceil(count/30)), current=Math.min(requested,last); const order=fields[i].split(', ').map(x=>x+' '+dir.toUpperCase()).join(', ')+(fields[i]===`o.${id}`?'':`, o.${id} ASC`);
   const expected=sql(`SELECT o.${id} FROM ${from} ORDER BY ${order} LIMIT 30 OFFSET ${(current-1)*30}`).map(Number);
   const u=new URL('objects.php',base);for(const [k,v] of Object.entries({type,manage:'1',sort:keys[type][i],dir,page:requested}))u.searchParams.set(k,v); await page.goto(u.href);
   const actual=await page.locator('.directory-row').evaluateAll(as=>as.map(a=>Number(new URL(a.href).searchParams.get('id')))); assert.deepEqual(actual,expected,`${type} ${keys[type][i]} ${dir} page ${current}`);
   assert.match(await page.locator('.result-count').innerText(),new RegExp(`^${count} .* Page ${current} of ${last}$`));
   if(actual.length){await page.locator('.directory-row').first().click();const back=new URL(await page.locator('.breadcrumbs a').last().getAttribute('href'),base);for(const [k,v] of Object.entries({type,manage:'1',sort:keys[type][i],dir,page:String(current)}))assert.equal(back.searchParams.get(k),v);}
  }
  await page.goto(base+`objects.php?type=${type}&sort=evil&dir=evil&page=-5`);assert.equal(await page.locator('#directory-sort').inputValue(),keys[type][0]);assert.equal(await page.locator('#directory-dir').inputValue(),'asc');assert.match(await page.locator('.result-count').innerText(),/Page 1 of/);
  console.log(type, count, 'sorts and boundaries passed');
 }
 const paintingCount=Number(sql('SELECT COUNT(*) FROM Paintings')[0]);await page.goto(base+'manage.php');const reported=await page.locator('.directory-row > span').allInnerTexts();const counts=[paintingCount,...['artists','galleries','genres','eras','subjects','painting-genres','painting-subjects'].map(type=>cases[type]).map(([table])=>Number(sql('SELECT COUNT(*) FROM '+(table.includes(' JOIN ')?table:table+' o'))[0]))];assert.deepEqual(reported.map(x=>Number(x.match(/\d+/)[0])),counts);
 const artist=Number(sql('SELECT ArtistID FROM Paintings WHERE ArtistID IS NOT NULL LIMIT 1')[0]);
 for(const sort of ['title','artist'])for(const dir of ['asc','desc'])for(const filter of ['all','artist','empty']){
 const where=filter==='artist'?` WHERE p.ArtistID=${artist}`:filter==='empty'?" WHERE p.Title LIKE '%zzzzzzzzzzzzzzzz%'":'';
 const count=Number(sql('SELECT COUNT(*) FROM Paintings p'+where)[0]);const order=sort==='title'?`p.Title ${dir.toUpperCase()}, p.PaintingID ASC`:`a.LastName ${dir.toUpperCase()}, a.FirstName ${dir.toUpperCase()}, p.PaintingID ASC`;
 const expected=sql(`SELECT p.PaintingID FROM Paintings p LEFT JOIN Artists a ON a.ArtistID=p.ArtistID${where} ORDER BY ${order} LIMIT 24`).map(Number);
 const url=new URL('art-main.php',base);url.searchParams.set('sort',sort);url.searchParams.set('dir',dir);if(filter==='artist')url.searchParams.set('artist',artist);if(filter==='empty')url.searchParams.set('q','zzzzzzzzzzzzzzzz');await page.goto(url.href);
 assert.match(await page.locator('.section-heading').first().innerText(),new RegExp(`of ${count} paintings`));const actual=await page.locator('.art-card .art-image').evaluateAll(as=>as.map(a=>Number(new URL(a.href).searchParams.get('id'))));assert.deepEqual(actual,expected,`paintings ${sort} ${dir} ${filter}`);
 if(actual.length){await page.locator('.art-card .art-image').first().click();const back=new URL(await page.locator('.breadcrumbs a').first().getAttribute('href'),base);assert.equal(back.searchParams.get('sort'),sort);assert.equal(back.searchParams.get('dir'),dir);if(filter==='artist')assert.equal(back.searchParams.get('artist'),String(artist));}
 }
 for(const width of [320,768,1024,1440]){await page.setViewportSize({width,height:800});await page.goto(base+'objects.php?type=artists');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`directory overflow at ${width}`);}

 await page.goto(base+'objects.php?type=artists&manage=1&page=2&sort=nationality&dir=desc');await page.getByRole('link',{name:'Add artist'}).click();let cancel=new URL(await page.getByRole('link',{name:'Cancel'}).getAttribute('href'),base);assert.equal(cancel.searchParams.get('page'),'2');assert.equal(cancel.searchParams.get('sort'),'nationality');assert.equal(cancel.searchParams.get('dir'),'desc');
 console.log('painting total and add/cancel context passed');
} finally {await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
