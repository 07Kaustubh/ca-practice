<?php
/**
 * Raises fee invoices from each client's retainer + billing cycle, and reminds
 * on the unpaid ones. CAs are notoriously bad at billing their own practice -
 * this is the half of the business that funds the other half.
 * Idempotent per period. Usage: php raise_fees.php [--commit]
 */
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf;
$commit = in_array('--commit',$argv);
$user=new User($db); $user->fetch(1); $user->getrights();

$share = array('Annual'=>1.0,'Half-yearly'=>0.5,'Quarterly'=>0.25,'Monthly'=>1/12,'Per filing'=>0.25);
$period = date('Y').'-Q'.ceil((int)date('n')/3);

$svc=new Product($db); $svc->fetch(0,'SVC-RET');

$res=$db->query("SELECT s.rowid,s.nom,COALESCE(e.fee_annual,0) fee,COALESCE(e.billing_cycle,'Annual') cyc,
                 COALESCE(e.tds_applicable,0) tds_applicable
                 FROM ".MAIN_DB_PREFIX."societe s
                 LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
                 WHERE s.client=1 AND COALESCE(e.fee_annual,0)>0");
$made=0;$skip=0;$value=0;
while($c=$db->fetch_array($res)){
  $amt = round($c['fee'] * ($share[$c['cyc']] ?? 1.0));
  if ($amt<=0) continue;
  $note = "Professional fees {$period} ({$c['cyc']})";
  $chk=$db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."facture
                   WHERE fk_soc=".(int)$c['rowid']." AND note_public='".$db->escape($note)."'");
  if($chk && $db->num_rows($chk)>0){ $skip++; continue; }
  if(!$commit){ $made++; $value+=$amt; continue; }

  $f=new Facture($db);
  $f->socid=(int)$c['rowid']; $f->date=dol_now(); $f->type=Facture::TYPE_STANDARD;
  $f->cond_reglement_id=1; $f->note_public=$note;
  $id=$f->create($user);
  if($id>0){
    $f->addline($note, $amt, 1, 18, 0, 0, ($svc->id?:0), 0, '', '', 0, 0, '', 'HT');
    $f->validate($user);
    // Driven by the client's recorded status. The previous `rowid % 2` heuristic
    // fabricated statutory withholding figures on real invoices - a professional
    // conduct problem for a CA, and silent.
    if (!empty($c['tds_applicable'])) {
      $db->query("INSERT INTO ".MAIN_DB_PREFIX."facture_extrafields (fk_object,tds_194j,tds_certificate)
                  VALUES ($id,".round($amt*0.10).",0)
                  ON DUPLICATE KEY UPDATE tds_194j=VALUES(tds_194j)");
    }
    $made++; $value+=$amt;
  }
}
printf("  %s: %d invoice(s), gross INR %s%s\n", $commit?'RAISED':'DRY RUN', $made,
       number_format($value), $skip?"   (already billed: $skip)":'');
