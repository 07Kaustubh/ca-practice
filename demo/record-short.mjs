/**
 * THE 90-SECOND CUT.
 *
 * The full walkthrough is 274 seconds. Nobody watches 274 seconds of software
 * they did not ask for. This is the version that gets forwarded on WhatsApp: the
 * cost of the problem, the four things that solve it, and what happens next.
 *
 * Every number on screen is read out of the running system, not written into the
 * script. If the data changes, the claims change with it.
 */
import { chromium } from 'playwright';
import { execSync } from 'child_process';
import fs from 'fs';
import { makeStage } from './lib.mjs';

const pw = fs.readFileSync(new URL('../secrets.env', import.meta.url)).toString()
             .match(/DOLI_ADMIN_PASSWORD=(.+)/)[1].trim();
const dbpw = fs.readFileSync(new URL('../secrets.env', import.meta.url)).toString()
             .match(/DB_PASSWORD=(.+)/)[1].trim();
const B = 'http://127.0.0.1:8080';

const q = (sql) => execSync(
  `docker compose exec -T -e MYSQL_PWD=${JSON.stringify(dbpw)} db mariadb -udolibarr dolibarr -sN -e ${JSON.stringify(sql)} 2>/dev/null`,
  { encoding: 'utf8' }).split('\n').filter(l => l && !/insecure|warning/i.test(l))[0]?.trim() ?? '0';

// read the claims out of the live system so they cannot drift from the truth
const CLIENTS  = q("SELECT COUNT(*) FROM llx_societe WHERE client=1;");
const DATES    = q("SELECT COUNT(*) FROM llx_actioncomm a JOIN llx_c_actioncomm c ON c.id=a.fk_action WHERE c.code='AC_OTH';");
const REMIND   = q("SELECT COUNT(*) FROM llx_actioncomm_reminder;");
const OUTST    = q("SELECT FORMAT(SUM(total_ttc),0) FROM llx_facture WHERE fk_statut<>2;");

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 },
  recordVideo: { dir: 'demo/take-short/', size: { width: 1440, height: 960 } } });
const p = await ctx.newPage();
const s = makeStage(p);
const wait = ms => p.waitForTimeout(ms);
async function go(u) { await p.goto(u, { waitUntil: 'domcontentloaded' }); await wait(1400); await s.ready(); }

await go(B + '/');

// 1 — the cost of the problem, before any software
await s.chapter('ONE CLIENT. ONE SLIP.', 'Rs 10,500',
  'GSTR-3B thirty days late is Rs 1,500. The TDS return is Rs 6,000. AOC-4 is Rs 100 a day, no cap.', 5200);
await s.chapter(`AND THERE ARE ${CLIENTS} OF THEM`, `${DATES} dates a year`,
  'Fixed by law. Derivable from each client profile. Nobody types those.', 4600);

// 2 — log in
await p.fill('input[name="username"]', 'admin');
await p.fill('input[name="password"]', pw);
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}), p.click('input[type="submit"]')]);
await wait(2200); await s.ready();

// 3 — the screen he opens every morning
await s.focus('.box-flex-container, .fichecenter', 'nothing is late',
  'Deadlines, unpaid fees and the bank on one screen. Nothing to run, nothing to learn.', 5600);
await s.unspot();
await s.focus('a:has-text("Compliance")', 'one tab',
  'Everything a filing needs is behind one tab.', 4200);
await s.unspot(); await s.hush();

// 4 — take on a client, the calendar writes itself
await go(B + '/custom/ca/client.php?mainmenu=ca&leftmenu=');
await p.fill('input[name="name"]', 'Rameshwar Agro Foods Pvt Ltd');
await p.fill('input[name="pan"]', 'AAGCR4521M');
await p.fill('input[name="gstin"]', '27AAGCR4521M1ZP');
await p.fill('input[name="tan"]', 'MUMR21458C');
await p.selectOption('select[name="entity_type"]', 'Private Limited').catch(() => {});
await p.selectOption('select[name="gst_scheme"]', 'Monthly').catch(() => {});
await p.selectOption('select[name="roc_applicable"]', '1').catch(() => {});
await s.say('GST scheme, TAN, entity type. That is the whole input.', 4600);
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}),
                   p.locator('input[type="submit"]').first().click().catch(() => {})]);
await wait(2400); await s.ready();
await s.say('Every statutory deadline that profile implies - dated, and on his calendar.', 5200);
await s.hush();

// 5 — the chase goes to the CLIENT
await p.setContent(`<body style="margin:0;background:#0b1020;color:#d7dee8;
  font:17px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;padding:38px 60px">
  <div style="color:#f43f5e;font:800 17px system-ui;letter-spacing:3.5px;margin-bottom:20px">WHATSAPP CHASE - DRY RUN</div>
  <pre style="white-space:pre;margin:0">${fs.readFileSync('demo/chase-preview.txt','utf8').replace(/[<&]/g,c=>({'<':'&lt;','&':'&amp;'}[c]))}</pre></body>`);
await s.ready();
await p.evaluate(() => { for (const id of ['__cur','__ripple']) { const e = document.getElementById(id); if (e) e.style.display='none'; } }).catch(()=>{});
await s.say('The chase goes to the CLIENT, not to him. Escalating tone. One message a day, whatever is outstanding.', 6200);
await s.say(`${REMIND} email reminders are scheduled alongside it.`, 4200);
await s.hush();

// 6 — proof the return went out
await go(B + '/custom/ca/filings.php?view=filed&mainmenu=ca&leftmenu=');
await s.focus('#ca-filed', 'filed, with the ARN',
  'What went out, when, by whom, and under which acknowledgement number.', 5600);
await s.unspot(); await s.hush();

// 7 — his own money
await go(B + '/compta/facture/list.php');
await s.focus('table', `Rs ${OUTST} outstanding`,
  'His own fees, TDS under 194J, and the practice bank - the money side, same place.', 5400);
await s.unspot(); await s.hush();

// 8 — what happens next
await s.chapter('WHAT HAPPENS NEXT', 'One evening to set up',
  'Your client list goes in once. The calendar derives itself. Reminders start that night.', 5200);
await s.chapter('WHAT IT DOES NOT DO', 'File returns for you',
  'The GST portal files. This makes sure you are never the reason a date was missed.', 5000);

await ctx.close(); await browser.close();
