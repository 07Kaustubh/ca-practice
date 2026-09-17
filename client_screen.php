<?php
/**
 * CLIENT PROFILE - add or edit a client, and get its statutory calendar in the
 * same request.
 *
 * Served from /var/www/html/custom/ca/client.php. It is inside the document
 * root, so it MUST boot main.inc.php: without it there is no session, no $user,
 * no rights, no CSRF and no injection scanning - it would be a public,
 * unauthenticated write endpoint on the internet.
 *
 * Why this screen exists: a client added through Dolibarr's own "New Third
 * Party" form gets ZERO deadlines, silently, because the calendar only ever ran
 * from deploy.sh over SSH. The practice does not find out until a due date is
 * missed. Here the profile and the calendar are written in one action, and the
 * list at the bottom marks every client that still has none.
 *
 * Permissions: hasRight() returns 0 for any module Dolibarr has not activated,
 * so a made-up 'ca' permission would refuse everyone for ever. What this screen
 * generates IS a set of agenda events, so the agenda permissions are the honest
 * gate - and they are what the Practice Staff group actually grants.
 */

// ── Dolibarr bootstrap (core fallback chain; this page sits two levels deep) ──
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php"))       $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php"))    $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once __DIR__.'/ca_calendar_lib.php';
// ca_generate_for_client() creates ActionComm rows on behalf of a User object.
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
$langs->loadLangs(array("main", "other", "errors"));

// ── access ───────────────────────────────────────────────────────────────────
// An external (customer-portal) user is still a logged-in $user. Without this
// they would pass every check below.
if ($user->socid > 0) accessforbidden();

$permtoread  = (!empty($user->admin) || $user->hasRight('agenda', 'myactions', 'read'));
$permtowrite = (!empty($user->admin) || $user->hasRight('agenda', 'allactions', 'create'));
if (!$permtoread) accessforbidden();

$action = GETPOST('action', 'aZ09', 2);
$socid  = GETPOSTINT('socid', 1);   // GET only; 0 or absent means "new client"

// These option strings are not decorative: ca_rules_for() switches on them
// character for character. "QRMP" without " (quarterly)" matches nothing and
// generates no GST deadlines at all, silently - so they are spelled out once
// here, identically to extrafields.php, and anything else is refused on save.
$ENTITY_TYPES = array('Proprietorship', 'Partnership', 'LLP', 'Private Limited',
                      'Public Limited', 'Individual', 'HUF', 'Trust/Society');
$GST_SCHEMES  = array('Not registered', 'Monthly', 'QRMP (quarterly)', 'Composition');
$BILL_CYCLES  = array('Annual', 'Half-yearly', 'Quarterly', 'Monthly', 'Per filing');

$TEXTFIELDS = array('gstin', 'pan', 'tan', 'cin', 'whatsapp_number');
$SELFIELDS  = array('entity_type', 'gst_scheme', 'billing_cycle');
$BOOLFIELDS = array('tax_audit', 'roc_applicable', 'tds_applicable', 'whatsapp_optin');
$UPPERFIELDS = array('gstin', 'pan', 'tan', 'cin');

// Column widths from llx_societe / llx_societe_extrafields. MariaDB runs strict
// by default, so an over-long value is a hard INSERT error, not a truncation -
// better to name the field than to show him a raw driver message.
$MAXLEN = array('name' => 128, 'email' => 128, 'phone' => 30, 'cin' => 21, 'whatsapp_number' => 20);

/**
 * The columns llx_societe_extrafields actually has.
 *
 * extrafields.php creates them, but it keeps gaining fields - tds_applicable is
 * in that script and absent from a database backup taken the same day. Naming a
 * column that does not exist yet fails the whole INSERT, which would leave the
 * CA unable to add any client at all. So we write the intersection: a field that
 * has not been installed is skipped, and everything else still saves.
 */
function ca_cs_efcols($db)
{
    $cols = array();
    $r = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."societe_extrafields");
    while ($r && $o = $db->fetch_object($r)) $cols[$o->Field] = true;
    return $cols;
}

/** A select that re-renders the submitted value after a rejected save. */
function ca_cs_select($name, $opts, $cur, $label = '')
{
    // aria-label: the visible cell to the left is not associated with the control,
    // so a screen reader announces this select with no name at all.
    $h = '<select name="'.dol_escape_htmltag($name).'" aria-label="'.dol_escape_htmltag($label ?: $name).'">';
    $h .= '<option value=""'.((string) $cur === '' ? ' selected' : '').'>-</option>';
    foreach ($opts as $o) {
        $h .= '<option value="'.dol_escape_htmltag($o).'"'.((string) $cur === $o ? ' selected' : '').'>'
             .dol_escape_htmltag($o).'</option>';
    }
    return $h.'</select>';
}

/** Yes/No as an explicit 0/1. A cleared checkbox posts nothing, which is
 *  indistinguishable from a field that was never on the form - and that is how
 *  "tax audit" quietly turns itself off. */
function ca_cs_bool($name, $cur, $label = '')
{
    $h = '<select name="'.dol_escape_htmltag($name).'" aria-label="'.dol_escape_htmltag($label ?: $name).'">';
    foreach (array(0 => 'No', 1 => 'Yes') as $v => $lbl) {
        $h .= '<option value="'.((int) $v).'"'.(((int) $cur) === (int) $v ? ' selected' : '').'>'.$lbl.'</option>';
    }
    return $h.'</select>';
}

$prefill = null;   // set when a save is rejected, so he does not retype the form

// ── save ─────────────────────────────────────────────────────────────────────
if ($action === 'save') {
    // main.inc.php already rejects a missing token and blanks $_POST on a wrong
    // one. Assert it independently anyway: this is a write endpoint, and that
    // protection can be switched off in conf.php by someone who is not us.
    if (empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) GETPOST('token', 'alpha'))) {
        httponly_accessforbidden('Invalid CSRF token', 403);
    }
    // Re-check the permission HERE, not only at the top of the page.
    if (!$permtowrite) accessforbidden();

    // The form carries its own target id. $socid above is GET-only, so a POST
    // that trusted it would read 0 and create a second client on every edit.
    $socid = GETPOSTINT('socid', 2);

    $in = array(
        'name'  => trim(GETPOST('name', 'alphanohtml', 2)),
        'email' => trim(GETPOST('email', 'alphanohtml', 2)),
        'phone' => trim(GETPOST('phone', 'alphanohtml', 2)),
    );
    // PAN/GSTIN/TAN are compared against each other and against what the CSV
    // importer stored, so they are upper-cased before validation AND before
    // storage - otherwise the importer's PAN dedupe misses this client.
    foreach ($TEXTFIELDS as $f) {
        $v = trim(GETPOST($f, 'alphanohtml', 2));
        $in[$f] = in_array($f, $UPPERFIELDS, true) ? strtoupper($v) : $v;
    }
    foreach ($SELFIELDS as $f)  $in[$f] = trim(GETPOST($f, 'alphanohtml', 2));
    foreach ($BOOLFIELDS as $f) $in[$f] = GETPOSTINT($f, 2) ? 1 : 0;
    $in['fee_annual'] = trim(GETPOST('fee_annual', 'alphanohtml', 2));

    // The statutory identifiers, by the same rules the importer applies.
    $errors = ca_validate_profile($in['pan'], $in['gstin'], $in['tan']);
    if ($in['name'] === '') $errors[] = "A client needs a name.";
    // (float) '60,000' is 60 - a silent 99.9% discount on the retainer.
    if ($in['fee_annual'] !== '' && !is_numeric($in['fee_annual'])) {
        $errors[] = "Annual retainer '".dol_escape_htmltag($in['fee_annual'])
                   ."' is not a number - enter digits only, without commas or 'Rs'.";
    }
    // A select value that is not one of the known strings drives an empty
    // calendar rather than an error, so refuse it here instead.
    if ($in['entity_type'] !== '' && !in_array($in['entity_type'], $ENTITY_TYPES, true))
        $errors[] = "'".dol_escape_htmltag($in['entity_type'])."' is not a known entity type.";
    if ($in['gst_scheme'] !== '' && !in_array($in['gst_scheme'], $GST_SCHEMES, true))
        $errors[] = "'".dol_escape_htmltag($in['gst_scheme'])."' is not a known GST scheme.";
    if ($in['billing_cycle'] !== '' && !in_array($in['billing_cycle'], $BILL_CYCLES, true))
        $errors[] = "'".dol_escape_htmltag($in['billing_cycle'])."' is not a known billing cycle.";
    foreach ($MAXLEN as $f => $n) {
        if (isset($in[$f]) && mb_strlen($in[$f]) > $n)
            $errors[] = str_replace('_', ' ', $f)." is longer than {$n} characters.";
    }

    if (!empty($errors)) {
        // EVERY error at once: fixing a PAN only to be told about the GSTIN on
        // the next attempt is how a profile gets abandoned half-entered.
        setEventMessages(null, $errors, 'errors');
        $prefill = $in;   // fall through and re-render with what he typed
    } else {
        $db->begin();
        if ($socid > 0) {
            $ok = $db->query("UPDATE ".MAIN_DB_PREFIX."societe SET"
                ." nom='".$db->escape($in['name'])."',"
                ." email='".$db->escape($in['email'])."',"
                ." phone='".$db->escape($in['phone'])."',"
                ." client=1, fk_user_modif=".((int) $user->id)
                ." WHERE rowid=".((int) $socid));
        } else {
            // status defaults to 1 but is set explicitly: a client created as
            // "closed" would vanish from every one of Dolibarr's own lists.
            $ok = $db->query("INSERT INTO ".MAIN_DB_PREFIX."societe"
                ." (nom, entity, client, status, datec, email, phone, fk_user_creat) VALUES ("
                ."'".$db->escape($in['name'])."', ".((int) $conf->entity).", 1, 1, NOW(), "
                ."'".$db->escape($in['email'])."', '".$db->escape($in['phone'])."', ".((int) $user->id).")");
            if ($ok) $socid = (int) $db->last_insert_id(MAIN_DB_PREFIX."societe");
        }

        if ($ok && $socid > 0) {
            $have = ca_cs_efcols($db);
            $cols = array('fk_object');
            $vals = array((string) ((int) $socid));
            $sets = array();
            foreach (array_merge($TEXTFIELDS, $SELFIELDS, $BOOLFIELDS, array('fee_annual')) as $f) {
                if (empty($have[$f])) continue;   // extrafield not installed here
                if (in_array($f, $BOOLFIELDS, true)) {
                    $v = (string) ((int) $in[$f]);
                } elseif ($f === 'fee_annual') {
                    $v = ($in['fee_annual'] === '' ? 'NULL' : (string) ((float) $in['fee_annual']));
                } else {
                    $v = "'".$db->escape($in[$f])."'";
                }
                $cols[] = $f; $vals[] = $v; $sets[] = $f."=".$v;
            }
            // No-op assignment when extrafields.php has never run: the statement
            // must still be syntactically complete.
            if (empty($sets)) $sets[] = "fk_object=".((int) $socid);
            // One statement, not SELECT-then-INSERT: llx_societe_extrafields
            // carries UNIQUE KEY uk_societe_extrafields(fk_object), so a second
            // browser tab cannot race this into two rows for one client.
            $ok = $db->query("INSERT INTO ".MAIN_DB_PREFIX."societe_extrafields (".implode(', ', $cols).")"
                ." VALUES (".implode(', ', $vals).")"
                ." ON DUPLICATE KEY UPDATE ".implode(', ', $sets));
        }

        if (!$ok) {
            $dberr = $db->lasterror();
            $db->rollback();
            setEventMessages("The database refused the save: ".dol_escape_htmltag($dberr), null, 'errors');
            $prefill = $in;
        } else {
            $db->commit();

            // ── the calendar, in the same request ────────────────────────────
            // Deliberately AFTER the commit. ActionComm::create() opens its own
            // transaction, and a profile that saved correctly must not be rolled
            // back because one deadline failed; generation is idempotent, so the
            // nightly job completes whatever this misses.
            ca_cal_ensure_tables($db);
            // Owner is user 1, exactly as the CLI generator uses. If this screen
            // used the logged-in staff member instead, the nightly run would not
            // recognise these rows and would not match them - the label check is
            // per client, but the reminder rows are per owner.
            $u = new User($db);
            $u->fetch(1);
            $u->getrights();

            $fy = ((int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1);
            $fylabel = 'FY '.$fy.'-'.substr((string) ($fy + 1), 2);
            $rows = ca_client_rows($db, $socid);

            $msg = 'Saved.';
            $style = 'mesgs';
            if (!$rows) {
                $msg .= ' The calendar was NOT generated - the client could not be read back.';
                $style = 'warnings';
            } else {
                $r = ca_generate_for_client($db, $u, $rows[0], $fy, (int) (getenv('CA_REMIND_DAYS') ?: 7));
                // How many the profile implies at all. Without this, "nothing was
                // made and nothing was skipped" reads as "this client owes no
                // filings" when it can equally mean every write failed - and
                // reporting a failure as a clean result is the exact defect this
                // screen was built to remove.
                $expected = count(ca_rules_for($rows[0], $fy));

                if ($expected === 0) {
                    $msg .= ' This profile implies no statutory filings for '.$fylabel
                          .' - no GST registration, no TAN and no ROC obligation. That is a real'
                          .' outcome, not a failure: there is nothing for this client to file.';
                } elseif ($r['made'] === 0 && $r['skipped'] === 0) {
                    $msg .= ' WARNING: '.$expected.' deadline(s) were expected for '.$fylabel
                          .' and none were written. The profile is saved; the calendar is not.'
                          .' Check the Dolibarr agenda module is enabled, then save again.';
                    $style = 'warnings';
                } elseif ($r['made'] === 0) {
                    $msg .= ' No new deadlines - all '.((int) $r['skipped']).' for '.$fylabel
                          .' were already on the calendar'
                          .(!empty($r['moved']) ? ', '.((int) $r['moved']).' of them moved to a new date' : '')
                          .'.';
                } else {
                    $msg .= ' '.((int) $r['made']).' statutory deadline'.($r['made'] == 1 ? '' : 's')
                          .' generated for '.$fylabel
                          .($r['skipped']  ? ', '.((int) $r['skipped']).' already present' : '')
                          .'; '.((int) $r['reminders']).' reminder'.($r['reminders'] == 1 ? '' : 's').' scheduled'
                          .($r['shifted']  ? ', '.((int) $r['shifted']).' shifted off a Sunday or holiday' : '')
                          .($r['extended'] ? ', '.((int) $r['extended']).' moved by a recorded extension' : '')
                          // 'moved' is newer than the rest of this array, and this library has
                          // changed under a running screen before - !empty() so a further change
                          // cannot turn a success message into a PHP notice.
                          .(!empty($r['moved']) ? ', '.((int) $r['moved']).' existing deadline(s) moved to a new date' : '')
                          .'.';
                }
            }
            setEventMessages($msg, null, $style);

            // POST-redirect-GET: a browser refresh must not re-save the profile.
            // socid is carried so he lands back on the client he just saved
            // rather than on an empty "new client" form.
            header("Location: ".$_SERVER["PHP_SELF"]."?socid=".((int) $socid));
            exit;
        }
    }
}

llxHeader('', 'Client profile');

$self = $_SERVER["PHP_SELF"];

// ── what the form should show ────────────────────────────────────────────────
$cur = array('name' => '', 'email' => '', 'phone' => '', 'fee_annual' => '');
foreach (array_merge($TEXTFIELDS, $SELFIELDS) as $f) $cur[$f] = '';
foreach ($BOOLFIELDS as $f) $cur[$f] = 0;

if ($prefill !== null) {
    $cur = $prefill;              // a rejected save - give him back his typing
} elseif ($socid > 0) {
    // e.* rather than a column list, for the same reason the INSERT filters:
    // naming an extrafield that is not installed yet would break the screen.
    $q = $db->query("SELECT s.rowid AS socid, s.nom, s.email, s.phone, e.*"
        ." FROM ".MAIN_DB_PREFIX."societe s"
        ." LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object = s.rowid"
        ." WHERE s.rowid = ".((int) $socid));
    if ($q && $o = $db->fetch_object($q)) {
        $cur['name']  = (string) $o->nom;
        $cur['email'] = (string) $o->email;
        $cur['phone'] = (string) $o->phone;
        foreach (array_merge($TEXTFIELDS, $SELFIELDS) as $f) $cur[$f] = isset($o->$f) ? (string) $o->$f : '';
        foreach ($BOOLFIELDS as $f) $cur[$f] = isset($o->$f) ? (int) $o->$f : 0;
        $cur['fee_annual'] = isset($o->fee_annual) ? (string) ((float) $o->fee_annual) : '';
    } else {
        setEventMessages("Client ".((int) $socid)." does not exist.", null, 'errors');
        $socid = 0;
    }
}

print '<div class="tabsAction">';
if ($socid > 0) print '<a class="butAction" href="'.$self.'">New client</a> ';
else print '<span class="butActionRefused">New client</span> ';
print '</div>';

print load_fiche_titre($socid > 0 ? 'Client profile #'.((int) $socid) : 'New client', '', 'object_company');

if (!$permtowrite) {
    print '<div class="warning">You can view this profile but not save it. That needs the agenda "create for all" permission.</div>';
}

print '<form method="POST" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
print '<div class="div-table-responsive">';
print '<table class="border centpercent">';

print '<tr><td class="titlefield fieldrequired">Name</td><td>'
     .'<input type="text" name="name" aria-label="Client name" size="48" maxlength="128" required value="'.dol_escape_htmltag($cur['name']).'"></td></tr>';
print '<tr><td>Email</td><td>'
     .'<input type="email" name="email" aria-label="Email address" size="48" maxlength="128" value="'.dol_escape_htmltag($cur['email']).'"></td></tr>';
print '<tr><td>Phone</td><td>'
     .'<input type="text" name="phone" aria-label="Phone number" size="24" maxlength="30" value="'.dol_escape_htmltag($cur['phone']).'"></td></tr>';

print '<tr class="liste_titre"><td colspan="2">Statutory identity</td></tr>';
print '<tr><td>GSTIN</td><td>'
     .'<input type="text" name="gstin" aria-label="GSTIN" size="20" maxlength="15" value="'.dol_escape_htmltag($cur['gstin']).'">'
     .' <span class="opacitymedium">15 characters, state code first; it must embed the PAN</span></td></tr>';
print '<tr><td>PAN</td><td>'
     .'<input type="text" name="pan" aria-label="PAN" size="14" maxlength="10" value="'.dol_escape_htmltag($cur['pan']).'">'
     .' <span class="opacitymedium">five letters, four digits, one letter</span></td></tr>';
print '<tr><td>TAN</td><td>'
     .'<input type="text" name="tan" aria-label="TAN" size="14" maxlength="10" value="'.dol_escape_htmltag($cur['tan']).'">'
     .' <span class="opacitymedium">a TAN is what makes this client owe TDS payments and 24Q/26Q returns</span></td></tr>';
print '<tr><td>CIN</td><td>'
     .'<input type="text" name="cin" aria-label="CIN" size="26" maxlength="21" value="'.dol_escape_htmltag($cur['cin']).'"></td></tr>';

print '<tr class="liste_titre"><td colspan="2">Compliance profile - this is what the calendar is derived from</td></tr>';
print '<tr><td>Entity type</td><td>'.ca_cs_select('entity_type', $ENTITY_TYPES, $cur['entity_type'], 'Entity type').'</td></tr>';
print '<tr><td>GST scheme</td><td>'.ca_cs_select('gst_scheme', $GST_SCHEMES, $cur['gst_scheme'], 'GST scheme').'</td></tr>';
print '<tr><td>Tax audit applicable</td><td>'.ca_cs_bool('tax_audit', $cur['tax_audit'], 'Tax audit applicable').'</td></tr>';
print '<tr><td>ROC/MCA filings</td><td>'.ca_cs_bool('roc_applicable', $cur['roc_applicable'], 'ROC/MCA filings').'</td></tr>';
print '<tr><td>Deducts TDS u/s 194J</td><td>'.ca_cs_bool('tds_applicable', $cur['tds_applicable'], 'Deducts TDS under section 194J').'</td></tr>';

print '<tr class="liste_titre"><td colspan="2">Contact and fees</td></tr>';
print '<tr><td>WhatsApp number</td><td>'
     .'<input type="text" name="whatsapp_number" aria-label="WhatsApp number" size="24" maxlength="20" value="'.dol_escape_htmltag($cur['whatsapp_number']).'"></td></tr>';
print '<tr><td>WhatsApp opt-in</td><td>'.ca_cs_bool('whatsapp_optin', $cur['whatsapp_optin'], 'WhatsApp opt-in')
     .' <span class="opacitymedium">no message is sent without this</span></td></tr>';
print '<tr><td>Annual retainer (INR)</td><td>'
     .'<input type="text" name="fee_annual" aria-label="Annual retainer in rupees" size="14" value="'.dol_escape_htmltag($cur['fee_annual']).'">'
     .' <span class="opacitymedium">digits only</span></td></tr>';
print '<tr><td>Billing cycle</td><td>'.ca_cs_select('billing_cycle', $BILL_CYCLES, $cur['billing_cycle'], 'Billing cycle').'</td></tr>';

print '</table></div>';
if ($permtowrite) {
    print '<div class="center"><br><input type="submit" class="button small" value="Save and generate calendar"></div>';
}
print '</form>';

// ── every client, and whether it actually has a calendar ─────────────────────
print '<br>';
print load_fiche_titre('Clients', '', 'object_company');

// No entity filter, deliberately: ca_client_rows() does not apply one either, so
// filtering here would show a client as absent while the nightly job keeps
// generating deadlines for it. The list and the generator must see one thing.
$sql = "SELECT s.rowid, s.nom, COALESCE(e.pan,'') AS pan, COALESCE(e.gst_scheme,'') AS gst_scheme,"
     ." (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."actioncomm a WHERE a.fk_soc = s.rowid) AS deadlines"
     ." FROM ".MAIN_DB_PREFIX."societe s"
     ." LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object = s.rowid"
     ." WHERE s.client = 1"
     ." ORDER BY s.nom ASC";
$resql = $db->query($sql);

$rowsout = array();
$blind = 0;
while ($resql && $o = $db->fetch_object($resql)) {
    $rowsout[] = $o;
    if ((int) $o->deadlines === 0) $blind++;
}
if ($blind > 0) {
    print '<div class="warning">'.((int) $blind).' client(s) below have no statutory deadlines at all.'
         .' Open each one and press Save to generate them.</div>';
}

print '<div class="div-table-responsive">';
print '<table id="ca-clients" class="tagtable liste">';
print '<tr class="liste_titre">';
print '<th class="right">Id</th><th>Client</th><th>PAN</th><th>GST scheme</th><th class="right">Deadlines</th>';
print '</tr>';
foreach ($rowsout as $o) {
    $cnt = (int) $o->deadlines;
    print '<tr class="oddeven">';
    print '<td class="right">'.((int) $o->rowid).'</td>';
    print '<td><a href="'.$self.'?socid='.((int) $o->rowid).'">'.dol_escape_htmltag($o->nom).'</a></td>';
    print '<td>'.dol_escape_htmltag($o->pan !== '' ? $o->pan : '-').'</td>';
    print '<td>'.dol_escape_htmltag($o->gst_scheme !== '' ? $o->gst_scheme : '-').'</td>';
    // The marker is the entire point of this table: a client with no deadlines
    // is the silent failure this screen exists to catch.
    if ($cnt === 0) print '<td class="right"><span class="error">0 - NO CALENDAR</span></td>';
    else print '<td class="right">'.$cnt.'</td>';
    print '</tr>';
}
if (!$rowsout) print '<tr class="oddeven"><td colspan="5">No clients yet.</td></tr>';
print '</table></div>';

llxFooter();
$db->close();
