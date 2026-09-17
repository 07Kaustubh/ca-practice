/**
 * ACCESSIBILITY AUDIT.
 *
 * Runs axe-core against every screen the CA actually touches. This had never been
 * done: the custom screens were written with placeholder-only inputs, which a
 * screen reader announces as nothing at all, and nobody had checked contrast or
 * table semantics.
 *
 * Objective findings only - axe reports the rule, the WCAG reference, and the
 * exact element. It is not a substitute for judgement, but it is the floor.
 *
 *   node a11y_audit.mjs           audit every screen
 *   node a11y_audit.mjs --json    machine-readable, for a gate
 */
import { chromium } from 'playwright';
import fs from 'fs';

const pw = fs.readFileSync('secrets.env', 'utf8').match(/DOLI_ADMIN_PASSWORD=(.+)/)[1].trim();
const AXE = fs.readFileSync('node_modules/axe-core/axe.min.js', 'utf8');
const B = 'http://127.0.0.1:8080';
const JSONOUT = process.argv.includes('--json');

const SCREENS = [
  ['dashboard',   '/index.php?mainmenu=home'],
  ['filings',     '/custom/ca/filings.php?view=ready'],
  ['client',      '/custom/ca/client.php'],
  ['rates',       '/custom/ca/rates.php'],
  ['adjustments', '/custom/ca/adjustments.php'],
  ['privacy',     '/custom/ca/privacy.php'],
];

// Dolibarr's own chrome carries violations we neither introduced nor can fix
// without forking a GPL dependency. Attribute honestly: report them, but judge
// OUR screens on what OUR code emits.
const OURS = (nodes) => nodes.filter(n => {
  const h = (n.html || '');
  return /name="(ack|filed_on|per_day|flat|cap|basis|hday|label|filing_like|new_due|note|reason|years|socid|rowid|pan|gstin|tan|cin|name|email|phone|fee_annual|turnover_annual|whatsapp_number)"/.test(h)
      || /id="ca-(ready|filed|clients|rates|holidays|extensions|erasures|erasure-log|retention|clients-privacy)"/.test(h);
});

const br = await chromium.launch();
const ctx = await br.newContext({ viewport: { width: 1600, height: 1000 } });
const p = await ctx.newPage();
await p.goto(B + '/', { waitUntil: 'domcontentloaded' });
await p.fill('input[name="username"]', 'admin');
await p.fill('input[name="password"]', pw);
await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}), p.click('input[type="submit"]')]);
await p.waitForTimeout(2000);

const report = [];
for (const [name, url] of SCREENS) {
  await p.goto(B + url, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1400);
  await p.addScriptTag({ content: AXE });
  const res = await p.evaluate(async () => await window.axe.run(document, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
  }));
  const rows = res.violations.map(v => ({
    id: v.id, impact: v.impact, help: v.help, wcag: (v.tags.find(t => /^wcag\d/.test(t)) || ''),
    total: v.nodes.length, ours: OURS(v.nodes).length,
    sample: (OURS(v.nodes)[0] || v.nodes[0] || {}).html?.slice(0, 110) || '',
  }));
  report.push({ screen: name, violations: rows });
}
await br.close();

if (JSONOUT) { console.log(JSON.stringify(report, null, 1)); process.exit(0); }

let oursTotal = 0, allTotal = 0;
for (const r of report) {
  const ours = r.violations.filter(v => v.ours > 0);
  const others = r.violations.filter(v => v.ours === 0);
  const o = ours.reduce((a, v) => a + v.ours, 0);
  const t = r.violations.reduce((a, v) => a + v.total, 0);
  oursTotal += o; allTotal += t;
  console.log(`\n${r.screen.toUpperCase()}   ours=${o}  (total on page incl. Dolibarr chrome=${t})`);
  for (const v of ours)
    console.log(`  OURS  [${(v.impact || '?').padEnd(8)}] ${v.id.padEnd(26)} x${String(v.ours).padEnd(3)} ${v.help}\n        ${v.sample}`);
  for (const v of others)
    console.log(`  dolibarr [${(v.impact || '?').padEnd(8)}] ${v.id.padEnd(23)} x${v.total}  ${v.help}`);
}
console.log(`\n  OUR violations: ${oursTotal}   |   whole-page incl. stock Dolibarr: ${allTotal}`);
process.exit(oursTotal > 0 ? 1 : 0);
