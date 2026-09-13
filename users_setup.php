<?php
/**
 * Per-person logins and a scoped role.
 * A shared `admin` account means a confidentiality duty cannot be attributed to
 * a person - which is exactly what ICAI expects a practice to be able to do, and
 * what DPDP s8(5)-(6) breach notification requires. It also gave every article
 * full Setup rights. Staff get a non-admin group with client + agenda rights only.
 */
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';   // dolibarr_set_const():
// the USER_PASSWORD_GENERATED='Perso' policy set in setup_all.php makes
// User::create() load modGeneratePassPerso, which calls dolibarr_set_const().
// Without this require, every user creation dies with an undefined-function fatal.
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
global $db,$conf;
$admin=new User($db); $admin->fetch(1); $admin->getrights();

// ── the scoped group ────────────────────────────────────────────────────────
$g=new UserGroup($db); $gid=0;
$r=$db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."usergroup WHERE nom='Practice Staff'");
if($r && $o=$db->fetch_object($r)) { $gid=$o->rowid; }
else { $g->name='Practice Staff'; $g->note='Articles/assistants: clients + deadlines, no Setup';
       $gid=$g->create($admin); }
if($gid>0){
  // societe (clients) + agenda (deadlines) read/write. Deliberately NOT: setup,
  // user admin, accounting, or delete.
  $want=array('societe'=>array('lire','creer'),'agenda'=>array('myactions_read','allactions_read','myactions_create'));
  $n=0;
  foreach($want as $mod=>$perms){
    foreach($perms as $p){
      $q=$db->query("SELECT id FROM ".MAIN_DB_PREFIX."rights_def
                     WHERE module='".$db->escape($mod)."' AND perms='".$db->escape($p)."' AND entity=".$conf->entity);
      while($q && $o=$db->fetch_object($q)){
        $db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."usergroup_rights (entity,fk_usergroup,fk_id)
                    VALUES (".$conf->entity.",".(int)$gid.",".(int)$o->id.")"); $n++;
      }
    }
  }
  echo "  group 'Practice Staff' id=$gid with $n permission(s)\n";
}

// ── one login per person ────────────────────────────────────────────────────
$staff = json_decode(getenv('CA_STAFF') ?: '[]', true);
if (!$staff) $staff = array(
  array('login'=>'ca.partner','last'=>'Gupta','first'=>'Partner',
        'email'=>'partner@'.substr(strrchr(getenv('CA_EMAIL')?:'ca@guptaco.in','@'),1),'admin'=>1),
  array('login'=>'article1','last'=>'Article','first'=>'One','email'=>'article1@guptaco.in','admin'=>0),
  array('login'=>'article2','last'=>'Article','first'=>'Two','email'=>'article2@guptaco.in','admin'=>0),
);
$made=0;$have=0;
foreach($staff as $s){
  $u=new User($db);
  if($u->fetch(0,$s['login'])>0){ $have++;
    if(empty($u->email)){ $u->email=$s['email']; $u->update($u,1); }
    continue; }
  $u=new User($db);
  $u->login=$s['login']; $u->lastname=$s['last']; $u->firstname=$s['first'];
  $u->email=$s['email']; $u->admin=(int)$s['admin']; $u->statut=1;
  // Must satisfy the password policy this project itself sets
  // (MAIN_SECURITY_MIN_LENGTH_PASSWORD=12, upper+lower+digit).
  $mk=function(){ $U='ABCDEFGHJKLMNPQRSTUVWXYZ'; $L='abcdefghijkmnpqrstuvwxyz';
    $D='23456789'; $S='!@#%^*-_'; $all=$U.$L.$D.$S;
    $p=$U[random_int(0,strlen($U)-1)].$L[random_int(0,strlen($L)-1)]
      .$D[random_int(0,strlen($D)-1)].$S[random_int(0,strlen($S)-1)];
    for($i=0;$i<12;$i++) $p.=$all[random_int(0,strlen($all)-1)];
    return str_shuffle($p); };
  $u->pass = $mk();   // random; operator resets at handover
  $uid=$u->create($admin);
  if($uid<=0){ echo "  ERR creating {$s['login']}: ".$u->error." ".implode(';',$u->errors)."\n"; }
  if($uid>0){
    // random password; the operator resets it on first handover
    if(!$s['admin'] && $gid>0) $db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."usergroup_user
        (entity,fk_user,fk_usergroup) VALUES (".$conf->entity.",".(int)$uid.",".(int)$gid.")");
    $made++;
  }
}
$tot=0; $r=$db->query("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX."user WHERE statut=1");
if($r && $o=$db->fetch_object($r)) $tot=$o->c;
echo "  users: created $made, already present $have, active total $tot\n";
if($tot < 2){ fwrite(STDERR,"  FAIL: still a single shared account\n"); exit(1); }
