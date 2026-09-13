#!/usr/bin/env bash
# STANDING GATE. Every defect found in review traced back to never running the
# suite against EMPTY volumes. This does exactly that. Run before any handover.
set -uo pipefail
cd "$(dirname "$0")"; . ./ca.env; set -a; . ./secrets.env; set +a
# single DB helper for every step (MYSQL_PWD, never -p on argv)
Q(){ docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN \
       -e "$1" 2>/dev/null | grep -v -i "insecure\|warning"; }
fail=0; step(){ printf '\n=== %s ===\n' "$1"; }
step "wipe to virgin volumes"
docker compose down -v >/dev/null 2>&1
n=$(docker volume ls --format '{{.Name}}' | grep -cE 'bakeoff_(db_data|doli_docs|doli_html)$')
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

step "per-user accounts exist (no shared admin)"
u=$(Q "SELECT COUNT(*) FROM llx_user WHERE statut=1;")
g=$(Q "SELECT COUNT(*) FROM llx_usergroup WHERE nom='Practice Staff';")
nonadmin=$(Q "SELECT COUNT(*) FROM llx_user WHERE statut=1 AND COALESCE(admin,0)=0;")
echo "  users=$u staff_group=$g non_admin=$nonadmin"
{ [ "${u:-0}" -ge 3 ] && [ "${g:-0}" -ge 1 ] && [ "${nonadmin:-0}" -ge 1 ]; } \
  && echo "  attribution OK - work is traceable to a person" \
  || { echo "  FAIL: shared-admin posture (users=$u group=$g non_admin=$nonadmin)"; fail=1; }

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
python3 mockmeta.py & MOCK_PID=$!; sleep 2;   python3 wa_fanout.py >/tmp/r_wa 2>&1; rc=$?
sed 's/^/  /' /tmp/r_wa    # ALWAYS show it - hiding stderr is how the alarm got missed
# exit 1 means aged-out or exhausted reminders exist. On a virgin DB that is a
# real failure, not noise. `-le 1` made this gate blind to D2's own signal.
[ $rc -eq 0 ] && echo "  exit=0 OK" || { echo "  FAIL exit=$rc (1 = aged-out/exhausted alarm)"; fail=1; }
kill $MOCK_PID 2>/dev/null
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
for job in ./sweeper.sh ./wa_fanout.py ./backup.sh; do
  o=$(env -i PATH="$CRONPATH" HOME="$HOME" sh -c "cd $PWD && $job" 2>&1); c=$?
  # EXIT CODE IS THE VERDICT. The previous version only grepped for four strings
  # and never tested $c, so a job exiting 2 was reported "OK" - the exact
  # fail-open this step exists to prevent.
  bad=0
  [ "$c" -ne 0 ] && bad=1
  echo "$o" | grep -qiE "KeyError|Traceback|not set|command not found|FATAL" && bad=1
  if [ $bad -eq 1 ]; then
    echo "  FAIL $job: exit=$c under cron env"; echo "$o" | head -3 | sed 's/^/      /'; fail=1
  else
    echo "  $job exit=0 OK under empty env"
  fi
done

step "PROD PROFILE: the config that actually ships"
# The prod overlay was outside the gate entirely, which is how HANDOVER ended up
# documenting a production path that never ran deploy.sh (D1 all over again).
# -v: the gate's founding lesson is "test cold". Without it the PROD step runs on
# volumes the dev deploy already configured, making its assertions unfailable.
docker compose down -v >/dev/null 2>&1
export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
export CA_SMTP_HOST=smtp.example.invalid    # prod has no Mailpit
export CA_HTTP_PORT=8081 CA_HTTPS_PORT=8443
docker compose up -d >/dev/null 2>&1
sleep 25
if ./deploy.sh >/tmp/r_prod 2>&1; then
  grep -qE "client extrafields: ([0-9]+)/\\1( |$)" /tmp/r_prod && echo "  extrafields OK on PROD" \
    || { echo "  FAIL: extrafields missing on prod"; fail=1; }
  a=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN \
      -e "SELECT value FROM llx_const WHERE name='AGENDA_REMINDER_EMAIL';" 2>/dev/null | grep -v -i "insecure\|warning")
  [ "$a" = "1" ] && echo "  AGENDA_REMINDER_EMAIL=1 on PROD" || { echo "  FAIL: AGENDA_REMINDER_EMAIL='$a' on prod"; fail=1; }
  code=$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:8443/ --max-time 20)
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
