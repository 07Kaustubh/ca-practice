<?php
/**
 * Generates each client's STATUTORY deadlines from their profile.
 * A CA does not type 1,200 reminders a year - the dates are fixed by law and
 * derivable: GST scheme sets GSTR cadence, a TAN means TDS, entity type means
 * ROC, audit applicability moves the ITR date.
 * Idempotent. Usage: php compliance_calendar.php [FY_START_YEAR]
 */
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf;

$user=new User($db); $user->fetch(1); $user->getrights();
if (empty($user->email)) { fwrite(STDERR,"FATAL: admin has no email - every reminder would die at status=-1\n"); exit(1); }

$fy = isset($argv[1]) && (int)$argv[1] ? (int)$argv[1]
    : ((int)date('n')>=4 ? (int)date('Y') : (int)date('Y')-1);
$REMIND = (int)(getenv('CA_REMIND_DAYS') ?: 7);
echo "  FY {$fy}-".substr((string)($fy+1),2)."   reminder lead {$REMIND} days\n";

function d($y,$m,$dd){ return dol_mktime(10,0,0,$m,$dd,$y); }

function rulesFor($c,$fy){
  $o=array(); $months=array();
  for($i=0;$i<12;$i++){ $m=(($i+3)%12)+1; $months[]=array($fy+($m>=4?0:1),$m); }
  $q=array(array(6,'Apr-Jun'),array(9,'Jul-Sep'),array(12,'Oct-Dec'),array(3,'Jan-Mar'));

  if($c['gst_scheme']==='Monthly'){
    foreach($months as $ym){ list($y,$m)=$ym; $ny=$m==12?$y+1:$y; $nm=$m==12?1:$m+1;
      $tag=date('M Y',mktime(0,0,0,$m,1,$y));
      $o[]=array("GSTR-1 $tag",d($ny,$nm,11)); $o[]=array("GSTR-3B $tag",d($ny,$nm,20)); }
  } elseif($c['gst_scheme']==='QRMP (quarterly)'){
    foreach($q as $qq){ $qm=$qq[0]; $qy=$fy+($qm>=4?0:1); $ny=$qm==12?$qy+1:$qy; $nm=$qm==12?1:$qm+1;
      $o[]=array("GSTR-1 (QRMP) {$qq[1]}",d($ny,$nm,13)); $o[]=array("GSTR-3B (QRMP) {$qq[1]}",d($ny,$nm,22)); }
  } elseif($c['gst_scheme']==='Composition'){
    foreach($q as $qq){ $qm=$qq[0]; $qy=$fy+($qm>=4?0:1); $ny=$qm==12?$qy+1:$qy; $nm=$qm==12?1:$qm+1;
      $o[]=array("CMP-08 {$qq[1]}",d($ny,$nm,18)); }
    $o[]=array("GSTR-4 annual FY{$fy}",d($fy+1,6,30));
  }
  if($c['gst_scheme']!=='' && $c['gst_scheme']!=='Not registered')
    $o[]=array("GSTR-9 annual return FY{$fy}",d($fy+1,12,31));

  if(!empty($c['tan'])){
    foreach($months as $ym){ list($y,$m)=$ym; if($m==3) continue;
      $ny=$m==12?$y+1:$y; $nm=$m==12?1:$m+1;
      $o[]=array("TDS payment ".date('M Y',mktime(0,0,0,$m,1,$y)),d($ny,$nm,7)); }
    $o[]=array("TDS payment Mar {$fy}",d($fy+1,4,30));
    $o[]=array("TDS return 26Q/24Q Q1",d($fy,7,31));
    $o[]=array("TDS return 26Q/24Q Q2",d($fy,10,31));
    $o[]=array("TDS return 26Q/24Q Q3",d($fy+1,1,31));
    $o[]=array("TDS return 26Q/24Q Q4",d($fy+1,5,31));
  }

  $audit=!empty($c['tax_audit']);
  if($c['entity_type']!=='Individual' || $audit){
    $o[]=array("Advance tax 15%",d($fy,6,15));  $o[]=array("Advance tax 45%",d($fy,9,15));
    $o[]=array("Advance tax 75%",d($fy,12,15)); $o[]=array("Advance tax 100%",d($fy+1,3,15));
  }
  if($audit){ $o[]=array("Tax audit 3CA/3CD FY{$fy}",d($fy+1,9,30));
              $o[]=array("ITR filing (audit) FY{$fy}",d($fy+1,10,31)); }
  else      { $o[]=array("ITR filing FY{$fy}",d($fy+1,7,31)); }

  if(!empty($c['roc_applicable'])){
    if($c['entity_type']==='LLP'){
      $o[]=array("LLP Form 11 FY{$fy}",d($fy+1,5,30));
      $o[]=array("LLP Form 8 FY{$fy}",d($fy+1,10,30));
    } else {
      $o[]=array("AOC-4 FY{$fy}",d($fy+1,10,30));
      $o[]=array("MGT-7 FY{$fy}",d($fy+1,11,29));
    }
    $o[]=array("DIR-3 KYC",d($fy,9,30));
  }
  return $o;
}

$res=$db->query("SELECT s.rowid,s.nom,
   COALESCE(e.gst_scheme,'') gst_scheme, COALESCE(e.tan,'') tan,
   COALESCE(e.entity_type,'') entity_type, COALESCE(e.tax_audit,0) tax_audit,
   COALESCE(e.roc_applicable,0) roc_applicable
   FROM ".MAIN_DB_PREFIX."societe s
   LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
   WHERE s.client=1");
if(!$res){ fwrite(STDERR,"FATAL: ".$db->lasterror()."\n"); exit(2); }

$made=0;$skip=0;$n=0;$rem=0;$stalecnt=0;
while($c=$db->fetch_array($res)){
  $n++;
  foreach(rulesFor($c,$fy) as $r){
    list($label,$ts)=$r; $full=$label.' - '.$c['nom'];
    $chk=$db->query("SELECT id FROM ".MAIN_DB_PREFIX."actioncomm WHERE fk_soc=".(int)$c['rowid']
                   ." AND label='".$db->escape($full)."'");
    if($chk && $db->num_rows($chk)>0){ $skip++; continue; }
    $a=new ActionComm($db); $a->type_code='AC_OTH'; $a->label=$full;
    $a->datep=$ts; $a->datef=$ts+3600; $a->socid=(int)$c['rowid'];
    $a->userownerid=$user->id; $a->percentage=0;   // 0 = "to do" so it reaches the dashboard
    $aid=$a->create($user);
    if($aid>0){
      // Clamp to now: for a deadline 3 days out, "7 days before" is already in
      // the past. Back-dating it makes the reminder look permanently overdue and
      // trips wa_fanout's aged-out alarm forever. Intent is "7 days before, or
      // immediately if that has passed".
      $remind_at = max($ts - $REMIND*86400, dol_now() + 120);
      // Dolibarr's browser-notification poller (core/ajax/check_notifications.php:81)
      // PERMANENTLY DELETES any reminder whose dateremind is >1 month old, for the
      // logged-in user, with no log. sweeper.sh cannot rescue those - the rows are
      // gone, not status=-1. A reminder that stale is useless anyway (its deadline
      // has long passed), so do not create one. The DEADLINE is still recorded.
      // Only remind about deadlines still AHEAD of us. A reminder for a filing
      // already due is noise, permanently trips wa_fanout's aged-out alarm, and
      // would be purged by check_notifications.php anyway.
      $stale = ($ts <= dol_now()) || ($remind_at < (dol_now() - 30*86400));
      $db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."actioncomm_resources
        (fk_actioncomm,element_type,fk_element,mandatory,transparency)
        VALUES ($aid,'user',".(int)$user->id.",0,0)");
      if(!$stale){
        $db->query("INSERT INTO ".MAIN_DB_PREFIX."actioncomm_reminder
          (dateremind,typeremind,fk_user,fk_soc,offsetvalue,offsetunit,status,entity,fk_actioncomm)
          VALUES ('".$db->idate($remind_at)."','email',".(int)$user->id.",
                  ".(int)$c['rowid'].",$REMIND,'d',0,".$conf->entity.",$aid)");
        $rem++;
      } else { $stalecnt++; }
      $made++;
    }
  }
}
echo "  clients $n   deadlines $made   reminders $rem   skipped-stale $stalecnt   already present $skip\n";
