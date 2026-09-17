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
 // NOTE: dates are NOT configured by a constant. dol_print_date() resolves its
 // format from the LANGUAGE PACK - $outputlangs->trans("FormatDateShort")
 // (functions.lib.php:3937). MAIN_DATE_FORMAT / MAIN_DATE_FORMAT_SHORT /
 // MAIN_DATETIME_FORMAT were set here and are read by NOTHING: grep the Dolibarr
 // source and there is not one reference. They made llx_const look configured
 // while every screen still rendered American dates. The real switch is
 // MAIN_LANG_DEFAULT=en_IN, set below.
 'MAIN_START_WEEK'=>'1',
 // Dolibarr prints its own version in the top bar (main.inc.php:2432 guards this
 // on MAIN_HIDE_VERSION). Advertising the exact build of the software to every
 // visitor is a free gift to anyone scanning for known issues, and it tells a
 // prospect they are looking at an off-the-shelf install rather than a product.
 'MAIN_HIDE_VERSION'=>'1',
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

// The default client list shows Alias, Zip, Phone, Sales-rep - all blank for a
// CA practice, and omits the two fields he actually sorts by. Pick the columns
// that carry information for this workload.
$cols='s.nom,s.email,extra.gstin,extra.pan,extra.entity_type,extra.gst_scheme,s.status';
$db->query("INSERT INTO ".MAIN_DB_PREFIX."user_param (fk_user,entity,param,value)
            VALUES (1,".$conf->entity.",'MAIN_SELECTEDFIELDS_thirdpartylist','".$db->escape($cols)."')
            ON DUPLICATE KEY UPDATE value=VALUES(value)");
dolibarr_set_const($db,'SOCIETE_ADD_REF_IN_LIST','0','chaine',0,'',$conf->entity);
echo "  client list columns set to name/email/GSTIN/PAN/entity/GST scheme\n";

// PERSONA: a CA has "clients", not "third parties".
// llx_overwrite_trans is Dolibarr's own override table, BUT it is inert unless
// MAIN_ENABLE_OVERWRITE_TRANSLATION=1 (translate.class.php:538). lang=NULL matches
// any language, which avoids depending on MAIN_LANG_DEFAULT=auto resolving.
dolibarr_set_const($db,'MAIN_ENABLE_OVERWRITE_TRANSLATION','1','chaine',0,'',$conf->entity);
// en_IN, not en_US. This is the ONLY thing that makes dates read 14/09/2026 to
// an Indian CA. en_US ships FormatDateShort=%m/%d/%Y AND FormatDateShortJQuery=
// mm/dd/yy, which is why two different American formats appeared on one screen -
// 09/14/2026 in a header and 12/31/27 in a table. en_IN sets all eight Format*
// keys to day-first coherently. It is still English, so no labels change.
dolibarr_set_const($db,'MAIN_LANG_DEFAULT','en_IN','chaine',0,'',$conf->entity);
// the inert constants were previously written here; remove them if an older
// deploy left them behind, so nobody reads them and believes dates are set
$db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name IN ('MAIN_DATE_FORMAT','MAIN_DATE_FORMAT_SHORT','MAIN_DATETIME_FORMAT')");
// ── the CA screens must be REACHABLE ────────────────────────────────────────
// Every custom screen was served correctly and linked from nowhere. A CA would
// have had to be told a URL to file a return. Menu entries are the difference
// between software he uses and software he is handed instructions for.
// NB: a left entry attaches to its parent through fk_menu = the TOP row's rowid.
// Setting fk_menu=0 and fk_mainmenu='ca' silently produced an EMPTY sidebar,
// which reads as broken rather than as absent.
$db->query("DELETE FROM ".MAIN_DB_PREFIX."menu WHERE module='ca' AND entity=".$conf->entity);
$ins = function ($type, $pos, $url, $title, $parent) use ($db, $conf) {
    $ok = $db->query("INSERT INTO ".MAIN_DB_PREFIX."menu
      (menu_handler,entity,module,type,mainmenu,leftmenu,fk_menu,position,url,target,titre,langs,level,perms,enabled,usertype)
      VALUES ('all',".$conf->entity.",'ca','".$db->escape($type)."','ca','',".(int) $parent.","
      .(int) $pos.",'".$db->escape($url)."','','".$db->escape($title)."','',0,'','1',0)");
    return $ok ? (int) $db->last_insert_id(MAIN_DB_PREFIX.'menu') : 0;
};
$top = $ins('top', 62, '/custom/ca/filings.php?view=ready', 'Compliance', 0);
$mn = $top ? 1 : 0;
if ($top) {
    foreach (array(
        array(100, '/custom/ca/filings.php?view=ready', 'Ready to file'),
        array(101, '/custom/ca/filings.php?view=filed', 'Filed'),
        array(102, '/custom/ca/client.php',             'Client profiles'),
        array(110, '/custom/ca/adjustments.php',        'Holidays & extensions'),
        array(111, '/custom/ca/rates.php',              'Late-fee rates'),
        array(112, '/custom/ca/privacy.php',            'Retention & erasure'),
    ) as $m) { if ($ins('left', $m[0], $m[1], $m[2], $top)) $mn++; }
}
echo "  menu: $mn entries under a 'Compliance' tab - one click, not a URL\n";

$labels = array(
  // The navigation still read like an ERP - Commerce, Products, Third parties -
  // which is the fastest way to tell a CA this was not built for him.
  // DO NOT override two halves of a compound label to the same word: Dolibarr
  // renders the agenda tab as "Agenda/Events", so mapping BOTH to 'Deadlines'
  // produced "Deadlines/Deadlines", which reads as a bug. Left as Agenda/Events.
  'Commercial'=>'Practice', 'Commerce'=>'Practice', 'Products'=>'Services',
  'ProductsAndServices'=>'Services',
  'Tools'=>'Utilities', 'MenuFinancial'=>'Billing', 'Accountancy'=>'Books',
  'Accounting'=>'Books', 'MenuAccountancy'=>'Books', 'Bank'=>'Bank & Cash',
  'ThirdParty'=>'Client', 'ThirdParties'=>'Clients', 'ThirdPartyName'=>'Client Name',
  'SearchIntoThirdparties'=>'Clients', 'MailToThirdparty'=>'Clients',
  'Prospect'=>'Enquiry', 'Prospects'=>'Enquiries',
  'Customer'=>'Client', 'Customers'=>'Clients',
  'Agenda'=>'Deadlines', 'MenuAgenda'=>'Deadlines',   // NOT 'Events' - see the note above
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
// Two things ship in this file. Both are DOM fixes that must run on every
  // authenticated page, and neither may be inline script, for the reason above.
  $js = 'document.addEventListener("DOMContentLoaded",function(){'
      // 1. the reminder-unit default
      . 'var s=document.getElementById("select_offsetunittype_duration");'
      . 'if(s&&!/[?&]id=/.test(location.search)){s.value="d";'
      . 'if(window.jQuery&&jQuery(s).data("select2")){jQuery(s).trigger("change");}}'
      // 2. a skip link. Dolibarr puts 21 tab stops of navigation in front of the
      //    content, so a keyboard user pays that toll on every page. The link is
      //    invisible until focused - the first Tab offers to jump to the work.
      . 'var m=document.getElementById("id-right")||document.querySelector(".fiche")||document.getElementById("id-container");'
      . 'if(m&&!document.getElementById("ca-skip")){'
      . 'if(!m.id){m.id="ca-main";}m.setAttribute("tabindex","-1");'
      . 'var st=document.createElement("style");'
      . 'st.textContent="#ca-skip{position:absolute;left:-9999px;top:0;z-index:99999;background:#1b2b4b;color:#fff;padding:10px 16px;font:600 14px system-ui;text-decoration:none}#ca-skip:focus{left:0}";'
      . 'document.head.appendChild(st);'
      . 'var a=document.createElement("a");a.id="ca-skip";a.href="#"+m.id;'
      . 'a.textContent="Skip to main content";'
      . 'document.body.insertBefore(a,document.body.firstChild);}'
      . '});';
$dir = DOL_DOCUMENT_ROOT.'/custom/ca';
@mkdir($dir, 0755, true);
file_put_contents($dir.'/ca-defaults.js', $js);
$sri = 'sha384-'.base64_encode(hash('sha384', $js, true));
dolibarr_set_const($db,'MAIN_HTML_HEADER',
  '<script src="'.DOL_URL_ROOT.'/custom/ca/ca-defaults.js" integrity="'.$sri.'" crossorigin="anonymous"></script>',
  'chaine',0,'',$conf->entity);
echo "  reminder-default JS shipped as a file, SRI-pinned (no inline script)\n";
echo "  reminder default forced to 7 DAYS (const + MAIN_HTML_HEADER injection)\n";
