<?php
// CSV -> clients. Fails loudly: a partial import that reports success is how a
// CA ends up with 40 of 50 clients and no idea which 10 are missing.
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
global $db,$conf;
$u=new User($db);$u->fetch(1);$u->getrights();
$path='/tmp/clients.csv';
if (!is_readable($path)) { fwrite(STDERR,"  FAIL: $path not readable\n"); exit(1); }
$fh=fopen($path,'r');
$hdr=fgetcsv($fh);
if (!$hdr) { fwrite(STDERR,"  FAIL: empty CSV\n"); exit(1); }
$required=array('name','gstin','pan','email','whatsapp','optin',
                'entity_type','gst_scheme','tan','tax_audit','roc_applicable','fee_annual','billing_cycle',
                'turnover_annual');
$optional=array('tds_applicable');
$missing=array_diff($required,$hdr);
if ($missing) { fwrite(STDERR,"  FAIL: CSV missing columns: ".implode(',',$missing)."\n"); exit(1); }
$ok=0;$fail=0;$skip=0;$errs=array();$line=1;
while(($r=fgetcsv($fh))!==false){
  $line++;
  if (count($r)!==count($hdr)) { $fail++; $errs[]="line $line: column count mismatch"; continue; }
  $row=array_combine($hdr,$r);
  if (trim($row['name'])==='') { $fail++; $errs[]="line $line: blank name"; continue; }
  // Validate the statutory identifiers. A malformed PAN silently imported is a
  // filing that fails months later, and there was no check at all before.
  $pan=strtoupper(trim($row['pan'])); $gst=strtoupper(trim($row['gstin'])); $tan=strtoupper(trim($row['tan']));
  if ($pan!=='' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/',$pan))
    { $fail++; $errs[]="line $line: malformed PAN '$pan'"; continue; }
  if ($gst!=='' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/',$gst))
    { $fail++; $errs[]="line $line: malformed GSTIN '$gst'"; continue; }
  if ($tan!=='' && !preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/',$tan))
    { $fail++; $errs[]="line $line: malformed TAN '$tan'"; continue; }
  if ($gst!=='' && $pan!=='' && substr($gst,2,10)!==$pan)
    { $fail++; $errs[]="line $line: GSTIN '$gst' does not embed PAN '$pan'"; continue; }
  // Dedupe on PAN, which is unique per entity. Name-matching silently merged two
  // different clients that happened to share a trading name.
  if ($pan!=='') {
    $q=$db->query("SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe s
                   JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
                   WHERE e.pan='".$db->escape($pan)."'");
  } else {
    $q=$db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE nom='".$db->escape($row['name'])."'");
  }
  if ($q && $db->num_rows($q)>0) { $skip++; continue; }   // idempotent re-run
  $s=new Societe($db);
  $s->name=$row['name']; $s->client=1; $s->email=$row['email'];
  $s->array_options['options_gstin']=$row['gstin'];
  $s->array_options['options_pan']=$row['pan'];
  $s->array_options['options_whatsapp_number']=$row['whatsapp'];
  $s->array_options['options_whatsapp_optin']=$row['optin'];
  // the compliance profile - this is what compliance_calendar.php derives from
  $s->array_options['options_entity_type']=$row['entity_type'];
  $s->array_options['options_gst_scheme']=$row['gst_scheme'];
  $s->array_options['options_tan']=$row['tan'];
  $s->array_options['options_tax_audit']=$row['tax_audit'];
  $s->array_options['options_roc_applicable']=$row['roc_applicable'];
  $s->array_options['options_fee_annual']=$row['fee_annual'];
  // decides whether GSTR-9 applies at all (exempt up to Rs 2 crore)
  $s->array_options['options_turnover_annual']=isset($row['turnover_annual'])?$row['turnover_annual']:0;
  $s->array_options['options_billing_cycle']=$row['billing_cycle'];
  $s->array_options['options_tds_applicable']=isset($row['tds_applicable'])?$row['tds_applicable']:0;
  $id=$s->create($u);
  if($id>0){$ok++;} else {$fail++; if(count($errs)<5)$errs[]="line $line: ".$s->error;}
}
echo "  imported=$ok  already-present=$skip  FAILED=$fail\n";
foreach($errs as $e) echo "  err: $e\n";
if ($fail>0) { fwrite(STDERR,"  FAIL: $fail row(s) did not import\n"); exit(1); }
