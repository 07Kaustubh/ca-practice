<?php
/**
 * RECORD A FILING - command line.
 *
 * The ledger grew arn/filed_at/filed_by columns and nothing in the product ever
 * wrote them, so every return sat at 'ready' for ever and the practice could not
 * answer "what is still outstanding?". This is the write path; the screen at
 * custom/ca/filings.php is the same call with a login in front of it.
 *
 *   php file_return.php <filing-id> <acknowledgement> [YYYY-MM-DD] [--amend] [--user=login|id]
 */
define('NOSESSION', '1');
require_once '/var/www/html/master.inc.php';
global $db;

// installed next to the screen; /tmp covers "copied in to run once"
$lib = null;
foreach (array('/var/www/html/custom/ca/ca_filing_lib.php',
               __DIR__.'/ca_filing_lib.php',
               '/tmp/ca_filing_lib.php') as $p) {
    if (is_readable($p)) { $lib = $p; break; }
}
if ($lib === null) {
    fwrite(STDERR, "  ca_filing_lib.php not found - run deploy.sh to install it\n");
    exit(2);
}
require_once $lib;

$args = array_slice($argv, 1);
$amend = false; $who = getenv('CA_FILED_BY') ?: '';
$pos = array();
foreach ($args as $a) {
    if ($a === '--amend')                       $amend = true;
    elseif (strpos($a, '--user=') === 0)        $who = substr($a, 7);
    else                                        $pos[] = $a;
}
if (count($pos) < 2) {
    fwrite(STDERR, "usage: php file_return.php <filing-id> <acknowledgement> [YYYY-MM-DD] [--amend] [--user=login|id]\n");
    exit(2);
}
list($id, $ack) = array($pos[0], $pos[1]);
$on = isset($pos[2]) ? $pos[2] : '';

// Attribute it to a real person. 'filed_by' is worthless if it is always 1.
if ($who === '') {
    $q = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE statut=1 ORDER BY rowid LIMIT 1");
    $uid = ($q && $o = $db->fetch_object($q)) ? (int) $o->rowid : 0;
} elseif (ctype_digit((string) $who)) {
    $uid = (int) $who;
} else {
    $q = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE login='".$db->escape($who)."' AND statut=1");
    $uid = ($q && $o = $db->fetch_object($q)) ? (int) $o->rowid : 0;
    if (!$uid) { fwrite(STDERR, "  no active user '{$who}'\n"); exit(1); }
}

$res = ca_record_filing($db, $id, $ack, $on, $uid, $amend);
if ($res['ok']) { echo "  ".$res['msg']."\n"; exit(0); }
fwrite(STDERR, "  REFUSED: ".$res['msg']."\n");
exit(1);
