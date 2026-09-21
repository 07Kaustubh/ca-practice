<?php
/**
 * FILING LEDGER - shared rules.
 *
 * Both the CLI (file_return.php) and the screen the CA uses
 * (custom/ca/filings.php) record a filing through ca_record_filing() here, so
 * the acknowledgement rules and the statutory rates exist in exactly one place.
 * When a rate changes, it changes here and every caller follows.
 *
 * Takes $db as a parameter and bootstraps nothing, so it is safe to include
 * from a CLI script (master.inc.php) or a web page (main.inc.php) alike.
 *
 * Rates current as at FY 2026-27. VERIFY BEFORE RELYING ON THEM.
 */

/**
 * LATE FEE / INTEREST RATES.
 *
 * These were hardcoded here, so changing a statutory rate meant editing PHP on
 * the server. Rates change by Finance Act and notification; a CA must be able to
 * update one without opening a source file. They now live in ca_rate, editable
 * at custom/ca/rates.php, and this array is both the SEED and the fallback when
 * no database handle is passed - so behaviour is identical if the table is
 * missing, and identical to what it was before.
 *
 * Current as at FY 2026-27. VERIFY BEFORE RELYING ON THEM.
 */
function ca_rate_defaults()
{
    return array(
      array('prio'=>10,'pattern'=>'^GSTR-(1|3B)',        'label'=>'GSTR-1 / GSTR-3B',
            'per_day'=>50,  'flat'=>0,    'cap'=>5000,
            'basis'=>'Rs 50/day u/s 47 (Rs 20 if nil), capped'),
      array('prio'=>20,'pattern'=>'^(CMP-08|GSTR-4|GSTR-9)','label'=>'CMP-08 / GSTR-4 / GSTR-9',
            'per_day'=>50,  'flat'=>0,    'cap'=>10000,
            'basis'=>'Rs 50/day u/s 47, capped'),
      array('prio'=>30,'pattern'=>'^TDS return',          'label'=>'TDS return 24Q/26Q',
            'per_day'=>200, 'flat'=>0,    'cap'=>0,
            'basis'=>'Rs 200/day u/s 234E, capped at the TDS amount'),
      array('prio'=>40,'pattern'=>'^TDS payment',         'label'=>'TDS payment',
            'per_day'=>0,   'flat'=>0,    'cap'=>0,
            'basis'=>'interest 1.5%/month u/s 201(1A) on the unpaid TDS'),
      array('prio'=>50,'pattern'=>'^ITR',                 'label'=>'Income-tax return',
            'per_day'=>0,   'flat'=>5000, 'cap'=>0,
            'basis'=>'Rs 5,000 u/s 234F (Rs 1,000 if total income < Rs 5L)'),
      array('prio'=>60,'pattern'=>'^Advance tax',         'label'=>'Advance tax instalment',
            'per_day'=>0,   'flat'=>0,    'cap'=>0,
            'basis'=>'interest 1%/month u/s 234B/234C on the shortfall'),
      array('prio'=>70,'pattern'=>'^(AOC-4|MGT-7|LLP Form)','label'=>'ROC annual filings',
            'per_day'=>100, 'flat'=>0,    'cap'=>0,
            'basis'=>'Rs 100/day under the Companies Act, no cap'),
      array('prio'=>80,'pattern'=>'^DIR-3',               'label'=>'DIR-3 KYC',
            'per_day'=>0,   'flat'=>5000, 'cap'=>0,
            'basis'=>'Rs 5,000 flat; DIN is deactivated until filed'),
    );
}

/** Create the rate table and seed it from the defaults above. Idempotent. */
function ca_rate_ensure($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS ca_rate (
      rowid INT AUTO_INCREMENT PRIMARY KEY,
      prio INT NOT NULL DEFAULT 100,
      pattern VARCHAR(96) NOT NULL,
      label VARCHAR(96) NOT NULL,
      per_day DECIMAL(10,2) NOT NULL DEFAULT 0,
      flat DECIMAL(10,2) NOT NULL DEFAULT 0,
      cap DECIMAL(12,2) NOT NULL DEFAULT 0,
      basis VARCHAR(255) NOT NULL,
      UNIQUE KEY uniq_pattern (pattern)) ENGINE=innodb");
    foreach (ca_rate_defaults() as $r) {
        $db->query("INSERT IGNORE INTO ca_rate (prio,pattern,label,per_day,flat,cap,basis) VALUES ("
          .(int) $r['prio'].",'".$db->escape($r['pattern'])."','".$db->escape($r['label'])."',"
          .(float) $r['per_day'].",".(float) $r['flat'].",".(float) $r['cap'].",'".$db->escape($r['basis'])."')");
    }
}

/** Rates in evaluation order. Order matters: the first pattern to match wins. */
function ca_rate_rows($db)
{
    $out = array();
    $r = $db->query("SELECT rowid,prio,pattern,label,per_day,flat,cap,basis FROM ca_rate ORDER BY prio, rowid");
    while ($r && $o = $db->fetch_object($r)) $out[] = (array) $o;
    return $out ?: ca_rate_defaults();   // never leave the CA with no rate table
}

/**
 * Late fee / interest for a filing that is $lateDays overdue.
 * Pass $db to use the editable table; omit it to use the built-in defaults.
 */
function exposureFor($filing, $lateDays, $db = null)
{
    if ($lateDays <= 0) return array(0, '');
    $rows = $db ? ca_rate_rows($db) : ca_rate_defaults();
    foreach ($rows as $r) {
        if (!preg_match('/'.str_replace('/', '\\/', $r['pattern']).'/', $filing)) continue;
        $amt = ((float) $r['flat'] > 0) ? (float) $r['flat'] : ((float) $r['per_day'] * $lateDays);
        if ((float) $r['cap'] > 0) $amt = min($amt, (float) $r['cap']);
        return array($amt, $r['basis']);
    }
    return array(0, '');
}

/**
 * What a valid acknowledgement looks like, per filing family.
 *
 * An acknowledgement that cannot be checked against the portal is worse than
 * no acknowledgement: it looks like proof of filing and is not. So each family
 * gets the shape the portal actually issues, and anything else is refused.
 *
 * @return array(regex, human-readable example)
 */
function ca_ack_rule($filing)
{
    // GST ARN: AA + 2-digit state + MM + YY + 6-digit serial + 1 check char = 15
    if (preg_match('/^(GSTR-|CMP-08)/', $filing))
        return array('/^[A-Z]{2}[0-9]{12}[A-Z0-9]$/', 'GST ARN, 15 characters, e.g. AA070926123456X');
    // Income-tax e-filing acknowledgement and TDS provisional receipt: 15 digits
    if (preg_match('/^(ITR|Tax audit)/', $filing))
        return array('/^[0-9]{15}$/', 'ITR acknowledgement number, 15 digits');
    if (preg_match('/^TDS return/', $filing))
        return array('/^[0-9]{15}$/', 'TDS provisional receipt / token number, 15 digits');
    // Challan identification number for a payment: BSR(7) + DDMMYYYY(8) + serial(5)
    if (preg_match('/^(TDS payment|Advance tax)/', $filing))
        return array('/^[0-9]{20}$/', 'CIN: 7-digit BSR + DDMMYYYY + 5-digit challan serial, 20 digits');
    // MCA SRN: one or two letters then 8 digits
    if (preg_match('/^(AOC-4|MGT-7|LLP Form|DIR-3)/', $filing))
        return array('/^[A-Z]{1,2}[0-9]{8}$/', 'MCA SRN, e.g. T12345678');
    return array('/^[A-Z0-9]{9,20}$/', '9-20 letters and digits');
}

/** Uppercase and drop the spaces/hyphens people paste in from a portal. */
function ca_normalise_ack($raw)
{
    return strtoupper(preg_replace('/[\s\-]/', '', (string) $raw));
}

/**
 * Record that a return was actually filed.
 *
 * @param  $db      Dolibarr DB handle
 * @param  $id      ca_filing.rowid
 * @param  $rawAck  acknowledgement as the user typed it
 * @param  $on      filing date, YYYY-MM-DD; '' means today
 * @param  $uid     llx_user.rowid of whoever is recording it
 * @param  $amend   true to overwrite an already-filed record
 * @return array('ok'=>bool, 'msg'=>string)
 */
function ca_record_filing($db, $id, $rawAck, $on, $uid, $amend = false)
{
    $id = (int) $id;
    $uid = (int) $uid;
    if ($id <= 0)  return array('ok' => false, 'msg' => 'no filing identified');
    if ($uid <= 0) return array('ok' => false, 'msg' => 'no user - a filing must be attributable to a person');

    $r = $db->query("SELECT rowid,fk_soc,fk_actioncomm,filing,period,due,status
                       FROM ca_filing WHERE rowid=".$id);
    if (!$r || !($f = $db->fetch_object($r))) return array('ok' => false, 'msg' => "filing $id does not exist");

    if ($f->status === 'not_applicable')
        return array('ok' => false, 'msg' => "'{$f->filing} {$f->period}' predates go-live; this system never tracked it");
    if ($f->status === 'filed' && !$amend)
        return array('ok' => false, 'msg' => "'{$f->filing} {$f->period}' is already filed - pass --amend to correct it");

    $ack = ca_normalise_ack($rawAck);
    if ($ack === '') return array('ok' => false, 'msg' => 'an acknowledgement number is required - that is the proof of filing');
    list($re, $eg) = ca_ack_rule($f->filing);
    if (!preg_match($re, $ack))
        return array('ok' => false, 'msg' => "'{$rawAck}' is not a valid acknowledgement for {$f->filing}. Expected {$eg}");

    $on = trim((string) $on);
    if ($on === '') $on = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) return array('ok' => false, 'msg' => "filing date '{$on}' is not YYYY-MM-DD");
    list($yy, $mm, $dd) = array_map('intval', explode('-', $on));
    if (!checkdate($mm, $dd, $yy)) return array('ok' => false, 'msg' => "filing date '{$on}' is not a real date");
    // A return cannot have been filed tomorrow. Backdating a filing to dodge a
    // late fee is the single most damaging thing this table could be used for.
    if ($on > date('Y-m-d')) return array('ok' => false, 'msg' => "filing date '{$on}' is in the future");

    // Late fee crystallises on the day it was filed - it stops accruing, it does
    // not vanish. The forward-looking exposure figure counts pending/ready only.
    $late = (int) floor((strtotime($on) - strtotime($f->due)) / 86400);
    if ($late < 0) $late = 0;
    list($amt, $note) = exposureFor($f->filing, $late);
    if ($late > 0) $note = "filed {$late} day(s) late - ".$note;
    else           $note = 'filed on time';

    $db->begin();
    $ok = $db->query("UPDATE ca_filing SET status='filed',
                        arn='".$db->escape($ack)."', filed_at='".$db->escape($on)."',
                        filed_by=".$uid.", late_days=".$late.", exposure_inr=".(float) $amt.",
                        note='".$db->escape($note)."'
                      WHERE rowid=".$id);
    if (!$ok) { $db->rollback(); return array('ok' => false, 'msg' => 'database refused the update: '.$db->lasterror()); }

    // Close it on the CA's own calendar too, so the agenda he already reads
    // agrees with the ledger instead of still showing the deadline as open.
    if ((int) $f->fk_actioncomm > 0) {
        $db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm SET percent=100
                     WHERE id=".(int) $f->fk_actioncomm);
    }
    // Documents collected for it have served their purpose.
    $db->query("UPDATE ca_docrequest SET status='received'
                 WHERE fk_actioncomm=".(int) $f->fk_actioncomm." AND status='pending'");
    $db->commit();

    $when = $late > 0 ? "{$late} day(s) late" : 'on time';
    return array('ok' => true, 'msg' => "{$f->filing} {$f->period} filed {$when}, ack {$ack}");
}

/**
 * "2026-10" is a database value, not something to show a CA. Render "Oct 2026".
 * Falls through unchanged if it is not a YYYY-MM string.
 */
function ca_period_label($p)
{
    $p = (string) $p;
    if (!preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) return $p;
    $t = mktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]);
    return $t === false ? $p : date('M Y', $t);
}

/**
 * Mark every outstanding document for one filing as received.
 *
 * This verb did not exist, and its absence was not merely a missing feature.
 * chase_clients.py chases on ca_docrequest.status='pending', so a client who
 * had already sent everything kept receiving WhatsApp messages asking for it.
 * The only writer of 'received' was ca_record_filing() - a side-effect of
 * recording a filing - so the documents could not be acknowledged until after
 * the return went out, which is the wrong way round.
 *
 * Deliberately takes no per-document argument. The CA opens an email with three
 * attachments; asking him to tick three boxes is bookkeeping for the machine.
 * One click per filing is the whole interaction.
 *
 * @return array('ok'=>bool, 'msg'=>string, 'n'=>int)
 */
function ca_mark_documents_received($db, $filingId, $uid)
{
    $filingId = (int) $filingId;
    if ($filingId <= 0) return array('ok' => false, 'msg' => 'no filing identified', 'n' => 0);

    $r = $db->query("SELECT f.rowid,f.fk_actioncomm,f.filing,f.period,f.status,s.nom
                       FROM ca_filing f
                       LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
                      WHERE f.rowid = ".$filingId);
    if (!$r || !($f = $db->fetch_object($r))) return array('ok' => false, 'msg' => "filing {$filingId} does not exist", 'n' => 0);
    if ((int) $f->fk_actioncomm <= 0)         return array('ok' => false, 'msg' => 'that filing has no document requests', 'n' => 0);

    $db->begin();
    $ok = $db->query("UPDATE ca_docrequest SET status='received', received_at=NOW()
                       WHERE fk_actioncomm=".(int) $f->fk_actioncomm." AND status='pending'");
    if (!$ok) { $db->rollback(); return array('ok' => false, 'msg' => 'database refused: '.$db->lasterror(), 'n' => 0); }
    $n = (int) $db->affected_rows($ok);

    // The filing is now unblocked. Recompute here rather than waiting for the
    // nightly job, so the screen he is looking at tells him the truth.
    if ($f->status === 'pending') {
        $db->query("UPDATE ca_filing SET status='ready' WHERE rowid=".$filingId."
                     AND NOT EXISTS (SELECT 1 FROM ca_docrequest d
                                      WHERE d.fk_actioncomm=".(int) $f->fk_actioncomm." AND d.status='pending')");
    }
    $db->commit();

    if ($n === 0) return array('ok' => true, 'n' => 0,
        'msg' => "Nothing was outstanding for {$f->filing} {$f->period} - already marked received.");
    return array('ok' => true, 'n' => $n,
        'msg' => "{$n} document(s) received for {$f->nom} - {$f->filing} {$f->period}. The chase stops for these.");
}

// ─────────────────────────────────────────────────────────────────────────────
// ONE-OFF TASKS
//
// Everything in this list was DERIVED from a client profile, which made the
// product a generator rather than a to-do list. A practice has plenty of work
// that no statute implies - "collect Form 16 from Sharma", "reply to the 143(1)
// notice", "renew the DSC" - and none of it matched the statutory label regex,
// so none of it could ever appear on the one screen he lives in. He kept a
// second list somewhere else, which is the failure this product exists to end.
// ─────────────────────────────────────────────────────────────────────────────

/** Ad-hoc rows are prefixed so every other rule can tell them from a statute. */
define('CA_TASK_PREFIX', 'Task: ');

function ca_is_task($filing) { return strpos((string) $filing, CA_TASK_PREFIX) === 0; }

/**
 * Add a one-off task. It becomes a real agenda event, so it inherits the same
 * calendar and the same email reminder as every statutory date - a task that
 * does not remind him is just a note.
 *
 * @param $socid  client it belongs to, or 0 for practice-wide
 * @return array('ok'=>bool,'msg'=>string)
 */
function ca_add_task($db, $socid, $what, $due, $uid, $remindDays = 7)
{
    global $conf;
    require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
    require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

    $socid = (int) $socid; $uid = (int) $uid;
    $what  = trim((string) $what);
    $due   = trim((string) $due);

    if ($what === '')  return array('ok' => false, 'msg' => 'Describe the task in a few words.');
    if (mb_strlen($what) > 80) return array('ok' => false, 'msg' => 'Keep the task under 80 characters.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) return array('ok' => false, 'msg' => "'{$due}' is not a date (YYYY-MM-DD).");
    list($y, $m, $d) = array_map('intval', explode('-', $due));
    if (!checkdate($m, $d, $y)) return array('ok' => false, 'msg' => "'{$due}' is not a real date.");

    $nom = 'the practice';
    if ($socid > 0) {
        $rs = $db->query("SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid=".$socid);
        if (!$rs || !($os = $db->fetch_object($rs))) return array('ok' => false, 'msg' => "client {$socid} does not exist");
        $nom = $os->nom;
    }

    $label = CA_TASK_PREFIX.$what.($socid > 0 ? ' - '.$nom : '');
    $ts = dol_mktime(10, 0, 0, $m, $d, $y);

    // Refuse a duplicate outright rather than quietly making a second one.
    $dup = $db->query("SELECT id FROM ".MAIN_DB_PREFIX."actioncomm
                        WHERE label='".$db->escape($label)."' AND DATE(datep)='".$db->escape($due)."'");
    if ($dup && $db->num_rows($dup) > 0) return array('ok' => false, 'msg' => 'That task already exists on that date.');

    $u = new User($db); $u->fetch($uid); $u->getrights();

    $db->begin();
    $a = new ActionComm($db);
    $a->type_code = 'AC_OTH';          // same code the statutory dates use, so it is one list
    $a->label = $label;
    $a->datep = $ts; $a->datef = $ts + 3600;
    $a->socid = $socid > 0 ? $socid : 0;
    $a->userownerid = $uid; $a->percentage = 0;
    $aid = $a->create($u);
    if ($aid <= 0) { $db->rollback(); return array('ok' => false, 'msg' => 'could not create the task: '.$a->error); }

    $db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."actioncomm_resources
                (fk_actioncomm,element_type,fk_element,mandatory,transparency)
                VALUES (".$aid.",'user',".$uid.",0,0)");

    // Same clamp the statutory generator uses: a reminder dated in the past is
    // purged by Dolibarr's poller and would vanish without trace.
    $remind_at = max($ts - ((int) $remindDays) * 86400, dol_now() + 120);
    if ($ts > dol_now()) {
        $db->query("INSERT INTO ".MAIN_DB_PREFIX."actioncomm_reminder
          (dateremind,typeremind,fk_user,fk_soc,offsetvalue,offsetunit,status,entity,fk_actioncomm)
          VALUES ('".$db->idate($remind_at)."','email',".$uid.",".($socid > 0 ? $socid : 0).",
                  ".((int) $remindDays).",'d',0,".$conf->entity.",".$aid.")");
    }

    $ok = $db->query("INSERT INTO ca_filing (fk_soc,fk_actioncomm,filing,period,due,status)
        VALUES (".$socid.",".$aid.",'".$db->escape(CA_TASK_PREFIX.$what)."',
                '".$db->escape(date('Y-m', $ts))."','".$db->escape($due)."','pending')");
    if (!$ok) { $db->rollback(); return array('ok' => false, 'msg' => 'could not list the task: '.$db->lasterror()); }
    $db->commit();

    return array('ok' => true, 'msg' => "Added: {$what}".($socid > 0 ? " for {$nom}" : '')
        .", due ".dol_print_date($ts, 'day').($ts > dol_now() ? " - you will be reminded {$remindDays} days before." : '.'));
}

/** Tick off a one-off task. No acknowledgement number: there is no portal here. */
function ca_complete_task($db, $id, $uid)
{
    $id = (int) $id;
    $r = $db->query("SELECT rowid,fk_actioncomm,filing,status FROM ca_filing WHERE rowid=".$id);
    if (!$r || !($f = $db->fetch_object($r))) return array('ok' => false, 'msg' => "task {$id} does not exist");
    if (!ca_is_task($f->filing))  return array('ok' => false, 'msg' => 'That is a statutory return - it needs its acknowledgement number.');
    if ($f->status === 'filed')   return array('ok' => false, 'msg' => 'Already done.');

    $db->begin();
    $ok = $db->query("UPDATE ca_filing SET status='filed', filed_at=CURDATE(), filed_by=".(int) $uid.",
                        late_days=GREATEST(0,DATEDIFF(CURDATE(),due)), exposure_inr=0, note='one-off task, completed'
                      WHERE rowid=".$id);
    if (!$ok) { $db->rollback(); return array('ok' => false, 'msg' => $db->lasterror()); }
    if ((int) $f->fk_actioncomm > 0)
        $db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm SET percent=100 WHERE id=".(int) $f->fk_actioncomm);
    $db->commit();
    return array('ok' => true, 'msg' => 'Done: '.substr($f->filing, strlen(CA_TASK_PREFIX)));
}

/**
 * Remove a row he does not owe.
 *
 * A one-off task is his, so it is deleted. A STATUTORY return is not - deleting
 * a GSTR-3B because it is inconvenient is precisely the failure this product
 * exists to prevent - so it is marked not-applicable with a reason and stays
 * auditable. Same button, honest difference.
 */
function ca_drop_filing($db, $id, $reason, $uid)
{
    $id = (int) $id;
    $r = $db->query("SELECT rowid,fk_actioncomm,filing,status FROM ca_filing WHERE rowid=".$id);
    if (!$r || !($f = $db->fetch_object($r))) return array('ok' => false, 'msg' => "row {$id} does not exist");
    if ($f->status === 'filed') return array('ok' => false, 'msg' => 'That has already been filed - it stays on the record.');

    $db->begin();
    if (ca_is_task($f->filing)) {
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."actioncomm_reminder WHERE fk_actioncomm=".(int) $f->fk_actioncomm);
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."actioncomm_resources WHERE fk_actioncomm=".(int) $f->fk_actioncomm);
        $db->query("DELETE FROM ca_docrequest WHERE fk_actioncomm=".(int) $f->fk_actioncomm);
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."actioncomm WHERE id=".(int) $f->fk_actioncomm);
        $ok = $db->query("DELETE FROM ca_filing WHERE rowid=".$id);
        $msg = 'Task removed.';
    } else {
        $why = trim((string) $reason);
        if ($why === '') { $db->rollback(); return array('ok' => false, 'msg' => 'Say why this return does not apply - it stays on the record either way.'); }
        // Build the note in PHP. MySQL has no '.' concatenation operator, so doing
        // it SQL-side is a runtime syntax error, not a style choice.
        $note = 'does not apply: '.mb_substr($why, 0, 180);
        $ok = $db->query("UPDATE ca_filing SET status='not_applicable',
                            note='".$db->escape($note)."'
                          WHERE rowid=".$id);
        if ((int) $f->fk_actioncomm > 0)
            $db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm SET percent=100 WHERE id=".(int) $f->fk_actioncomm);
        $db->query("UPDATE ca_docrequest SET status='received' WHERE fk_actioncomm=".(int) $f->fk_actioncomm." AND status='pending'");
        $msg = "Marked not applicable - kept on the record with your reason, not deleted.";
    }
    if (!$ok) { $db->rollback(); return array('ok' => false, 'msg' => $db->lasterror()); }
    $db->commit();
    return array('ok' => true, 'msg' => $msg);
}
