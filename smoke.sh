#!/usr/bin/env bash
cd "$(dirname "$0")"; source ./ca.env; set -a; . ./secrets.env; set +a
Q(){ docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e "$1" 2>/dev/null | grep -v -i warning; }
docker compose cp smoke.php dolibarr:/tmp/smoke.php >/dev/null
docker compose exec -T dolibarr php /tmp/smoke.php >/dev/null 2>&1
before=$(curl -s http://localhost:8025/api/v1/messages 2>/dev/null | python3 -c "import sys,json;print(json.load(sys.stdin).get('total',0))" 2>/dev/null || echo 0)
Q "UPDATE llx_cronjob SET datenextrun=NULL,datelastrun=NULL WHERE module_name='agenda';"
docker compose exec -T dolibarr php /var/www/scripts/cron/cron_run_jobs.php "$CA_CRON_KEY" admin >/dev/null 2>&1
out=$(Q "SELECT lastoutput FROM llx_cronjob WHERE rowid=1;")
after=$(curl -s http://localhost:8025/api/v1/messages 2>/dev/null | python3 -c "import sys,json;print(json.load(sys.stdin).get('total',0))" 2>/dev/null || echo 0)
echo "  cron says : $out"; echo "  inbox     : $before -> $after"
aid=$(Q "SELECT id FROM llx_actioncomm WHERE label='__SMOKETEST__ reminder';")
Q "DELETE FROM llx_actioncomm_reminder WHERE fk_actioncomm=$aid; DELETE FROM llx_actioncomm WHERE id=$aid; DELETE FROM llx_societe WHERE nom='__SMOKETEST__';"
[ "$after" -gt "$before" ] && { echo "  SMOKE PASS"; exit 0; } || { echo "  SMOKE FAIL"; exit 1; }
