<?php
/**
 * DPDP ACT 2023 - RETENTION AND ERASURE.
 *
 * Section 8(7) requires a Data Fiduciary to erase personal data once consent is
 * withdrawn or the purpose is served, UNLESS retention is required by law. For a
 * CA those two duties pull in opposite directions: the client asks to be erased,
 * while the Income-tax Act, the Companies Act, GST law and ICAI all separately
 * REQUIRE the practice to keep records for years.
 *
 * So erasure here is not deletion-on-demand. A request is RECORDED and HELD
 * until the longest applicable statutory period expires; only then is it
 * executed, and even then the statutory compliance record (which filing, which
 * period, which acknowledgement number) survives - destroying that would breach
 * the very retention duty that justified the hold. What is erased is the
 * personal IDENTIFIERS attached to it.
 *
 * THE PERIODS BELOW ARE DEFAULTS, NOT LEGAL ADVICE. They are configurable, and
 * every practice should confirm them against its own counsel before relying on
 * them.
 */

/**
 * Statutory retention floors. This array is BOTH the seed and the fallback when
 * the table is absent, so behaviour is identical either way.
 */
function ca_retention_defaults()
{
    return array(
      array('pkey' => 'incometax', 'label' => 'Income-tax books and working papers', 'years' => 6,
            'basis' => 'Income-tax Act s.44AA r/w Rule 6F(5) - 6 years from the end of the relevant assessment year'),
      array('pkey' => 'gst',       'label' => 'GST records',                          'years' => 6,
            'basis' => 'CGST Act s.36 - 72 months from the due date of the annual return'),
      array('pkey' => 'icai',      'label' => 'ICAI audit working papers',            'years' => 7,
            'basis' => 'ICAI SQC 1 - retention of engagement documentation'),
      array('pkey' => 'companies', 'label' => 'Companies Act books of account',       'years' => 8,
            'basis' => 'Companies Act 2013 s.128(5) - 8 financial years'),
    );
}

function ca_privacy_ensure_tables($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS ca_retention_policy (
      rowid INT AUTO_INCREMENT PRIMARY KEY,
      pkey VARCHAR(32) NOT NULL,
      label VARCHAR(96) NOT NULL,
      years INT NOT NULL DEFAULT 0,
      basis VARCHAR(255) NOT NULL,
      UNIQUE KEY uniq_pkey (pkey)) ENGINE=innodb");
    foreach (ca_retention_defaults() as $r) {
        $db->query("INSERT IGNORE INTO ca_retention_policy (pkey,label,years,basis) VALUES ('"
          .$db->escape($r['pkey'])."','".$db->escape($r['label'])."',".(int) $r['years'].",'"
          .$db->escape($r['basis'])."')");
    }
    $db->query("CREATE TABLE IF NOT EXISTS ca_erasure_request (
      rowid INT AUTO_INCREMENT PRIMARY KEY,
      fk_soc INT NOT NULL,
      requested_at DATETIME NOT NULL,
      requested_by INT NULL,
      reason VARCHAR(255) NULL,
      status ENUM('held','due','done','withdrawn') NOT NULL DEFAULT 'held',
      due_at DATE NULL,
      completed_at DATETIME NULL,
      UNIQUE KEY uniq_soc (fk_soc)) ENGINE=innodb");
    // The log must evidence that an erasure happened WITHOUT re-storing the
    // identifier it erased - so the name is hashed, never kept.
    $db->query("CREATE TABLE IF NOT EXISTS ca_erasure_log (
      rowid INT AUTO_INCREMENT PRIMARY KEY,
      fk_soc INT NOT NULL,
      nom_hash VARCHAR(64) NOT NULL,
      fields_cleared VARCHAR(255) NOT NULL,
      acted_at DATETIME NOT NULL,
      fk_user INT NULL,
      note VARCHAR(255) NULL) ENGINE=innodb");
}

/** Rows of the policy table, falling back to the built-in defaults. */
function ca_retention_rows($db)
{
    $out = array();
    $r = $db->query("SELECT rowid,pkey,label,years,basis FROM ca_retention_policy ORDER BY years DESC, pkey");
    while ($r && $o = $db->fetch_object($r)) $out[] = (array) $o;
    return $out ?: ca_retention_defaults();
}

/** The binding period is the LONGEST of them - the practice must satisfy all. */
function ca_retention_years($db)
{
    $max = 0;
    foreach (ca_retention_rows($db) as $r) $max = max($max, (int) $r['years']);
    return $max;
}

/**
 * The date personal data for this client may be erased: the last statutory due
 * date recorded against them (or, failing that, when they were taken on) plus
 * the binding retention period.
 */
function ca_retention_expiry($db, $socid)
{
    $socid = (int) $socid;
    $base = null;
    $r = $db->query("SELECT MAX(datep) d FROM ".MAIN_DB_PREFIX."actioncomm WHERE fk_soc=".$socid);
    if ($r && $o = $db->fetch_object($r)) $base = $o->d;
    if (!$base) {
        $r2 = $db->query("SELECT datec d FROM ".MAIN_DB_PREFIX."societe WHERE rowid=".$socid);
        if ($r2 && $o2 = $db->fetch_object($r2)) $base = $o2->d;
    }
    if (!$base) return null;
    $y = ca_retention_years($db);
    return date('Y-m-d', strtotime(substr($base, 0, 10).' +'.$y.' years'));
}

function ca_request_erasure($db, $socid, $reason, $uid)
{
    $socid = (int) $socid; $uid = (int) $uid;
    if ($socid <= 0) return array('ok' => false, 'msg' => 'no client identified');
    $chk = $db->query("SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid=".$socid);
    if (!$chk || !($c = $db->fetch_object($chk))) return array('ok' => false, 'msg' => "client {$socid} does not exist");
    $due = ca_retention_expiry($db, $socid);
    if ($due === null) return array('ok' => false, 'msg' => 'cannot compute a retention expiry for this client');
    $ok = $db->query("INSERT INTO ca_erasure_request (fk_soc,requested_at,requested_by,reason,status,due_at)
        VALUES (".$socid.",NOW(),".$uid.",'".$db->escape((string) $reason)."','held','".$db->escape($due)."')
        ON DUPLICATE KEY UPDATE requested_at=NOW(), requested_by=".$uid.",
          reason='".$db->escape((string) $reason)."', status='held', due_at='".$db->escape($due)."', completed_at=NULL");
    if (!$ok) return array('ok' => false, 'msg' => 'database refused the request: '.$db->lasterror());
    return array('ok' => true, 'due_at' => $due,
      'msg' => "Erasure recorded for '{$c->nom}'. Held until {$due}, when the statutory retention period expires.");
}

function ca_withdraw_erasure($db, $socid)
{
    $socid = (int) $socid;
    $ok = $db->query("UPDATE ca_erasure_request SET status='withdrawn' WHERE fk_soc=".$socid." AND status<>'done'");
    return array('ok' => (bool) $ok, 'msg' => $ok ? 'Erasure request withdrawn.' : $db->lasterror());
}

/** Requests whose statutory hold has expired. */
function ca_erasure_due($db)
{
    $out = array();
    $r = $db->query("SELECT e.rowid,e.fk_soc,e.due_at,e.status,s.nom
                       FROM ca_erasure_request e
                       LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid=e.fk_soc
                      WHERE e.status IN ('held','due') AND e.due_at IS NOT NULL AND e.due_at <= CURDATE()
                      ORDER BY e.due_at");
    while ($r && $o = $db->fetch_object($r)) $out[] = (array) $o;
    return $out;
}

/**
 * Erase the personal identifiers, keep the compliance record.
 *
 * ca_filing (what was filed, for which period, under which acknowledgement) and
 * the agenda history are deliberately NOT touched: they are the practice's own
 * evidence of having filed on time, and the statute that justifies holding the
 * erasure is the same statute that requires keeping them.
 */
function ca_execute_erasure($db, $socid, $uid)
{
    $socid = (int) $socid; $uid = (int) $uid;
    $r = $db->query("SELECT e.status,e.due_at,s.nom FROM ca_erasure_request e
                     LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid=e.fk_soc WHERE e.fk_soc=".$socid);
    if (!$r || !($e = $db->fetch_object($r))) return array('ok' => false, 'msg' => 'no erasure request for that client');
    if ($e->status === 'done')      return array('ok' => false, 'msg' => 'already erased');
    if ($e->status === 'withdrawn') return array('ok' => false, 'msg' => 'that request was withdrawn');
    // A CA must not be able to erase inside the statutory window by clicking a button.
    if ($e->due_at === null || $e->due_at > date('Y-m-d'))
        return array('ok' => false, 'msg' => "still within the statutory retention period - not erasable until {$e->due_at}");

    $fields = 'nom,email,phone,gstin,pan,tan,cin,whatsapp_number,whatsapp_optin';
    $hash = hash('sha256', (string) $e->nom);

    $db->begin();
    $a = $db->query("UPDATE ".MAIN_DB_PREFIX."societe
                        SET nom='Erased client #".$socid."', email=NULL, phone=NULL
                      WHERE rowid=".$socid);
    $b = $db->query("UPDATE ".MAIN_DB_PREFIX."societe_extrafields
                        SET gstin=NULL,pan=NULL,tan=NULL,cin=NULL,whatsapp_number=NULL,whatsapp_optin=0
                      WHERE fk_object=".$socid);
    $c = $db->query("INSERT INTO ca_erasure_log (fk_soc,nom_hash,fields_cleared,acted_at,fk_user,note)
        VALUES (".$socid.",'".$db->escape($hash)."','".$db->escape($fields)."',NOW(),".$uid.",
                'DPDP s.8(7); statutory record in ca_filing retained')");
    $d = $db->query("UPDATE ca_erasure_request SET status='done', completed_at=NOW() WHERE fk_soc=".$socid);
    if (!$a || !$b || !$c || !$d) { $db->rollback(); return array('ok' => false, 'msg' => 'erasure failed: '.$db->lasterror()); }
    $db->commit();
    return array('ok' => true, 'msg' => "Personal identifiers erased for client #{$socid}. The filing record is retained.");
}
