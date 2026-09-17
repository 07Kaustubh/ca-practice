/**
 * SIMULATE A CA USING THE SYSTEM.
 *
 * My own verdict said "no real CA has ever used it" and that every credibility
 * judgement was inference. This is the closest honest substitute: drive the real
 * screens through the tasks a practice actually performs, and require each one to
 * change the DATABASE - a 200 response proves nothing, a state change proves the
 * feature works.
 *
 *   node ca_walkthrough.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'child_process';
import fs from 'fs';

const pw = fs.readFileSync('secrets.env').toString().match(/DOLI_ADMIN_PASSWORD=(.+)/)[1].trim();
const dbpw = fs.readFileSync('secrets.env').toString().match(/DB_PASSWORD=(.+)/)[1].trim();
const B = 'http://127.0.0.1:8080';
const SHOTS = 'demo/walkthrough';
fs.mkdirSync(SHOTS, { recursive: true });

const q = (sql) => execSync(
  `docker compose exec -T -e MYSQL_PWD=${JSON.stringify(dbpw)} db mariadb -udolibarr dolibarr -sN -e ${JSON.stringify(sql)} 2>/dev/null`,
  { encoding: 'utf8' }).split('\n').filter(l => l && !/insecure|warning/i.test(l)).join('\n').trim();

let pass = 0, fail = 0;
const results = [];
function check(task, ok, detail) {
  if (ok) { pass++; console.log(`  PASS  ${task}`); }
  else    { fail++; console.log(`  FAIL  ${task}  -- ${detail}`); }
  results.push({ task, ok, detail });
}

const br = await chromium.launch();
const ctx = await br.newContext({ viewport: { width: 1600, height: 1000 } });
const p = await ctx.newPage();
const shot = (n) => p.screenshot({ path: `${SHOTS}/${n}.png`, fullPage: false });

// ── T1: he logs in ───────────────────────────────────────────────────────────
await p.goto(B + '/', { waitUntil: 'domcontentloaded' });
await p.fill('input[name="username"]', 'admin');
await p.fill('input[name="password"]', pw);
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}), p.click('input[type="submit"]')]);
await p.waitForTimeout(1500);
const loggedIn = !(await p.locator('input[name="password"]').count());
check('T1 log in', loggedIn, 'still on the login form');
await shot('t1-logged-in');

// ── T2: what is waiting on him this morning ──────────────────────────────────
await p.goto(B + '/custom/ca/filings.php?view=ready', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1200);
const readyRows = await p.locator('#ca-ready tr.oddeven').count();
const readyDb = parseInt(q("SELECT COUNT(*) FROM ca_filing WHERE status='ready';") || '0', 10);
check('T2 ready-to-file list matches the ledger',
      readyRows === readyDb && readyDb > 0, `screen ${readyRows} vs db ${readyDb}`);
await shot('t2-ready-to-file');

// ── T3: he files one on the portal and records the acknowledgement ───────────
const fid = q("SELECT rowid FROM ca_filing WHERE status='ready' AND filing LIKE 'GSTR-%' LIMIT 1;");
if (!fid) { check('T3 record a filing', false, 'no ready GSTR filing to record'); }
else {
  await p.goto(B + '/custom/ca/filings.php?view=ready', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(800);
  const row = p.locator(`#ca-ready form:has(input[name="filing_id"][value="${fid}"])`);
  await row.locator('input[name="ack"]').fill('AA070926314159X');
  await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}),
                     row.locator('input[type="submit"]').click()]);
  await p.waitForTimeout(1200);
  const st = q(`SELECT status FROM ca_filing WHERE rowid=${fid};`);
  const ar = q(`SELECT COALESCE(arn,'') FROM ca_filing WHERE rowid=${fid};`);
  check('T3 record a filing with its acknowledgement',
        st === 'filed' && ar === 'AA070926314159X', `status=${st} arn=${ar}`);
  await shot('t3-filed');
}

// ── T4: he takes on a new client ─────────────────────────────────────────────
q("DELETE FROM llx_societe WHERE nom='Walkthrough Foods Pvt Ltd';");
await p.goto(B + '/custom/ca/client.php', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(800);
await p.fill('input[name="name"]', 'Walkthrough Foods Pvt Ltd');
await p.fill('input[name="pan"]', 'AAGCW1234F');
await p.fill('input[name="gstin"]', '27AAGCW1234F1Z5');
await p.fill('input[name="tan"]', 'MUMW98765D');
await p.selectOption('select[name="entity_type"]', 'Private Limited').catch(() => {});
await p.selectOption('select[name="gst_scheme"]', 'Monthly').catch(() => {});
await p.selectOption('select[name="roc_applicable"]', '1').catch(() => {});
await Promise.all([p.waitForNavigation({ timeout: 40000 }).catch(() => {}),
                   p.locator('input[type="submit"]').first().click()]);
await p.waitForTimeout(2500);
const nsid = q("SELECT rowid FROM llx_societe WHERE nom='Walkthrough Foods Pvt Ltd';");
const ndl = parseInt(q(`SELECT COUNT(*) FROM llx_actioncomm WHERE fk_soc=${nsid || 0};`) || '0', 10);
check('T4 new client gets a statutory calendar on save', !!nsid && ndl >= 30, `socid=${nsid} deadlines=${ndl}`);
await shot('t4-new-client');

// ── T5: a malformed PAN must be refused ──────────────────────────────────────
await p.goto(B + '/custom/ca/client.php', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(600);
await p.fill('input[name="name"]', 'Walkthrough Bad PAN Ltd');
await p.fill('input[name="pan"]', 'NOTAPAN999');
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}),
                   p.locator('input[type="submit"]').first().click()]);
await p.waitForTimeout(1200);
const badCount = parseInt(q("SELECT COUNT(*) FROM llx_societe WHERE nom='Walkthrough Bad PAN Ltd';") || '0', 10);
check('T5 malformed PAN refused, nothing written', badCount === 0, `${badCount} rows created`);
await shot('t5-bad-pan-refused');

// ── T6: the government extends a deadline ────────────────────────────────────
const pre = q("SELECT SUBSTRING_INDEX(label,' - ',1) FROM llx_actioncomm WHERE label LIKE 'GSTR-3B %' ORDER BY datep LIMIT 1;");
const wasOn = q(`SELECT COUNT(*) FROM llx_actioncomm WHERE label LIKE '${pre}%';`);
await p.goto(B + '/custom/ca/adjustments.php', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(800);
await p.fill('input[name="filing_like"]', pre);
await p.fill('input[name="new_due"]', '2027-03-31');
await p.fill('input[name="note"]', 'walkthrough extension').catch(() => {});
const extForm = p.locator('form:has(input[name="filing_like"])');
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}),
                   extForm.locator('input[type="submit"]').click()]);
await p.waitForTimeout(1200);
execSync('./ca_job.sh calendar >/dev/null 2>&1');
const moved = parseInt(q(`SELECT COUNT(*) FROM llx_actioncomm WHERE label LIKE '${pre}%' AND DATE(datep)='2027-03-31';`) || '0', 10);
check(`T6 extension moves every client (${pre})`, moved > 0 && String(moved) === wasOn, `moved ${moved} of ${wasOn}`);
await shot('t6-extension');

// ── T7: a client asks to be erased ───────────────────────────────────────────
await p.goto(B + '/custom/ca/privacy.php', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(900);
const reqForm = p.locator('#ca-clients-privacy form:has(input[name="reason"])').first();
const reqSid = await reqForm.locator('input[name="socid"]').getAttribute('value');
await reqForm.locator('input[name="reason"]').fill('walkthrough - consent withdrawn');
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}),
                   reqForm.locator('input[type="submit"]').click()]);
await p.waitForTimeout(1200);
const erStatus = q(`SELECT status FROM ca_erasure_request WHERE fk_soc=${reqSid};`);
const erDue = q(`SELECT due_at FROM ca_erasure_request WHERE fk_soc=${reqSid};`);
const heldFuture = erStatus === 'held' && erDue > new Date().toISOString().slice(0, 10);
check('T7 erasure recorded and HELD for the statutory period', heldFuture, `status=${erStatus} due=${erDue}`);
await shot('t7-erasure-held');

// ── T8: a statutory rate changes ─────────────────────────────────────────────
await p.goto(B + '/custom/ca/rates.php', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(800);
const rateRow = p.locator('#ca-rates form:has(input[name="per_day"])').first();
const rid = await rateRow.locator('input[name="rowid"]').getAttribute('value');
const wasRate = q(`SELECT per_day FROM ca_rate WHERE rowid=${rid};`);
await rateRow.locator('input[name="per_day"]').fill('123');
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}),
                   rateRow.locator('input[type="submit"]').click()]);
await p.waitForTimeout(1000);
const nowRate = q(`SELECT ROUND(per_day) FROM ca_rate WHERE rowid=${rid};`);
check('T8 a statutory rate is editable without a deploy', nowRate === '123', `was ${wasRate} now ${nowRate}`);
q(`UPDATE ca_rate SET per_day=${parseFloat(wasRate) || 50} WHERE rowid=${rid};`);
await shot('t8-rate-edited');

console.log(`\n  ${pass + fail} tasks, ${pass} pass, ${fail} FAIL`);
console.log(`  screenshots: ${SHOTS}/`);
await br.close();
process.exit(fail ? 1 : 0);
