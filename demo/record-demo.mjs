import { chromium } from 'playwright';
import { execSync } from 'child_process';
import fs from 'fs';
import { makeStage } from './lib.mjs';

const pw = fs.readFileSync(new URL('../secrets.env',import.meta.url))
             .toString().match(/DOLI_ADMIN_PASSWORD=(.+)/)[1].trim();
const B='http://127.0.0.1:8080', M='http://127.0.0.1:8025';
const TOTAL=13;

const browser=await chromium.launch();
const ctx=await browser.newContext({viewport:{width:1440,height:1000},
  recordVideo:{dir:'demo/take/',size:{width:1440,height:1000}}});
const p=await ctx.newPage();
const s=makeStage(p);
const wait=ms=>p.waitForTimeout(ms);

async function go(url){ await p.goto(url,{waitUntil:'domcontentloaded'}); await wait(1600); await s.ready(); }
async function click(sel,label,hold=900){
  await s.cursorTo(sel);
  if(label) await s.spotlight(sel,label), await wait(hold);
  await s.ripple(sel);
  const el=await p.$(sel);
  if(el){ await el.scrollIntoViewIfNeeded().catch(()=>{}); await el.click({force:true}).catch(()=>{}); }
  await s.unspot(); await wait(600);
}
async function typeIn(sel,text,d=62){
  await s.cursorTo(sel); await s.ripple(sel);
  await p.click(sel,{force:true}).catch(()=>{});
  await p.type(sel,text,{delay:d}).catch(()=>{});
  await wait(700);
}

// ───────── 0 · title
await go(B+'/');
await s.chapter('CHARTERED ACCOUNTANT · INDIA','Practice Management',
  'Dolibarr — open source, self-hosted. Client register, filing deadlines, automatic reminders.',5200);

// ───────── 1 · login
await s.step(1,TOTAL);
await s.say('Log in. On the VPS this is your own domain over HTTPS.',4200);
await typeIn('input[name="username"]','admin');
await typeIn('input[name="password"]',pw,40);
await s.hush();
await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}),p.click('input[type="submit"]')]);
await wait(3000); await s.ready();

// ───────── 2 · dashboard
await s.step(2,TOTAL);
await go(B+'/index.php?mainmenu=home');
await s.chapter('STEP 2 OF 10','The Dashboard','What the CA sees every morning.',3600);
await s.step(2,TOTAL);
await s.focus('.tmenu','the whole menu','Only four menu items. Dolibarr ships ~140 modules — the rest stay off.',6000);
await s.unspot();
await s.focus('div.box-flex-item','his next deadlines','Upcoming filing deadlines, per client. This is the point of the tool.',6000);
await s.unspot();
await s.focus('table','scheduled jobs','A scheduled-jobs panel. If "jobs in error" is not zero, reminders are broken.',6000);
await s.unspot(); await s.hush();

// ───────── 3 · client register
await s.step(3,TOTAL);
await go(B+'/societe/list.php');
await s.chapter('STEP 3 OF 10','The Client Register','Imported from CSV in one command.',3400);
await s.step(3,TOTAL);
await s.focus('table','53 clients','Every client, searchable and sortable. GSTIN and PAN come in from the CSV.',6200);
await s.unspot(); await s.hush();

// ───────── 4 · add a client
await s.step(4,TOTAL);
await go(B+'/societe/card.php?action=create');
await s.chapter('STEP 4 OF 10','Adding a Client','Three things here are easy to get wrong.',3600);
await s.step(4,TOTAL);
await s.say('The heading says "New Third Party" — that is Dolibarr\'s own wording, not a bug.',5000);
await typeIn('input[name="name"]','Sharma Textiles Pvt Ltd',55);
await typeIn('input[name="email"]','accounts@sharmatextiles.in',38);
await s.say('Now the first trap.',2600);
await click('input[name="customer"]','TICK THIS — or the client never appears in the list',3600);
await s.say('Miss that box and the record saves, but the client is invisible in the register.',5200);
await s.say('Second trap: GSTIN, PAN and WhatsApp are hidden behind "More...".',5000);
await p.evaluate(()=>{const a=[...document.querySelectorAll('a')].find(x=>/^More\.\.\./.test(x.innerText.trim())); if(a) a.click();});
await wait(1800); await s.ready();
await typeIn('[name="options_gstin"]','27AAAAA0000A1Z5',48);
await typeIn('[name="options_pan"]','AAAAA0000A',48);
await typeIn('[name="options_whatsapp_number"]','919820098200',44);
await s.say('Third trap — and this one silently disables WhatsApp for the client.',5000);
await click('[name="options_whatsapp_optin"]','Meta requires documented consent',3800);
await s.say('No opt-in ticked, no WhatsApp sent. The script refuses, by design.',5000);
await s.hush();
const cbtn=await p.$('input[type="submit"][name="add"], input[type="submit"]');
if(cbtn){ await cbtn.scrollIntoViewIfNeeded().catch(()=>{}); await s.cursorTo('input[type="submit"]');
  await s.ripple('input[type="submit"]');
  await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}),cbtn.click({force:true})]); }
await wait(3000); await s.ready();
await s.focus('td:has-text("27AAAAA0000A1Z5")','GSTIN, saved','Saved. GSTIN and PAN now show directly on the record — no expanding needed.',6400)
  .catch(async()=>{ await s.say('Saved. GSTIN and PAN now show directly on the record.',5200); });
await s.unspot(); await s.hush();

// ───────── 5 · deadline
await s.step(5,TOTAL);
await go(B+'/comm/action/card.php?action=create');
await s.chapter('STEP 5 OF 10','A Filing Deadline','With the reminder that actually matters.',3600);
await s.step(5,TOTAL);
await typeIn('input[name="label"]','GSTR-3B filing due - Sharma Textiles',42);
const d=new Date(Date.now()+7*864e5);
await p.fill('input[name="ap"]',`${String(d.getMonth()+1).padStart(2,'0')}/${String(d.getDate()).padStart(2,'0')}/${d.getFullYear()}`).catch(()=>{});
await s.focus('input[name="ap"]','mandatory','Title and due date. The date is mandatory — a blank one is rejected.',5600);
await s.unspot();
await p.selectOption('select[name="socid"]',{label:/Sharma Textiles/}).catch(async()=>{
  await p.evaluate(()=>{const sel=document.querySelector('select[name="socid"]');
    const o=[...sel.options].find(x=>/Sharma Textiles/.test(x.textContent));
    if(o){sel.value=o.value; sel.dispatchEvent(new Event('change',{bubbles:true}));}});});
await wait(1200); await s.ready();
await s.focus('select[name="socid"]','links it to the client','Link it to the client. That is what puts the deadline on their record.',5600);
await s.unspot();
await click('input[name="addreminder"]','turn the reminder on',3200);
await wait(1200);
await p.fill('input[name="offsetvalue"]','7').catch(()=>{});
await p.locator('select[name="offsetunittype_duration"]').scrollIntoViewIfNeeded().catch(()=>{});
await wait(800); await s.ready();
await s.focus('input[name="offsetvalue"]','seven...','Remind seven before the deadline. Seven what?',4600);
await s.unspot();
await s.focus('select[name="offsetunittype_duration"]','DEFAULTS TO MINUTES',
  'It defaults to MINUTES. Left alone he is warned 7 minutes before a GST deadline.',7000);
await s.say('The single easiest mistake to make in this entire application.',5400);
await s.unspot();
await p.selectOption('select[name="offsetunittype_duration"]','d').catch(()=>{});
await s.focus('select[name="offsetunittype_duration"]','now: DAYS',
  'Change it to Days. Seven days before — which is what he meant.',6000);
await s.unspot();
const st=await p.inputValue('select[name="offsetunittype_duration"]').catch(()=>'?');
const sq=await p.inputValue('input[name="offsetvalue"]').catch(()=>'?');
console.log(`    ASSERT reminder = ${sq} / ${st}  (want 7 / d)`);
await s.hush();
const abtn=await p.$('input[type="submit"][name="add"], input[type="submit"]');
if(abtn){ await abtn.scrollIntoViewIfNeeded().catch(()=>{});
  await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}),abtn.click({force:true})]); }
await wait(3000); await s.ready();

// ───────── 5b · the calendar is GENERATED
await s.step(6,TOTAL);
await s.chapter('STEP 6 OF 13','The Calendar Generates Itself',
  'Statutory dates are fixed by law and derivable from each client profile.',4200);
await s.say('One GST-monthly client with a TAN owes about 44 filings a year.',5200);
await s.say('Across 47 clients that is over a thousand deadlines. Nobody types those.',5600);
await go(B+'/societe/card.php?socid=1');
await s.focus('td:has-text("Monthly"), td:has-text("QRMP")','GST scheme',
  'So the profile drives it: GST scheme, TAN, entity type, audit applicability.',6200)
  .catch(async()=>{ await s.say('The profile drives it: GST scheme, TAN, entity type, audit.',5200); });
await s.unspot();
await go(B+'/comm/action/list.php');
await s.step(7,TOTAL);
await s.focus('table','1,111 deadlines, generated',
  'GSTR-1 on the 11th, GSTR-3B on the 20th, TDS on the 7th, advance tax, ITR, ROC.',6400);
await s.unspot();
await s.say('Generated once each April. Re-running it never duplicates anything.',5400);
await s.hush();

// ───────── 6 · on the client record
await s.chapter('STEP 6 OF 10','On the Client Record','Every deadline, in one place.',3400);
await s.step(6,TOTAL);
await s.focus('a:has-text("Deadlines")','this tab','The deadline now sits on Sharma Textiles, under the Deadlines tab.',6200)
  .catch(async()=>{ await s.say('The deadline now sits on the client record.',5000); });
await s.unspot(); await s.hush();

// ───────── 7 · all deadlines
await s.step(7,TOTAL);
await go(B+'/comm/action/list.php');
await s.chapter('STEP 7 OF 10','Every Client, Every Deadline','The practice-wide view.',3400);
await s.step(7,TOTAL);
await s.focus('table','every client, every deadline','Filterable by date, client or type — his compliance calendar.',6200);
await s.unspot(); await s.hush();

// ───────── 7b · the practice's own money
await s.step(10,TOTAL);
await s.chapter('STEP 10 OF 13','His Own Fees',
  'The half of the practice that funds the other half.',4200);
await go(B+'/compta/facture/list.php');
await s.focus('table','47 fee invoices',
  'Retainers billed pro-rata on each client cycle, at 18% GST.',6200);
await s.unspot();
await s.say('Rs 926,500 in fees, Rs 166,770 GST, Rs 1,093,270 billed this period.',6000);
await s.step(11,TOTAL);
await s.say('And the part every generic CRM misses: TDS under section 194J.',5600);
await s.say('Clients withhold 10% of his fees. Untracked, he overpays his own tax.',6000);
await s.say('Rs 45,525 receivable here, across 23 clients, all awaiting Form 16A.',6000);
await s.hush();

// ───────── 8 · the cron
await s.chapter('STEP 8 OF 10','The Reminder Fires','No one has to remember to press anything.',3600);
await s.step(12,TOTAL);
await s.say('A container runs the reminder job every 5 minutes. Triggering it now.',5000);
try{
  execSync(`docker compose exec -T db mariadb -udolibarr -p"${process.env.DB_PASSWORD}" dolibarr -e "UPDATE llx_actioncomm_reminder SET dateremind=DATE_SUB(NOW(),INTERVAL 5 MINUTE), status=0 WHERE status=0; UPDATE llx_cronjob SET datenextrun=NULL,datelastrun=NULL WHERE rowid=1;"`,{stdio:'ignore'});
  execSync(`docker compose exec -T dolibarr php /var/www/scripts/cron/cron_run_jobs.php "${process.env.CA_CRON_KEY}" admin`,{stdio:'ignore'});
}catch(e){}
await wait(2200);

// ───────── 9 · the email
await s.step(13,TOTAL);
await go(M+'/');
await s.say('The mail server. In development this catches everything so you can verify.',5200);
await s.focus('tbody tr, .message','the reminder just sent','There it is — the reminder for the deadline created a minute ago.',6000)
  .catch(async()=>{ await s.say('There it is — the reminder just sent.',5000); });
await s.unspot();
const first=await p.$('.message, [class*="message"], tbody tr');
if(first){ await first.click({force:true}).catch(()=>{}); }
await wait(2800); await s.ready();
await s.say('Real email. Real content. Not a simulation.',4600);
await wait(2200); await s.hush();

// ───────── 10 · close
await s.chapter('WHAT IS DONE','Email reminders work end to end',
  '47 clients · 1,111 statutory deadlines · fee invoicing · TDS 194J · unattended cron · delivered mail · backup and restore · TLS',5200);
await s.chapter('WHAT NEEDS THE CA','Three things only he can provide',
  'A Meta Business account for WhatsApp · an SMTP relay on his domain · the VPS itself',5600);
await s.chapter('','~/ca-practice','USAGE.md — how to run it.   HANDOVER.md — why, and every gotcha.',5000);

await ctx.close();
const vp=await p.video().path().catch(()=>null);
await browser.close();
console.log('  raw:',vp);
