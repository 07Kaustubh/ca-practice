#!/usr/bin/env python3
"""Fan WhatsApp out from the email reminders the CA already creates in the UI.
No new UI, no Dolibarr core patches - survives upgrades."""
import subprocess, json, urllib.request, os, sys, re

def _load_env():
    """cron gives an almost-empty environment. sweeper.sh and backup.sh source
    ca.env/secrets.env themselves; this script did not, so it died at import with
    KeyError every 10 minutes on the VPS and only left a traceback in a log."""
    here = os.path.dirname(os.path.abspath(__file__))
    for fn in ("ca.env", "secrets.env"):
        p = os.path.join(here, fn)
        if not os.path.exists(p):
            continue
        for raw in open(p):
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            if line.startswith("export "):
                line = line[7:]
            if "=" not in line:
                continue
            k, v = line.split("=", 1)
            k = k.strip(); v = v.strip().strip('"').strip("'")
            # ca.env writes ${VAR:-default} so callers can override. Take the
            # default when the var is unset; a real env value always wins.
            m = re.match(r"^\$\{(\w+):-(.*)\}$", v)
            if m:
                v = os.environ.get(m.group(1), m.group(2))
            elif v.startswith("${"):
                continue
            os.environ.setdefault(k, v)
    os.chdir(here)   # docker compose must run from the project dir

_load_env()

def _need(k):
    v = os.environ.get(k)
    if not v:
        sys.exit(f"FATAL: {k} not set (checked env, ca.env, secrets.env in "
                 f"{os.path.dirname(os.path.abspath(__file__))})")
    return v

PHONE_ID = _need("WA_PHONE_ID"); TOKEN = _need("WA_TOKEN")
BASE = _need("WA_API_BASE"); DBPW = _need("DB_PASSWORD")
# A permanent Meta token grants the right to message his clients as him and bill
# him for it. It must never leave the box over plaintext. Mock endpoints for
# testing must be opted into explicitly.
if not (BASE.startswith("https://") or os.environ.get("WA_ALLOW_INSECURE") == "1"):
    sys.exit(f"FATAL: WA_API_BASE is not https ({BASE}). Refusing to send a bearer "
             f"token in clear. Set WA_ALLOW_INSECURE=1 only for local mock testing.")
TO_CLIENT = os.environ.get("WA_NOTIFY_CLIENT", "0") == "1"
TO_CA     = os.environ.get("WA_NOTIFY_CA", "0") == "1"
CA_NUM    = os.environ.get("WA_CA_NUMBER", "")
MAX_AGE   = int(os.environ.get("WA_MAX_AGE_DAYS", "2"))
MAX_TRIES = int(os.environ.get("WA_MAX_TRIES", "3"))
fatal_stop = None   # set when an account-level error makes every further send pointless
exhausted = 0

def sq(v):
    """Escape a value for single-quoted SQL. Shelling out to `mariadb -e` gives
    us no bind parameters, so every interpolated value goes through here."""
    if v is None: return "NULL"
    s = str(v).replace("\\", "\\\\").replace("'", "''")
    s = "".join(ch for ch in s if ch >= " " or ch == " ")
    return "'" + s + "'"

def q(sql, allow_empty=True):
    # Dolibarr writes dateremind via idate() in PHP's tz (Asia/Kolkata) while the
    # DB session is UTC. Pin the session tz so NOW() shares Dolibarr's frame.
    # MYSQL_PWD, not -p<pw>: an argv password is readable by any local user via
    # `ps` or /proc/<pid>/cmdline, and it lands in audit and monitoring tooling.
    r = subprocess.run(["docker","compose","exec","-T","-e",f"MYSQL_PWD={DBPW}",
        "db","mariadb","-udolibarr","dolibarr","-sN","-e","SET time_zone='+05:30'; " + sql],
        capture_output=True, text=True)
    err = "\n".join(l for l in r.stderr.splitlines() if "insecure" not in l.lower())
    if r.returncode != 0:
        # FAIL LOUD. A swallowed DB error must never read as "nothing to do".
        print(f"FATAL: database error (rc={r.returncode}): {err.strip()}", file=sys.stderr)
        sys.exit(2)
    return [l.split("\t") for l in r.stdout.split("\n") if l.strip()]

# `fails` is SEPARATE from `tries`. Sharing one counter let benign 'skipped'
# writes (the default state of every new client) burn the retry budget, so a
# real failure got abandoned after ONE attempt. That is the silent permanent
# miss this whole tool exists to prevent.
q("""CREATE TABLE IF NOT EXISTS ca_wa_sent (
  fk_reminder INT NOT NULL, recipient VARCHAR(20) NOT NULL, phone VARCHAR(20),
  wamid VARCHAR(64), status VARCHAR(12), err VARCHAR(200),
  tries INT NOT NULL DEFAULT 1, fails INT NOT NULL DEFAULT 0,
  sent_at DATETIME, PRIMARY KEY (fk_reminder,recipient));""")
q("ALTER TABLE ca_wa_sent ADD COLUMN IF NOT EXISTS fails INT NOT NULL DEFAULT 0;")
q("ALTER TABLE ca_wa_sent ADD COLUMN IF NOT EXISTS escalated TINYINT NOT NULL DEFAULT 0;")

# Aged-out reminders are NOT silently dropped - they are counted and surfaced.
# Only count reminders that COULD have been sent. A client who never opted in is
# a business state, not an outage; counting them made this alarm always-on = ignored.
aged = q(f"""SELECT COUNT(*) FROM llx_actioncomm_reminder r
  JOIN llx_actioncomm a ON a.id=r.fk_actioncomm
  JOIN llx_societe s ON s.rowid=a.fk_soc
  LEFT JOIN llx_societe_extrafields e ON e.fk_object=s.rowid
  WHERE r.dateremind <= NOW()
    AND r.dateremind < DATE_SUB(NOW(), INTERVAL {MAX_AGE} DAY)
    AND COALESCE(e.whatsapp_optin,0)=1 AND COALESCE(e.whatsapp_number,'')<>''
    AND NOT EXISTS (SELECT 1 FROM ca_wa_sent w
                    WHERE w.fk_reminder=r.rowid AND w.status='sent'
                       OR (w.fk_reminder=r.rowid AND w.fails>={MAX_TRIES}));""")
n_aged = int(aged[0][0]) if aged and aged[0][0] else 0
if n_aged:
    print(f"ALERT: {n_aged} reminder(s) aged past {MAX_AGE}d and were never WhatsApped "
          f"- likely an outage. These need manual review.", file=sys.stderr)

def due_for(recipient):
    """Only a 'sent' row blocks. 'failed' retries up to MAX_TRIES. 'skipped' always
    re-evaluates, so ticking opt-in later actually delivers."""
    return q(f"""SELECT r.rowid,a.label,DATE_FORMAT(a.datep,'%d-%m-%Y'),s.nom,
      COALESCE(e.whatsapp_number,''),COALESCE(e.whatsapp_optin,0)
      FROM llx_actioncomm_reminder r
      JOIN llx_actioncomm a ON a.id=r.fk_actioncomm
      JOIN llx_societe s ON s.rowid=a.fk_soc
      LEFT JOIN llx_societe_extrafields e ON e.fk_object=s.rowid
      WHERE r.dateremind<=NOW()
        AND r.dateremind >= DATE_SUB(NOW(), INTERVAL {MAX_AGE} DAY)
        AND NOT EXISTS (SELECT 1 FROM ca_wa_sent w
          WHERE w.fk_reminder=r.rowid AND w.recipient={sq(recipient)}
            AND (w.status='sent' OR w.fails>={MAX_TRIES}));""")

# ── Meta error classification ────────────────────────────────────────────────
# Meta's instruction is explicit: "Build your app's error handling around error
# codes instead of subcodes or HTTP response status codes." HTTP status is NOT
# documented for the Cloud API messages endpoint at all, so nothing below reads it.
#
# RETRYABLE is the CLOSED set Meta actually says to retry. Everything not in it is
# permanent for this payload: retrying burns the budget, delays the alert, and for
# 131048/368 actively makes the account's standing worse.
WA_RETRYABLE = {
    1:      "invalid request or possible server error - check status page",
    2:      "temporary downtime or overload",
    4:      "app API call rate limit",
    80007:  "WABA rate limit",
    130429: "throughput limit reached",
    131000: "unknown error, Meta says try again",
    131016: "service temporarily unavailable",
    131056: "pair rate limit - 1 msg per 6s to the same user",
    131057: "account in maintenance mode (upgrade in progress)",
    133004: "server temporarily unavailable",
}
# These fail EVERY message until a human acts. Continuing the run just multiplies
# the damage - an expired token would fail all 885 reminders, three times each.
WA_ACCOUNT_FATAL = {
    190:    "access token expired - get a new token",
    131031: "WABA restricted or disabled for a policy violation",
    133010: "sender phone number not registered on the platform",
    131042: "payment method problem - the account cannot send at all",
    368:    "temporarily blocked for policy violations - do NOT auto-retry",
    131048: "spam rate limit - quality is low; retrying makes it worse",
}
# Meta states "do not retry" for these in as many words.
WA_DO_NOT_RETRY = {
    130403: "this business has blocked the end user",
    131050: "user opted out of marketing messages from you",
    131049: "held to maintain healthy ecosystem engagement - wait 24h",
}


def wa_classify(code):
    """-> ('retry'|'permanent'|'fatal', human reason)"""
    if code in WA_ACCOUNT_FATAL: return 'fatal', WA_ACCOUNT_FATAL[code]
    if code in WA_RETRYABLE:     return 'retry', WA_RETRYABLE[code]
    if code in WA_DO_NOT_RETRY:  return 'permanent', WA_DO_NOT_RETRY[code]
    return 'permanent', 'not in Meta\'s documented retryable set'


def wa_parse_error(ex):
    """Pull Meta's structured error out of an HTTPError body.

    urlopen raises HTTPError whose BODY carries {"error":{"code":...}}. str(ex)
    gives only "HTTP Error 400: Bad Request", which is why every Meta error used
    to look identical and get retried the same way.
    """
    body = None
    try:
        raw = ex.read()
        body = json.loads(raw.decode() if isinstance(raw, bytes) else raw)
    except Exception:
        return None, str(ex)[:180]
    err = (body or {}).get('error') or {}
    code = err.get('code')
    detail = err.get('error_data', {}).get('details') or err.get('message') or ''
    return (int(code) if isinstance(code, int) else None), str(detail)[:180]


def record(rid, who, phone, wamid, status, err, permanent=False):
    # A permanent failure jumps straight to the cap: there is nothing to retry, and
    # burning two more attempts only delays telling the CA.
    inc = MAX_TRIES if (status == 'failed' and permanent) else (1 if status == 'failed' else 0)
    q(f"""INSERT INTO ca_wa_sent (fk_reminder,recipient,phone,wamid,status,err,tries,fails,sent_at)
      VALUES ({int(rid)},{sq(who)},{sq(phone)},{sq(wamid)},{sq(status)},{sq(err)},1,{inc},NOW())
      ON DUPLICATE KEY UPDATE wamid=VALUES(wamid), status=VALUES(status),
        err=VALUES(err), tries=tries+1, fails=fails+{inc}, sent_at=NOW();""")
    if status == 'failed':
        r = q(f"SELECT fails FROM ca_wa_sent WHERE fk_reminder={int(rid)} AND recipient={sq(who)};")
        n = int(r[0][0]) if r and r[0][0] else 0
        if n >= MAX_TRIES:
            # LOUD at the moment of exhaustion - not a silent drop discovered days later
            print(f"ALERT: reminder {rid}/{who} EXHAUSTED {n} delivery attempts and will "
                  f"NOT be retried. Manual action required.", file=sys.stderr)
            global exhausted; exhausted += 1

def send(rid, who, phone, client, label, due):
    if not re.fullmatch(r"\d{10,15}", phone or ""):
        record(rid, who, phone, None, "skipped", "invalid number format"); 
        print(f"  SKIP   rid={rid} -> {who}: invalid number {phone!r}"); return
    # Meta: "If the plus sign is omitted, your business phone number's country
    # calling code is prepended" - which silently misdelivers to another country.
    payload = {"messaging_product":"whatsapp","to":"+"+phone.lstrip("+"),"type":"template",
      "template":{"name":"compliance_deadline_reminder","language":{"code":"en"},
        "components":[{"type":"body","parameters":[
          {"type":"text","text":client},{"type":"text","text":label},{"type":"text","text":due}]}]}}
    req = urllib.request.Request(f"{BASE}/{PHONE_ID}/messages", data=json.dumps(payload).encode(),
        headers={"Authorization":f"Bearer {TOKEN}","Content-Type":"application/json"})
    try:
        # NB: a 200 means Meta ACCEPTED the request, not that it was delivered.
        # Delivery arrives later on the messages webhook.
        wamid = json.load(urllib.request.urlopen(req, timeout=10))["messages"][0]["id"]
        record(rid, who, phone, wamid, "sent", None)
        print(f"  SENT   rid={rid} -> {who} {phone} wamid={wamid}")
    except urllib.error.HTTPError as ex:
        code, detail = wa_parse_error(ex)
        kind, why = wa_classify(code) if code is not None else ('retry', 'unparseable error body')
        tag = f"[{code}]" if code is not None else "[?]"
        if kind == 'fatal':
            record(rid, who, phone, None, "failed", f"{code}: {detail}", permanent=True)
            print(f"  FATAL  rid={rid} {tag} {why}", file=sys.stderr)
            print(f"  HALTING: this fails every message until a human acts.", file=sys.stderr)
            global fatal_stop; fatal_stop = f"{code}: {why}"
        elif kind == 'permanent':
            record(rid, who, phone, None, "failed", f"{code}: {detail}", permanent=True)
            print(f"  PERMANENT rid={rid} -> {who} {tag} {why} - not retrying")
        else:
            record(rid, who, phone, None, "failed", f"{code}: {detail}")
            print(f"  RETRY  rid={rid} -> {who} {tag} {why} (cap {MAX_TRIES})")
    except Exception as ex:
        record(rid, who, phone, None, "failed", str(ex)[:180])
        print(f"  FAILED rid={rid} -> {who}: {ex}  (will retry, cap {MAX_TRIES})")

total = 0
if TO_CLIENT:
    rows = due_for("client"); total += len(rows)
    for rid,label,due,client,phone,optin in rows:
        if optin != "1":
            record(rid,"client",phone,None,"skipped","no documented opt-in")
            print(f"  SKIP   rid={rid} {client}: no opt-in (Meta requires it) - will re-check")
        elif fatal_stop is None:
            send(rid,"client",phone,client,label,due)
if TO_CA and CA_NUM:
    rows = due_for("ca"); total += len(rows)
    for rid,label,due,client,phone,optin in rows:
        send(rid,"ca",CA_NUM,client,label,due)
# An exhausted WhatsApp reminder used to leave ONE stderr line and then vanish -
# excluded from due_for AND from the aged-out query, so the 2-day backstop never
# mentioned it either. sweeper.sh gets this right for email; mirror it here:
# durable flag + real digest + do NOT mark escalated if the digest fails.
pending = q(f"""SELECT w.fk_reminder,w.recipient,w.phone,COALESCE(w.err,'?'),a.label
  FROM ca_wa_sent w JOIN llx_actioncomm_reminder r ON r.rowid=w.fk_reminder
  JOIN llx_actioncomm a ON a.id=r.fk_actioncomm
  WHERE w.fails>={MAX_TRIES} AND w.escalated=0;""")
if pending:
    lines = "\r\n".join(
        f"reminder#{p[0]} to {p[1]} {p[2]}  '{p[4]}'  last error: {p[3]}" for p in pending)
    body = (f"Subject: [ACTION REQUIRED] {len(pending)} WhatsApp reminder(s) permanently failed\r\n"
            f"From: {os.environ.get('CA_EMAIL','')}\r\nTo: {os.environ.get('CA_EMAIL','')}\r\n\r\n"
            f"These WhatsApp reminders exhausted {MAX_TRIES} attempts and were NEVER delivered:\r\n\r\n"
            f"{lines}\r\n")
    open('/tmp/wa_digest.eml','w').write(body)
    cp = subprocess.run(["docker","compose","cp","/tmp/wa_digest.eml","dolibarr:/tmp/digest.eml"],
                        capture_output=True, text=True)
    # sweeper.sh copies this too; omitting it made the digest fail with
    # "Could not open input file" and the CA was never told.
    cp2 = subprocess.run(["docker","compose","cp","send_digest.php","dolibarr:/tmp/send_digest.php"],
                         capture_output=True, text=True)
    if cp2.returncode != 0: cp = cp2
    snd = subprocess.run(["docker","compose","exec","-T","-e","CA_EMAIL","-e","CA_SMTP_HOST",
                          "-e","CA_SMTP_PORT","dolibarr","php","/tmp/send_digest.php"],
                         capture_output=True, text=True)
    if cp.returncode == 0 and snd.returncode == 0:
        ids = ",".join(str(int(p[0])) for p in pending)
        q(f"UPDATE ca_wa_sent SET escalated=1 WHERE fk_reminder IN ({ids}) AND fails>={MAX_TRIES};")
        print(f"  escalation digest DELIVERED for {len(pending)} exhausted reminder(s)")
    else:
        print(f"  ESCALATION DIGEST FAILED - NOT marking escalated so it retries: "
              f"{(snd.stderr or snd.stdout or cp.stderr).strip()[:160]}", file=sys.stderr)
        exhausted += len(pending)

print(f"processed {total} dispatch candidate(s); aged-out {n_aged}")
sys.exit(1 if (n_aged or exhausted or pending) else 0)
