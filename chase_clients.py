#!/usr/bin/env python3
"""
Chase the CLIENT for documents. This is the product.

The CA already knows GSTR-3B is due on the 20th. What loses him the deadline is
Sharma Textiles not sending the purchase register on the 17th. So WhatsApp -
the one channel Indian clients actually read - points at the client asking for
documents, not at the CA announcing a date he already knows.

Escalation: T-10 polite, T-5 firm, T-2 urgent. Never more than one message per
client per day, however many documents are outstanding.
Usage: chase_clients.py [--commit]
"""
import subprocess, json, urllib.request, os, sys, re
HERE=os.path.dirname(os.path.abspath(__file__))
def _load():
    for fn in ("ca.env","secrets.env"):
        p=os.path.join(HERE,fn)
        if not os.path.exists(p): continue
        for raw in open(p):
            l=raw.strip()
            if not l or l.startswith("#"): continue
            if l.startswith("export "): l=l[7:]
            if "=" not in l: continue
            k,v=l.split("=",1); k=k.strip(); v=v.strip().strip('"').strip("'")
            m=re.match(r"^\$\{(\w+):-(.*)\}$",v)
            if m: v=os.environ.get(m.group(1),m.group(2))
            elif v.startswith("${"): continue
            os.environ.setdefault(k,v)
    os.chdir(HERE)
_load()
COMMIT="--commit" in sys.argv
PHONE_ID=os.environ.get("WA_PHONE_ID",""); TOKEN=os.environ.get("WA_TOKEN","")
BASE=os.environ.get("WA_API_BASE",""); DBPW=os.environ.get("DB_PASSWORD","")
if not (BASE.startswith("https://") or os.environ.get("WA_ALLOW_INSECURE")=="1"):
    sys.exit(f"FATAL: WA_API_BASE is not https ({BASE}).")

def sq(v):
    if v is None: return "NULL"
    s=str(v).replace("\\","\\\\").replace("'","''")
    return "'"+"".join(c for c in s if c>=" " or c==" ")+"'"
def q(sql):
    r=subprocess.run(["docker","compose","exec","-T","-e",f"MYSQL_PWD={DBPW}","db",
        "mariadb","-udolibarr","dolibarr","-sN","-e","SET time_zone='+05:30'; "+sql],
        capture_output=True,text=True)
    if r.returncode!=0:
        print(f"FATAL: db error: {r.stderr.strip()[:200]}",file=sys.stderr); sys.exit(2)
    # NB: do NOT .strip() the whole payload - it eats the trailing tab when the
    # last column is empty (last_chase is NULL for every never-chased client),
    # which silently drops a field and crashes the unpack on the first run.
    return [l.split("\t") for l in r.stdout.split("\n") if l.strip()]

# one row per client: their most urgent outstanding item + how many others
rows=q("""SELECT s.rowid, s.nom, MIN(d.due) AS due, COUNT(*) AS n,
   SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT d.doc_type ORDER BY d.due SEPARATOR ' | '),' | ',3) AS docs,
   SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT d.filing ORDER BY d.due),',',1) AS filing,
   COALESCE(e.whatsapp_number,''), COALESCE(e.whatsapp_optin,0),
   DATEDIFF(MIN(d.due), CURDATE()) AS days_left,
   COALESCE(MAX(d.chase_count),0) AS chases,
   COALESCE(DATE(MAX(d.last_chase)),'') AS last
 FROM ca_docrequest d
 JOIN llx_societe s ON s.rowid=d.fk_soc
 LEFT JOIN llx_societe_extrafields e ON e.fk_object=s.rowid
 WHERE d.status='pending' AND d.due >= CURDATE()
 GROUP BY s.rowid, s.nom, e.whatsapp_number, e.whatsapp_optin
 HAVING days_left <= 10
 ORDER BY days_left, n DESC;""")

def tone(d):
    return ("urgent","Only %d day(s) left") if d<=2 else \
           ("firm","Due in %d days")        if d<=5 else \
           ("polite","Due in %d days")
sent=skip=fail=0
for sid,nom,due,n,docs,filing,phone,optin,days,chases,last in rows:
    days=int(days); n=int(n)
    if last==__import__("datetime").date.today().isoformat():
        skip+=1; continue                                   # one message per client per day
    if optin!="1":
        skip+=1
        if not COMMIT: print(f"  SKIP  {nom}: no WhatsApp opt-in")
        continue
    if not re.fullmatch(r"\d{10,15}", phone or ""):
        skip+=1
        if not COMMIT: print(f"  SKIP  {nom}: no valid number")
        continue
    level,_=tone(days)
    extra=f" (+{n-3} more)" if n>3 else ""
    body=f"{docs}{extra}"
    if not COMMIT:
        # The statutory label gets its full width and is NEVER cut: 22 chars turned
        # "Advance tax 45% FY2026-27" into "Advance tax 45% FY2026", which reads as
        # the wrong financial year to a CA. Client names ellipsise instead.
        nm = nom if len(nom) <= 26 else nom[:25]+"\u2026"
        print(f"  WOULD CHASE [{level:6}] {nm:26} {filing:31} in {days:2}d \u2014 {n} doc{'' if n==1 else 's'}")
        sent+=1; continue
    payload={"messaging_product":"whatsapp","to":phone,"type":"template",
      "template":{"name":"document_request_reminder","language":{"code":"en"},
        "components":[{"type":"body","parameters":[
          {"type":"text","text":nom},{"type":"text","text":filing},
          {"type":"text","text":str(days)},{"type":"text","text":body}]}]}}
    req=urllib.request.Request(f"{BASE}/{PHONE_ID}/messages",data=json.dumps(payload).encode(),
      headers={"Authorization":f"Bearer {TOKEN}","Content-Type":"application/json"})
    try:
        urllib.request.urlopen(req,timeout=10).read()
        q(f"UPDATE ca_docrequest SET chase_count=chase_count+1, last_chase=NOW() "
          f"WHERE fk_soc={int(sid)} AND status='pending';")
        print(f"  CHASED [{level}] {nom} \u2014 {n} doc{'' if n==1 else 's'}, {days}d left"); sent+=1
    except Exception as ex:
        print(f"  FAILED {nom}: {ex}",file=sys.stderr); fail+=1
print(f"{'chased' if COMMIT else 'would chase'} {sent}, skipped {skip}, failed {fail}")
sys.exit(1 if fail else 0)
