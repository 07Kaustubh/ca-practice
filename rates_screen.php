<?php
/**
 * LATE FEE RATES - the statutory amounts, editable without a deploy.
 *
 * Served from /var/www/html/custom/ca/rates.php. It is inside the document
 * root, so it MUST boot main.inc.php: without it there is no session, no $user,
 * no rights, no CSRF and no injection scanning - it would be a public,
 * unauthenticated write endpoint on the internet.
 *
 * These rates were hardcoded in ca_filing_lib.php, so a Finance Act change meant
 * editing PHP on the server. They now live in ca_rate; ca_rate_defaults() is
 * still the seed and the fallback, so behaviour is unchanged when the table is
 * missing. exposureFor() reads this table for every overdue figure the CA sees.
 *
 * Permissions: hasRight() returns 0 for any module Dolibarr has not activated,
 * so a made-up 'ca' permission would refuse everyone for ever. These amounts
 * price agenda deadlines, so the agenda permissions are the honest gate - and
 * they are what the Practice Staff group actually grants.
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

require_once __DIR__.'/ca_filing_lib.php';
$langs->loadLangs(array("main", "other", "errors"));

// ── access ───────────────────────────────────────────────────────────────────
// An external (customer-portal) user is still a logged-in $user. Without this
// they would pass every check below.
if ($user->socid > 0) accessforbidden();

$permtoread  = (!empty($user->admin) || $user->hasRight('agenda', 'myactions', 'read'));
$permtowrite = (!empty($user->admin) || $user->hasRight('agenda', 'allactions', 'create'));
if (!$permtoread) accessforbidden();

// Create and seed before anything reads or writes it. Idempotent, so this is
// also what installs the table on a system that has never opened this screen.
ca_rate_ensure($db);

$action = GETPOST('action', 'aZ09', 2);

// ── save one rate ────────────────────────────────────────────────────────────
if ($action === 'saverate') {
    // main.inc.php already rejects a missing token and blanks $_POST on a wrong
    // one. Assert it independently anyway: this is a write endpoint, and that
    // protection can be switched off in conf.php by someone who is not us.
    if (empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) GETPOST('token', 'alpha'))) {
        httponly_accessforbidden('Invalid CSRF token', 403);
    }
    // Re-check the permission HERE, not only at the top of the page.
    if (!$permtowrite) accessforbidden();

    $rowid = GETPOSTINT('rowid', 2);
    // Read as text, not with GETPOSTINT: "50.5" through an int filter becomes 50
    // and "abc" becomes 0, and a rate silently reset to zero reports every
    // overdue return as costing nothing.
    $vals  = array(
        'per_day' => trim(GETPOST('per_day', 'alphanohtml', 2)),
        'flat'    => trim(GETPOST('flat', 'alphanohtml', 2)),
        'cap'     => trim(GETPOST('cap', 'alphanohtml', 2)),
    );
    $basis = trim(GETPOST('basis', 'alphanohtml', 2));

    $errors = array();
    if ($rowid <= 0) $errors[] = "No rate row was identified.";
    foreach ($vals as $f => $v) {
        if ($v === '' || !is_numeric($v)) {
            $errors[] = "'".$f."' must be a number - '".dol_escape_htmltag($v)."' is not.";
        } elseif ((float) $v < 0) {
            $errors[] = "'".$f."' cannot be negative.";
        }
    }
    if (mb_strlen($basis) > 255) $errors[] = "The basis note is longer than 255 characters.";

    if (!empty($errors)) {
        setEventMessages(null, $errors, 'errors');
    } else {
        $ok = $db->query("UPDATE ca_rate SET"
            ." per_day=".((float) $vals['per_day']).","
            ." flat=".((float) $vals['flat']).","
            ." cap=".((float) $vals['cap']).","
            ." basis='".$db->escape($basis)."'"
            ." WHERE rowid=".((int) $rowid));
        if (!$ok) {
            setEventMessages("The database refused the change: ".dol_escape_htmltag($db->lasterror()), null, 'errors');
        } else {
            setEventMessages("Rate updated. It applies to every exposure figure from now on;"
                ." amounts already recorded against a filed return are not recalculated.", null, 'mesgs');
        }
    }

    // POST-redirect-GET: a browser refresh must not re-apply the change.
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

llxHeader('', 'Late fee rates');

print load_fiche_titre('Late fee and interest rates', '', 'object_payment');

print '<div class="info">'
     .'These are the amounts used to compute the overdue exposure shown against every return.'
     .' <b>Flat takes precedence over per-day</b> whenever it is non-zero, and <b>cap = 0 means no cap</b>.'
     .' They are current as at FY 2026-27 and <b>must be verified against the statute</b> before they are'
     .' relied on - a Finance Act or a notification can change any of them mid-year.'
     .'</div>';

if (!$permtowrite) {
    print '<div class="warning">You can view these rates but not change them. That needs the agenda "create for all" permission.</div>';
}

// Queried directly rather than through ca_rate_rows(): that helper falls back to
// ca_rate_defaults() when the table is empty, and those rows carry no rowid - it
// would render forms that post rowid=0 and silently update nothing.
$sql = "SELECT rowid, prio, pattern, label, per_day, flat, cap, basis FROM ca_rate ORDER BY prio, rowid";
$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table id="ca-rates" class="tagtable liste">';
print '<tr class="liste_titre">';
print '<th class="right">Prio</th><th>Pattern</th><th>Label</th>';
print '<th class="right">Per day</th><th class="right">Flat</th><th class="right">Cap</th><th>Basis</th><th></th>';
print '</tr>';

$n = 0;
while ($resql && $o = $db->fetch_object($resql)) {
    $n++;
    print '<tr class="oddeven">';
    print '<td class="right">'.((int) $o->prio).'</td>';
    // Pattern is shown but never editable. It is the regex exposureFor() matches
    // on; a broken one stops matching anything and silently reports every
    // overdue return in that family as costing nothing at all.
    print '<td class="nowrap"><span class="opacitymedium" title="read-only: this regex decides which filings the rate applies to">'
         .dol_escape_htmltag($o->pattern).'</span></td>';
    print '<td>'.dol_escape_htmltag($o->label).'</td>';

    if ($permtowrite) {
        // One form per row: a single bulk form would write one row's amounts
        // against another the moment a field was left blank.
        print '<td colspan="5">';
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="saverate">';
        print '<input type="hidden" name="rowid" value="'.((int) $o->rowid).'">';
        print 'per day <input type="text" name="per_day" aria-label="Per-day amount for '.dol_escape_htmltag($o->label).'" size="6" required value="'.dol_escape_htmltag((string) ((float) $o->per_day)).'">';
        print ' flat <input type="text" name="flat" aria-label="Flat amount for '.dol_escape_htmltag($o->label).'" size="7" required value="'.dol_escape_htmltag((string) ((float) $o->flat)).'">';
        print ' cap <input type="text" name="cap" aria-label="Maximum cap for '.dol_escape_htmltag($o->label).'" size="8" required value="'.dol_escape_htmltag((string) ((float) $o->cap)).'">';
        print ' basis <input type="text" name="basis" aria-label="Statutory basis for '.dol_escape_htmltag($o->label).'" size="46" maxlength="255" value="'.dol_escape_htmltag($o->basis).'">';
        print ' <input type="submit" class="button small" aria-label="Save rate for '.dol_escape_htmltag($o->label).'" value="Save">';
        print '</form>';
        print '</td>';
    } else {
        print '<td class="right">'.dol_escape_htmltag((string) ((float) $o->per_day)).'</td>';
        print '<td class="right">'.dol_escape_htmltag((string) ((float) $o->flat)).'</td>';
        print '<td class="right">'.dol_escape_htmltag((float) $o->cap > 0 ? (string) ((float) $o->cap) : 'no cap').'</td>';
        print '<td>'.dol_escape_htmltag($o->basis).'</td>';
        print '<td></td>';
    }
    print '</tr>';
}
if (!$n) print '<tr class="oddeven"><td colspan="8">No rates are recorded - ca_rate_ensure() should have seeded them.</td></tr>';
print '</table></div>';

llxFooter();
$db->close();
