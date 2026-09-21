#!/usr/bin/env bash
# STANDING GATE. Every defect found in review traced back to never running the
# suite against EMPTY volumes. This does exactly that. Run before any handover.
set -uo pipefail
cd "$(dirname "$0")"; . ./ca.env; set -a; . ./secrets.env; set +a
# single DB helper for every step (MYSQL_PWD, never -p on argv)
Q(){ docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN \
       -e "$1" 2>/dev/null | grep -v -i "insecure\|warning"; }
fail=0; step(){ printf '\n=== %s ===\n' "$1"; }

# THE SUITE MUST NEVER TALK TO META. mockmeta.py stands in for the Graph API.
# Three call sites below started the mock (or not) and then let wa_fanout.py read
# WA_API_BASE from ca.env - https://graph.facebook.com - so every run made live
# outbound calls to Meta with a bogus token. They returned 401, which still
# leaves retry attempts, so the gates passed and nobody saw it. A later run then
# burned the remaining attempts and the job exited 1 on an unrelated step.
# ca.env writes ${WA_API_BASE:-default}, so an exported value wins by design.
export WA_API_BASE="http://127.0.0.1:9099"
export WA_ALLOW_INSECURE=1
python3 mockmeta.py & MOCK_PID=$!
trap 'kill $MOCK_PID 2>/dev/null' EXIT
sleep 1
curl -s -o /dev/null --max-time 3 -X POST -d '{}' http://127.0.0.1:9099/ping \
  && echo "  mock Graph API up on 127.0.0.1:9099 (no request leaves this host)" \
  || { echo "  FAIL: mock Graph API did not start - the suite would call Meta for real"; fail=1; }
# the health-check POST lands in the capture file too; start each run clean so
# the "did anything actually reach the mock" count cannot pass on its own probe
rm -f /tmp/wa_capture.json
step "wipe to virgin volumes"
# The compose project was renamed bakeoff -> ca-practice; this pattern was not.
# It matched nothing, so the gate reported "0 survived" however many survived -
# a gate that could not fail. Derive the prefix, then PROVE the detector can see
# a real volume before trusting the zero it reports.
proj="${COMPOSE_PROJECT_NAME:-$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]_-')}"
VOLRE="^${proj}_(db_data|doli_docs|doli_html)$"
docker volume create "${proj}_db_data" >/dev/null 2>&1   # canary: planted, must be seen
probe=$(docker volume ls --format '{{.Name}}' | grep -cE "$VOLRE")
[ "${probe:-0}" -ge 1 ] && echo "  detector live (sees ${proj}_db_data)" \
  || { echo "  FAIL: '$VOLRE' cannot see a planted volume - this gate would pass vacuously"; fail=1; }
docker compose down -v >/dev/null 2>&1
n=$(docker volume ls --format '{{.Name}}' | grep -cE "$VOLRE")
[ "$n" = "0" ] && echo "  app volumes: 0" || { echo "  FAIL: $n app volume(s) survived"; fail=1; }
step "deploy from nothing";      CA_IMPORT_CLIENTS=1 CA_RAISE_FEES=1 ./deploy.sh >/tmp/r_dep 2>&1 || { echo "  FAIL"; tail -5 /tmp/r_dep; fail=1; }
grep -qE "client extrafields: ([0-9]+)/\\1( |$)" /tmp/r_dep && echo "  extrafields OK" || { echo "  FAIL: extrafields"; fail=1; }
step "CSV import via deploy.sh's own wiring"
grep -q "imported=" /tmp/r_dep && sed -n 's/^\(  imported=.*\)/ \1/p' /tmp/r_dep || { echo "  FAIL: deploy.sh did not run the importer"; fail=1; }
n=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e "SELECT COUNT(*) FROM llx_societe;" 2>/dev/null | grep -v -i "insecure\|warning")
g=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e "SELECT COUNT(*) FROM llx_societe_extrafields WHERE gstin<>'' AND pan<>'';" 2>/dev/null | grep -v -i "insecure\|warning")
echo "  clients=$n  with GSTIN+PAN=$g"
want=$(tail -n +2 clients.csv | cut -d, -f1 | sort -u | wc -l | tr -d ' ')
[ "${g:-0}" -ge "$want" ] && echo "  import verified ($g/$want distinct clients carry GSTIN+PAN)" || { echo "  FAIL: only $g/$want have GSTIN+PAN"; fail=1; }

step "filing ledger closes the loop (documents in -> return out)"
fl=$(Q "SELECT COUNT(*) FROM ca_filing;")
pre=$(Q "SELECT COUNT(*) FROM ca_filing WHERE status='not_applicable';")
badrisk=$(Q "SELECT COUNT(*) FROM ca_filing WHERE status='not_applicable' AND exposure_inr>0;")
echo "  filings=$fl pre-go-live=$pre"
[ "${fl:-0}" -ge 500 ] && echo "  ledger OK" || { echo "  FAIL: only ${fl:-0} filings"; fail=1; }
# a system switched on today must not invent a liability for returns filed before it existed
[ "${badrisk:-1}" = "0" ] && echo "  no fabricated pre-go-live exposure" \
  || { echo "  FAIL: $badrisk pre-go-live filings carry exposure"; fail=1; }
arn=$(Q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='dolibarr' AND table_name='ca_filing' AND column_name='arn';")
[ "${arn:-0}" = "1" ] && echo "  ARN/acknowledgement recorded" || { echo "  FAIL: no ARN column"; fail=1; }

step "the CA can record that a return was FILED (pending -> ready -> filed + ARN)"
# The ledger had arn/filed_at/filed_by columns and NOTHING in the product wrote
# them. A practice that cannot record a filing leaves every return 'ready' for
# ever. This drives the whole lifecycle, so the write path cannot go orphan again.
# GSTR filings only, so the acknowledgement family - and the valid ARN shape - is
# deterministic. Drive filings from pending to ready ON DEMAND: assuming a spare
# still waiting on documents.
docker compose cp filings.php dolibarr:/tmp/fl.php >/dev/null 2>&1
mkready(){
  local f ac
  f=$(Q "SELECT f.rowid FROM ca_filing f JOIN ca_docrequest d ON d.fk_actioncomm=f.fk_actioncomm WHERE f.status='pending' AND f.filing LIKE 'GSTR-%' LIMIT 1;")
  [ -n "$f" ] || return 1
  ac=$(Q "SELECT fk_actioncomm FROM ca_filing WHERE rowid=$f;")
  Q "UPDATE ca_docrequest SET status='received' WHERE fk_actioncomm=$ac;" >/dev/null
  docker compose exec -T -e CA_GOLIVE dolibarr php /tmp/fl.php >/dev/null 2>&1
  echo "$f"
}
fid=$(mkready)
if [ -z "${fid:-}" ]; then echo "  FAIL: no pending filing with documents to drive the lifecycle"; fail=1; else
  st=$(Q "SELECT status FROM ca_filing WHERE rowid=$fid;")
  [ "$st" = "ready" ] && echo "  documents received -> READY" || { echo "  FAIL: docs all received but status=$st"; fail=1; }
  docker compose cp file_return.php dolibarr:/tmp/fr.php >/dev/null 2>&1
  docker compose exec -T dolibarr php /tmp/fr.php "$fid" AA070926123456X >/tmp/r_fr 2>&1
  st=$(Q "SELECT status FROM ca_filing WHERE rowid=$fid;")
  ar=$(Q "SELECT COALESCE(arn,'') FROM ca_filing WHERE rowid=$fid;")
  fa=$(Q "SELECT filed_at IS NOT NULL FROM ca_filing WHERE rowid=$fid;")
  fb=$(Q "SELECT filed_by IS NOT NULL FROM ca_filing WHERE rowid=$fid;")
  { [ "$st" = "filed" ] && [ -n "$ar" ] && [ "$fa" = "1" ] && [ "$fb" = "1" ]; } \
    && echo "  recorded: status=$st arn=$ar (filed_at and filed_by set)" \
    || { echo "  FAIL: transition not recorded (status=$st arn='$ar' filed_at=$fa filed_by=$fb)"; sed 's/^/      /' /tmp/r_fr | head -3; fail=1; }
  # An unverifiable acknowledgement fakes proof of filing, so junk must be refused.
  # Assert the tool RAN and REFUSED: "state unchanged" is also true when the tool
  # is missing altogether, which is how the first cut of this passed vacuously.
  fid2=$(mkready)   # a second one, freshly driven to ready - do not assume a spare exists
  if [ -z "${fid2:-}" ]; then echo "  FAIL: no ready GSTR filing to test refusal against"; fail=1; else
    docker compose exec -T dolibarr php /tmp/fr.php "$fid2" "'; DROP TABLE ca_filing; --" >/tmp/r_fr2 2>&1; rc2=$?
    st2=$(Q "SELECT status FROM ca_filing WHERE rowid=$fid2;")
    alive=$(Q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='dolibarr' AND table_name='ca_filing';")
    { [ "$rc2" -eq 1 ] && grep -q REFUSED /tmp/r_fr2 && [ "$st2" = "ready" ] && [ "$alive" = "1" ]; } \
      && echo "  junk acknowledgement refused (exit $rc2), table intact" \
      || { echo "  FAIL: junk not refused (exit=$rc2 status=$st2 table_alive=$alive)"; sed 's/^/      /' /tmp/r_fr2 | head -2; fail=1; }
    # a filing recorded against tomorrow would let a late return look punctual
    docker compose exec -T dolibarr php /tmp/fr.php "$fid2" AA070926999888X 2099-01-01 >/tmp/r_fr3 2>&1; rc3=$?
    { [ "$rc3" -eq 1 ] && grep -q 'in the future' /tmp/r_fr3; } && echo "  back-dating guard: future filing date refused" \
      || { echo "  FAIL: accepted a filing date in the future (exit=$rc3)"; fail=1; }
  fi
  # The statutory rate table drives the exposure the CA is shown. Exercise it
  # directly: the DB path above files on time, so nothing else proves these rates.
  exo=$(docker compose exec -T dolibarr php -r 'require "/var/www/html/custom/ca/ca_filing_lib.php"; list($a,)=exposureFor("GSTR-3B Aug 2026",9); list($b,)=exposureFor("GSTR-3B Aug 2026",400); list($c,)=exposureFor("TDS return 26Q Q1",10); echo "$a|$b|$c";' 2>/dev/null | tr -d '\r')
  [ "$exo" = "450|5000|2000" ] && echo "  late-fee table: GSTR 9d=Rs450, capped Rs5000, TDS 10d=Rs2000" \
    || { echo "  FAIL: late-fee table gave '$exo', expected '450|5000|2000'"; fail=1; }
fi
# the screen the CA actually uses - present, and behind the login
pg=$(docker compose exec -T dolibarr sh -c 'test -f /var/www/html/custom/ca/filings.php && echo yes || echo no' 2>/dev/null | tr -d '\r')
[ "$pg" = "yes" ] && echo "  filings screen present" || { echo "  FAIL: no screen - write path is CLI-only, the CA cannot reach it"; fail=1; }
code=$(curl -s -o /tmp/r_pg -w '%{http_code}' "http://127.0.0.1:8080/custom/ca/filings.php" --max-time 15)
if grep -qiE 'name="password"|dol_loginfunction|<form[^>]*login' /tmp/r_pg; then
  echo "  screen is behind the Dolibarr login (http $code)"
else
  echo "  FAIL: filings screen answered without a login (http $code) - unauthenticated write endpoint"; fail=1
fi

step "statutory labels name the year the statute means"
# FY2026-27's March IS March 2027. The calendar read 'TDS payment Mar 2026'
# against a 30-Apr-2027 due date, and DIR-3 KYC carried the previous FY's date.
# These assert the RELATIONSHIP, so they still hold after the FY rolls over.
badtds=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE label LIKE 'TDS payment Mar %' AND (YEAR(datep) <> CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(label,'TDS payment Mar ',-1),' ',1) AS UNSIGNED) OR MONTH(datep)<>4 OR DAY(datep)<>30);")
baddir=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE label LIKE 'DIR-3 KYC FY%' AND (YEAR(datep) <> CAST(SUBSTRING(label,LOCATE('FY',label)+2,4) AS UNSIGNED)+1 OR MONTH(datep)<>9 OR DAY(datep)<>30);")
noyear=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE (label LIKE '%(QRMP)%' OR label LIKE 'CMP-08%') AND SUBSTRING_INDEX(label,' - ',1) NOT REGEXP '[0-9]{4}';")
echo "  tds-label-vs-due=$badtds  dir3-fy-vs-due=$baddir  quarterly-without-year=$noyear"
[ "${badtds:-1}" = "0" ] && echo "  'TDS payment Mar YYYY' falls due 30-Apr-YYYY" || { echo "  FAIL: $badtds TDS labels name the wrong year"; fail=1; }
[ "${baddir:-1}" = "0" ] && echo "  DIR-3 KYC FYa-b falls due 30-Sep of b" || { echo "  FAIL: $baddir DIR-3 rows dated to the wrong FY"; fail=1; }
[ "${noyear:-1}" = "0" ] && echo "  every quarterly label carries its year" || { echo "  FAIL: $noyear quarterly labels name no year"; fail=1; }

step "app clock and db clock agree (no created-after-modified)"
# datec written by PHP in IST, tms by MariaDB ON UPDATE in UTC: every record
# looked modified 5h30m before it was created, in the one column a regulator reads.
inv=$(Q "SELECT COUNT(*) FROM llx_societe WHERE tms < datec;")
worst=$(Q "SELECT COALESCE(MAX(TIMESTAMPDIFF(MINUTE,tms,datec)),0) FROM llx_societe;")
dbtz=$(Q "SELECT @@session.time_zone;")
echo "  db tz=$dbtz  modified-before-created=$inv  worst skew=${worst}min"
[ "${inv:-1}" = "0" ] && echo "  no timestamp inversion" || { echo "  FAIL: $inv client(s) modified before they were created"; fail=1; }
[ "${worst:-999}" -le 1 ] && echo "  PHP(IST) and MariaDB agree" || { echo "  FAIL: ${worst}min skew between app and db clocks"; fail=1; }

step "no reserved or undeliverable domains in client data"
res=$(Q "SELECT COUNT(*) FROM llx_societe WHERE email<>'' AND (email LIKE '%@example.%' OR email LIKE '%.example.%' OR email LIKE '%@test.%' OR email LIKE '%@localhost%' OR email LIKE '%.invalid');")
tot=$(Q "SELECT COUNT(*) FROM llx_societe WHERE email<>'';")
echo "  emails=$tot on-reserved-domains=$res"
[ "${res:-1}" = "0" ] && echo "  every client email sits on a plausible domain" \
  || { echo "  FAIL: $res client(s) on IANA-reserved domains (mail silently blackholes)"; fail=1; }

step "dates render day-first, the way an Indian practice reads them"
# MAIN_DATE_FORMAT / _SHORT / MAIN_DATETIME_FORMAT were written to llx_const and
# are read by NOTHING in Dolibarr - dol_print_date() takes its format from the
# LANGUAGE PACK. The database looked configured while every screen still rendered
# American dates. en_US also ships TWO different American formats, %m/%d/%Y for
# text and mm/dd/yy for the datepicker, which is how 09/14/2026 and 12/31/27 both
# appeared on one screen in the demo recording.
# Assert the RENDERED string, not the constant - believing llx_const IS the bug.
lang=$(Q "SELECT value FROM llx_const WHERE name='MAIN_LANG_DEFAULT';")
inert=$(Q "SELECT COUNT(*) FROM llx_const WHERE name IN ('MAIN_DATE_FORMAT','MAIN_DATE_FORMAT_SHORT','MAIN_DATETIME_FORMAT');")
docker compose cp datefmt_check.php dolibarr:/tmp/dfc.php >/dev/null 2>&1
rendered=$(docker compose exec -T dolibarr php /tmp/dfc.php 2>/dev/null | tr -d '\r\n')
docker compose exec -T dolibarr rm -f /tmp/dfc.php >/dev/null 2>&1
echo "  lang=$lang  inert-date-constants=$inert  rendered=$rendered"
[ "$lang" = "en_IN" ] && echo "  language pack en_IN (day-first, still English)" \
  || { echo "  FAIL: MAIN_LANG_DEFAULT='$lang' renders month-first"; fail=1; }
[ "${inert:-1}" = "0" ] && echo "  no inert date constants pretending to configure anything" \
  || { echo "  FAIL: $inert MAIN_DATE_* constant(s) present - Dolibarr reads none of them"; fail=1; }
# 31 December: day 31 cannot be mistaken for a month, so this is unambiguous
[ "$rendered" = "31/12/2026|dd/mm/yy" ] && echo "  31-Dec-2026 renders 31/12/2026, datepicker dd/mm/yy" \
  || { echo "  FAIL: rendered '$rendered', expected '31/12/2026|dd/mm/yy'"; fail=1; }
step "a client added in the UI gets a calendar (not silently nothing)"
# Creating a client through Dolibarr's own "New Third Party" form produced ZERO
# deadlines, ZERO document requests and ZERO reminders - silently, no error. The
# calendar only ever ran from deploy.sh over SSH, so the practice would not find
# out until a due date was missed. This gate is that hole.
grep -q 'ca_job.sh calendar' ca.crontab && echo "  calendar is scheduled nightly" \
  || { echo "  FAIL: calendar not in ca.crontab - a new client waits for a manual deploy"; fail=1; }
Q "INSERT INTO llx_societe (entity,nom,client,datec,tms,status) VALUES (1,'__GATE_UI__',1,NOW(),NOW(),1);" >/dev/null
gsid=$(Q "SELECT rowid FROM llx_societe WHERE nom='__GATE_UI__';")
Q "INSERT INTO llx_societe_extrafields (fk_object,gstin,pan,tan,gst_scheme,entity_type,roc_applicable,whatsapp_optin)
   VALUES ($gsid,'27AAACT2727Q1ZW','AAACT2727Q','MUMA12345B','Monthly','Private Limited',1,1);" >/dev/null
./ca_job.sh calendar >/tmp/r_cal 2>&1
dl=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE fk_soc=$gsid;")
echo "  deadlines generated for the UI-created client: $dl"
[ "${dl:-0}" -ge 30 ] && echo "  the nightly job picks it up without anyone opening a shell" \
  || { echo "  FAIL: only ${dl:-0} deadlines - a UI-added client is invisible to the product"; tail -2 /tmp/r_cal | sed 's/^/      /'; fail=1; }
Q "DELETE r FROM llx_actioncomm_reminder r JOIN llx_actioncomm a ON a.id=r.fk_actioncomm WHERE a.fk_soc=$gsid;" >/dev/null
Q "DELETE x FROM llx_actioncomm_resources x JOIN llx_actioncomm a ON a.id=x.fk_actioncomm WHERE a.fk_soc=$gsid;" >/dev/null
Q "DELETE FROM llx_actioncomm WHERE fk_soc=$gsid;" >/dev/null
Q "DELETE FROM llx_societe_extrafields WHERE fk_object=$gsid;" >/dev/null
Q "DELETE FROM llx_societe WHERE rowid=$gsid;" >/dev/null

step "the statutory rules match the statute, not just themselves"
# Every other gate proves the calendar is SELF-CONSISTENT - that a label saying
# "TDS payment Mar 2027" carries the date the code intends. None of them can prove
# the code intends the RIGHT date, because each encodes the same assumption as the
# rule it tests. statute_test.php is the other half: every assertion names the
# provision it comes from, so a wrong one must be argued against the citation.
docker compose cp statute_test.php dolibarr:/tmp/st.php >/dev/null 2>&1
out=$(docker compose exec -T dolibarr php /tmp/st.php 2>&1); rc=$?
echo "$out" | tail -1 | sed 's/^/  /'
if [ $rc -eq 0 ]; then echo "  every statutory rule asserted against its provision"
else echo "  FAIL: the calendar disagrees with the statute"; echo "$out" | grep -E '^  (WRONG|MISSING|EXTRA)' | head -6 | sed 's/^/    /'; fail=1; fi
docker compose exec -T dolibarr rm -f /tmp/st.php >/dev/null 2>&1

step "a holiday or a government extension moves REAL due dates"
FILRE="label REGEXP '^(GSTR-|CMP-08|TDS |Advance tax|ITR|Tax audit|AOC-4|MGT-7|LLP Form|DIR-3)'"
sun=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE $FILRE AND DAYOFWEEK(datep)=1;")
echo "  statutory deadlines falling on a Sunday: $sun (correct - they are NOT moved)"
# THIS GATE USED TO ASSERT THE OPPOSITE, AND THE OPPOSITE WAS WRONG.
# s.10 of the General Clauses Act only operates where a Court or Office is CLOSED.
# The GST and income-tax portals are open 24x7, so a due date landing on a Sunday
# does NOT move. Shifting it to Monday told the CA a date LATER than the statute -
# he files on the Monday and pays the late fee this product exists to prevent.
# A statutory date must survive generation EXACTLY, unless an extension moved it.
[ "${sun:-0}" -ge 1 ] && echo "  Sundays present and flagged, not shifted - the statutory date survived" \
  || { echo "  FAIL: 0 statutory dates on a Sunday - something is still moving them"; fail=1; }
# An extension must move deadlines that ALREADY exist, not just future ones -
# the adjustments screen promises exactly that.
pre=$(Q "SELECT label FROM llx_actioncomm WHERE label LIKE 'GSTR-3B %' ORDER BY datep LIMIT 1;" | sed 's/ - .*//')
if [ -z "${pre:-}" ]; then echo "  FAIL: no GSTR-3B deadline to test an extension against"; fail=1; else
  was=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE label LIKE '${pre}%';")
  Q "INSERT INTO ca_extension (filing_like,new_due,note,fk_user,datec) VALUES ('${pre}','2027-02-28','regression gate',1,NOW())
     ON DUPLICATE KEY UPDATE new_due='2027-02-28';" >/dev/null
  ./ca_job.sh calendar >/dev/null 2>&1
  mv=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE label LIKE '${pre}%' AND DATE(datep)='2027-02-28';")
  echo "  '${pre}': $mv of $was existing deadline(s) moved"
  { [ "${mv:-0}" = "${was:-0}" ] && [ "${mv:-0}" -ge 1 ]; } && echo "  the extension reached every client at once" \
    || { echo "  FAIL: extension moved $mv of $was"; fail=1; }
  Q "DELETE FROM ca_extension WHERE filing_like='${pre}';" >/dev/null
  ./ca_job.sh calendar >/dev/null 2>&1
  back=$(Q "SELECT COUNT(*) FROM llx_actioncomm WHERE label LIKE '${pre}%' AND DATE(datep)='2027-02-28';")
  [ "${back:-1}" = "0" ] && echo "  withdrawing it restores the statutory date" \
    || { echo "  FAIL: $back deadline(s) stuck on the withdrawn extension date"; fail=1; }
fi

step "late-fee rates are editable data, not PHP source"
rates=$(Q "SELECT COUNT(*) FROM ca_rate;")
echo "  rate rows: $rates"
[ "${rates:-0}" -ge 8 ] && echo "  seeded" || { echo "  FAIL: ca_rate has ${rates:-0} rows"; fail=1; }
# Changing a statutory rate must change the figure the CA is shown, WITHOUT a
# deploy. If this fails the rates are decorative and the real ones are still in PHP.
Q "UPDATE ca_rate SET per_day=77 WHERE pattern='^GSTR-(1|3B)';" >/dev/null
got=$(docker compose exec -T dolibarr php -r 'require "/var/www/html/custom/ca/ca_filing_lib.php"; define("NOSESSION","1"); require_once "/var/www/html/master.inc.php"; global $db; list($a,)=exposureFor("GSTR-3B Aug 2026",10,$db); echo (int)$a;' 2>/dev/null | tr -d '\r')
[ "$got" = "770" ] && echo "  editing a rate changes the exposure (10d x Rs 77 = Rs 770)" \
  || { echo "  FAIL: per_day set to 77, 10 days gave '$got' not 770"; fail=1; }
Q "UPDATE ca_rate SET per_day=50 WHERE pattern='^GSTR-(1|3B)';" >/dev/null

step "the screens are usable without a mouse or a screen"
# axe-core, WCAG 2.1 A + AA, against every screen the CA touches. This had never
# been run: the custom screens used placeholder-only inputs, which a screen reader
# announces as "edit text, blank" - 50 critical violations, all ours. Dolibarr's
# own chrome carries violations we cannot fix without forking a GPL dependency,
# so the gate judges OUR markup only and says so.
if [ -d node_modules/axe-core ] && [ -f a11y_audit.mjs ]; then
  a11y=$(node a11y_audit.mjs 2>&1 | grep -oE 'OUR violations: [0-9]+' | grep -oE '[0-9]+$')
  echo "  accessibility violations in our markup: ${a11y:-?}"
  [ "${a11y:-1}" = "0" ] && echo "  every form control has an accessible name" \
    || { echo "  FAIL: ${a11y} WCAG violation(s) in our own screens"; fail=1; }
else
  echo "  FAIL: axe-core or a11y_audit.mjs missing - the gate cannot run"; fail=1
fi

step "every screen the practice is run from is present and behind the login"
for pg in filings client rates adjustments; do
  ex=$(docker compose exec -T dolibarr sh -c "test -f /var/www/html/custom/ca/${pg}.php && echo yes || echo no" 2>/dev/null | tr -d '\r')
  if [ "$ex" != "yes" ]; then echo "  FAIL: ${pg}.php not installed"; fail=1; continue; fi
  code=$(curl -s -o /tmp/r_pg -w '%{http_code}' "http://127.0.0.1:8080/custom/ca/${pg}.php" --max-time 15)
  if grep -qiE 'name="password"|dol_loginfunction|<form[^>]*login' /tmp/r_pg; then
    echo "  ${pg}.php present, behind the login (http $code)"
  else
    echo "  FAIL: ${pg}.php answered without a login (http $code) - unauthenticated write endpoint"; fail=1
  fi
done


step "document requests generated from real filings only"
dr=$(Q "SELECT COUNT(*) FROM ca_docrequest;")
junk=$(Q "SELECT COUNT(*) FROM ca_docrequest WHERE filing LIKE 'Invoice %' OR filing LIKE 'Third party %';")
cl=$(Q "SELECT COUNT(DISTINCT fk_soc) FROM ca_docrequest WHERE status='pending';")
echo "  requests=$dr clients=$cl audit-event-junk=$junk"
[ "${dr:-0}" -ge 50 ] && echo "  doc requests OK" || { echo "  FAIL: only ${dr:-0}"; fail=1; }
[ "${junk:-1}" = "0" ] && echo "  no audit events mistaken for filings" || { echo "  FAIL: $junk audit events treated as filings"; fail=1; }

step "client chase respects opt-in and one-per-day"
# chase_clients.py only chases documents due within 10 days - correctly, nobody
# wants nagging about a return due in a fortnight. But that made this gate depend
# on the WALL CLOCK: run the suite on a date when the nearest deadline is 16 days
# out and the chase legitimately does nothing, the grep below finds no SKIP line,
# and a green product reports red. Put one document inside the window first.
noopt=$(Q "SELECT s.rowid FROM llx_societe s LEFT JOIN llx_societe_extrafields e ON e.fk_object=s.rowid
           WHERE s.client=1 AND COALESCE(e.whatsapp_optin,0)=0 LIMIT 1;")
yesopt=$(Q "SELECT s.rowid FROM llx_societe s JOIN llx_societe_extrafields e ON e.fk_object=s.rowid
            WHERE s.client=1 AND e.whatsapp_optin=1 AND COALESCE(e.whatsapp_number,'')<>'' LIMIT 1;")
for c in "$noopt" "$yesopt"; do
  [ -n "$c" ] && Q "UPDATE ca_docrequest SET due=DATE_ADD(CURDATE(), INTERVAL 3 DAY), last_chase=NULL
                     WHERE fk_soc=$c AND status='pending' LIMIT 4;" >/dev/null
done
inwindow=$(Q "SELECT COUNT(*) FROM ca_docrequest WHERE status='pending' AND DATEDIFF(due,CURDATE()) BETWEEN 0 AND 10;")
echo "  documents inside the 10-day chase window: $inwindow"
[ "${inwindow:-0}" -ge 1 ] || { echo "  FAIL: could not put a document in the chase window"; fail=1; }
out=$(WA_ALLOW_INSECURE=1 WA_API_BASE=http://127.0.0.1:9099 python3 chase_clients.py 2>&1)
echo "$out" | tail -1 | sed 's/^/  /'
echo "$out" | grep -q "Traceback" && { echo "  FAIL: chase crashed"; echo "$out" | tail -3 | sed 's/^/      /'; fail=1; }
echo "$out" | grep -q "SKIP.*no WhatsApp opt-in" && echo "  opt-in enforced on the chase" \
  || { echo "  FAIL: chase ignored opt-in"; fail=1; }

step "morning digest renders and delivers"
docker compose cp morning_digest.php dolibarr:/tmp/md.php >/dev/null 2>&1
before=$(curl -s http://127.0.0.1:8025/api/v1/messages 2>/dev/null | python3 -c "import sys,json;print(json.load(sys.stdin).get('total',0))" 2>/dev/null || echo 0)
docker compose exec -T -e CA_EMAIL dolibarr php /tmp/md.php >/tmp/r_md 2>&1
after=$(curl -s http://127.0.0.1:8025/api/v1/messages 2>/dev/null | python3 -c "import sys,json;print(json.load(sys.stdin).get('total',0))" 2>/dev/null || echo 0)
echo "  digest inbox $before -> $after"
[ "${after:-0}" -gt "${before:-0}" ] && echo "  digest delivered" || { echo "  FAIL: digest not delivered"; tail -2 /tmp/r_md; fail=1; }

step "per-user accounts exist AND can actually work (no shared admin)"
u=$(Q "SELECT COUNT(*) FROM llx_user WHERE statut=1;")
g=$(Q "SELECT COUNT(*) FROM llx_usergroup WHERE nom='Practice Staff';")
nonadmin=$(Q "SELECT COUNT(*) FROM llx_user WHERE statut=1 AND COALESCE(admin,0)=0;")
echo "  users=$u staff_group=$g non_admin=$nonadmin"
{ [ "${u:-0}" -ge 3 ] && [ "${g:-0}" -ge 1 ] && [ "${nonadmin:-0}" -ge 1 ]; } \
  && echo "  attribution OK - work is traceable to a person" \
  || { echo "  FAIL: shared-admin posture (users=$u group=$g non_admin=$nonadmin)"; fail=1; }
# Counting accounts is not enough. article1/article2 existed with ZERO effective
# rights - they could log in and do nothing, so every real task went back to the
# admin login and this gate passed while the shared-admin posture it forbids was
# exactly what the practice had. Capability, not headcount.
minrights=$(Q "SELECT COALESCE(MIN(c),0) FROM (SELECT (SELECT COUNT(*) FROM llx_user_rights ur WHERE ur.fk_user=u.rowid) + (SELECT COUNT(*) FROM llx_usergroup_rights gr JOIN llx_usergroup_user gu ON gu.fk_usergroup=gr.fk_usergroup WHERE gu.fk_user=u.rowid) c FROM llx_user u WHERE u.statut=1 AND COALESCE(u.admin,0)=0) t;")
canfile=$(Q "SELECT COUNT(DISTINCT u.rowid) FROM llx_user u JOIN llx_usergroup_user gu ON gu.fk_user=u.rowid JOIN llx_usergroup_rights gr ON gr.fk_usergroup=gu.fk_usergroup JOIN llx_rights_def r ON r.id=gr.fk_id WHERE u.statut=1 AND COALESCE(u.admin,0)=0 AND r.module='agenda' AND r.perms='allactions' AND r.subperms='create';")
echo "  least-privileged non-admin holds $minrights right(s); $canfile of $nonadmin can record a filing"
[ "${minrights:-0}" -ge 4 ] && echo "  staff accounts are usable, not decorative" \
  || { echo "  FAIL: a non-admin user holds only ${minrights} right(s) - the practice must fall back to admin"; fail=1; }
[ "${canfile:-0}" = "${nonadmin:-0}" ] && [ "${canfile:-0}" -ge 1 ] && echo "  every article can record a filing without the admin login" \
  || { echo "  FAIL: only $canfile of $nonadmin non-admins can record a filing"; fail=1; }

step "audit trail is ON (it was disabled for cosmetics)"
at=$(Q "SELECT COUNT(*) FROM llx_const WHERE name LIKE 'MAIN_AGENDA_ACTIONAUTO_%' AND value='1';")
echo "  audit triggers enabled: $at"
[ "${at:-0}" -gt 100 ] && echo "  audit trail OK" || { echo "  FAIL: audit trail off ($at)"; fail=1; }

step "fee invoicing ran from deploy.sh own wiring (ORPH-19)"
grep -q "RAISED:" /tmp/r_dep && sed -n 's/^\(  RAISED:.*\)/ \1/p' /tmp/r_dep \
  || { echo "  FAIL: deploy.sh never raised invoices (CA_RAISE_FEES unwired)"; fail=1; }
inv=$(Q "SELECT COUNT(*) FROM llx_facture;")
tdsrows=$(Q "SELECT COUNT(*) FROM llx_facture_extrafields WHERE tds_194j>0;")
tdsclients=$(Q "SELECT COUNT(*) FROM llx_societe_extrafields WHERE tds_applicable=1;")
echo "  invoices=$inv tds_invoices=$tdsrows tds_clients=$tdsclients"
[ "${inv:-0}" -ge 40 ] && echo "  invoicing OK" || { echo "  FAIL: only ${inv:-0} invoices"; fail=1; }
[ "${tdsrows:-0}" = "${tdsclients:-0}" ] && echo "  TDS matches tds_applicable exactly ($tdsrows)" \
  || { echo "  FAIL: $tdsrows TDS rows vs $tdsclients flagged clients"; fail=1; }

step "restore.sh actually works (ORPH-20)"
Q "INSERT INTO llx_societe (entity,nom,client,datec,status) VALUES (1,'__DR_CANARY__',1,NOW(),1);" >/dev/null
./backup.sh >/tmp/r_bk 2>&1 && grep -q "BACKUP OK" /tmp/r_bk || { echo "  FAIL: backup"; tail -3 /tmp/r_bk; fail=1; }
BK=$(ls -dt "${CA_BACKUP_DIR:-$HOME/ca-practice-backups}"/*/ 2>/dev/null | head -1)
Q "DELETE FROM llx_societe WHERE nom='__DR_CANARY__';" >/dev/null
gone=$(Q "SELECT COUNT(*) FROM llx_societe WHERE nom='__DR_CANARY__';")
[ "${gone:-1}" = "0" ] && echo "  canary destroyed" || { echo "  FAIL: canary survived"; fail=1; }
if [ -f "$BK/db.sql" ]; then
  ./restore.sh "$BK" >/tmp/r_rs 2>&1
  back=$(Q "SELECT COUNT(*) FROM llx_societe WHERE nom='__DR_CANARY__';")
  [ "${back:-0}" = "1" ] && echo "  RESTORE VERIFIED - canary recovered" \
    || { echo "  FAIL: restore did not recover canary"; tail -3 /tmp/r_rs; fail=1; }
  Q "DELETE FROM llx_societe WHERE nom='__DR_CANARY__';" >/dev/null
else
  echo "  dump is age-encrypted; decrypt drill is manual (documented)"
fi

step "timezone pin still correct (ORPH-21, was tz.php orphan)"
skew=$(Q "SELECT TIMESTAMPDIFF(MINUTE, NOW(), CONVERT_TZ(NOW(),'+00:00','+05:30'));")
echo "  db->IST offset: ${skew}min"
[ "${skew:-0}" = "330" ] && echo "  +05:30 confirmed (wa_fanout pins this)" \
  || { echo "  FAIL: unexpected offset ${skew}"; fail=1; }

step "WhatsApp opt-in gate enforced (ORPH-22, was wa_seed.php orphan)"
docker compose cp wa_seed.php dolibarr:/tmp/ws.php >/dev/null 2>&1
docker compose exec -T dolibarr php /tmp/ws.php >/tmp/r_ws 2>&1
noopt=$(Q "SELECT COUNT(*) FROM llx_societe_extrafields WHERE COALESCE(whatsapp_optin,0)=0;")
WA_ALLOW_INSECURE=1 python3 wa_fanout.py >/tmp/r_wm 2>&1
echo "  clients without opt-in: $noopt"
if [ "${noopt:-0}" = "0" ] || grep -q "no opt-in" /tmp/r_wm; then echo "  opt-in gate enforced"
else echo "  FAIL: wa_fanout did not skip un-opted clients"; fail=1; fi

step "compliance calendar generated from profiles"
n=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e \
    "SELECT COUNT(*) FROM llx_actioncomm;" 2>/dev/null | grep -v -i "insecure\|warning")
rem=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e \
    "SELECT COUNT(*) FROM llx_actioncomm_reminder;" 2>/dev/null | grep -v -i "insecure\|warning")
echo "  deadlines=$n reminders=$rem"
[ "${n:-0}" -ge 500 ] && echo "  calendar OK" || { echo "  FAIL: expected 500+ deadlines, got ${n:-0}"; fail=1; }
[ "${rem:-0}" -ge 500 ] && echo "  reminders OK" || { echo "  FAIL: only ${rem:-0} reminders"; fail=1; }

step "practice finance modules + service catalogue"
sv=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e \
    "SELECT COUNT(*) FROM llx_product WHERE ref LIKE 'SVC-%';" 2>/dev/null | grep -v -i "insecure\|warning")
tf=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e \
    "SELECT COUNT(*) FROM llx_extrafields WHERE elementtype='facture' AND name LIKE 'tds%';" 2>/dev/null | grep -v -i "insecure\|warning")
echo "  services=$sv tds_fields=$tf"
[ "${sv:-0}" -ge 9 ] && [ "${tf:-0}" -ge 2 ] && echo "  finance OK" || { echo "  FAIL: services=$sv tds=$tf"; fail=1; }

step "wa_fanout on virgin db"
# The mock is already up for the whole suite (top of file) and WA_API_BASE points
# at it. This line used to start a SECOND mock and then never point anything at
# it, so the dispatch it graded actually went to Meta.
python3 wa_fanout.py >/tmp/r_wa 2>&1; rc=$?
sed 's/^/  /' /tmp/r_wa    # ALWAYS show it - hiding stderr is how the alarm got missed
# exit 1 means aged-out or exhausted reminders exist. On a virgin DB that is a
# real failure, not noise. `-le 1` made this gate blind to D2's own signal.
[ $rc -eq 0 ] && echo "  exit=0 OK" || { echo "  FAIL exit=$rc (1 = aged-out/exhausted alarm)"; fail=1; }
# capture proves the dispatch went to the mock and not out to the internet
sent=$(grep -c '/messages' /tmp/wa_capture.json 2>/dev/null || echo 0)
[ "${sent:-0}" -ge 1 ] && echo "  $sent message(s) captured by the mock - nothing left the host" \
  || echo "  (nothing dispatched this run - no message to capture)"
step "WhatsApp errors are classified, not blindly retried"
# The mock always returned 200, so this whole path was dead code. wa_fanout caught
# `except Exception` and used str(ex) - which for an HTTPError is only
# "HTTP Error 400: Bad Request". Meta's structured error.code, carried in the
# BODY, was never read, so every failure looked identical and was retried 3x:
# an expired token would fail all ~880 reminders, three times each, before anyone
# was told. Meta's own instruction is to branch on error.code, never HTTP status.
wa_case(){   # code, expected marker, expected fails
  pkill -f mockmeta.py 2>/dev/null; sleep 1
  ids=$(Q "SELECT r.rowid FROM llx_actioncomm_reminder r JOIN llx_actioncomm a ON a.id=r.fk_actioncomm JOIN llx_societe_extrafields e ON e.fk_object=a.fk_soc WHERE COALESCE(e.whatsapp_optin,0)=1 AND COALESCE(e.whatsapp_number,'')<>'' LIMIT 2;" | tr '\n' ',' | sed 's/,$//')
  [ -n "$ids" ] || { echo "  FAIL: no opted-in reminder to dispatch"; fail=1; return; }
  Q "UPDATE llx_actioncomm_reminder SET dateremind=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE rowid IN ($ids);" >/dev/null
  Q "DELETE FROM ca_wa_sent;" >/dev/null
  CA_MOCK_FAIL=$1 python3 mockmeta.py 2>/dev/null & local M=$!; sleep 1
  local out; out=$(WA_ALLOW_INSECURE=1 WA_API_BASE=http://127.0.0.1:9099 python3 wa_fanout.py 2>&1)
  kill $M 2>/dev/null; wait $M 2>/dev/null
  local got; got=$(Q "SELECT COALESCE(MAX(fails),0) FROM ca_wa_sent;")
  if echo "$out" | grep -q "$2" && [ "${got:-0}" = "$3" ]; then
    echo "  $1 -> $2, fails=$got"
  else
    echo "  FAIL: code $1 expected '$2' with fails=$3, got fails=$got"; echo "$out" | head -2 | sed 's/^/      /'; fail=1
  fi
}
# permanent: must NOT burn retries - straight to the cap so the CA hears today
wa_case 131026 PERMANENT 3
# retryable: Meta explicitly says try again later
wa_case 130429 RETRY 1
# account-level: an expired token fails EVERY message; keep going and you just
# multiply the damage and the account's bad standing
wa_case 190 HALTING 3
# and the happy path still delivers
wa_case "" SENT 0
pkill -f mockmeta.py 2>/dev/null

step "smoke: reminder -> inbox"; ./smoke.sh >/tmp/r_sm 2>&1 && echo "  PASS" || { echo "  FAIL"; cat /tmp/r_sm; fail=1; }
step "healthcheck";              ./healthcheck.sh >/tmp/r_hc 2>&1 && echo "  HEALTHY" || { echo "  FAIL"; cat /tmp/r_hc; fail=1; }
step "sweeper fails loud when db down"
docker compose stop db >/dev/null 2>&1; ./sweeper.sh >/dev/null 2>&1
[ $? -eq 2 ] && echo "  exit=2 OK" || { echo "  FAIL: did not exit 2"; fail=1; }
docker compose start db >/dev/null 2>&1
for i in $(seq 1 25); do docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mariadb -uroot -e "SELECT 1" >/dev/null 2>&1 && break; sleep 3; done
step "cron-env: run every ca.crontab job with an EMPTY environment"
# regression previously sourced ca.env then called the jobs in the SAME shell -
# an environment cron never provides. That masked wa_fanout.py dying at import.
# Use the PATH the crontab actually ships, not one derived from this shell.
CRONPATH=$(grep -E '^PATH=' ca.crontab | head -1 | cut -d= -f2-)
[ -n "$CRONPATH" ] || { echo "  FAIL: ca.crontab has no PATH= line"; fail=1; CRONPATH=/usr/bin:/bin; }
# Derive the job list FROM ca.crontab. A hardcoded list silently falls behind
# every time a cron entry is added - which is exactly how chase_clients.py,
# morning_digest.php and doc_requests.php escaped this gate.
# Capture a trailing SUBCOMMAND too (./ca_job.sh digest). Matching only the
# script name ran ca_job.sh with no argument, which just prints usage and exits 2;
# and before that, the three inline `docker compose` cron lines matched nothing
# at all and were never tested - they had been failing every night.
# A leading-dash token (--commit) is deliberately NOT captured: the gate must not
# send real messages.
JOBS=$(grep -oE '\./[a-z_]+\.(sh|py)( +[a-z][a-z_]*)?' ca.crontab | sed 's/  */ /g' | sort -u)
cronjobs=$(echo "$JOBS" | grep -c .)
echo "  jobs discovered in ca.crontab: $cronjobs"
# every non-comment crontab line must map to a discovered job, or the gate is blind again
crlines=$(grep -cE '^[0-9*]' ca.crontab)
[ "$cronjobs" -ge "$crlines" ] && echo "  all $crlines scheduled line(s) covered" \
  || { echo "  FAIL: $crlines scheduled line(s) but only $cronjobs discovered - the gate is blind to $((crlines-cronjobs))"; fail=1; }
# `while read` on a herestring, not a for-loop over $JOBS: jobs now carry a
# subcommand and word-splitting a multi-word job silently ran the wrong thing.
# A herestring keeps this in the CURRENT shell, so `fail=1` still takes effect -
  # through a pipe the loop is a subshell and every failure it recorded was lost.
  while IFS= read -r job; do
  [ -n "$job" ] || continue
  # </dev/null: the job list arrives on THIS loop's stdin, and backup.sh reads
  # stdin - it swallowed the remaining six jobs and the gate reported one PASS.
  # WA_API_BASE/WA_ALLOW_INSECURE are the ONLY two variables forwarded, and only
  # so the gate cannot dispatch to Meta for real. Everything else stays empty,
  # which is the whole point of this step: wa_fanout.py still has to find its own
  # settings through _load_env() reading ca.env, exactly as it does under cron.
  o=$(env -i PATH="$CRONPATH" HOME="$HOME" \
        WA_API_BASE="$WA_API_BASE" WA_ALLOW_INSECURE="$WA_ALLOW_INSECURE" \
        sh -c "cd $PWD && $job" </dev/null 2>&1); c=$?
  # EXIT CODE IS THE VERDICT. The previous version only grepped for four strings
  # and never tested $c, so a job exiting 2 was reported "OK" - the exact
  # fail-open this step exists to prevent.
  bad=0
  [ "$c" -ne 0 ] && bad=1
  # 'usage:' catches a job invoked without the subcommand it now needs
  echo "$o" | grep -qiE "KeyError|Traceback|not set|command not found|FATAL|usage:" && bad=1
  if [ $bad -eq 1 ]; then
    echo "  FAIL $job: exit=$c under cron env"; echo "$o" | head -3 | sed 's/^/      /'; fail=1
  else
    echo "  $job exit=0 OK under empty env"
  fi
done <<< "$JOBS"


step "DPDP: erasure is HELD for the statutory period, then clears identifiers only"
# The last open audit finding. Personal data (PAN, GSTIN, phone) of 47 client
# businesses accumulated for ever with no retention policy and no erasure path.
# s.8(7) requires erasure on withdrawal of consent UNLESS retention is required
# by law - and for a CA it always is, for years. So erasure is held, then run.
ret=$(Q "SELECT COALESCE(MAX(years),0) FROM ca_retention_policy;")
echo "  binding retention period: ${ret}y"
[ "${ret:-0}" -ge 6 ] && echo "  retention policy seeded" || { echo "  FAIL: no retention policy"; fail=1; }
Q "INSERT INTO llx_societe (entity,nom,client,datec,tms,status) VALUES (1,'__GATE_DPDP__',1,NOW(),NOW(),1);" >/dev/null
dsid=$(Q "SELECT rowid FROM llx_societe WHERE nom='__GATE_DPDP__';")
Q "INSERT INTO llx_societe_extrafields (fk_object,gstin,pan,gst_scheme,entity_type) VALUES ($dsid,'27AAACT2727Q1ZW','AAACT2727Q','Monthly','Private Limited');" >/dev/null
out=$(docker compose exec -T -e GSID="$dsid" dolibarr php -r '
define("NOSESSION","1"); require_once "/var/www/html/master.inc.php";
require_once "/var/www/html/custom/ca/ca_privacy_lib.php"; global $db;
ca_privacy_ensure_tables($db); $sid=(int)getenv("GSID");
$r=ca_request_erasure($db,$sid,"regression gate",1);
$e=ca_execute_erasure($db,$sid,1);
echo ($r["ok"]?"REQ_OK":"REQ_FAIL").":".($e["ok"]?"ERASED_EARLY":"REFUSED");
' 2>/dev/null | tr -d '\r')
echo "  request then immediate erase -> $out"
[ "$out" = "REQ_OK:REFUSED" ] && echo "  erasure refused inside the statutory window" \
  || { echo "  FAIL: expected REQ_OK:REFUSED, got '$out'"; fail=1; }
pan_before=$(Q "SELECT pan FROM llx_societe_extrafields WHERE fk_object=$dsid;")
Q "UPDATE ca_erasure_request SET due_at=DATE_SUB(CURDATE(),INTERVAL 1 DAY), status='due' WHERE fk_soc=$dsid;" >/dev/null
docker compose exec -T -e GSID="$dsid" dolibarr php -r '
define("NOSESSION","1"); require_once "/var/www/html/master.inc.php";
require_once "/var/www/html/custom/ca/ca_privacy_lib.php"; global $db;
$e=ca_execute_erasure($db,(int)getenv("GSID"),1); echo $e["ok"]?"ok":"fail";' >/dev/null 2>&1
pan_after=$(Q "SELECT COALESCE(pan,'CLEARED') FROM llx_societe_extrafields WHERE fk_object=$dsid;")
nom_after=$(Q "SELECT nom FROM llx_societe WHERE rowid=$dsid;")
logged=$(Q "SELECT COUNT(*) FROM ca_erasure_log WHERE fk_soc=$dsid;")
plain=$(Q "SELECT COUNT(*) FROM ca_erasure_log WHERE nom_hash LIKE '%GATE_DPDP%';")
echo "  once due: pan '$pan_before' -> '$pan_after', nom -> '$nom_after'"
{ [ "$pan_after" = "CLEARED" ] && [ "$nom_after" != "__GATE_DPDP__" ]; } && echo "  identifiers erased" \
  || { echo "  FAIL: identifiers survived erasure"; fail=1; }
[ "${logged:-0}" -ge 1 ] && [ "${plain:-1}" = "0" ] && echo "  erasure logged, name hashed not stored" \
  || { echo "  FAIL: log rows=$logged plaintext-name-matches=$plain"; fail=1; }
# the statutory record must NOT be destroyed by an erasure
keptf=$(Q "SELECT COUNT(*) FROM ca_filing;")
[ "${keptf:-0}" -ge 500 ] && echo "  filing ledger intact ($keptf rows) - erasure clears identifiers, not evidence" \
  || { echo "  FAIL: ca_filing dropped to ${keptf:-0} rows"; fail=1; }
Q "DELETE FROM ca_erasure_log WHERE fk_soc=$dsid; DELETE FROM ca_erasure_request WHERE fk_soc=$dsid;" >/dev/null
Q "DELETE r FROM llx_actioncomm_reminder r JOIN llx_actioncomm a ON a.id=r.fk_actioncomm WHERE a.fk_soc=$dsid;" >/dev/null
Q "DELETE x FROM llx_actioncomm_resources x JOIN llx_actioncomm a ON a.id=x.fk_actioncomm WHERE a.fk_soc=$dsid;" >/dev/null
Q "DELETE FROM ca_docrequest WHERE fk_soc=$dsid; DELETE FROM ca_filing WHERE fk_soc=$dsid; DELETE FROM llx_actioncomm WHERE fk_soc=$dsid; DELETE FROM llx_societe_extrafields WHERE fk_object=$dsid; DELETE FROM llx_societe WHERE rowid=$dsid;" >/dev/null

step "the practice does not advertise which software it is running"
hv=$(Q "SELECT COALESCE(value,'0') FROM llx_const WHERE name='MAIN_HIDE_VERSION';")
ov=$(Q "SELECT COUNT(*) FROM llx_overwrite_trans;")
echo "  MAIN_HIDE_VERSION=$hv  vocabulary overrides=$ov"
[ "$hv" = "1" ] && echo "  version string suppressed in the UI" || { echo "  FAIL: Dolibarr version shown to every visitor"; fail=1; }
[ "${ov:-0}" -ge 20 ] && echo "  ERP vocabulary replaced with CA vocabulary" || { echo "  FAIL: only ${ov:-0} label overrides"; fail=1; }

step "PROD PROFILE: the config that actually ships"
# The prod overlay was outside the gate entirely, which is how HANDOVER ended up
# documenting a production path that never ran deploy.sh (D1 all over again).
# -v: the gate's founding lesson is "test cold". Without it the PROD step runs on
# volumes the dev deploy already configured, making its assertions unfailable.
docker compose down -v >/dev/null 2>&1
export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
export CA_SMTP_HOST=smtp.example.invalid    # prod has no Mailpit
# Pick FREE ports rather than hardcoding 8081/8443. The gate's claim is "the prod
# profile deploys and serves HTTPS through Caddy", not "port 8443 specifically" -
# and on a machine where something else already holds 8443 the suite failed with
# "address already in use", which reads as a product defect and is not one. This
# repo is public now; it must not assume one developer's free ports.
freeport(){ python3 -c "import socket;s=socket.socket();s.bind(('0.0.0.0',0));print(s.getsockname()[1]);s.close()"; }
export CA_HTTP_PORT=$(freeport) CA_HTTPS_PORT=$(freeport)
echo "  prod ports for this run: http=$CA_HTTP_PORT https=$CA_HTTPS_PORT" 
docker compose up -d >/dev/null 2>&1
sleep 25
if ./deploy.sh >/tmp/r_prod 2>&1; then
  grep -qE "client extrafields: ([0-9]+)/\\1( |$)" /tmp/r_prod && echo "  extrafields OK on PROD" \
    || { echo "  FAIL: extrafields missing on prod"; fail=1; }
  a=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN \
      -e "SELECT value FROM llx_const WHERE name='AGENDA_REMINDER_EMAIL';" 2>/dev/null | grep -v -i "insecure\|warning")
  [ "$a" = "1" ] && echo "  AGENDA_REMINDER_EMAIL=1 on PROD" || { echo "  FAIL: AGENDA_REMINDER_EMAIL='$a' on prod"; fail=1; }
  code=$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:$CA_HTTPS_PORT/ --max-time 20)
  [ "$code" = "200" ] && echo "  https via Caddy: 200" || { echo "  FAIL: https returned $code"; fail=1; }
  d8=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/ --max-time 5)
  [ "$d8" = "000" ] && echo "  :8080 unpublished (Caddy is the only ingress)" || { echo "  FAIL: 8080 reachable ($d8)"; fail=1; }
else
  echo "  FAIL: deploy.sh does not work under the prod profile"; tail -4 /tmp/r_prod | sed 's/^/      /'; fail=1
fi
docker compose down >/dev/null 2>&1
unset COMPOSE_FILE CA_SMTP_HOST CA_HTTP_PORT CA_HTTPS_PORT
. ./ca.env; set -a; . ./secrets.env; set +a
docker compose up -d >/dev/null 2>&1

printf '\n'; [ $fail -eq 0 ] && echo "REGRESSION SUITE PASS" || echo "REGRESSION SUITE FAIL"
exit $fail
