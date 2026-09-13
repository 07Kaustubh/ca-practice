<?php
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf;
$u=new User($db);$u->fetch(1);$u->getrights();
// pick one opted-in client, one not opted-in, and null a third's number
$ids=array();
$res=$db->query("SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe s JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid WHERE e.whatsapp_optin=1 LIMIT 1");
if($o=$db->fetch_object($res)) $ids['optin']=$o->rowid;
$res=$db->query("SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe s JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid WHERE e.whatsapp_optin=0 LIMIT 1");
if($o=$db->fetch_object($res)) $ids['nooptin']=$o->rowid;
$res=$db->query("SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe s JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid WHERE e.whatsapp_optin=1 LIMIT 1 OFFSET 1");
if($o=$db->fetch_object($res)){ $ids['nonum']=$o->rowid;
  $db->query("UPDATE ".MAIN_DB_PREFIX."societe_extrafields SET whatsapp_number='' WHERE fk_object=".$o->rowid); }
foreach($ids as $k=>$sid){
  $a=new ActionComm($db);$a->type_code='AC_OTH';$a->label='GSTR-3B filing due ['.$k.']';
  $a->datep=dol_now()+7*86400;$a->datef=$a->datep+3600;$a->socid=$sid;
  $a->userownerid=$u->id;$a->percentage=-1;$aid=$a->create($u);
  $db->query("INSERT INTO ".MAIN_DB_PREFIX."actioncomm_reminder
   (dateremind,typeremind,fk_user,offsetvalue,offsetunit,status,entity,fk_actioncomm)
   VALUES ('".$db->idate(dol_now()-120)."','email',".$u->id.",7,'d',0,".$conf->entity.",".$aid.")");
  echo "  seeded [$k] socid=$sid\n";
}
