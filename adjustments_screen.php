<?php
/**
 * HOLIDAYS AND EXTENSIONS - the two things that move a statutory due date.
 *
 * Served from /var/www/html/custom/ca/adjustments.php. It is inside the document
 * root, so it MUST boot main.inc.php: without it there is no session, no $user,
 * no rights, no CSRF and no injection scanning - it would be a public,
 * unauthenticated write endpoint on the internet.
 *
 * Both are DATA, not code. The CBDT extends a due date by press release on the
 * morning it expires; a gazetted holiday is announced months ahead. Neither can
 * wait for a deploy, so ca_holiday and ca_extension are edited here and read by
 * ca_falls_on_holiday() and ca_extension_for() during generation.
 *
 * Permissions: hasRight() returns 0 for any module Dolibarr has not activated,
 * so a made-up 'ca' permission would refuse everyone for ever. What these rows
 * move ARE agenda events, so the agenda permissions are the honest gate - and
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

require_once __DIR__.'/ca_calendar_lib.php';
$langs->loadLangs(array("main", "other", "errors"));

// ── access ───────────────────────────────────────────────────────────────────
// An external (customer-portal) user is still a logged-in $user. Without this
// they would pass every check below.
if ($user->socid > 0) accessforbidden();

$permtoread  = (!empty($user->admin) || $user->hasRight('agenda', 'myactions', 'read'));
$permtowrite = (!empty($user->admin) || $user->hasRight('agenda', 'allactions', 'create'));
if (!$permtoread) accessforbidden();

// Create both tables before anything reads or writes them. Idempotent.
ca_cal_ensure_tables($db);

$action = GETPOST('action', 'aZ09', 2);

// Every success says this. A CA who records an extension and sees yesterday's
// dates unchanged will assume the screen did nothing and record it twice.
$TAIL = ' It applies to deadlines generated from now on; deadlines that already exist'
      .' are refreshed by the nightly calendar job.';

/** True when $s is not a real YYYY-MM-DD date. checkdate() is what stops
 *  2026-02-30 - the regex alone accepts it happily. */
function ca_adj_baddate($s)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $s, $m)) return true;
    return !checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** The CSRF assertion and the write permission, together, for every action.
 *  main.inc.php already rejects a missing token and blanks $_POST on a wrong
 *  one - but that protection can be switched off in conf.php by someone who is
 *  not us, and on an invalid token Dolibarr CONTINUES executing the page. */
function ca_adj_guard($permtowrite)
{
    if (empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) GETPOST('token', 'alpha'))) {
        httponly_accessforbidden('Invalid CSRF token', 403);
    }
    // Re-checked HERE, not only at the top of the page.
    if (!$permtowrite) accessforbidden();
}

// ── A: holidays ──────────────────────────────────────────────────────────────
if ($action === 'addholiday') {
    ca_adj_guard($permtowrite);

    $hday  = trim(GETPOST('hday', 'alphanohtml', 2));
    $label = trim(GETPOST('label', 'alphanohtml', 2));

    $errors = array();
    if (ca_adj_baddate($hday)) $errors[] = "'".dol_escape_htmltag($hday)."' is not a real date in YYYY-MM-DD form.";
    if ($label === '') $errors[] = "A holiday needs a name - 'holiday' on its own tells nobody why a date moved.";
    if (mb_strlen($label) > 96) $errors[] = "The holiday name is longer than 96 characters.";

    if (!empty($errors)) {
        setEventMessages(null, $errors, 'errors');
    } else {
        // ca_holiday carries UNIQUE KEY uniq_hday, so a plain INSERT of a date
        // already listed is a hard error. Re-recording the same day with a
        // better name is a correction, not a mistake, so treat it as one.
        $db->begin();
        $ok = true;
        $seen = false;
        $q = $db->query("SELECT rowid FROM ca_holiday WHERE hday='".$db->escape($hday)."'");
        if ($q && $db->num_rows($q) > 0) $seen = true;
        if ($seen) {
            $ok = $db->query("UPDATE ca_holiday SET label='".$db->escape($label)."'"
                           ." WHERE hday='".$db->escape($hday)."'");
        } else {
            $ok = $db->query("INSERT INTO ca_holiday (hday, label) VALUES"
                           ." ('".$db->escape($hday)."', '".$db->escape($label)."')");
        }
        if (!$ok) {
            setEventMessages("The database refused the holiday: ".dol_escape_htmltag($db->lasterror()), null, 'errors');
            $db->rollback();
        } else {
            $db->commit();
            ca_cal_flush();
            setEventMessages(($seen ? 'Holiday updated: ' : 'Holiday recorded: ')
                .dol_escape_htmltag($label).' on '.dol_escape_htmltag($hday).'.'.$TAIL, null, 'mesgs');
        }
    }
    // POST-redirect-GET: a browser refresh must not re-record the holiday.
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action === 'delholiday') {
    ca_adj_guard($permtowrite);

    $rowid = GETPOSTINT('rowid', 2);
    if ($rowid <= 0) {
        setEventMessages("No holiday was identified.", null, 'errors');
    } elseif (!$db->query("DELETE FROM ca_holiday WHERE rowid=".((int) $rowid))) {
        setEventMessages("The database refused the deletion: ".dol_escape_htmltag($db->lasterror()), null, 'errors');
    } else {
        ca_cal_flush();
        setEventMessages("Holiday removed. Due dates will no longer be shifted off that day."
            .$TAIL, null, 'mesgs');
    }
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

// ── B: extensions ────────────────────────────────────────────────────────────
if ($action === 'addextension') {
    ca_adj_guard($permtowrite);

    $like    = trim(GETPOST('filing_like', 'alphanohtml', 2));
    $newdue  = trim(GETPOST('new_due', 'alphanohtml', 2));
    $note    = trim(GETPOST('note', 'alphanohtml', 2));

    $errors = array();
    if ($like === '') $errors[] = "A filing prefix is required - it is what decides which deadlines move.";
    if (mb_strlen($like) > 96) $errors[] = "The filing prefix is longer than 96 characters.";
    if (ca_adj_baddate($newdue)) $errors[] = "'".dol_escape_htmltag($newdue)."' is not a real date in YYYY-MM-DD form.";
    if (mb_strlen($note) > 255) $errors[] = "The note is longer than 255 characters.";

    if (!empty($errors)) {
        setEventMessages(null, $errors, 'errors');
    } else {
        // ca_extension carries UNIQUE KEY uniq_ext on filing_like. A department
        // that extends a date twice in one season is ordinary, so the second
        // notification replaces the first rather than being refused.
        $db->begin();
        $seen = false;
        $q = $db->query("SELECT rowid FROM ca_extension WHERE filing_like='".$db->escape($like)."'");
        if ($q && $db->num_rows($q) > 0) $seen = true;
        if ($seen) {
            $ok = $db->query("UPDATE ca_extension SET"
                ." new_due='".$db->escape($newdue)."',"
                ." note='".$db->escape($note)."',"
                ." fk_user=".((int) $user->id).", datec=NOW()"
                ." WHERE filing_like='".$db->escape($like)."'");
        } else {
            $ok = $db->query("INSERT INTO ca_extension (filing_like, new_due, note, fk_user, datec) VALUES"
                ." ('".$db->escape($like)."', '".$db->escape($newdue)."', '".$db->escape($note)."',"
                ." ".((int) $user->id).", NOW())");
        }
        if (!$ok) {
            setEventMessages("The database refused the extension: ".dol_escape_htmltag($db->lasterror()), null, 'errors');
            $db->rollback();
        } else {
            $db->commit();
            ca_cal_flush();
            setEventMessages(($seen ? 'Extension updated: ' : 'Extension recorded: ')
                .'every filing starting "'.dol_escape_htmltag($like).'" is now due '
                .dol_escape_htmltag($newdue).', for all clients at once.'.$TAIL, null, 'mesgs');
        }
    }
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action === 'delextension') {
    ca_adj_guard($permtowrite);

    $rowid = GETPOSTINT('rowid', 2);
    if ($rowid <= 0) {
        setEventMessages("No extension was identified.", null, 'errors');
    } elseif (!$db->query("DELETE FROM ca_extension WHERE rowid=".((int) $rowid))) {
        setEventMessages("The database refused the deletion: ".dol_escape_htmltag($db->lasterror()), null, 'errors');
    } else {
        ca_cal_flush();
        setEventMessages("Extension removed. Those filings revert to their statutory due date."
            .$TAIL, null, 'mesgs');
    }
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

llxHeader('', 'Holidays and extensions');

$self = $_SERVER["PHP_SELF"];

if (!$permtowrite) {
    print '<div class="warning">You can view these adjustments but not change them. That needs the agenda "create for all" permission.</div>';
}

// ── A: holidays ──────────────────────────────────────────────────────────────
print load_fiche_titre('Gazetted holidays', '', 'object_calendar');
print '<div class="info">A due date that lands on a Sunday, or on a holiday listed here, is moved to the'
     .' is NOT moved. Section 10 of the General Clauses Act 1897 only operates where an act must be'
     .' done in a Court or Office and that office is CLOSED - and the portals are open 24x7, so the'
     .' condition is never met. Relief is granted by NOTIFICATION instead, which is what the'
     .' extensions below are for. A date listed here is flagged on the calendar, never shifted:'
     .' telling you a later date than the statute is how a late fee happens.'
     .' because the GST and income-tax portals accept filings on one.</div>';

if ($permtowrite) {
    print '<form method="POST" action="'.$self.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="addholiday">';
    print '<div>';
    print 'Date <input type="date" name="hday" aria-label="Holiday date" required>';
    print ' Holiday <input type="text" name="label" aria-label="Holiday name" size="36" maxlength="96" required placeholder="e.g. Diwali">';
    print ' <input type="submit" class="button small" value="Add holiday">';
    print '</div>';
    print '</form><br>';
}

$sqlh = "SELECT rowid, hday, label FROM ca_holiday ORDER BY hday";
$resh = $db->query($sqlh);

print '<div class="div-table-responsive">';
print '<table id="ca-holidays" class="tagtable liste">';
print '<tr class="liste_titre"><th class="center">Date</th><th>Holiday</th><th></th></tr>';
$nh = 0;
while ($resh && $o = $db->fetch_object($resh)) {
    $nh++;
    print '<tr class="oddeven">';
    print '<td class="center nowrap">'.dol_escape_htmltag($o->hday).'</td>';
    print '<td>'.dol_escape_htmltag($o->label).'</td>';
    print '<td>';
    if ($permtowrite) {
        print '<form method="POST" action="'.$self.'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="delholiday">';
        print '<input type="hidden" name="rowid" value="'.((int) $o->rowid).'">';
        print '<input type="submit" class="button small" value="Delete">';
        print '</form>';
    }
    print '</td>';
    print '</tr>';
}
if (!$nh) print '<tr class="oddeven"><td colspan="3">No holidays recorded - only Sundays will shift a due date.</td></tr>';
print '</table></div>';

// ── B: extensions ────────────────────────────────────────────────────────────
print '<br>';
print load_fiche_titre('Government extensions', '', 'object_calendar');
print '<div class="info">An extension moves that due date for <b>every client at once</b>, and the'
     .' <b>longest matching prefix wins</b> - so "GSTR-3B Aug 2026" can be extended without touching'
     .' "GSTR-3B Sep 2026".</div>';

if ($permtowrite) {
    print '<form method="POST" action="'.$self.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="addextension">';
    print '<div>';
    print 'Filing prefix <input type="text" name="filing_like" aria-label="Filing label prefix the extension applies to" size="30" maxlength="96" required placeholder="e.g. GSTR-3B Aug 2026">';
    print ' New due date <input type="date" name="new_due" aria-label="New due date granted by the extension" required>';
    print ' Note <input type="text" name="note" aria-label="Note, e.g. the notification reference" size="34" maxlength="255" placeholder="e.g. CBDT press release 12 Sep">';
    print ' <input type="submit" class="button small" value="Add extension">';
    print '</div>';
    print '<div class="opacitymedium">The prefix matches the START of a filing label, so it must be typed as the'
         .' calendar writes it.</div>';
    print '</form><br>';
}

$sqle = "SELECT rowid, filing_like, new_due, note, datec FROM ca_extension ORDER BY datec DESC";
$rese = $db->query($sqle);

print '<div class="div-table-responsive">';
print '<table id="ca-extensions" class="tagtable liste">';
print '<tr class="liste_titre"><th>Filing prefix</th><th class="center">New due date</th><th>Note</th>'
     .'<th class="center">Recorded</th><th></th></tr>';
$ne = 0;
while ($rese && $o = $db->fetch_object($rese)) {
    $ne++;
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($o->filing_like).'</td>';
    print '<td class="center nowrap">'.dol_escape_htmltag($o->new_due).'</td>';
    print '<td>'.dol_escape_htmltag((string) $o->note).'</td>';
    print '<td class="center nowrap">'.dol_print_date($db->jdate($o->datec), 'dayhour').'</td>';
    print '<td>';
    if ($permtowrite) {
        print '<form method="POST" action="'.$self.'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="delextension">';
        print '<input type="hidden" name="rowid" value="'.((int) $o->rowid).'">';
        print '<input type="submit" class="button small" value="Delete">';
        print '</form>';
    }
    print '</td>';
    print '</tr>';
}
if (!$ne) print '<tr class="oddeven"><td colspan="5">No extensions recorded - every due date is the statutory one.</td></tr>';
print '</table></div>';

llxFooter();
$db->close();
