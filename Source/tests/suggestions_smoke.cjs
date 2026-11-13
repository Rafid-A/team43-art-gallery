require('./browsing_test_config.cjs').configure();
﻿const { chromium } = require(process.env.BROWSING_PLAYWRIGHT);
const assert = require('assert');
(async () => {
 const browser = await chromium.launch({executablePath:process.env.BROWSING_BROWSER,headless:true});
 const ctx = await browser.newContext({reducedMotion:'reduce'}); const page = await ctx.newPage();
 const base=process.env.BROWSING_URL; let errors=[]; page.on('pageerror', e=>errors.push(e.message));
 let r=await ctx.request.get(base+'suggestions.php?type=title&prefix=a'); assert.equal(r.status(),401);
 await page.goto(base+'art-login.php'); await page.locator('#userid').fill('user1'); await page.locator('#password').fill('pass1'); await page.getByRole('button', { name: 'Log in', exact: true }).click(); await page.waitForURL('**/admin.php'); await page.goto(base+'art-main.php');
 for (const type of ['title','artist']) {
  for (const prefix of ['a','zzzzzzzzzzzzzzzz','', '%','_','!',"'",'<tag>','é','a'.repeat(255)]) {
   r=await ctx.request.get(base+'suggestions.php',{params:{type,prefix}}); assert.equal(r.status(),200,`${type} ${prefix}`); const json=await r.json(); assert.ok(Array.isArray(json.results)&&json.results.length<=10); if(!prefix) assert.equal(json.results.length,0);
  }
 }
 r=await ctx.request.get(base+'suggestions.php?type=title&prefix=a'); const first=(await r.json()).results.find(x=>/^[A-Za-z]{3}/.test(x.label)); assert.ok(first); const fragment=first.label.slice(1,3); r=await ctx.request.get(base+'suggestions.php',{params:{type:'title',prefix:fragment}}); assert.ok(!(await r.json()).results.some(x=>x.id===first.id && !first.label.toLowerCase().startsWith(fragment.toLowerCase())));
 for(const query of ['type[]=title&prefix=a','type=bad&prefix=a','type=title&prefix[]=a','type=title&prefix='+ 'x'.repeat(256),'type=title&prefix=%FF']) {r=await ctx.request.get(base+'suggestions.php?'+query);assert.equal(r.status(),400,query);}
 r=await ctx.request.post(base+'suggestions.php');assert.equal(r.status(),405);
 await page.locator('#q').fill('a'); await page.locator('#q-suggestions [role=option]').first().waitFor(); assert.equal(await page.locator('#q').getAttribute('aria-expanded'),'true'); await page.locator('#q').press('ArrowDown'); assert.ok(await page.locator('#q').getAttribute('aria-activedescendant')); await page.locator('#q').press('Escape'); assert.equal(await page.locator('#q').getAttribute('aria-expanded'),'false'); assert.equal(await page.locator('#q').inputValue(),'a');
 await page.locator('#artist-lookup').fill('a'); await page.locator('#artist-lookup-suggestions [role=option]').first().waitFor();
 for (const width of [320,768,1024,1440]) {await page.setViewportSize({width,height:800}); assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth),`overflow ${width}`);}
 await page.locator('#artist-lookup').press('Escape'); assert.equal(await page.locator('#artist-lookup').getAttribute('aria-expanded'),'false'); assert.equal(await page.locator('#artist-lookup').inputValue(),'a');
 await page.route('**/suggestions.php?*', async route => {await new Promise(resolve=>setTimeout(resolve,700)); await route.fulfill({json:{results:[{id:1,label:'Old',url:'painting.php?id=1'}]}}).catch(()=>{});});
 await page.locator('#q').fill('old'); await page.waitForTimeout(250); await page.locator('#q').press('Escape'); await page.waitForTimeout(800); assert.equal(await page.locator('#q').getAttribute('aria-expanded'),'false'); assert.equal(await page.locator('#q').inputValue(),'old');
 await page.locator('#q').fill('old'); await page.waitForTimeout(250); await page.locator('#q').fill(''); await page.waitForTimeout(800); assert.equal(await page.locator('#q').getAttribute('aria-expanded'),'false');
 await page.locator('#q').fill('old'); await page.waitForTimeout(250); await page.locator('#artist').focus(); await page.waitForTimeout(800); assert.equal(await page.locator('#q').getAttribute('aria-expanded'),'false');
 await page.unrouteAll({behavior:'wait'});
 await page.route('**/suggestions.php?*', async route => { const prefix=new URL(route.request().url()).searchParams.get('prefix'); if(prefix==='old') await new Promise(resolve=>setTimeout(resolve,700)); await route.fulfill({json:{results:[{id:prefix==='old'?1:2,label:prefix,url:'painting.php?id='+(prefix==='old'?1:2)}]}}).catch(()=>{}); });
 await page.locator('#q').fill('old'); await page.waitForTimeout(250); await page.locator('#q').fill('new'); await page.locator('#q-suggestions [role=option]').first().waitFor(); await page.waitForTimeout(800); assert.equal(await page.locator('#q-suggestions [role=option]').first().textContent(),'new');
 await page.locator('#q').press('Tab'); assert.equal(await page.locator('#q').getAttribute('aria-expanded'),'false');
 await page.unrouteAll({behavior:'wait'}); await page.route('**/suggestions.php?*', route=>route.abort()); await page.locator('#q').fill('failure'); await page.getByText('Suggestions are unavailable. Please try again.').first().waitFor();
 await page.unrouteAll({behavior:'wait'}); await page.route('**/suggestions.php?*', route=>route.fulfill({status:200,contentType:'application/json',body:'{'})); await page.locator('#q').fill('a'); await page.getByText('Suggestions are unavailable. Please try again.').first().waitFor();
 await page.unrouteAll({behavior:'wait'}); await page.route('**/suggestions.php?*', route=>route.fulfill({json:{results:[{id:1,label:'Choice',url:'painting.php?id=1'}]}})); await page.locator('#q').fill('choice'); await page.locator('#q-suggestions [role=option]').first().waitFor(); await page.locator('#q').press('ArrowDown'); await page.locator('#q').press('Enter'); await page.waitForURL('**/painting.php?*'); assert.ok(page.url().includes('id=1'));
 await page.goto(base+'art-main.php'); await page.route('**/suggestions.php?*', route=>route.fulfill({json:{results:[{id:1,label:'Artist',url:'objects.php?type=artists&id=1'}]}})); await page.locator('#artist-lookup').fill('artist'); await page.locator('#artist-lookup-suggestions [role=option]').first().click(); await page.waitForURL('**/objects.php?*'); assert.ok(page.url().includes('type=artists&id=1'));
 assert.deepEqual(errors,[]); await ctx.close();
 const nojs=await browser.newContext({javaScriptEnabled:false});const p=await nojs.newPage(); await p.goto(base+'art-login.php'); await p.locator('#userid').fill('user1');await p.locator('#password').fill('pass1');await p.getByRole('button', { name: 'Log in', exact: true }).click();await p.waitForURL('**/admin.php');await p.goto(base+'art-main.php');assert.equal(await p.locator('#artist').count(),1);await p.locator('#q').fill('art');await p.locator('button:has-text("View artworks")').click();assert.ok(p.url().includes('q=art')); await nojs.close(); await browser.close(); console.log('suggestions smoke checks passed');
})().catch(e=>{console.error(e);process.exitCode=1});
