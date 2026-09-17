<?php
/**
 * The CA's morning email. ONE screen.
 *
 * A dashboard showing 1,221 open deadlines is less useful than a sticky note.
 * He needs this week's items ranked by which client has not sent documents,
 * plus anything that is quietly rotting.
 */
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf;
$user=new User($db); $user->fetch(1);
$to = $user->email ?: getenv('CA_EMAIL');
if(!$to){ fwrite(STDERR,"FATAL: no recipient\n"); exit(1); }

function rows($db,$sql){ $o=array(); $r=$db->query($sql); while($r && $x=$db->fetch_array($r)) $o[]=$x; return $o; }

// Sorting purely by date buries everything under whichever statutory date falls
// next - advance tax hits all 47 clients on the same day, so the whole screen
// becomes one row repeated. Show the most urgent few PER FILING TYPE so he sees
// his actual week: GST, TDS, advance tax and ITR side by side.
$blocked = rows($db,"SELECT nom, filing, due, n, days, chases FROM (
   SELECT s.nom, d.filing, MIN(d.due) due, COUNT(*) n,
          DATEDIFF(MIN(d.due),CURDATE()) days, COALESCE(MAX(d.chase_count),0) chases,
          ROW_NUMBER() OVER (PARTITION BY SUBSTRING_INDEX(d.filing,' ',1)
                             ORDER BY MIN(d.due), COUNT(*) DESC) rn
   FROM ca_docrequest d JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid=d.fk_soc
   WHERE d.status='pending' AND d.due BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)
   GROUP BY s.nom, d.filing
 ) x WHERE rn <= 3 ORDER BY days, n DESC LIMIT 12");

// how much is hidden behind the sample above
$more = rows($db,"SELECT COUNT(*) c FROM (SELECT 1 FROM ca_docrequest d
   WHERE d.status='pending' AND d.due BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)
   GROUP BY d.fk_soc, d.filing) y");

$ready = rows($db,"SELECT COUNT(DISTINCT CONCAT(a.fk_soc,a.label)) c
 FROM ".MAIN_DB_PREFIX."actioncomm a
 WHERE a.datep BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
   AND a.label REGEXP '^(GSTR|CMP-08|TDS|Advance tax|ITR|Tax audit|AOC-4|MGT-7|LLP Form|DIR-3)'
   AND NOT EXISTS (SELECT 1 FROM ca_docrequest d WHERE d.fk_actioncomm=a.id AND d.status='pending')");

// Silent wrongness is the real risk: a profile that has rotted makes the whole
// calendar confidently incorrect, and it LOOKS authoritative.
$stale = rows($db,"SELECT s.nom, 'no GST scheme recorded' issue
   FROM ".MAIN_DB_PREFIX."societe s LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
   WHERE s.client=1 AND COALESCE(e.gst_scheme,'')=''
 UNION ALL SELECT s.nom,'GST registered but no GSTIN' FROM ".MAIN_DB_PREFIX."societe s
   JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
   WHERE s.client=1 AND e.gst_scheme NOT IN ('','Not registered') AND COALESCE(e.gstin,'')=''
 UNION ALL SELECT s.nom,'no TAN recorded but TDS filings generated' FROM ".MAIN_DB_PREFIX."societe s
   JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
   WHERE s.client=1 AND COALESCE(e.tan,'')='' AND EXISTS
     (SELECT 1 FROM ".MAIN_DB_PREFIX."actioncomm a WHERE a.fk_soc=s.rowid AND a.label LIKE 'TDS%')
 UNION ALL SELECT s.nom,'no annual fee set (will never be invoiced)' FROM ".MAIN_DB_PREFIX."societe s
   JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
   WHERE s.client=1 AND COALESCE(e.fee_annual,0)=0
 UNION ALL SELECT s.nom,'no WhatsApp opt-in (cannot be chased)' FROM ".MAIN_DB_PREFIX."societe s
   JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
   WHERE s.client=1 AND COALESCE(e.whatsapp_optin,0)=0
 UNION ALL SELECT s.nom,'NO CALENDAR - not one deadline generated' FROM ".MAIN_DB_PREFIX."societe s
   WHERE s.client=1 AND NOT EXISTS
     (SELECT 1 FROM ".MAIN_DB_PREFIX."actioncomm a WHERE a.fk_soc=s.rowid)");
// The last one is the loudest thing this email can say. A client with no
// deadlines is invisible to every other part of the system - no document
// requests, no chase, no filings - and until the calendar ran nightly that was
// the silent outcome of adding a client through the web UI.

// One issue type repeated ten times reads as a bug. Show at most three of each,
// then summarise the rest - the point is the CATEGORY of rot, not the roll call.
$seen=array(); $trim=array(); $extra=0;
foreach($stale as $r){ $k=$r['issue']; $seen[$k]=($seen[$k]??0)+1;
  if($seen[$k]<=3) $trim[]=$r; else $extra++; }
$stale=$trim;

$overdue = rows($db,"SELECT COUNT(*) c FROM ca_docrequest WHERE status='pending' AND due < CURDATE()");

// The filing ledger: what actually went out, what is ready, and the money at
// risk. "GSTR-3B due in 2 days" moves nobody; "6 days late = Rs 300 + interest"
// is the sentence that gets the documents sent.
$fil = rows($db,"SELECT
   SUM(status='filed' AND filed_at >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)) filed_wk,
   SUM(status='ready')  rdy,
   SUM(status='pending' AND due < CURDATE()) late,
   COALESCE(SUM(exposure_inr),0) risk FROM ca_filing");
$risky = rows($db,"SELECT s.nom, f.filing, f.late_days, f.exposure_inr, f.note
   FROM ca_filing f JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid=f.fk_soc
   WHERE f.exposure_inr > 0 ORDER BY f.exposure_inr DESC LIMIT 4");
$money   = rows($db,"SELECT COALESCE(SUM(total_ttc),0) t, COUNT(*) n FROM ".MAIN_DB_PREFIX."facture WHERE fk_statut=1");

// Rs 10,93,270 - not 1,093,270. Western thousands grouping in an Indian
// practice's own email is the fastest way to say "this was not built for you".
function inr($n){
  $n=(string)(int)round((float)$n); $neg=$n[0]==='-'; if($neg)$n=substr($n,1);
  if(strlen($n)<=3) return ($neg?'-':'').$n;
  $last3=substr($n,-3); $rest=substr($n,0,-3);
  $rest=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$rest);   // pairs above the last three
  return ($neg?'-':'').$rest.','.$last3;
}
// Truncate VISIBLY. A silent cut turned "Advance tax 45% FY2026-27" into
// "Advance tax 45% FY2026", which reads as the wrong financial year to a CA,
// and "...Pvt Ltd" into "...Pvt Lt" colliding with the next column.
function fit($s,$w){ $s=(string)$s; return mb_strlen($s)<=$w ? str_pad($s,$w)
  : mb_substr($s,0,$w-1)."\xe2\x80\xa6"; }
$RULE=str_repeat("=",78);
$b="";
// the query window is INTERVAL 14 DAY, so this header said WEEK while the
// overflow line below said fortnight - two different periods on one screen
$b.="NEXT 14 DAYS — waiting on the client\n".$RULE."\n";
if(!$blocked) $b.="  nothing outstanding.\n";
foreach($blocked as $r){
  $flag = $r['days']<=2 ? "!!" : ($r['days']<=5 ? "! " : "  ");
  // statutory label gets its full 31 chars and is NEVER cut - it is the one
  // string a CA reads for correctness. Client names ellipsise instead.
  $b.=sprintf("%s %s %s %2dd  %d %s%s\n",
      $flag,fit($r['nom'],26),fit($r['filing'],31),$r['days'],$r['n'],
      ((int)$r['n']===1?'doc ':'docs'),
      $r['chases']>0?"  (chased {$r['chases']}x)":"  (not yet chased)");
}
$hidden = (int)$more[0]['c'] - count($blocked);
if($hidden>0) $b.=sprintf("   ... and %d more client/filing combinations this fortnight\n",$hidden);
$b.="\nFILED in the last 7 days: ".(int)$fil[0]['filed_wk']
   ."   |   READY TO FILE now: ".(int)$fil[0]['rdy']."\n";
// "9 ready to file" is a dead number unless it says where to go and record them.
if((int)$fil[0]['rdy']>0){
  // no localhost in the CA's own email - CA_BASE_URL is his practice URL
  $url=rtrim(getenv('CA_BASE_URL')?:'','/');
  if($url!=='') $b.="  record them as filed: ".$url."/custom/ca/filings.php\n";
  else          $b.="  record them on the Filings screen.\n";
}
if((int)$fil[0]['late']>0){
  $b.=sprintf("\nOVERDUE — EXPOSURE Rs %s across %d filing%s\n%s\n",
      inr($fil[0]['risk']),(int)$fil[0]['late'],((int)$fil[0]['late']===1?'':'s'),$RULE);
  foreach($risky as $r)
    $b.=sprintf("  %s %s %2dd late  Rs %-9s %s\n",
        fit($r['nom'],26),fit($r['filing'],31),$r['late_days'],
        inr($r['exposure_inr']),mb_substr((string)$r['note'],0,34));
}
if((int)$overdue[0]['c']>0) $b.="OVERDUE document requests: ".(int)$overdue[0]['c']."\n";
$b.="\nDATA QUALITY — a stale profile makes the calendar confidently wrong\n".$RULE."\n";
if(!$stale) $b.="  all client profiles complete.\n";
foreach($stale as $r) $b.=sprintf("  %s %s\n",fit($r['nom'],32),$r['issue']);
if($extra>0) $b.=sprintf("  ... and %d more client%s with the same gaps\n",$extra,($extra===1?'':'s'));
$b.=sprintf("\nUNPAID FEES: %d invoice%s, Rs %s\n",(int)$money[0]['n'],
  ((int)$money[0]['n']===1?'':'s'),inr($money[0]['t']));

if (in_array('--print',$argv)) { echo $b; exit(0); }
$msg="Subject: [Practice] This week — ".count($blocked)." client(s) to chase\r\n"
    ."From: $to\r\nTo: $to\r\n\r\n$b\r\n";
file_put_contents('/tmp/digest.eml',$msg);
$host=getenv('CA_SMTP_HOST')?:'mailpit'; $port=(int)(getenv('CA_SMTP_PORT')?:1025);
$fp=@fsockopen($host,$port,$e,$s,10);
if(!$fp){ fwrite(STDERR,"FATAL: cannot reach $host:$port\n"); exit(3); }
$rd=function($fp,$want,$what){ $l=fgets($fp,2048);
  if(!$l || (int)substr(trim($l),0,3)!==$want){ fwrite(STDERR,"FATAL: $what -> ".trim((string)$l)."\n"); exit(3);} };
$rd($fp,220,'banner'); fwrite($fp,"EHLO ca\r\n");
do{$l=fgets($fp,2048);}while($l && preg_match('/^250-/',$l));
fwrite($fp,"MAIL FROM:<$to>\r\n"); $rd($fp,250,'MAIL FROM');
fwrite($fp,"RCPT TO:<$to>\r\n");   $rd($fp,250,'RCPT TO');
fwrite($fp,"DATA\r\n");            $rd($fp,354,'DATA');
fwrite($fp,$msg."\r\n.\r\n");      $rd($fp,250,'body');
fwrite($fp,"QUIT\r\n"); fclose($fp);
echo "  digest delivered to $to (".count($blocked)." to chase, ".count($stale)." data issues)\n";
