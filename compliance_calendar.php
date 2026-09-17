<?php
/**
 * Generates each client's STATUTORY deadlines from their profile.
 * A CA does not type 1,200 reminders a year - the dates are fixed by law and
 * derivable: GST scheme sets GSTR cadence, a TAN means TDS, entity type means
 * ROC, audit applicability moves the ITR date.
 *
 * The rules themselves now live in ca_calendar_lib.php, shared with the client
 * screen, so a client added through the web UI gets a calendar too. This file is
 * just the batch entry point.
 *
 * Idempotent. Safe to run nightly from cron - and it now is.
 *
 * Usage: php compliance_calendar.php [FY_START_YEAR] [--client=ID]
 */
define('NOSESSION', '1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db, $conf;

$lib = null;
foreach (array('/var/www/html/custom/ca/ca_calendar_lib.php', '/tmp/ca_calendar_lib.php') as $p)
    if (is_readable($p)) { $lib = $p; break; }
if ($lib === null) { fwrite(STDERR, "  ca_calendar_lib.php not found - run deploy.sh to install it\n"); exit(2); }
require_once $lib;

$user = new User($db); $user->fetch(1); $user->getrights();
if (empty($user->email)) { fwrite(STDERR, "FATAL: admin has no email - every reminder would die at status=-1\n"); exit(1); }

$fy = 0; $only = 0;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--client=') === 0) $only = (int) substr($a, 9);
    elseif ((int) $a)                  $fy = (int) $a;
}
if (!$fy) $fy = ((int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1);
$REMIND = (int) (getenv('CA_REMIND_DAYS') ?: 7);

ca_cal_ensure_tables($db);
echo "  FY {$fy}-".substr((string) ($fy + 1), 2)."   reminder lead {$REMIND} days\n";

$t = array('made' => 0, 'skipped' => 0, 'reminders' => 0, 'stale' => 0, 'shifted' => 0, 'extended' => 0, 'moved' => 0, 'retired' => 0);
$clients = ca_client_rows($db, $only);
foreach ($clients as $c) {
    $r = ca_generate_for_client($db, $user, $c, $fy, $REMIND);
    foreach ($t as $k => $v) $t[$k] += $r[$k];
}
printf("  clients %d   deadlines %d   reminders %d   skipped-stale %d   already present %d\n",
    count($clients), $t['made'], $t['reminders'], $t['stale'], $t['skipped']);
if ($t['shifted'] || $t['extended'])
    printf("  falling on a Sunday/holiday %d (FLAGGED, not moved)   moved by a government extension %d\n", $t['shifted'], $t['extended']);
// Existing rows that the shift or an extension has since moved. This is the line
// that proves an extension announced this morning reached the whole practice.
if ($t['moved'])
    printf("  EXISTING deadlines re-dated %d\n", $t['moved']);
// A profile change (Monthly -> QRMP, TAN removed) leaves deadlines the client no
// longer owes. Retiring them is the only destructive thing this job does.
if ($t['retired'])
    printf("  superseded deadlines retired %d\n", $t['retired']);
