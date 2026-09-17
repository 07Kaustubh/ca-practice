<?php
/**
 * DOCUMENT COLLECTION — the actual job.
 *
 * A CA already knows GSTR-3B is due on the 20th; that is his profession. What
 * costs him the deadline is that the client has not sent the purchase register
 * on the 17th. So the primary object here is not the deadline - it is the
 * document, per client, per period, with a status and a chase count.
 * The deadline is an attribute of it.
 *
 * Usage: php doc_requests.php [horizon_days]      (default 21)
 */
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf;
$user=new User($db); $user->fetch(1);
$HORIZON = isset($argv[1]) ? (int)$argv[1] : (int)(getenv('CA_DOC_HORIZON') ?: 30);

$db->query("CREATE TABLE IF NOT EXISTS ca_docrequest (
  rowid INT AUTO_INCREMENT PRIMARY KEY,
  fk_soc INT NOT NULL, fk_actioncomm INT NULL,
  period VARCHAR(16) NOT NULL, filing VARCHAR(64) NOT NULL,
  doc_type VARCHAR(96) NOT NULL,
  status ENUM('pending','received','na') NOT NULL DEFAULT 'pending',
  due DATE NOT NULL,
  requested_at DATETIME NULL, received_at DATETIME NULL,
  chase_count INT NOT NULL DEFAULT 0, last_chase DATETIME NULL,
  UNIQUE KEY uniq_req (fk_soc,period,filing,doc_type),
  KEY k_status (status), KEY k_due (due)) ENGINE=innodb");

/** What each filing actually needs FROM THE CLIENT. */
function docsFor($filing){
  if (preg_match('/^GSTR-1/',$filing))   return array('Sales register / B2B invoices','Credit & debit notes','Export invoices (if any)');
  if (preg_match('/^GSTR-3B/',$filing))  return array('Purchase register','Bank statement','ITC reconciliation / GSTR-2B match');
  if (preg_match('/^CMP-08/',$filing))   return array('Turnover summary','Bank statement');
  if (preg_match('/^GSTR-9/',$filing))   return array('Annual sales & purchase summary','Reconciliation with books');
  if (preg_match('/^TDS payment/',$filing)) return array('TDS deduction details','Challan / payment proof');
  if (preg_match('/^TDS return/',$filing))  return array('Deductee-wise breakup (PAN + amount)','Challan details');
  if (preg_match('/^Advance tax/',$filing)) return array('Estimated income for the year','Advance tax already paid');
  if (preg_match('/^ITR/',$filing))      return array('Bank statements (all accounts)','Form 16 / 16A','Investment & 80C proofs','Books of account');
  if (preg_match('/^Tax audit/',$filing))return array('Books of account','Fixed asset register','Stock statement','Party ledgers');
  if (preg_match('/^(AOC-4|MGT-7|LLP Form)/',$filing)) return array('Signed financial statements','Board resolution','Shareholding / partner details');
  if (preg_match('/^DIR-3/',$filing))    return array('Director KYC (PAN, Aadhaar, photo)');
  return array();   // unknown label = not a filing; ask for nothing
}

// llx_actioncomm also holds Dolibarr's AUDIT events ("Invoice X validated",
// "Third party Y created") now that the audit trail is on. Only rows whose
// label matches a statutory filing are real deadlines - match explicitly rather
// than assuming everything in the table is one.
$FILINGS = "a.label REGEXP '^(GSTR-1|GSTR-3B|GSTR-4|GSTR-9|CMP-08|TDS payment|TDS return|"
         . "Advance tax|ITR filing|Tax audit|AOC-4|MGT-7|LLP Form|DIR-3)'";
$res=$db->query("SELECT a.id, a.label, a.datep, a.fk_soc, s.nom
                 FROM ".MAIN_DB_PREFIX."actioncomm a
                 JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid=a.fk_soc
                 WHERE a.datep BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL $HORIZON DAY)
                   AND $FILINGS
                 ORDER BY a.datep");
$made=0;$skip=0;$deadlines=0;
while($res && $o=$db->fetch_object($res)){
  $deadlines++;
  $filing = trim(preg_replace('/ - .*$/','',$o->label));
  $period = date('Y-m', $db->jdate($o->datep));
  $due    = date('Y-m-d', $db->jdate($o->datep));
  $docs = docsFor($filing);
  if (!$docs) { continue; }
  foreach($docs as $doc){
    $ok=$db->query("INSERT IGNORE INTO ca_docrequest
      (fk_soc,fk_actioncomm,period,filing,doc_type,status,due,requested_at)
      VALUES (".(int)$o->fk_soc.",".(int)$o->id.",'".$db->escape($period)."',
              '".$db->escape($filing)."','".$db->escape($doc)."','pending','".$db->escape($due)."',NOW())");
    if($ok && $db->affected_rows($ok)>0) $made++; else $skip++;
  }
}
echo "  horizon {$HORIZON}d: {$deadlines} filing(s) -> {$made} document request(s) created, {$skip} already open\n";
