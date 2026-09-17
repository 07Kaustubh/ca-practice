<?php
/**
 * STATUTORY CALENDAR - shared rules.
 *
 * These rules used to live inside compliance_calendar.php, which only ever ran
 * from deploy.sh over SSH. A client added through Dolibarr's own "New Third
 * Party" form therefore got ZERO deadlines, zero document requests, zero
 * reminders - silently, with no error. The practice would not find out until a
 * due date was missed.
 *
 * Extracted here so the CLI, the cron job and the client screen all generate the
 * same calendar from the same code, and so adding a client anywhere produces one.
 *
 * Takes $db as a parameter and bootstraps nothing: safe to include from a CLI
 * script (master.inc.php) or a web page (main.inc.php) alike.
 */

require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

// Rs 2 crore. Re-notified annually under the proviso to CGST s.44 - if a future
// notification changes it, change it HERE, and check that year's notification.
if (!defined('CA_GSTR9_THRESHOLD')) define('CA_GSTR9_THRESHOLD', 20000000);

/** Holidays and government extensions are DATA, not code - a CA must be able to
 *  record an extension the morning the CBDT announces it, without a deploy. */
function ca_cal_ensure_tables($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS ca_holiday (
      rowid INT AUTO_INCREMENT PRIMARY KEY,
      hday DATE NOT NULL,
      label VARCHAR(96) NOT NULL,
      UNIQUE KEY uniq_hday (hday)) ENGINE=innodb");

    // A government extension moves a due date for everyone at once. Matching is
    // on the filing label prefix so one row covers every client.
    $db->query("CREATE TABLE IF NOT EXISTS ca_extension (
      rowid INT AUTO_INCREMENT PRIMARY KEY,
      filing_like VARCHAR(96) NOT NULL,
      new_due DATE NOT NULL,
      note VARCHAR(255) NULL,
      fk_user INT NULL,
      datec DATETIME NOT NULL,
      UNIQUE KEY uniq_ext (filing_like)) ENGINE=innodb");
}

function d($y, $m, $dd) { return dol_mktime(10, 0, 0, $m, $dd, $y); }

/**
 * Every statutory deadline implied by one client's profile.
 * Pure: no database, no side effects - so it can be unit-checked directly.
 *
 * @return array of array(label, timestamp)
 */
/**
 * Which day QRMP GSTR-3B falls on for this client: 22nd or 24th.
 *
 * Rule 61(1)(ii), as substituted by Notification 82/2020-CT, splits States and UTs
 * into two groups by the principal place of business. The GSTIN encodes that State
 * in its first two digits, so the split needs no new field.
 *
 * Category X (22nd) is South + West plus their island UTs; Category Y (24th) is
 * North + East + Centre-North plus Delhi, Chandigarh, J&K and Ladakh. The list is
 * encoded, not the mnemonic. Two traps: code 25 (Daman & Diu) was RETIRED on the
 * 2020 merger into code 26, and Ladakh (38) is Category Y, not X.
 *
 * Unknown or absent GSTIN falls back to the 22nd - the EARLIER date. Telling a CA
 * a date earlier than the statute is harmless; later is what causes a late fee.
 */
function ca_qrmp_3b_day($c)
{
    $X = array('22','23','24','26','27','28','29','30','31','32','33','34','35','36','37');
    $g = isset($c['gstin']) ? trim((string) $c['gstin']) : '';
    if (strlen($g) < 2 || !ctype_digit(substr($g, 0, 2))) return 22;
    return in_array(substr($g, 0, 2), $X, true) ? 22 : 24;
}

function ca_rules_for($c, $fy)
{
    // Indian convention is "FY 2026-27". Bare "FY2026" reads as 2025-26 and makes
    // every annual due date look a year wrong to anyone who files returns.
    $FY = $fy."-".substr((string) ($fy + 1), 2);
    $o = array(); $months = array();
    for ($i = 0; $i < 12; $i++) { $m = (($i + 3) % 12) + 1; $months[] = array($fy + ($m >= 4 ? 0 : 1), $m); }
    // Quarter labels must carry the calendar year: Jan-Mar of FY 2026-27 is 2027.
    $q = array(array(6, 'Apr-Jun '.$fy), array(9, 'Jul-Sep '.$fy),
               array(12, 'Oct-Dec '.$fy), array(3, 'Jan-Mar '.($fy + 1)));

    if ($c['gst_scheme'] === 'Monthly') {
        foreach ($months as $ym) { list($y, $m) = $ym; $ny = $m == 12 ? $y + 1 : $y; $nm = $m == 12 ? 1 : $m + 1;
            $tag = date('M Y', mktime(0, 0, 0, $m, 1, $y));
            $o[] = array("GSTR-1 $tag", d($ny, $nm, 11)); $o[] = array("GSTR-3B $tag", d($ny, $nm, 20)); }
    } elseif ($c['gst_scheme'] === 'QRMP (quarterly)') {
        // Rule 61(1)(ii) splits QRMP GSTR-3B by the State of the principal place of
        // business: Category X on the 22nd, Category Y on the 24th. The State is the
        // first two digits of the GSTIN, so no extra field is needed.
        $day3b = ca_qrmp_3b_day($c);
        foreach ($q as $qq) { $qm = $qq[0]; $qy = $fy + ($qm >= 4 ? 0 : 1); $ny = $qm == 12 ? $qy + 1 : $qy; $nm = $qm == 12 ? 1 : $qm + 1;
            $o[] = array("GSTR-1 (QRMP) {$qq[1]}", d($ny, $nm, 13)); $o[] = array("GSTR-3B (QRMP) {$qq[1]}", d($ny, $nm, $day3b)); }
        // PMT-06: a QRMP client owes tax MONTHLY for months 1 and 2 of each quarter,
        // due the 25th. This was not modelled at all, so those clients silently
        // accrued s.50 interest with nothing on their calendar to prevent it.
        foreach ($months as $ym) { list($y, $m) = $ym;
            if (in_array($m, array(6, 9, 12, 3), true)) continue;   // month 3 rides on the quarterly 3B
            $ny = $m == 12 ? $y + 1 : $y; $nm = $m == 12 ? 1 : $m + 1;
            $o[] = array("PMT-06 ".date('M Y', mktime(0, 0, 0, $m, 1, $y)), d($ny, $nm, 25)); }
    } elseif ($c['gst_scheme'] === 'Composition') {
        foreach ($q as $qq) { $qm = $qq[0]; $qy = $fy + ($qm >= 4 ? 0 : 1); $ny = $qm == 12 ? $qy + 1 : $qy; $nm = $qm == 12 ? 1 : $qm + 1;
            $o[] = array("CMP-08 {$qq[1]}", d($ny, $nm, 18)); }
        $o[] = array("GSTR-4 annual FY{$FY}", d($fy + 1, 6, 30));
    }
    // GSTR-9 is NOT universal. CGST s.44 read with the annual exemption
    // notification exempts aggregate turnover up to Rs 2 crore, and that
    // exemption is re-notified EVERY year - so the threshold is a parameter, not
    // a constant. Emitting it for every registered client invented an obligation
    // for small clients; emitting it for nobody would miss a real one. Emit only
    // when turnover is KNOWN and above the threshold. Unknown turnover is a
    // data-quality gap the morning email reports, not a silent guess.
    if ($c['gst_scheme'] !== '' && $c['gst_scheme'] !== 'Not registered'
        && (float) (isset($c['turnover_annual']) ? $c['turnover_annual'] : 0) > CA_GSTR9_THRESHOLD)
        $o[] = array("GSTR-9 annual return FY{$FY}", d($fy + 1, 12, 31));

    if (!empty($c['tan'])) {
        foreach ($months as $ym) { list($y, $m) = $ym; if ($m == 3) continue;
            $ny = $m == 12 ? $y + 1 : $y; $nm = $m == 12 ? 1 : $m + 1;
            $o[] = array("TDS payment ".date('M Y', mktime(0, 0, 0, $m, 1, $y)), d($ny, $nm, 7)); }
        // March of FY 2026-27 is March 2027 - the label previously said the FY start
        // year while the due date used the correct calendar year, so every March TDS
        // row read as a year late to anyone who files them.
        $o[] = array("TDS payment Mar ".($fy + 1), d($fy + 1, 4, 30));
        $o[] = array("TDS return 26Q/24Q Q1 FY{$FY}", d($fy, 7, 31));
        $o[] = array("TDS return 26Q/24Q Q2 FY{$FY}", d($fy, 10, 31));
        $o[] = array("TDS return 26Q/24Q Q3 FY{$FY}", d($fy + 1, 1, 31));
        $o[] = array("TDS return 26Q/24Q Q4 FY{$FY}", d($fy + 1, 5, 31));
    }

    // A company's accounts are audited under the Companies Act whatever the 44AB
    // flag says, and s.263(1)(c) Row 2 gives a company 31 October unconditionally.
    // Branching only on the flag gave a Private Limited 31 July and NO audit report.
    $isCompany = in_array($c['entity_type'], array('Private Limited', 'Public Limited'), true);
    $audit = !empty($c['tax_audit']) || $isCompany;
    if ($c['entity_type'] !== 'Individual' || $audit) {
        $o[] = array("Advance tax 15% FY{$FY}", d($fy, 6, 15));
        $o[] = array("Advance tax 45% FY{$FY}", d($fy, 9, 15));
        $o[] = array("Advance tax 75% FY{$FY}", d($fy, 12, 15));
        $o[] = array("Advance tax 100% FY{$FY}", d($fy + 1, 3, 15));
    }
    if ($audit) { $o[] = array("Tax audit 3CA/3CD FY{$FY}", d($fy + 1, 9, 30));
                  $o[] = array("ITR filing (audit) FY{$FY}", d($fy + 1, 10, 31)); }
    else {
        // Finance Act 2026 substituted Explanation 2 w.e.f. 01.03.2026 and moved
        // non-audit BUSINESS and PROFESSION assessees from 31 July to 31 August.
        // A GST registration or a TAN is the evidence of business we actually hold.
        $hasBusiness = ($c['gst_scheme'] !== '' && $c['gst_scheme'] !== 'Not registered')
                        || !empty($c['tan']);
        $o[] = array("ITR filing FY{$FY}", d($fy + 1, $hasBusiness ? 8 : 7, 31));
    }

    if (!empty($c['roc_applicable'])) {
        if ($c['entity_type'] === 'LLP') {
            $o[] = array("LLP Form 11 FY{$FY}", d($fy + 1, 5, 30));
            $o[] = array("LLP Form 8 FY{$FY}", d($fy + 1, 10, 30));
        } else {
            $o[] = array("AOC-4 FY{$FY}", d($fy + 1, 10, 30));
            $o[] = array("MGT-7 FY{$FY}", d($fy + 1, 11, 29));
        }
        // DIR-3 KYC for FY 2026-27 falls due 30 Sep 2027, not 30 Sep 2026. The old
        // rule emitted the PREVIOUS year's deadline against the current FY.
        $o[] = array("DIR-3 KYC FY{$FY}", d($fy + 1, 9, 30));
    }
    return $o;
}

/**
 * Does this due date land on a Sunday or a recorded holiday? ADVISORY ONLY.
 *
 * THIS DELIBERATELY DOES NOT MOVE THE DATE, AND AN EARLIER VERSION OF THIS FILE
 * WAS WRONG TO DO SO.
 *
 * Section 10 of the General Clauses Act 1897 only operates where an act must be
 * done "in any Court or Office" and that office is CLOSED. The GST and income-tax
 * portals are open 24x7, so the triggering condition is never met and the due date
 * does not move. GST has its own machinery for relief - s.37(4), s.39(6), s.168A -
 * and CBIC issues a NOTIFICATION when it intends to grant it. That is what
 * ca_extension is for.
 *
 * The direction of the error is what makes this serious. Telling a CA a date
 * EARLIER than the statute is harmless. Telling a LATER one means he files late
 * and pays the fee this product exists to prevent. Shifting a Sunday due date to
 * Monday did exactly that, on 163 deadlines.
 *
 * So: report it, flag it, never move it.
 */
function ca_falls_on_holiday($db, $ts, &$why = null)
{
    static $hol = null;
    if ($hol === null) {
        $hol = array();
        $r = $db->query("SELECT hday,label FROM ca_holiday");
        while ($r && $o = $db->fetch_object($r)) $hol[$o->hday] = $o->label;
    }
    $day = date('Y-m-d', $ts);
    if (date('N', $ts) == 7) { $why = 'Sunday'; return true; }
    if (isset($hol[$day]))   { $why = $hol[$day]; return true; }
    return false;
}

/**
 * A government extension overrides the statutory date for every client at once.
 * Longest matching prefix wins, so "GSTR-3B Aug 2026" can be extended without
 * touching "GSTR-3B Sep 2026".
 *
 * @return int|null new timestamp, or null if no extension applies
 */
function ca_extension_for($db, $label)
{
    static $ext = null;
    if ($ext === null) {
        $ext = array();
        $r = $db->query("SELECT filing_like,new_due FROM ca_extension ORDER BY CHAR_LENGTH(filing_like) DESC");
        while ($r && $o = $db->fetch_object($r)) $ext[] = array($o->filing_like, $o->new_due);
    }
    foreach ($ext as $e) {
        if (stripos($label, $e[0]) === 0) return dol_mktime(10, 0, 0,
            (int) substr($e[1], 5, 2), (int) substr($e[1], 8, 2), (int) substr($e[1], 0, 4));
    }
    return null;
}

/** Reset the per-request caches after editing holidays or extensions. */
function ca_cal_flush() { /* caches are per-process; nothing to do in CLI/web one-shots */ }

/**
 * Generate every missing deadline for ONE client. Idempotent: an existing label
 * for that client is left alone, so it is safe to run nightly and safe to call
 * again the moment a profile is edited.
 *
 * @return array(made, skipped, reminders, stale, shifted, extended)
 */
function ca_generate_for_client($db, $user, $c, $fy, $remindDays = 7)
{
    $made = 0; $skip = 0; $rem = 0; $stalecnt = 0; $shifted = 0; $extended = 0; $moved = 0;
    $want = array();

    foreach (ca_rules_for($c, $fy) as $r) {
        list($label, $ts) = $r;
        $full = $label.' - '.$c['nom'];
        $want[$full] = 1;   // the complete set this profile currently implies

        $newts = ca_extension_for($db, $label);
        if ($newts !== null) { $ts = $newts; $extended++; }
        else {
            // DELIBERATELY DOES NOT MOVE THE DATE - see ca_falls_on_holiday().
            $why = null;
            if (ca_falls_on_holiday($db, $ts, $why)) $shifted++;
        }

        $chk = $db->query("SELECT id,datep FROM ".MAIN_DB_PREFIX."actioncomm WHERE fk_soc=".(int) $c['rowid']
                        ." AND label='".$db->escape($full)."'");
        if ($chk && $ex = $db->fetch_object($chk)) {
            $skip++;
            // A holiday, or a government extension announced this morning, moves a
            // date that ALREADY exists. Skipping outright would leave the practice
            // filing on a date the portal rejects, and would make the extensions
            // screen a lie - it promises the nightly run refreshes existing rows.
            $cur = $db->jdate($ex->datep);
            if ($cur !== null && abs($cur - $ts) > 3600) {
                // The COLUMN is datep2. 'datef' is the ActionComm object property and
                // does not exist in the table, so naming it here failed the whole
                // statement - datep never moved either, while the counter below
                // happily reported success. Count only what the database accepted.
                $ok = $db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm SET datep='".$db->idate($ts)."',"
                          ." datep2='".$db->idate($ts + 3600)."' WHERE id=".(int) $ex->id);
                if ($ok) { $moved++; }
                else { fwrite(STDERR, "  re-dating ".$full." failed: ".$db->lasterror()."\n"); }
            }
            continue;
        }

        $a = new ActionComm($db); $a->type_code = 'AC_OTH'; $a->label = $full;
        $a->datep = $ts; $a->datef = $ts + 3600; $a->socid = (int) $c['rowid'];
        $a->userownerid = $user->id; $a->percentage = 0;   // 0 = "to do" so it reaches the dashboard
        $aid = $a->create($user);
        if ($aid <= 0) continue;

        // Clamp to now: for a deadline 3 days out, "7 days before" is already in the
        // past. Back-dating it makes the reminder look permanently overdue and trips
        // wa_fanout's aged-out alarm forever.
        $remind_at = max($ts - $remindDays * 86400, dol_now() + 120);
        // Only remind about deadlines still AHEAD of us. Dolibarr's notification
        // poller PERMANENTLY DELETES reminders older than a month, with no log, so a
        // stale one is not merely useless - it is unrecoverable.
        $stale = ($ts <= dol_now()) || ($remind_at < (dol_now() - 30 * 86400));

        $db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."actioncomm_resources
          (fk_actioncomm,element_type,fk_element,mandatory,transparency)
          VALUES ($aid,'user',".(int) $user->id.",0,0)");
        if (!$stale) {
            global $conf;
            $db->query("INSERT INTO ".MAIN_DB_PREFIX."actioncomm_reminder
              (dateremind,typeremind,fk_user,fk_soc,offsetvalue,offsetunit,status,entity,fk_actioncomm)
              VALUES ('".$db->idate($remind_at)."','email',".(int) $user->id.",
                      ".(int) $c['rowid'].",".(int) $remindDays.",'d',0,".$conf->entity.",$aid)");
            $rem++;
        } else { $stalecnt++; }
        $made++;
    }
    $retired = ca_retire_superseded($db, $c, $want);
    return array('made' => $made, 'skipped' => $skip, 'reminders' => $rem, 'moved' => $moved,
                 'stale' => $stalecnt, 'shifted' => $shifted, 'extended' => $extended,
                 'retired' => $retired);
}

/** The profile fields the rules read, for one client or all of them. */
function ca_client_rows($db, $socid = 0)
{
    $sql = "SELECT s.rowid,s.nom,
       COALESCE(e.gst_scheme,'') gst_scheme, COALESCE(e.tan,'') tan, COALESCE(e.gstin,'') gstin,
       COALESCE(e.turnover_annual,0) turnover_annual,
       COALESCE(e.entity_type,'') entity_type, COALESCE(e.tax_audit,0) tax_audit,
       COALESCE(e.roc_applicable,0) roc_applicable
       FROM ".MAIN_DB_PREFIX."societe s
       LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields e ON e.fk_object=s.rowid
       WHERE s.client=1";
    if ($socid > 0) $sql .= " AND s.rowid=".(int) $socid;
    $res = $db->query($sql);
    $out = array();
    while ($res && $row = $db->fetch_array($res)) $out[] = $row;
    return $out;
}

/**
 * Validate the statutory identifiers on a client profile.
 *
 * The CSV importer has always enforced these; the Dolibarr third-party form has
 * not, so a client typed into the UI could carry a malformed PAN. A filing made
 * against the wrong PAN is a filing made for the wrong entity.
 *
 * @return array of human-readable errors (empty array = valid)
 */
function ca_validate_profile($pan, $gstin, $tan)
{
    $e = array();
    $pan   = strtoupper(trim((string) $pan));
    $gstin = strtoupper(trim((string) $gstin));
    $tan   = strtoupper(trim((string) $tan));

    if ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan))
        $e[] = "PAN '{$pan}' is malformed - expected five letters, four digits, one letter";
    if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', $gstin))
        $e[] = "GSTIN '{$gstin}' is malformed - expected 15 characters, state code first";
    if ($tan !== '' && !preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', $tan))
        $e[] = "TAN '{$tan}' is malformed - expected four letters, five digits, one letter";
    // A GSTIN embeds the PAN at positions 3-12. If they disagree, one is a typo,
    // and the calendar would be built for an entity that does not exist.
    if ($pan !== '' && $gstin !== '' && strlen($gstin) === 15 && substr($gstin, 2, 10) !== $pan)
        $e[] = "GSTIN does not embed PAN ('".substr($gstin, 2, 10)."' vs '{$pan}') - one of them is wrong";
    return $e;
}

/**
 * Retire deadlines the profile no longer implies.
 *
 * Switching a client from GST Monthly to QRMP used to ADD the quarterly
 * deadlines and leave the monthly ones standing, so the client carried both
 * schemes for ever and the CA chased documents for returns that no longer exist.
 * Same for removing a TAN, or clearing the ROC flag.
 *
 * This is the only destructive path in the calendar, so it is deliberately
 * narrow. A deadline is retired ONLY when all of these hold:
 *   - it belongs to this client
 *   - it is in the FUTURE (history is never rewritten)
 *   - its label is one this system generates (a CA's own diary entries are
 *     never touched, whatever they are called)
 *   - the current profile does not imply it
 *   - it has not been FILED (a filed return is statutory evidence; it stays
 *     even if the profile later changes)
 *
 * @param  $want  set of labels the profile currently implies, keyed by label
 * @return int    how many were retired
 */
function ca_retire_superseded($db, $c, $want)
{
    if (!$want) return 0;   // never mass-delete on an empty rule set
    $n = 0;
    $res = $db->query("SELECT a.id, a.label FROM ".MAIN_DB_PREFIX."actioncomm a
        WHERE a.fk_soc = ".(int) $c['rowid']."
          AND a.datep > NOW()
          AND a.label REGEXP '^(GSTR-|CMP-08|TDS |Advance tax|ITR|Tax audit|AOC-4|MGT-7|LLP Form|DIR-3)'
          AND NOT EXISTS (SELECT 1 FROM ca_filing f
                           WHERE f.fk_actioncomm = a.id AND f.status = 'filed')");
    $kill = array();
    while ($res && $o = $db->fetch_object($res)) {
        if (!isset($want[$o->label])) $kill[] = (int) $o->id;
    }
    foreach ($kill as $id) {
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."actioncomm_reminder WHERE fk_actioncomm=".$id);
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."actioncomm_resources WHERE fk_actioncomm=".$id);
        // the derived rows follow the deadline they were derived from
        $db->query("DELETE FROM ca_docrequest WHERE fk_actioncomm=".$id);
        $db->query("DELETE FROM ca_filing WHERE fk_actioncomm=".$id." AND status <> 'filed'");
        if ($db->query("DELETE FROM ".MAIN_DB_PREFIX."actioncomm WHERE id=".$id)) $n++;
    }
    return $n;
}
