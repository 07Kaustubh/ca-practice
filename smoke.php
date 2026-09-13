<?php
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf;
$u=new User($db);$u->fetch(1);$u->getrights();
$s=new Societe($db);$s->name='__SMOKETEST__';$s->client=1;$s->email='smoke@test.invalid';$sid=$s->create($u);
$a=new ActionComm($db);$a->type_code='AC_OTH';$a->label='__SMOKETEST__ reminder';
$a->datep=dol_now()+7*86400;$a->datef=$a->datep+3600;$a->socid=$sid;
$a->userownerid=$u->id;$a->percentage=-1;$aid=$a->create($u);
$db->query("INSERT INTO ".MAIN_DB_PREFIX."actioncomm_reminder
 (dateremind,typeremind,fk_user,offsetvalue,offsetunit,status,entity,fk_actioncomm)
 VALUES ('".$db->idate(dol_now()-120)."','email',".$u->id.",7,'d',0,".$conf->entity.",".$aid.")");
echo "socid=$sid actionid=$aid\n";
