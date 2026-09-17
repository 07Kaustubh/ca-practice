<?php
/**
 * The CA's OWN practice books. Deliberately NOT his clients' ledgers - Dolibarr
 * is one company's accounts, and he already has Tally/Winman for client books.
 *  - fee invoices to clients        (modFacture)
 *  - bank + cash, reconciliation    (modBanque)
 *  - practice expenses              (modFournisseur)
 *  - GST on his fees, TDS u/s 194J  (modTax + extrafield)
 *  - P&L / double entry             (modAccounting)
 */
define('NOSESSION','1');
require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
global $db,$conf,$langs;

$mods = array(
  'modFacture'      => 'customer invoices (his fees)',
  'modBanque'       => 'bank accounts and cash',
  'modFournisseur'  => 'supplier bills (practice expenses)',
  'modTax'          => 'taxes and social charges',
  'modProduct'      => 'service catalogue',
  'modService'      => 'services',
  'modComptabilite' => 'basic bookkeeping / reports',
);
foreach ($mods as $m=>$why) {
  $r = activateModule($m,0);
  $err = (is_array($r)&&!empty($r['errors'])) ? implode(';',$r['errors']) : '';
  printf("  %-16s %-38s %s\n", $m, $why, $err===''?'OK':"ERR $err");
}

// Indian tax defaults for a professional practice
$consts = array(
  'MAIN_INFO_TVAINTRA'          => '',
  'FACTURE_VAT_ALWAYS_ZERO'     => '0',
  'MAIN_MODULE_TAX'             => '1',
  'INVOICE_ADD_ZERO_TOTAL_LINES'=> '0',
  // CA services attract 18% GST
  'MAIN_INFO_DEFAULT_VAT'       => '18',
);
foreach ($consts as $k=>$v) dolibarr_set_const($db,$k,$v,'chaine',0,'',$conf->entity);

// TDS u/s 194J: clients withhold 10% of professional fees. He must track it to
// claim credit - no generic CRM models this and it is real money.
$e = new ExtraFields($db);
$e->addExtraField('tds_194j','TDS deducted u/s 194J (INR)','price',10,10,'facture');
$e->addExtraField('tds_certificate','Form 16A received','boolean',11,3,'facture');
$n=0; $r=$db->query("SELECT name FROM ".MAIN_DB_PREFIX."extrafields WHERE elementtype='facture'");
while($r && $o=$db->fetch_object($r)) $n++;
echo "  invoice extrafields: $n (tds_194j, tds_certificate)\n";

// standard CA service catalogue so invoices are two clicks, not free text
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
$user=new User($db); $user->fetch(1); $user->getrights();
$svcs = array(
  array('SVC-GST-M','GST return filing - monthly (GSTR-1 + 3B)',2500),
  array('SVC-GST-Q','GST return filing - quarterly (QRMP)',4000),
  array('SVC-GSTR9','GST annual return GSTR-9 / 9C',12000),
  array('SVC-TDS-Q','TDS quarterly return (24Q/26Q)',3000),
  array('SVC-ITR','Income tax return filing',7500),
  array('SVC-AUDIT','Tax audit and Form 3CD',35000),
  array('SVC-ROC','ROC annual filing (AOC-4 + MGT-7)',15000),
  array('SVC-RET','Annual retainer - compliance',60000),
  array('SVC-ADV','Advisory / consultation (per hour)',3000),
);
$made=0;
foreach ($svcs as $s) {
  $p=new Product($db);
  if ($p->fetch(0,$s[0])>0) continue;
  $p->ref=$s[0]; $p->label=$s[1]; $p->type=Product::TYPE_SERVICE;
  $p->price=$s[2]; $p->price_base_type='HT'; $p->tva_tx=18; $p->status=1; $p->status_buy=0;
  if ($p->create($user)>0) $made++;
}
echo "  CA service catalogue: $made created (18% GST)\n";

// ── the practice's own bank account ─────────────────────────────────────────
// modBanque was enabled but no account was ever created, so the dashboard's
// balances widget read "No financial accounts recorded" and the funds-management
// half of the brief was, in practice, absent. One current account is the minimum
// that makes the module real; the CA renames it and adds his own.
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
$acc = new Account($db);
$exists = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account WHERE ref='CA-CURRENT'");
if ($exists && $db->num_rows($exists) > 0) {
    echo "  bank account: already present\n";
} else {
    $acc->ref           = 'CA-CURRENT';
    $acc->label         = getenv('CA_FIRM_NAME') ? getenv('CA_FIRM_NAME').' - Current Account' : 'Practice Current Account';
    $acc->courant       = Account::TYPE_CURRENT;
    $acc->type          = Account::TYPE_CURRENT;
    $acc->currency_code = 'INR';
    $acc->country_id    = 102;          // India
    $acc->date_solde    = dol_now();
    $acc->solde         = 0;
    $acc->clos          = 0;
    $acc->status        = 1;
    $r = $acc->create($user);
    echo $r > 0 ? "  bank account: CA-CURRENT created (INR)\n"
                : "  bank account: FAILED - ".$acc->error."\n";
}

// ── the dashboard the CA actually lands on ──────────────────────────────────
// Stock Dolibarr ships exactly the widgets a practice needs; the default set was
// simply the wrong ones. Out: supplier orders, supplier invoices, product stock
// alerts, product distribution, "previous login Unknown" - none of which a CA
// practice has any use for, all of which rendered as empty boxes and noise.
// In: upcoming deadlines, unpaid fees, bank balances, clients.
$want_boxes = array(
  'box_actions_future.php' => 1,   // WHAT IS COMING UP - the reason he opens this
  'box_factures_imp.php'   => 2,   // unpaid fees
  'box_comptes.php'        => 3,   // bank + cash balances
  'box_clients.php'        => 4,   // latest clients
  'box_factures.php'       => 5,   // latest invoices
  'box_graph_invoices_permonth.php' => 6,
);
$db->query("DELETE FROM ".MAIN_DB_PREFIX."boxes WHERE position=0 AND entity=".$conf->entity);
$nb=0;
foreach ($want_boxes as $file=>$ord) {
  $r=$db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."boxes_def WHERE file='".$db->escape($file)."'");
  if ($r && $o=$db->fetch_object($r)) {
    $db->query("INSERT INTO ".MAIN_DB_PREFIX."boxes (box_id,position,box_order,fk_user,entity)
                VALUES (".(int)$o->rowid.",0,".(int)$ord.",0,".$conf->entity.")");
    $nb++;
  }
}
echo "  dashboard: $nb CA-relevant widgets (supplier/product/login noise removed)\n";

