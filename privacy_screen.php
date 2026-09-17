<?php
/**
 * DPDP RETENTION AND ERASURE - the screen the practice answers a client from.
 *
 * Served from /var/www/html/custom/ca/privacy.php. It is inside the document
 * root, so it MUST boot main.inc.php: without it there is no session, no $user,
 * no rights and no CSRF - and this page can erase personal data.
 *
 * Gated on the agenda permissions, like every other screen here: hasRight()
 * returns 0 for any module Dolibarr has not activated, so an invented 'ca'
 * permission would refuse everyone for ever.
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

require_once __DIR__.'/ca_privacy_lib.php';
$langs->loadLangs(array("main", "other", "errors"));

if ($user->socid > 0) accessforbidden();
$permtoread  = (!empty($user->admin) || $user->hasRight('agenda', 'myactions', 'read'));
$permtowrite = (!empty($user->admin) || $user->hasRight('agenda', 'allactions', 'create'));
if (!$permtoread) accessforbidden();

ca_privacy_ensure_tables($db);
$action = GETPOST('action', 'aZ09', 2);

if ($action !== '') {
    // main.inc.php already rejects a missing token and blanks $_POST on a wrong
    // one. Assert it independently anyway - this page erases personal data.
    if (empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) GETPOST('token', 'alpha'))) {
        httponly_accessforbidden('Invalid CSRF token', 403);
    }
    if (!$permtowrite) accessforbidden();

    if ($action === 'savepolicy') {
        $rowid = GETPOSTINT('rowid', 2);
        $years = GETPOST('years', 'alphanohtml', 2);
        if ($rowid <= 0 || !ctype_digit((string) $years) || (int) $years < 0 || (int) $years > 30) {
            setEventMessages("Retention period must be a whole number of years between 0 and 30.", null, 'errors');
        } else {
            $ok = $db->query("UPDATE ca_retention_policy SET years=".(int) $years." WHERE rowid=".$rowid);
            setEventMessages($ok ? "Retention period updated. It applies to erasure dates computed from now on."
                                 : $db->lasterror(), null, $ok ? 'mesgs' : 'errors');
        }
    } elseif ($action === 'request') {
        $r = ca_request_erasure($db, GETPOSTINT('socid', 2), GETPOST('reason', 'alphanohtml', 2), (int) $user->id);
        setEventMessages($r['msg'], null, $r['ok'] ? 'mesgs' : 'errors');
    } elseif ($action === 'withdraw') {
        $r = ca_withdraw_erasure($db, GETPOSTINT('socid', 2));
        setEventMessages($r['msg'], null, $r['ok'] ? 'mesgs' : 'errors');
    } elseif ($action === 'erase') {
        $r = ca_execute_erasure($db, GETPOSTINT('socid', 2), (int) $user->id);
        setEventMessages($r['msg'], null, $r['ok'] ? 'mesgs' : 'errors');
    }
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

llxHeader('', 'Retention and erasure');

$years = ca_retention_years($db);
print load_fiche_titre('Retention and erasure (DPDP Act 2023)', '', 'object_technic');
print '<div class="opacitymedium" style="max-width:900px;margin-bottom:14px">';
print 'Section 8(7) requires personal data to be erased once consent is withdrawn or the purpose is served, '
    . '<b>unless retention is required by law</b>. A practice is separately required to keep records - so an '
    . 'erasure request here is <b>recorded and held</b> until the longest statutory period expires, and only then '
    . 'carried out. When it is, the personal identifiers go but the filing record (what was filed, for which '
    . 'period, under which acknowledgement) is kept: that is the evidence the same statute requires. '
    . 'The binding period is currently <b>'.((int) $years).' years</b>. '
    . 'These periods are defaults, not legal advice - confirm them with your own counsel.';
print '</div>';

// ── policy ───────────────────────────────────────────────────────────────────
print load_fiche_titre('Retention policy', '', '');
print '<div class="div-table-responsive"><table id="ca-retention" class="tagtable liste">';
print '<tr class="liste_titre"><th>Record type</th><th class="right">Years</th><th>Statutory basis</th><th></th></tr>';
$r = $db->query("SELECT rowid,pkey,label,years,basis FROM ca_retention_policy ORDER BY years DESC, pkey");
while ($r && $o = $db->fetch_object($r)) {
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($o->label).'</td>';
    print '<td class="right">'.((int) $o->years).'</td>';
    print '<td class="opacitymedium">'.dol_escape_htmltag($o->basis).'</td>';
    print '<td>';
    if ($permtowrite) {
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="savepolicy">';
        print '<input type="hidden" name="rowid" value="'.((int) $o->rowid).'">';
        print '<input type="text" name="years" aria-label="Retention period in years for '.dol_escape_htmltag($o->label).'" size="3" value="'.((int) $o->years).'" required>';
        print ' <input type="submit" class="button small" value="Save">';
        print '</form>';
    }
    print '</td></tr>';
}
print '</table></div><br>';

// ── clients ──────────────────────────────────────────────────────────────────
print load_fiche_titre('Clients and when their data may be erased', '', '');
print '<div class="div-table-responsive"><table id="ca-clients-privacy" class="tagtable liste">';
print '<tr class="liste_titre"><th>Client</th><th class="center">Erasable from</th><th>Request</th></tr>';
$r = $db->query("SELECT s.rowid,s.nom,e.status,e.due_at
                   FROM ".MAIN_DB_PREFIX."societe s
                   LEFT JOIN ca_erasure_request e ON e.fk_soc=s.rowid
                  WHERE s.client=1 ORDER BY s.nom LIMIT 200");
while ($r && $o = $db->fetch_object($r)) {
    $exp = ca_retention_expiry($db, $o->rowid);
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($o->nom).'</td>';
    print '<td class="center">'.dol_escape_htmltag($exp === null ? '-' : dol_print_date(strtotime($exp), 'day')).'</td>';
    print '<td>';
    if (!$permtowrite) {
        print dol_escape_htmltag($o->status ?: '');
    } elseif ($o->status === 'done') {
        print '<span class="opacitymedium">erased</span>';
    } elseif ($o->status === 'held' || $o->status === 'due') {
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="withdraw">';
        print '<input type="hidden" name="socid" value="'.((int) $o->rowid).'">';
        print dol_escape_htmltag($o->status === 'due' ? 'due' : 'held until '.$o->due_at).' ';
        print '<input type="submit" class="button small" value="Withdraw">';
        print '</form>';
    } else {
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="request">';
        print '<input type="hidden" name="socid" value="'.((int) $o->rowid).'">';
        print '<input type="text" name="reason" aria-label="Reason for erasure request, '.dol_escape_htmltag($o->nom).'" size="22" placeholder="reason (e.g. consent withdrawn)">';
        print ' <input type="submit" class="button small" value="Request erasure">';
        print '</form>';
    }
    print '</td></tr>';
}
print '</table></div><br>';

// ── requests ─────────────────────────────────────────────────────────────────
print load_fiche_titre('Erasure requests', '', '');
print '<div class="div-table-responsive"><table id="ca-erasures" class="tagtable liste">';
print '<tr class="liste_titre"><th>Client</th><th>Reason</th><th class="center">Requested</th>'
     .'<th class="center">Status</th><th class="center">Erasable from</th><th></th></tr>';
$r = $db->query("SELECT e.fk_soc,e.reason,e.requested_at,e.status,e.due_at,s.nom
                   FROM ca_erasure_request e LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid=e.fk_soc
                  ORDER BY e.requested_at DESC");
$any = 0;
while ($r && $o = $db->fetch_object($r)) {
    $any++;
    $isdue = ($o->status === 'due' || ($o->status === 'held' && $o->due_at !== null && $o->due_at <= date('Y-m-d')));
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($o->nom).'</td>';
    print '<td>'.dol_escape_htmltag((string) $o->reason).'</td>';
    print '<td class="center">'.dol_print_date($db->jdate($o->requested_at), 'day').'</td>';
    print '<td class="center">'.dol_escape_htmltag($o->status).'</td>';
    print '<td class="center">'.dol_escape_htmltag((string) $o->due_at).'</td>';
    print '<td>';
    // No button at all while the statutory hold is running - the guard is in the
    // library too, but an absent button is the clearer statement.
    if ($permtowrite && $isdue && $o->status !== 'done') {
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="erase">';
        print '<input type="hidden" name="socid" value="'.((int) $o->fk_soc).'">';
        print '<input type="submit" class="button small" value="Erase now">';
        print '</form>';
    } elseif ($o->status === 'done') {
        print '<span class="opacitymedium">completed</span>';
    } else {
        print '<span class="opacitymedium">held until '.dol_escape_htmltag((string) $o->due_at).'</span>';
    }
    print '</td></tr>';
}
if (!$any) print '<tr class="oddeven"><td colspan="6">No erasure has been requested.</td></tr>';
print '</table></div><br>';

// ── log ──────────────────────────────────────────────────────────────────────
print load_fiche_titre('Erasure log', '', '');
print '<div class="opacitymedium" style="margin-bottom:8px">A Data Fiduciary must be able to evidence compliance. '
    . 'The client name is stored here only as a one-way hash, so this log proves an erasure happened without '
    . 're-storing the identifier it erased.</div>';
print '<div class="div-table-responsive"><table id="ca-erasure-log" class="tagtable liste">';
print '<tr class="liste_titre"><th class="center">When</th><th>Fields cleared</th><th>Note</th></tr>';
$r = $db->query("SELECT acted_at,fields_cleared,note FROM ca_erasure_log ORDER BY acted_at DESC LIMIT 200");
$anyl = 0;
while ($r && $o = $db->fetch_object($r)) {
    $anyl++;
    print '<tr class="oddeven">';
    print '<td class="center">'.dol_print_date($db->jdate($o->acted_at), 'dayhour').'</td>';
    print '<td>'.dol_escape_htmltag($o->fields_cleared).'</td>';
    print '<td class="opacitymedium">'.dol_escape_htmltag((string) $o->note).'</td>';
    print '</tr>';
}
if (!$anyl) print '<tr class="oddeven"><td colspan="3">Nothing has been erased.</td></tr>';
print '</table></div>';

llxFooter();
$db->close();
