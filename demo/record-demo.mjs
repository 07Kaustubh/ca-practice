import { chromium } from 'playwright';
import { execSync } from 'child_process';
import fs from 'fs';
import { makeStage } from './lib.mjs';
const pw = fs.readFileSync(new URL('../secrets.env',import.meta.url)).toString()
             .match(/DOLI_ADMIN_PASSWORD=(.+)/)[1].trim();
const B='http://127.0.0.1:8080', M='http://127.0.0.1:8025', TOTAL=12;
const browser=await chromium.launch();
const ctx=await browser.newContext({viewport:{width:1440,height:960},
  recordVideo:{dir:'demo/take/',size:{width:1440,height:960}}});
const p=await ctx.newPage(); const s=makeStage(p);
const wait=ms=>p.waitForTimeout(ms);
async function go(u){ await p.goto(u,{waitUntil:'domcontentloaded'}); await wait(1500); await s.ready(); }
async function pre(text,title){
  // The chapter card fades over 0.5s and its pink eyebrow ("STEP n OF 9") was
  // bleeding through as a second, larger badge overlapping the data. Kill the
  // card and the caption before replacing the document.
  await p.evaluate(()=>{ const c=document.getElementById('__card');
    if(c){ c.style.transition='none'; c.style.opacity='0'; }
    const cap=document.getElementById('__cap'); if(cap) cap.style.opacity='0';
  }).catch(()=>{});
  await p.waitForTimeout(700);                 // full-screen monospace artefact
  await p.setContent(`<body style="margin:0;background:#0b1020;color:#d7dee8;
    font:17px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;padding:38px 60px">
    <div style="color:#f43f5e;font:800 17px system-ui;letter-spacing:3.5px;margin-bottom:20px">${title}</div>
    <pre style="white-space:pre;margin:0;font-variant-ligatures:none">${text.replace(/[<&]/g,c=>({'<':'&lt;','&':'&amp;'}[c]))}</pre></body>`);
  await s.ready();
  // These pages are pure text artefacts - there is nothing to point at, and the
  // re-injected pointer parked itself mid-paragraph, sitting on top of a word.
  await p.evaluate(()=>{ for(const id of ['__cur','__ripple']){
    const e=document.getElementById(id); if(e) e.style.display='none'; } }).catch(()=>{});
}

await go(B+'/');
await s.chapter('ONE CLIENT. ONE SLIP.','Rs 10,500',
  'GSTR-3B thirty days late is Rs 1,500. The TDS return is Rs 6,000. AOC-4 is Rs 100 a day with no cap.',6000);
await s.chapter('AND HE HAS FORTY-SEVEN OF THEM','1,261 dates a year',
  'He already knows the dates. What loses him a filing is the client who has not sent the documents.',5600);

await s.step(1,TOTAL);
await s.say('Log in.',2600);
await p.fill('input[name="username"]','admin'); await p.fill('input[name="password"]',pw);
await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}),p.click('input[type="submit"]')]);
await wait(2500); await s.ready(); await s.hush();

// 2 - the dashboard he lands on
await s.step(2,TOTAL);
await s.chapter('STEP 2 OF 12','The Screen He Opens Every Morning',
  'Not a report he has to run. The first thing on the screen when he logs in.',4400);
await go(B+'/index.php?mainmenu=home');
await s.focus('.box-flex-container, .fichecenter','what is coming up',
  'Deadlines due, fees unpaid, and the bank - without opening anything.',6000);
await s.unspot();
await s.focus('a:has-text("Compliance")','one tab',
  'Everything a filing needs sits behind one tab. No URLs, no training.',5200);
await s.unspot();
await s.say('Zero late. The returns filed before this system existed are not counted against him.',6000);
await s.hush();

// 2 — the morning email IS the product
await s.step(3,TOTAL);
await pre(fs.readFileSync('demo/morning-email.txt','utf8').slice(0,1600),'08:00 — DAILY DIGEST');
await s.chapter('STEP 3 OF 12','His Morning, In 30 Seconds','One email. Not a dashboard with a thousand rows.',4200);
await s.ready();
await s.say('This week, ranked by urgency. Two exclamation marks means two days left.',6000);
await s.say('"Not yet chased" is the column that matters — nobody has chased that client yet.',6200);
await s.say('And a data-quality block: a stale profile makes the calendar confidently wrong.',6400);
await s.hush();

// 3 — the register
await s.step(4,TOTAL);
await go(B+'/societe/list.php');
await s.focus('table','47 clients','Every client, with the profile that drives everything else.',5600);
await s.unspot(); await s.hush();

// 4 — profile drives the calendar
// 4 - he takes on a client himself, and the calendar appears
await s.step(5,TOTAL);
await s.chapter('STEP 5 OF 12','Taking On A Client',
  'No spreadsheet to send anyone. He types the profile and the statutory calendar derives itself.',4600);
await go(B+'/custom/ca/client.php');
await p.fill('input[name="name"]','Rameshwar Agro Foods Pvt Ltd');
await p.fill('input[name="pan"]','AAGCR4521M');
await p.fill('input[name="gstin"]','27AAGCR4521M1ZP');
await p.fill('input[name="tan"]','MUMR21458C');
await p.selectOption('select[name="entity_type"]','Private Limited').catch(()=>{});
await p.selectOption('select[name="gst_scheme"]','Monthly').catch(()=>{});
await p.selectOption('select[name="roc_applicable"]','1').catch(()=>{});
await s.say('GST scheme, TAN, entity type. That is the whole input.',5200);
await Promise.all([p.waitForNavigation({timeout:30000}).catch(()=>{}),
                   p.click('input[type="submit"][value*="Save"], input.button[type="submit"]').catch(()=>{})]);
await wait(2500); await s.ready();
await s.say('Every statutory deadline that profile implies - derived, dated, and on his calendar.',6400);
await s.hush();

await s.step(6,TOTAL);
await s.chapter('STEP 6 OF 12','The Profile Is The Engine','GST scheme, TAN, entity type, audit applicability.',4200);
await go(B+'/societe/card.php?socid=1');
// The left panel is Dolibarr's generic Prof-ID block and is blank for a CA.
// The CA fields live in the extrafields table on the right - point there.
await s.focus('td:has-text("GST filing scheme"), tr:has-text("GST filing scheme")','GST scheme · TAN · entity type',
  'A GST-monthly client with a TAN owes close to fifty filings a year. Nobody types those.',6400);
await s.unspot(); await s.hush();

// 5 — the calendar, shown on the client record where it reads clearly
await s.step(7,TOTAL);
await go(B+'/societe/agenda.php?socid=1');
await s.focus('table','this client alone',
  'Every statutory filing for one client, derived from that profile.',6200);
await s.unspot();
await s.say('Across 47 clients that is about 1,200 filings a year. Nobody types those.',5800);
await s.hush();

// 6 — documents: the actual job
await s.step(8,TOTAL);
await s.chapter('STEP 8 OF 12','What He Is Actually Waiting For',
  'The deadline is not the problem. The missing purchase register is.',5000);
const docs=execSync(`docker compose exec -T -e MYSQL_PWD="${process.env.DB_PASSWORD}" db mariadb -udolibarr dolibarr -e "SELECT s.nom AS client, d.filing, d.doc_type, d.due FROM ca_docrequest d JOIN llx_societe s ON s.rowid=d.fk_soc WHERE d.status='pending' ORDER BY d.due LIMIT 14;" 2>/dev/null`,{encoding:'utf8'});
await pre(docs,'OPEN DOCUMENT REQUESTS');
await s.say('Per client, per filing, exactly which document is outstanding.',6000);
await s.hush();

// 7 — the chase
await s.step(9,TOTAL);
await s.chapter('STEP 9 OF 12','Chasing The Client, Not The CA',
  'WhatsApp is the one channel Indian clients actually read.',4600);
await pre(fs.readFileSync('demo/chase-preview.txt','utf8'),'WHATSAPP CHASE — DRY RUN');
await s.say('Escalating tone: polite at ten days, firm at five, urgent at two.',6000);
await s.say('One message per client per day, however many documents are outstanding.',5800);
await s.say('No opt-in recorded, no message. Meta requires it and the code enforces it.',6200);
await s.hush();

// 8 - the loop closes: proof the return actually went OUT
await s.step(10,TOTAL);
await s.chapter('STEP 10 OF 12','Proof It Went Out',
  'Documents coming in is half a system. The acknowledgement number is the other half.',5000);
await go(B+'/custom/ca/filings.php?view=ready');
await s.focus('#ca-ready','ready to file',
  'Documents all back. These are waiting on him now, not on the client.',6200);
await s.unspot();
await s.say('He files on the portal, then records the acknowledgement here.',5400);
await s.say('A number in the wrong shape for that return is refused - an ack nobody can check is not proof.',6600);
// Both tables on one page leave the filed list permanently under the fold, and
// the page is too short to scroll it up. The screen's own Filed view is where a
// CA would look anyway.
await go(B+'/custom/ca/filings.php?view=filed');
await s.focus('#ca-filed','filed, with the ARN',
  'What went out, when, by whom, and under which acknowledgement.',6400);
await s.unspot();
await s.say('That is the answer to "did we file it?" - and to a notice, two years later.',6000);
await s.hush();

// 8 — his own money
await s.step(11,TOTAL);
await go(B+'/compta/facture/list.php');
await s.focus('table','his own fees','Retainers billed pro-rata at 18% GST.',5600);
await s.unspot();
await s.say('And TDS under section 194J — clients withhold 10% of his fees.',6000);
await s.say('Untracked, he overpays his own tax. No generic CRM models this.',5800);
// the other half of "basic funds management" - his own bank, not the clients'
await go(B+'/compta/bank/list.php');
await s.focus('table','his own bank',
  'His practice account, reconciliation and books - the money side, in the same place.',6000);
await s.unspot(); await s.hush();
await s.hush();

// 9 — proof
await s.step(12,TOTAL);
await go(M+'/');
await s.say('Every reminder and digest is delivered, not simulated.',5200);
await s.hush();
await s.chapter('WHAT IT DOES','Chases clients for documents',
  'Client register · derived statutory calendar · document collection · WhatsApp chase · filing record with acknowledgement · fee invoicing with TDS 194J · run entirely from the browser',5600);
await s.chapter('WHAT HAPPENS NEXT','One evening to set up',
  'Your client list goes in once. The calendar derives itself. Reminders start that night.',5600);
await s.chapter('WHAT IT DOES NOT','File returns, or keep your clients books',
  'The GST portal files. Tally keeps their ledgers. This makes sure you are never waiting on a document at 5pm on the 19th.',6000);
await ctx.close(); await browser.close();
