import { chromium } from 'playwright';
import fs from 'fs';
const pw=fs.readFileSync('secrets.env','utf8').match(/DOLI_ADMIN_PASSWORD=(.+)/)[1].trim();
const b=await chromium.launch();
const p=await b.newPage({viewport:{width:1440,height:900}});
await p.goto('http://127.0.0.1:8080/',{waitUntil:'domcontentloaded'});
await p.fill('input[name="username"]','admin'); await p.fill('input[name="password"]',pw);
await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}),p.click('input[type="submit"]')]);
await p.waitForTimeout(2500);
await p.goto('http://127.0.0.1:8080/index.php?mainmenu=home',{waitUntil:'domcontentloaded'});
await p.waitForTimeout(2000);

const top = await p.$$eval('#id-top a, .tmenu a, ul.tmenu li a',
  as => [...new Set(as.map(a=>(a.innerText||'').trim()).filter(t=>t && t.length<30))]);
console.log('  TOP MENU:', JSON.stringify(top));
const side = await p.$$eval('#id-left a, .vmenu a, div.vmenu a',
  as => [...new Set(as.map(a=>(a.innerText||'').trim()).filter(t=>t && t.length<30))]);
console.log('  SIDEBAR :', JSON.stringify(side.slice(0,10)));
await p.screenshot({path:'shot-persona.png',fullPage:true});
await b.close();
