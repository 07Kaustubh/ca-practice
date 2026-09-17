<?php
/**
 * DPDP RETENTION SWEEP.
 *
 * Promotes erasure requests whose statutory hold has expired, and - only when
 * asked with --commit - carries them out. Dry run is the default on purpose:
 * destroying personal data must never be something a cron job does because
 * somebody forgot a flag.
 *
 *   php retention.php [--commit] [--user=ID]
 */
define('NOSESSION', '1');
require_once '/var/www/html/master.inc.php';
global $db;

$lib = null;
foreach (array('/var/www/html/custom/ca/ca_privacy_lib.php', '/tmp/ca_privacy_lib.php') as $p)
    if (is_readable($p)) { $lib = $p; break; }
if ($lib === null) { fwrite(STDERR, "  ca_privacy_lib.php not found - run deploy.sh to install it\n"); exit(2); }
require_once $lib;

$commit = false; $who = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--commit')               $commit = true;
    elseif (strpos($a, '--user=') === 0) $who = substr($a, 7);
}
if ($who === '') {
    $q = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE statut=1 ORDER BY rowid LIMIT 1");
    $uid = ($q && $o = $db->fetch_object($q)) ? (int) $o->rowid : 0;
} else {
    $uid = ctype_digit((string) $who) ? (int) $who : 0;
    if (!$uid) {
        $q = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE login='".$db->escape($who)."' AND statut=1");
        $uid = ($q && $o = $db->fetch_object($q)) ? (int) $o->rowid : 0;
    }
    if (!$uid) { fwrite(STDERR, "  no active user '{$who}'\n"); exit(1); }
}

ca_privacy_ensure_tables($db);
$years = ca_retention_years($db);

// Anything past its hold becomes 'due' whether or not we are committing, so the
// screen and the digest can show it.
$db->query("UPDATE ca_erasure_request SET status='due'
             WHERE status='held' AND due_at IS NOT NULL AND due_at <= CURDATE()");

$n = function ($sql) use ($db) { $r = $db->query($sql); return ($r && $o = $db->fetch_object($r)) ? (int) $o->c : 0; };
$clients = $n("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX."societe WHERE client=1");
$held    = $n("SELECT COUNT(*) c FROM ca_erasure_request WHERE status='held'");
$due     = $n("SELECT COUNT(*) c FROM ca_erasure_request WHERE status='due'");
$reqs    = $n("SELECT COUNT(*) c FROM ca_erasure_request WHERE status IN ('held','due')");

$done = 0; $failed = 0;
if ($commit) {
    foreach (ca_erasure_due($db) as $e) {
        $r = ca_execute_erasure($db, $e['fk_soc'], $uid);
        if ($r['ok']) $done++;
        else { $failed++; fwrite(STDERR, "  client {$e['fk_soc']}: {$r['msg']}\n"); }
    }
}

printf("  retention %dy · clients %d · erasure requests %d (held %d, due %d) · erased this run %d%s\n",
    $years, $clients, $reqs, $held, $due, $done,
    $commit ? ($failed ? " ({$failed} failed)" : '') : ' (dry run - pass --commit)');
