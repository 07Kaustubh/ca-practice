<?php
/**
 * FILING STATUS + LATE-FEE EXPOSURE.
 *
 * Tracking documents IN without recording the return going OUT is half a system:
 * you cannot answer "what is still outstanding?", you cannot prove timely filing
 * if challenged, and the document chase has no terminal state.
 *
 * It also computes the money at risk. "GSTR-3B due in 2 days" moves nobody.
 * "GSTR-3B 6 days late = Rs 300 late fee + interest" moves everybody - and it is
 * the number a CA quotes to the client to get the documents.
 *
 * Rates are CONFIGURABLE and current as at FY 2026-27. Verify before relying on
 * them; statutory rates change and this file is the single place to update.
 */
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
global $db,$conf;

$db->query("CREATE TABLE IF NOT EXISTS ca_filing (
  rowid INT AUTO_INCREMENT PRIMARY KEY,
  fk_soc INT NOT NULL, fk_actioncomm INT NULL,
  filing VARCHAR(96) NOT NULL, period VARCHAR(16) NOT NULL, due DATE NOT NULL,
  status ENUM('pending','ready','filed','not_applicable') NOT NULL DEFAULT 'pending',
  arn VARCHAR(64) NULL,              -- GST ARN / ITR ack no / MCA SRN
  filed_at DATE NULL, filed_by INT NULL,
  late_days INT NOT NULL DEFAULT 0,
  exposure_inr DECIMAL(12,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  UNIQUE KEY uniq_filing (fk_soc,filing,period),
  KEY k_status (status), KEY k_due (due)) ENGINE=innodb");

// Late fee rates and acknowledgement rules live in the shared lib, so this
// generator, the CLI (file_return.php) and the screen the CA uses cannot drift
// apart. One place to change when a statutory rate changes.
$lib = null;
foreach (array('/var/www/html/custom/ca/ca_filing_lib.php', '/tmp/ca_filing_lib.php') as $p)
  if (is_readable($p)) { $lib = $p; break; }
if ($lib === null) { fwrite(STDERR, "  ca_filing_lib.php not found - run deploy.sh to install it\n"); exit(2); }
require_once $lib;
// Rates live in an editable table now. Seeding is idempotent, so this both
// creates it on first run and leaves a CA's later edits alone.
ca_rate_ensure($db);

// GO-LIVE BOUNDARY.
// The calendar is generated for the whole FY including months already past.
// Treating those as "overdue" invents a liability for returns the CA filed long
// before this system existed - on day one it showed Rs 7.12 lakh of exposure
// that does not exist. Anything due before go-live is outside our knowledge.
$golive = getenv('CA_GOLIVE') ?: date('Y-m-d');

// mirror every generated deadline into a filing record
// PMT-06 was added to the calendar but not to this mirror, so those deadlines
// never entered the ledger, never got a go-live verdict, and showed up on the
// dashboard as permanently late. A rule added to the calendar must be added here.
$FIL = "a.label REGEXP '^(GSTR-1|GSTR-3B|GSTR-4|GSTR-9|CMP-08|PMT-06|TDS payment|TDS return|"
     . "Advance tax|ITR filing|Tax audit|AOC-4|MGT-7|LLP Form|DIR-3)'";
$res=$db->query("SELECT a.id,a.label,a.datep,a.fk_soc FROM ".MAIN_DB_PREFIX."actioncomm a
                 WHERE $FIL AND a.fk_soc>0");
$made=0;
while($res && $o=$db->fetch_object($res)){
  $filing=trim(preg_replace('/ - .*$/','',$o->label));
  $period=date('Y-m',$db->jdate($o->datep));
  $due=date('Y-m-d',$db->jdate($o->datep));
  $ok=$db->query("INSERT IGNORE INTO ca_filing (fk_soc,fk_actioncomm,filing,period,due,status)
    VALUES (".(int)$o->fk_soc.",".(int)$o->id.",'".$db->escape($filing)."',
            '".$db->escape($period)."','".$db->escape($due)."',
            '".($due < $golive ? 'not_applicable' : 'pending')."')");
  if($ok && $db->affected_rows($ok)>0) $made++;
}

// a filing whose documents are all in is READY; overdue ones accrue exposure
$db->query("UPDATE ca_filing f SET status='ready'
  WHERE f.status='pending' AND NOT EXISTS
    (SELECT 1 FROM ca_docrequest d WHERE d.fk_actioncomm=f.fk_actioncomm AND d.status='pending')
    AND EXISTS (SELECT 1 FROM ca_docrequest d2 WHERE d2.fk_actioncomm=f.fk_actioncomm)");

// exposure only on filings this system was actually responsible for
$db->query("UPDATE ca_filing SET note='before go-live - not tracked by this system'
            WHERE status='not_applicable' AND (note IS NULL OR note='')");
// Close them on the agenda too. Dolibarr's dashboard counts any event with
// percent<100 and a past date as LATE, so 318 returns the CA filed before this
// system existed were rendering as "350 late / 28.25% late" on the home page -
// the same fabricated-liability problem already fixed for the morning email,
// surfacing again on the one screen he sees first.
$db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm a
              JOIN ca_filing f ON f.fk_actioncomm=a.id
               SET a.percent=100
             WHERE f.status='not_applicable' AND a.percent<100");
// Dolibarr's audit trail logs "Third party X created", "Invoice Y validated" as
// AGENDA EVENTS with percent=0. The home dashboard counts any such event with a
// past date as LATE, so the audit trail I deliberately switched on was inflating
// the CA's to-do list with 107 records of things that had ALREADY happened.
// An audit record is evidence, not a task. Our own deadlines are AC_OTH; every
// other code is an automatic record, so close those.
$db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm a
              JOIN ".MAIN_DB_PREFIX."c_actioncomm c ON c.id=a.fk_action
               SET a.percent=100
             WHERE c.code <> 'AC_OTH' AND a.percent<100");
$upd=$db->query("SELECT rowid,filing,DATEDIFF(CURDATE(),due) ld FROM ca_filing
                 WHERE status IN ('pending','ready') AND due < CURDATE()
                   AND due >= '".$db->escape($golive)."'");
$rows=array(); while($upd && $r=$db->fetch_object($upd)) $rows[]=$r;
$risk=0;
foreach($rows as $r){
  list($amt,$note)=exposureFor($r->filing,(int)$r->ld,$db);
  $db->query("UPDATE ca_filing SET late_days=".(int)$r->ld.", exposure_inr=".(float)$amt.
             ", note='".$db->escape($note)."' WHERE rowid=".(int)$r->rowid);
  $risk+=$amt;
}
$tot=0;$rdy=0;$fil=0;
$q=$db->query("SELECT COUNT(*) c,SUM(status='ready') r,SUM(status='filed') f FROM ca_filing");
if($q && $o=$db->fetch_object($q)){ $tot=$o->c;$rdy=(int)$o->r;$fil=(int)$o->f; }
$pre=0; $q2=$db->query("SELECT COUNT(*) c FROM ca_filing WHERE status='not_applicable'");
if($q2 && $o2=$db->fetch_object($q2)) $pre=(int)$o2->c;
printf("  filings %d (new %d) · pre-go-live %d · ready %d · filed %d · overdue %d · exposure Rs %s\n",
  $tot,$made,$pre,$rdy,$fil,count($rows),number_format($risk,0));
