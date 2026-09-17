<?php
/**
 * RECORD A FILING - the screen the CA actually uses.
 *
 * Served from /var/www/html/custom/ca/filings.php. It is inside the document
 * root, so it MUST boot main.inc.php: without it there is no session, no $user,
 * no rights, no CSRF and no injection scanning - it would be a public,
 * unauthenticated write endpoint on the internet.
 *
 * Permissions: hasRight() returns 0 for any module Dolibarr has not activated,
 * so a made-up 'ca' permission would refuse everyone for ever. These filings are
 * agenda events, so the agenda permissions are the honest gate - and they are
 * what the Practice Staff group actually grants.
 *
 * The recording rules live in ca_filing_lib.php, shared with file_return.php.
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

$action = GETPOST('action', 'aZ09', 2);

// ── record ───────────────────────────────────────────────────────────────────
if ($action === 'setfiled') {
    // main.inc.php already rejects a missing token and blanks $_POST on a wrong
    // one. Assert it independently anyway: this is a write endpoint, and that
    // protection can be switched off in conf.php by someone who is not us.
    if (empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) GETPOST('token', 'alpha'))) {
        httponly_accessforbidden('Invalid CSRF token', 403);
    }
    // Re-check the permission HERE, not only at the top of the page.
    if (!$permtowrite) accessforbidden();

    $id  = GETPOSTINT('filing_id', 2);
    // 'alphanohtml' rather than 'aZ09': people paste an ARN straight off the
    // portal with spaces and hyphens, and aZ09 blanks the whole value instead of
    // cleaning it. The lib normalises, then allow-lists against the family's
    // exact shape before any of it reaches SQL.
    $ack = GETPOST('ack', 'alphanohtml', 2);
    $on  = GETPOST('filed_on', 'alphanohtml', 2);

    $r = ca_record_filing($db, $id, $ack, $on, (int) $user->id, false);
    setEventMessages($r['msg'], null, $r['ok'] ? 'mesgs' : 'errors');

    // POST-redirect-GET: a browser refresh must not resubmit the filing.
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

llxHeader('', 'Record a filing');

// Both lists at once overflow a laptop screen, and the page is then too short to
// scroll either to the top - the filed table sat permanently under the fold.
// A CA doing a filing run wants one or the other anyway:
//   ?view=ready  what is waiting on me    ?view=filed  what I have already lodged
$view = GETPOST('view', 'aZ09', 1);
if (!in_array($view, array('ready', 'filed'), true)) $view = 'all';
$self = $_SERVER["PHP_SELF"];
print '<div class="tabsAction">';
foreach (array('all' => 'Both', 'ready' => 'Ready to file', 'filed' => 'Filed') as $k => $lbl) {
    if ($k === $view) print '<span class="butActionRefused">'.dol_escape_htmltag($lbl).'</span> ';
    else print '<a class="butAction" href="'.$self.'?view='.$k.'">'.dol_escape_htmltag($lbl).'</a> ';
}
print '</div>';

if ($view !== 'filed') {
print load_fiche_titre('Returns ready to file', '', 'object_task');

if (!$permtowrite) {
    print '<div class="warning">You can view this list but not record a filing. That needs the agenda "create for all" permission.</div>';
}

// ── ready to file ────────────────────────────────────────────────────────────
$sql = "SELECT f.rowid, f.filing, f.period, f.due, f.late_days, f.exposure_inr, s.nom"
     ." FROM ca_filing f LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc"
     ." WHERE f.status = 'ready' ORDER BY f.due ASC, s.nom ASC";
$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table id="ca-ready" class="tagtable liste">';
print '<tr class="liste_titre">';
print '<th>Client</th><th>Filing</th><th>Period</th><th class="center">Due</th>';
print '<th class="right">Late</th><th class="right">Exposure</th>';
print '<th>Acknowledgement</th><th class="center">Filed on</th><th></th>';
print '</tr>';

$n = 0;
while ($resql && $o = $db->fetch_object($resql)) {
    $n++;
    list($re, $eg) = ca_ack_rule($o->filing);
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($o->nom).'</td>';
    print '<td>'.dol_escape_htmltag($o->filing).'</td>';
    print '<td class="nowrap">'.dol_escape_htmltag(ca_period_label($o->period)).'</td>';
    print '<td class="center">'.dol_print_date($db->jdate($o->due), 'day').'</td>';
    print '<td class="right">'.((int) $o->late_days > 0 ? ((int) $o->late_days).'d' : '-').'</td>';
    print '<td class="right">'.((float) $o->exposure_inr > 0 ? 'Rs '.price((float) $o->exposure_inr, 0, '', 0, 0) : '-').'</td>';
    // one form per row: the CA records them one at a time, as they come back
    // from the portal, and a single bulk form would post the wrong ack number
    // against the wrong return the moment one field is left blank.
    print '<td colspan="3">';
    if ($permtowrite) {
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="setfiled">';
        print '<input type="hidden" name="filing_id" value="'.((int) $o->rowid).'">';
        $who = $o->filing.' for '.$o->nom;
        // aria-label, not a visible <label>: the column header carries the meaning
        // for a sighted user, but a screen reader announces an unlabelled input as
        // "edit text, blank". Naming the ROW makes it unambiguous either way.
        print '<input type="text" name="ack" size="34" aria-label="Acknowledgement number, '.dol_escape_htmltag($who).'" placeholder="'.dol_escape_htmltag($eg).'" title="'.dol_escape_htmltag($eg).'" required>';
        print ' <input type="date" name="filed_on" aria-label="Date filed, '.dol_escape_htmltag($who).'" value="'.dol_escape_htmltag(date('Y-m-d')).'" max="'.dol_escape_htmltag(date('Y-m-d')).'">';
        print ' <input type="submit" class="button small" aria-label="Record as filed, '.dol_escape_htmltag($who).'" value="Record as filed">';
        print '</form>';
    }
    print '</td>';
    print '</tr>';
}
if (!$n) print '<tr class="oddeven"><td colspan="9">Nothing is ready to file - every open return is still waiting on documents.</td></tr>';
print '</table></div>';
}   // end: $view !== 'filed'

// ── recently filed, so he can see what he has already told it ────────────────
if ($view !== 'ready') {
print '<br>';
print load_fiche_titre('Filed in the last 7 days', '', 'object_task');
$sql2 = "SELECT f.filing, f.period, f.arn, f.filed_at, f.late_days, s.nom, u.login"
      ." FROM ca_filing f LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc"
      ." LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = f.filed_by"
      ." WHERE f.status = 'filed' AND f.filed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
      ." ORDER BY f.filed_at DESC, s.nom ASC";
$res2 = $db->query($sql2);
print '<div class="div-table-responsive">';
print '<table id="ca-filed" class="tagtable liste">';
print '<tr class="liste_titre"><th>Client</th><th>Filing</th><th>Period</th>'
     .'<th>Acknowledgement</th><th class="center">Filed</th><th class="right">Late</th><th>By</th></tr>';
$m = 0;
while ($res2 && $o2 = $db->fetch_object($res2)) {
    $m++;
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($o2->nom).'</td>';
    print '<td>'.dol_escape_htmltag($o2->filing).'</td>';
    print '<td class="nowrap">'.dol_escape_htmltag(ca_period_label($o2->period)).'</td>';
    print '<td>'.dol_escape_htmltag($o2->arn).'</td>';
    print '<td class="center">'.dol_print_date($db->jdate($o2->filed_at), 'day').'</td>';
    print '<td class="right">'.((int) $o2->late_days > 0 ? ((int) $o2->late_days).'d' : 'on time').'</td>';
    print '<td>'.dol_escape_htmltag($o2->login).'</td>';
    print '</tr>';
}
if (!$m) print '<tr class="oddeven"><td colspan="7">Nothing recorded in the last 7 days.</td></tr>';
print '</table></div>';
}   // end: $view !== 'ready'

llxFooter();
$db->close();
