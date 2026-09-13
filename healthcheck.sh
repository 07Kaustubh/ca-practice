#!/usr/bin/env bash
cd "$(dirname "$0")"; source ./ca.env; set -a; . ./secrets.env; set +a
Q(){ docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e "$1" 2>/dev/null | grep -v -i warning; }
fail=0
[ "$(Q "SELECT value FROM llx_const WHERE name='AGENDA_REMINDER_EMAIL';")" = "1" ] || { echo "FAIL: AGENDA_REMINDER_EMAIL off"; fail=1; }
[ -n "$(Q "SELECT email FROM llx_user WHERE rowid=1 AND email<>'';")" ] || { echo "FAIL: admin has no email"; fail=1; }
docker compose exec -T dolibarr php /var/www/scripts/cron/cron_run_jobs.php "$CA_CRON_KEY" admin 2>&1 | grep -q "does not match" && { echo "FAIL: CRON_KEY mismatch"; fail=1; }
echo "  cron lastoutput: $(Q "SELECT COALESCE(lastoutput,'NEVER RAN') FROM llx_cronjob WHERE rowid=1;")"
d=$(Q "SELECT COUNT(*) FROM llx_actioncomm_reminder WHERE status=-1;")
[ "$d" = "0" ] || { echo "WARN: $d dead reminder(s) - run ./sweeper.sh"; }
[ $fail -eq 0 ] && echo "HEALTHY" || echo "UNHEALTHY"; exit $fail
