<?php
/**
 * STATUTORY RULE AUDIT.
 *
 * The regression suite proves the calendar is SELF-CONSISTENT - that a label
 * saying "TDS payment Mar 2027" carries the date the code intends. It cannot
 * prove the code intends the right date, because the gate encodes the same
 * assumption as the rule it tests.
 *
 * This file is the other half: each assertion is a claim about the STATUTE, with
 * the provision named. If a rule here is wrong, a client gets a penalty notice,
 * so every expectation carries its source and a wrong one must be argued against
 * the citation, not against the code.
 *
 * ca_rules_for() is pure, so this asserts the RAW statutory date. Weekend and
 * holiday shifting happens later in ca_generate_for_client() and is covered by
 * its own gate.
 *
 *   php statute_test.php            assert everything
 *   php statute_test.php --list     print every rule a profile generates
 */
define('NOSESSION', '1');
require_once '/var/www/html/master.inc.php';

$lib = null;
foreach (array('/var/www/html/custom/ca/ca_calendar_lib.php', '/tmp/ca_calendar_lib.php') as $p)
    if (is_readable($p)) { $lib = $p; break; }
if ($lib === null) { fwrite(STDERR, "  ca_calendar_lib.php not found\n"); exit(2); }
require_once $lib;

$FY = 2026;   // FY 2026-27

/** Client profiles the rules branch on. */
function profile($over = array())
{
    return array_merge(array(
        'rowid' => 1, 'nom' => 'T', 'gst_scheme' => '', 'tan' => '', 'gstin' => '',
        'entity_type' => 'Individual', 'tax_audit' => 0, 'roc_applicable' => 0, 'turnover_annual' => 0,
    ), $over);
}

$pass = 0; $fail = 0; $failed = array();

/** Assert that a rule whose label starts with $prefix falls due on $ymd. */
function expect($rules, $prefix, $ymd, $source)
{
    global $pass, $fail, $failed;
    $hit = null;
    foreach ($rules as $r) {
        if (strpos($r[0], $prefix) === 0) { $hit = $r; break; }
    }
    if ($hit === null) {
        $fail++; $failed[] = "MISSING  {$prefix}  (expected {$ymd})  [{$source}]";
        printf("  FAIL  %-34s expected %s but the rule is NOT GENERATED\n", $prefix, $ymd);
        return;
    }
    $got = date('Y-m-d', $hit[1]);
    if ($got === $ymd) { $pass++; printf("  ok    %-34s %s\n", $prefix, $got); }
    else {
        $fail++; $failed[] = "WRONG    {$prefix}  expected {$ymd} got {$got}  [{$source}]";
        printf("  FAIL  %-34s expected %s  GOT %s   <- %s\n", $prefix, $ymd, $got, $source);
    }
}

/** Assert that a profile does NOT generate a rule (threshold / applicability). */
function expect_absent($rules, $prefix, $why)
{
    global $pass, $fail, $failed;
    foreach ($rules as $r) {
        if (strpos($r[0], $prefix) === 0) {
            $fail++; $failed[] = "EXTRA    {$prefix} generated but should not be  [{$why}]";
            printf("  FAIL  %-34s generated, but %s\n", $prefix, $why);
            return;
        }
    }
    $pass++; printf("  ok    %-34s correctly absent\n", $prefix);
}

// ── GST: monthly ─────────────────────────────────────────────────────────────
echo "\nGST - monthly filer\n";
$m = ca_rules_for(profile(array('gst_scheme' => 'Monthly')), $FY);
expect($m, 'GSTR-1 Apr 2026',  '2026-05-11', 'CGST Rule 59(1) - 11th of the following month');
expect($m, 'GSTR-3B Apr 2026', '2026-05-20', 'CGST Rule 61(1) - 20th of the following month');
expect($m, 'GSTR-1 Mar 2027',  '2027-04-11', 'same rule across the FY boundary');
// GSTR-9 is exempt up to Rs 2 crore, and the exemption is re-notified annually.
expect_absent($m, 'GSTR-9 annual', 'turnover is unknown/below Rs 2 crore - the annual return is exempt');
$big = ca_rules_for(profile(array('gst_scheme' => 'Monthly', 'turnover_annual' => 50000000)), $FY);
expect($big, 'GSTR-9 annual',    '2027-12-31', 'CGST s.44 - above Rs 2 crore, 31 December following the FY');

// ── GST: QRMP ────────────────────────────────────────────────────────────────
echo "\nGST - QRMP filer\n";
$q = ca_rules_for(profile(array('gst_scheme' => 'QRMP (quarterly)')), $FY);
expect($q, 'GSTR-1 (QRMP) Apr-Jun',  '2026-07-13', 'CGST Rule 59(2) - 13th of the month following the quarter');
expect($q, 'GSTR-3B (QRMP) Apr-Jun', '2026-07-22', 'Rule 61(1)(ii) Category X (GSTIN 27 = Maharashtra) - 22nd');
$qy = ca_rules_for(profile(array('gst_scheme' => 'QRMP (quarterly)', 'gstin' => '07AAACT2727Q1ZW')), $FY);
expect($qy, 'GSTR-3B (QRMP) Apr-Jun', '2026-07-24', 'Rule 61(1)(ii) Category Y (GSTIN 07 = Delhi) - 24th');
// QRMP clients owe monthly tax in months 1 and 2 of each quarter. Not modelled
// at all until now, so a QRMP client silently accrued s.50 interest.
expect($q, 'PMT-06 Apr 2026', '2026-05-25', 'QRMP monthly payment - 25th of the following month');
expect($q, 'PMT-06 May 2026', '2026-06-25', 'QRMP monthly payment - month 2 of the quarter');
expect_absent($q, 'PMT-06 Jun 2026', 'month 3 is settled by the quarterly GSTR-3B, not PMT-06');

// ── GST: composition ─────────────────────────────────────────────────────────
echo "\nGST - composition dealer\n";
$c = ca_rules_for(profile(array('gst_scheme' => 'Composition')), $FY);
expect($c, 'CMP-08 Apr-Jun',   '2026-07-18', 'CGST Rule 62(1)(i) - 18th of the month following the quarter');
expect($c, 'GSTR-4 annual',    '2027-06-30', 'CGST Rule 62(1)(ii) as amended by Notfn 38/2023-CT - 30 June, FY2024-25 onward');

// ── TDS ──────────────────────────────────────────────────────────────────────
echo "\nTDS - deductor with a TAN\n";
$t = ca_rules_for(profile(array('tan' => 'MUMA12345B')), $FY);
expect($t, 'TDS payment Apr 2026', '2026-05-07', 'IT Rule 30(2) - 7th of the following month');
expect($t, 'TDS payment Mar 2027', '2027-04-30', 'IT Rule 30(2) proviso - March deducted, deposit by 30 April');
expect($t, 'TDS return 26Q/24Q Q1', '2026-07-31', 'IT Rule 31A(2) - Q1 by 31 July');
expect($t, 'TDS return 26Q/24Q Q2', '2026-10-31', 'IT Rule 31A(2) - Q2 by 31 October');
expect($t, 'TDS return 26Q/24Q Q3', '2027-01-31', 'IT Rule 31A(2) - Q3 by 31 January');
expect($t, 'TDS return 26Q/24Q Q4', '2027-05-31', 'IT Rule 31A(2) - Q4 by 31 May');

// ── income tax ───────────────────────────────────────────────────────────────
echo "\nIncome tax - individual, no audit\n";
$i = ca_rules_for(profile(), $FY);
expect($i, 'ITR filing FY',      '2027-07-31', 'FA2026 Expl.2 - no business indicia (no GST, no TAN) stays 31 July');
// Finance Act 2026 substituted Explanation 2 w.e.f. 01.03.2026 and moved
// non-audit BUSINESS/PROFESSION assessees from 31 July to 31 August.
$b = ca_rules_for(profile(array('gst_scheme' => 'Monthly', 'gstin' => '27AAACT2727Q1ZW')), $FY);
expect($b, 'ITR filing FY',      '2027-08-31', 'FA2026 Expl.2 - non-audit business/profession assessee, 31 August');
expect_absent($i, 'Advance tax', 'an individual with no business income and no audit is not given instalments here');

echo "\nIncome tax - audit case\n";
$a = ca_rules_for(profile(array('tax_audit' => 1)), $FY);
expect($a, 'Tax audit 3CA/3CD',   '2027-09-30', 'IT s.44AB - audit report one month before the ITR date');
expect($a, 'ITR filing (audit)',  '2027-10-31', 'IT s.139(1) Explanation 2(a) - audit case, 31 October');
expect($a, 'Advance tax 15%',     '2026-06-15', 'IT s.211 - 15% by 15 June');
expect($a, 'Advance tax 45%',     '2026-09-15', 'IT s.211 - 45% cumulative by 15 September');
expect($a, 'Advance tax 75%',     '2026-12-15', 'IT s.211 - 75% cumulative by 15 December');
expect($a, 'Advance tax 100%',    '2027-03-15', 'IT s.211 - 100% cumulative by 15 March');

echo "\nIncome tax - PRIVATE LIMITED with the audit flag OFF\n";
$co = ca_rules_for(profile(array('entity_type' => 'Private Limited', 'roc_applicable' => 1)), $FY);
expect($co, 'ITR filing (audit)', '2027-10-31', 'ITA2025 s.263(1)(c) Row 2 / 1961 s.139(1) Expl.2 Row 2(i) - a company is 31 Oct, always');
// A company is audited under the Companies Act whatever the 44AB flag says, so
// the audit report is a real obligation we were simply not emitting.
expect($co, 'Tax audit 3CA/3CD', '2027-09-30', 'ITA2025 s.63 - specified date is one month before the return due date');

// ── MCA / ROC ────────────────────────────────────────────────────────────────
echo "\nMCA - company\n";
expect($co, 'AOC-4 FY',     '2027-10-30', 'SUSPECT: s.137 is 30 days FROM THE AGM, not a fixed date');
expect($co, 'MGT-7 FY',     '2027-11-29', 'SUSPECT: s.92 is 60 days FROM THE AGM, not a fixed date');
expect($co, 'DIR-3 KYC FY', '2027-09-30', 'Companies (Appointment and Qualification of Directors) Rules - 30 September');

echo "\nMCA - LLP\n";
$l = ca_rules_for(profile(array('entity_type' => 'LLP', 'roc_applicable' => 1)), $FY);
expect($l, 'LLP Form 11 FY', '2027-05-30', 'LLP Rules r.25(1) - within 60 days of FY end');
expect($l, 'LLP Form 8 FY',  '2027-10-30', 'LLP Rules r.24(4) - within 30 days of 6 months from FY end');

// ── summary ──────────────────────────────────────────────────────────────────
printf("\n  %d asserted, %d pass, %d FAIL\n", $pass + $fail, $pass, $fail);
if ($failed) { echo "\n"; foreach ($failed as $f) echo "  $f\n"; }
exit($fail ? 1 : 0);
