<?php
// Per-client fields. Two jobs: identify the client, and DRIVE the compliance
// calendar. compliance_calendar.php reads these to decide which statutory
// deadlines that client actually has.
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
global $db;
$e = new ExtraFields($db);

$want = array(
  // identity
  array('gstin','GSTIN','varchar',10,15,''),
  array('pan','PAN','varchar',11,10,''),
  array('tan','TAN (TDS deductor)','varchar',12,10,''),
  array('cin','CIN (companies)','varchar',13,21,''),
  // profile that drives the calendar
  array('entity_type','Entity type','select',20,0,
        'Proprietorship,Partnership,LLP,Private Limited,Public Limited,Individual,HUF,Trust/Society'),
  array('gst_scheme','GST filing scheme','select',21,0,
        'Not registered,Monthly,QRMP (quarterly),Composition'),
  array('tax_audit','Tax audit applicable','boolean',22,3,''),
  array('roc_applicable','ROC/MCA filings','boolean',23,3,''),
  // practice economics
  array('fee_annual','Annual retainer (INR)','price',30,10,''),
  // Decides GSTR-9: the annual return is exempt up to Rs 2 crore aggregate
  // turnover. Without this the calendar either invents the obligation for
  // every small client or misses it for every large one.
  array('turnover_annual','Aggregate turnover (INR)','price',31,10,''),
  array('billing_cycle','Billing cycle','select',31,0,'Annual,Half-yearly,Quarterly,Monthly,Per filing'),
  // 194J applicability is a function of the PAYER's status and threshold, never
  // of a database row id. It must be recorded per client, not guessed.
  array('tds_applicable','Deducts TDS u/s 194J','boolean',32,3,''),
  // contact
  array('whatsapp_number','WhatsApp Number','varchar',40,20,''),
  array('whatsapp_optin','WhatsApp Opt-in','boolean',41,3,''),
);

foreach ($want as $w) {
  $param = '';
  if ($w[2]==='select' && $w[5]!=='') {
    $opts = array(); foreach (explode(',', $w[5]) as $o) $opts[$o] = $o;
    $param = array('options'=>$opts);
  }
  $e->addExtraField($w[0], $w[1], $w[2], $w[3], $w[4], 'societe', 0, 0, '', $param);
}

$found=array(); $r=$db->query("SELECT name FROM ".MAIN_DB_PREFIX."extrafields WHERE elementtype='societe'");
while ($r && $o=$db->fetch_object($r)) $found[]=$o->name;
$missing = array();
foreach ($want as $w) if (!in_array($w[0],$found)) $missing[]=$w[0];
echo "  client extrafields: ".(count($want)-count($missing))."/".count($want)."\n";
if ($missing) { fwrite(STDERR,"  FAIL: missing ".implode(',',$missing)."\n"); exit(1); }
