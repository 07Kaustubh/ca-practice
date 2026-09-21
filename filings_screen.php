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

// Keep the view and any client filter across the POST-redirect-GET, so he lands
// back on the list he was working, not at the top of a different one.
$backview = GETPOST('view', 'aZ09', 3);
$backsoc  = GETPOSTINT('socid', 3);
$back = $_SERVER["PHP_SELF"]
      .'?view='.(in_array($backview, array('ready', 'filed', 'all'), true) ? $backview : 'all')
      .($backsoc > 0 ? '&socid='.$backsoc : '');

// ── the four things he can do to a row ───────────────────────────────────────────
if (in_array($action, array('setfiled', 'gotdocs', 'addtask', 'donetask', 'droprow'), true)) {
    // main.inc.php already rejects a missing token and blanks $_POST on a wrong
    // one. Assert it independently anyway: these are write endpoints, and that
    // protection can be switched off in conf.php by someone who is not us.
    if (empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], (string) GETPOST('token', 'alpha'))) {
        httponly_accessforbidden('Invalid CSRF token', 403);
    }
    // Re-check the permission HERE, not only at the top of the page.
    if (!$permtowrite) accessforbidden();

    $id = GETPOSTINT('filing_id', 2);

    if ($action === 'gotdocs') {
        // One click, no typing. See ca_mark_documents_received() for why this verb
        // has to exist at all: without it the client kept being chased on WhatsApp
        // for documents he had already sent.
        $r = ca_mark_documents_received($db, $id, (int) $user->id);
    } elseif ($action === 'addtask') {
        // The list was generated-only, so a one-off job had nowhere to live and he
        // kept a second list elsewhere. Client is optional: plenty of practice work
        // belongs to nobody in particular.
        $r = ca_add_task($db, GETPOSTINT('task_socid', 2),
                         GETPOST('task_what', 'alphanohtml', 2),
                         GETPOST('task_due', 'alphanohtml', 2),
                         (int) $user->id);
    } elseif ($action === 'donetask') {
        $r = ca_complete_task($db, $id, (int) $user->id);
    } elseif ($action === 'droprow') {
        // Deletes a one-off task; a statutory return is marked not-applicable with
        // a reason instead. See ca_drop_filing() - same button, honest difference.
        $r = ca_drop_filing($db, $id, GETPOST('why', 'alphanohtml', 2), (int) $user->id);
    } else {
        // 'alphanohtml' rather than 'aZ09': people paste an ARN straight off the
        // portal with spaces and hyphens, and aZ09 blanks the whole value instead
        // of cleaning it. The lib normalises, then allow-lists against the family's
        // exact shape before any of it reaches SQL.
        $ack = GETPOST('ack', 'alphanohtml', 2);
        $on  = GETPOST('filed_on', 'alphanohtml', 2);
        $r = ca_record_filing($db, $id, $ack, $on, (int) $user->id, false);
    }
    setEventMessages($r['msg'], null, $r['ok'] ? 'mesgs' : 'errors');

    // POST-redirect-GET: a browser refresh must not resubmit.
    header("Location: ".$back);
    exit;
}

llxHeader('', 'Record a filing');

// Both lists at once overflow a laptop screen, and the page is then too short to
// scroll either to the top - the filed table sat permanently under the fold.
// A CA doing a filing run wants one or the other anyway:
//   ?view=ready  what is waiting on me    ?view=filed  what I have already lodged
// What he actually asks is "what do I have to file" and "what have I filed".
// 'ready' used to mean a SYSTEM state - every document in - and this screen showed
// only that, so a return still waiting on documents was invisible and could never
// be recorded. Worse, nothing anywhere let him say the documents had arrived, so
// a filing could not reach 'ready' on its own and the list emptied permanently.
// The list is now everything DUE. Documents are a column, not a lock on the row.
$view = GETPOST('view', 'aZ09', 1);
if (!in_array($view, array('ready', 'filed'), true)) $view = 'all';
$socid = GETPOSTINT('socid', 1);
$DUEDAYS = 30;   // one filing cycle; the whole pending list is ~800 rows and unusable

$self = $_SERVER["PHP_SELF"];
$qs   = ($socid > 0 ? '&socid='.$socid : '');
print '<div class="tabsAction">';
foreach (array('all' => 'Both', 'ready' => 'To file', 'filed' => 'Filed') as $k => $lbl) {
    if ($k === $view) print '<span class="butActionRefused">'.dol_escape_htmltag($lbl).'</span> ';
    else print '<a class="butAction" href="'.$self.'?view='.$k.$qs.'">'.dol_escape_htmltag($lbl).'</a> ';
}
// The daily phone call is "did you file my GSTR?". Answering it meant scanning a
// register-wide list, so every client name below is a filter into their own history.
if ($socid > 0) {
    $nm = '';
    $rs = $db->query("SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid=".$socid);
    if ($rs && $os = $db->fetch_object($rs)) $nm = $os->nom;
    print '<a class="butAction" href="'.$self.'?view='.$view.'">Show all clients</a> ';
    print '<span class="opacitymedium" style="margin-left:10px">showing only '.dol_escape_htmltag($nm).'</span>';
}
print '</div>';

if ($view !== 'filed') {
print load_fiche_titre('Returns to file'.($socid > 0 ? '' : ' - next '.$DUEDAYS.' days'), '', 'object_task');

if (!$permtowrite) {
    print '<div class="warning">You can view this list but not record a filing. That needs the agenda "create for all" permission.</div>';
}

// ── add a one-off task ───────────────────────────────────────────────────
// Three fields on the same screen as the list, not a separate page. Everything
// here was DERIVED from a client profile, so work no statute implies - collect a
// Form 16, answer a notice, renew a DSC - had nowhere to go and lived on paper.
if ($permtowrite) {
    print '<form method="POST" action="'.$self.'" style="margin:6px 0 14px 0">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="addtask">';
    print '<input type="hidden" name="view" value="'.dol_escape_htmltag($view).'">';
    print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
    print '<span class="opacitymedium">One-off task:</span> ';
    print '<input type="text" name="task_what" size="40" maxlength="80" required
           aria-label="What the one-off task is" placeholder="e.g. collect Form 16 from the client"> ';
    print '<select name="task_socid" aria-label="Which client the task is for">';
    print '<option value="0">(no particular client)</option>';
    $cl = $db->query("SELECT rowid,nom FROM ".MAIN_DB_PREFIX."societe WHERE client=1 ORDER BY nom");
    while ($cl && $oc = $db->fetch_object($cl)) {
        print '<option value="'.((int) $oc->rowid).'"'.($socid == $oc->rowid ? ' selected' : '').'>'
             .dol_escape_htmltag($oc->nom).'</option>';
    }
    print '</select> ';
    print '<input type="date" name="task_due" required aria-label="When the task is due"
           value="'.dol_escape_htmltag(date('Y-m-d', strtotime('+7 days'))).'"> ';
    print '<input type="submit" class="button small" value="Add task">';
    print '</form>';
}

// ── what is due ──────────────────────────────────────────────────────────────
// status IN ('pending','ready'): a return whose documents are still outstanding
// is still his to file, and he is the one who knows whether he has them.
$sql = "SELECT f.rowid, f.filing, f.period, f.due, f.late_days, f.exposure_inr, f.fk_soc, s.nom,"
     ." (SELECT GROUP_CONCAT(d.doc_type ORDER BY d.doc_type SEPARATOR ', ')"
     ."    FROM ca_docrequest d"
     ."   WHERE d.fk_actioncomm = f.fk_actioncomm AND d.status='pending') AS waiting"
     ." FROM ca_filing f LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc"
     ." WHERE f.status IN ('pending','ready')";
// A client filter is a question about THAT client, so the horizon does not apply -
// he wants their whole open position, not the next month of it.
if ($socid > 0) $sql .= " AND f.fk_soc = ".$socid;
else            $sql .= " AND f.due <= DATE_ADD(CURDATE(), INTERVAL ".((int) $DUEDAYS)." DAY)";
$sql .= " ORDER BY f.due ASC, s.nom ASC";
$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table id="ca-ready" class="tagtable liste">';
print '<tr class="liste_titre">';
print '<th>Client</th><th>Filing</th><th>Period</th><th class="center">Due</th>';
print '<th class="right">Late</th><th class="right">Exposure</th>';
print '<th>Waiting on</th><th>Record it</th><th></th>';
print '</tr>';

$n = 0;
while ($resql && $o = $db->fetch_object($resql)) {
    $n++;
    $isTask = ca_is_task($o->filing);
    list($re, $eg) = ca_ack_rule($o->filing);
    // A one-off task has no client and no portal, so 'for <client>' reads oddly.
    $who = $o->filing.($o->nom !== null && $o->nom !== '' ? ' for '.$o->nom : '');
    print '<tr class="oddeven">';
    print '<td>'.($o->fk_soc > 0
         ? '<a href="'.$self.'?view='.$view.'&socid='.((int) $o->fk_soc).'" title="Only this client">'
           .dol_escape_htmltag($o->nom).'</a>'
         : '<span class="opacitymedium">the practice</span>').'</td>';
    print '<td>'.dol_escape_htmltag($isTask ? substr($o->filing, strlen(CA_TASK_PREFIX)) : $o->filing)
         .($isTask ? ' <span class="opacitymedium">(task)</span>' : '').'</td>';
    print '<td class="nowrap">'.dol_escape_htmltag(ca_period_label($o->period)).'</td>';
    print '<td class="center">'.dol_print_date($db->jdate($o->due), 'day').'</td>';
    print '<td class="right">'.((int) $o->late_days > 0 ? ((int) $o->late_days).'d' : '-').'</td>';
    print '<td class="right">'.((float) $o->exposure_inr > 0 ? 'Rs '.price((float) $o->exposure_inr, 0, '', 0, 0) : '-').'</td>';

    // Waiting on: context, never a gate. If the documents are sitting in his
    // inbox, one click says so - and that is what stops the client being chased.
    print '<td>';
    if ($isTask) {
        // A one-off task has no statutory document list to wait on.
        print '<span class="opacitymedium">-</span>';
    } elseif (!empty($o->waiting)) {
        print '<span class="opacitymedium">'.dol_escape_htmltag($o->waiting).'</span>';
        if ($permtowrite) {
            print ' <form method="POST" action="'.$self.'" style="display:inline">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="gotdocs">';
            print '<input type="hidden" name="view" value="'.dol_escape_htmltag($view).'">';
            print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
            print '<input type="hidden" name="filing_id" value="'.((int) $o->rowid).'">';
            print '<input type="submit" class="button small" aria-label="Got the documents, '.dol_escape_htmltag($who).'" value="Got them">';
            print '</form>';
        }
    } else {
        print '<span class="opacitymedium">all in</span>';
    }
    print '</td>';

    // one form per row: the CA records them one at a time, as they come back
    // from the portal, and a single bulk form would post the wrong ack number
    // against the wrong return the moment one field is left blank.
    print '<td>';
    if ($permtowrite) {
        print '<form method="POST" action="'.$self.'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="'.($isTask ? 'donetask' : 'setfiled').'">';
        print '<input type="hidden" name="view" value="'.dol_escape_htmltag($view).'">';
        print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
        print '<input type="hidden" name="filing_id" value="'.((int) $o->rowid).'">';
        if ($isTask) {
            // No acknowledgement number: there is no portal behind a one-off task,
            // and demanding one would make his own to-do list harder than the
            // statutory work it sits beside.
            print '<input type="submit" class="button small" aria-label="Mark done, '.dol_escape_htmltag($who).'" value="Done">';
        } else {
            // aria-label, not a visible <label>: the column header carries the meaning
            // for a sighted user, but a screen reader announces an unlabelled input as
            // "edit text, blank". Naming the ROW makes it unambiguous either way.
            print '<input type="text" name="ack" size="34" aria-label="Acknowledgement number, '.dol_escape_htmltag($who).'" placeholder="'.dol_escape_htmltag($eg).'" title="'.dol_escape_htmltag($eg).'" required>';
            print ' <input type="date" name="filed_on" aria-label="Date filed, '.dol_escape_htmltag($who).'" value="'.dol_escape_htmltag(date('Y-m-d')).'" max="'.dol_escape_htmltag(date('Y-m-d')).'">';
            print ' <input type="submit" class="button small" aria-label="Record as filed, '.dol_escape_htmltag($who).'" value="Record as filed">';
        }
        print '</form>';
    }
    print '</td>';

    // Remove a row he does not owe. A task is his, so it goes. A statutory return
    // is not - it is marked not-applicable WITH A REASON and kept, because
    // deleting an inconvenient GSTR-3B is the exact failure this product prevents.
    print '<td class="nowrap">';
    if ($permtowrite) {
        print '<form method="POST" action="'.$self.'" style="display:inline">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="droprow">';
        print '<input type="hidden" name="view" value="'.dol_escape_htmltag($view).'">';
        print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
        print '<input type="hidden" name="filing_id" value="'.((int) $o->rowid).'">';
        if ($isTask) {
            print '<input type="submit" class="button small" aria-label="Remove task, '.dol_escape_htmltag($who).'" value="Remove">';
        } else {
            print '<input type="text" name="why" size="16" aria-label="Why '.dol_escape_htmltag($who).' does not apply" placeholder="why not applicable">';
            print ' <input type="submit" class="button small" aria-label="Mark not applicable, '.dol_escape_htmltag($who).'" value="N/A">';
        }
        print '</form>';
    }
    print '</td>';
    print '</tr>';
}
if (!$n) print '<tr class="oddeven"><td colspan="9">'
    .($socid > 0 ? 'Nothing outstanding for this client.' : 'Nothing due in the next '.$DUEDAYS.' days.')
    .'</td></tr>';
print '</table></div>';
}   // end: $view !== 'filed'

// ── recently filed, so he can see what he has already told it ────────────────
if ($view !== 'ready') {
print '<br>';
// Filtered to one client, the 7-day window is the wrong question: he is on the
// phone being asked about a return from March. Show that client's whole history.
print load_fiche_titre($socid > 0 ? 'Everything filed for this client' : 'Filed in the last 7 days', '', 'object_task');
$sql2 = "SELECT f.filing, f.period, f.arn, f.filed_at, f.late_days, s.nom, u.login"
      ." FROM ca_filing f LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc"
      ." LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = f.filed_by"
      ." WHERE f.status = 'filed'";
if ($socid > 0) $sql2 .= " AND f.fk_soc = ".$socid;
else            $sql2 .= " AND f.filed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
$sql2 .= " ORDER BY f.filed_at DESC, s.nom ASC";
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
if (!$m) print '<tr class="oddeven"><td colspan="7">'
    .($socid > 0 ? 'Nothing has been recorded as filed for this client yet.' : 'Nothing recorded in the last 7 days.')
    .'</td></tr>';
print '</table></div>';
}   // end: $view !== 'ready'

llxFooter();
$db->close();
