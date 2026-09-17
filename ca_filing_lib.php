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
