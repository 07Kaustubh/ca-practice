<?php
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf,$langs;
$FIRM=getenv('CA_FIRM_NAME')?:'Gupta & Co'; $EMAIL=getenv('CA_EMAIL')?:'ca@guptaco.in';
$SMTP=getenv('CA_SMTP_HOST')?:'mailpit';   $PORT=getenv('CA_SMTP_PORT')?:'1025';
$CKEY=getenv('CA_CRON_KEY')?:'bakeoffkey';
foreach (array('modSociete','modAgenda','modCron') as $m) {
  $r=activateModule($m,0);
  echo "  module $m: ".((is_array($r)&&!empty($r['errors']))?implode(';',$r['errors']):'OK')."\n";
}
$IN=0; $res=$db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code='IN'");
if ($res && $o=$db->fetch_object($res)) $IN=$o->rowid;
$consts=array(
 'AGENDA_REMINDER_EMAIL'=>'1','CRON_KEY'=>$CKEY,
 'MAIN_MAIL_SENDMODE'=>'smtps','MAIN_MAIL_SMTP_SERVER'=>$SMTP,'MAIN_MAIL_SMTP_PORT'=>$PORT,
 'MAIN_MAIL_EMAIL_FROM'=>$EMAIL,'MAIN_DISABLE_ALL_MAILS'=>'0',
 'MAIN_INFO_SOCIETE_NOM'=>$FIRM,'MAIN_INFO_SOCIETE_COUNTRY'=>$IN.':IN:India',
 'MAIN_INFO_SOCIETE_TOWN'=>'Bengaluru','MAIN_INFO_SOCIETE_ZIP'=>'560001',
 'MAIN_MONNAIE'=>'INR','SOCIETE_FISCAL_MONTH_START'=>'4');
foreach ($consts as $k=>$v) dolibarr_set_const($db,$k,$v,'chaine',0,'',$conf->entity);
echo "  ".count($consts)." constants set\n";
// PERSONA CONFIG: silence the audit-event noise that clutters each client record
$n=0; $res=$db->query("SELECT name FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'MAIN_AGENDA_ACTIONAUTO_%' AND value='1'");
$kill=array(); while($res && $o=$db->fetch_object($res)) $kill[]=$o->name;
foreach($kill as $k){ dolibarr_set_const($db,$k,'0','chaine',0,'',$conf->entity); $n++; }
echo "  disabled $n auto-event triggers (audit noise)\n";
$u=new User($db); $u->fetch(1);
if (empty($u->email)) { $u->email=$EMAIL; $u->update($u,1); echo "  admin email set -> $EMAIL\n"; }
else echo "  admin email -> {$u->email}\n";

// AUDIT TRAIL: an earlier version disabled all ~158 MAIN_AGENDA_ACTIONAUTO_*
// triggers to reduce dashboard noise. That removed the practice's only record of
// who touched which client record, which makes DPDP s8(5)-(6) breach
// notification impossible to perform accurately. Noise is the price of a log.
$n=0; $res=$db->query("SELECT name FROM ".MAIN_DB_PREFIX."const
                       WHERE name LIKE 'MAIN_AGENDA_ACTIONAUTO_%' AND value<>'1'");
$on=array(); while($res && $o=$db->fetch_object($res)) $on[]=$o->name;
foreach($on as $k){ dolibarr_set_const($db,$k,'1','chaine',0,'',$conf->entity); $n++; }
echo "  audit trail: re-enabled $n trigger(s)\n";

// SESSION / TRANSPORT HARDENING
foreach (array(
  'MAIN_SECURITY_CSRF_WITH_TOKEN'      => '1',
  'MAIN_SECURITY_FORCE_HTTPS'          => (strpos(getenv('DOLI_URL_ROOT') ?: '', 'https') === 0 ? '1' : '0'),
  'MAIN_SESSION_TIMEOUT'               => '3600',
  'MAIN_SECURITY_MAXFILESIZE'          => '10485760',
  'MAIN_SECURITY_DISABLEFORGETPASSLINK' => '1',
  'USER_PASSWORD_GENERATED'            => 'Perso',
  'MAIN_SECURITY_MIN_LENGTH_PASSWORD'  => '12',
) as $k=>$v) dolibarr_set_const($db,$k,$v,'chaine',0,'',$conf->entity);
echo "  session/CSRF/password hardening applied\n";

// PERSONA: a CA has "clients", not "third parties".
// llx_overwrite_trans is Dolibarr's own override table, BUT it is inert unless
// MAIN_ENABLE_OVERWRITE_TRANSLATION=1 (translate.class.php:538). lang=NULL matches
// any language, which avoids depending on MAIN_LANG_DEFAULT=auto resolving.
dolibarr_set_const($db,'MAIN_ENABLE_OVERWRITE_TRANSLATION','1','chaine',0,'',$conf->entity);
dolibarr_set_const($db,'MAIN_LANG_DEFAULT','en_US','chaine',0,'',$conf->entity);
$labels = array(
  'ThirdParty'=>'Client', 'ThirdParties'=>'Clients', 'ThirdPartyName'=>'Client Name',
  'SearchIntoThirdparties'=>'Clients', 'MailToThirdparty'=>'Clients',
  'Prospect'=>'Enquiry', 'Prospects'=>'Enquiries',
  'Customer'=>'Client', 'Customers'=>'Clients',
  'Agenda'=>'Deadlines', 'MenuAgenda'=>'Deadlines', 'Events'=>'Deadlines',
);
$n=0;
foreach ($labels as $k=>$v) {
  $db->query("DELETE FROM ".MAIN_DB_PREFIX."overwrite_trans WHERE transkey='".$db->escape($k)."'");
  if ($db->query("INSERT INTO ".MAIN_DB_PREFIX."overwrite_trans (entity,lang,transkey,transvalue)
      VALUES (".$conf->entity.",NULL,'".$db->escape($k)."','".$db->escape($v)."')")) $n++;
}
echo "  $n label overrides (ERP vocabulary -> CA vocabulary)\n";
// ── Reminder default: Minutes -> Days ───────────────────────────────────────
// card.php:1980 renders the unit select with a HARDCODED 'i' (Minutes), so no
// constant alone can fix the initial page load. AGENDA_DEFAULT_REMINDER_OFFSET_UNIT
// (note the word order - AGENDA_REMINDER_DEFAULT_UNIT is read by nothing) only
// fires from a JS handler when the event type CHANGES. So we also inject a tiny
// script via MAIN_HTML_HEADER, which Dolibarr supports (main.inc.php:215).
// No core patch = survives upgrades.
$codes = '';
// Default group_concat_max_len is 1024: as the dictionary grows the list is cut
// mid-code and reminder defaults quietly stop applying to the tail.
$db->query("SET SESSION group_concat_max_len = 65535");
$rc = $db->query("SELECT GROUP_CONCAT(code) c FROM ".MAIN_DB_PREFIX."c_actioncomm WHERE active=1");
if ($rc && $oc = $db->fetch_object($rc)) $codes = $oc->c;
dolibarr_set_const($db,'AGENDA_DEFAULT_REMINDER_EVENT_TYPES',$codes,'chaine',0,'',$conf->entity);
dolibarr_set_const($db,'AGENDA_DEFAULT_REMINDER_OFFSET','7','chaine',0,'',$conf->entity);
dolibarr_set_const($db,'AGENDA_DEFAULT_REMINDER_OFFSET_UNIT','d','chaine',0,'',$conf->entity);
dolibarr_set_const($db,'AGENDA_REMINDER_DEFAULT_OFFSET','7','chaine',0,'',$conf->entity);
$db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name='AGENDA_REMINDER_DEFAULT_UNIT'");
// The reminder-unit default (card.php:1980 hardcodes 'i' = Minutes) still needs
// fixing, but NOT by storing executable JavaScript in a database row. An inline
// <script> there is a persistent execution channel: any future write primitive -
// a stolen admin session, a tampered backup restore - turns into JS on every
// authenticated page view. Ship it as a static file instead; the constant then
// holds an inert URL that a CSP can police and a hash can pin.
$js = 'document.addEventListener("DOMContentLoaded",function(){'
    . 'var s=document.getElementById("select_offsetunittype_duration");'
    . 'if(s&&!/[?&]id=/.test(location.search)){s.value="d";'
    . 'if(window.jQuery&&jQuery(s).data("select2")){jQuery(s).trigger("change");}}});';
$dir = DOL_DOCUMENT_ROOT.'/custom/ca';
@mkdir($dir, 0755, true);
file_put_contents($dir.'/ca-defaults.js', $js);
$sri = 'sha384-'.base64_encode(hash('sha384', $js, true));
dolibarr_set_const($db,'MAIN_HTML_HEADER',
  '<script src="'.DOL_URL_ROOT.'/custom/ca/ca-defaults.js" integrity="'.$sri.'" crossorigin="anonymous"></script>',
  'chaine',0,'',$conf->entity);
echo "  reminder-default JS shipped as a file, SRI-pinned (no inline script)\n";
echo "  reminder default forced to 7 DAYS (const + MAIN_HTML_HEADER injection)\n";
