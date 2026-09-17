#!/usr/bin/env bash
# CONTAINER JOBS, RUN THE WAY CRON RUNS THEM.
#
# ca.crontab used to call `docker compose ...` inline for the digest, the
# document refresh and the filing ledger. cron provides no environment, and
# docker-compose.yml cannot even be PARSED without DB_PASSWORD, so all three
# failed every single night with "required variable DB_PASSWORD is missing" -
# silently, into a log nobody reads. The digest additionally never forwarded
# CA_EMAIL or CA_SMTP_HOST into the container, so even a working copy would have
# posted to mailpit:1025 with no recipient.
#
# Routing them through a script also makes them visible to the cron-env gate,
# which only ever discovered `./name.sh` style jobs and was blind to the
# inline-docker lines - the reason this went unnoticed.
set -uo pipefail
cd "$(dirname "$0")"
. ./ca.env; set -a; . ./secrets.env; set +a

case "${1:-}" in
  digest)   src=morning_digest.php;      dst=md.php ;;
  docs)     src=doc_requests.php;        dst=dr.php ;;
  filings)  src=filings.php;             dst=fl.php ;;
  # The calendar was generated ONLY by deploy.sh, over SSH. A client added in the
  # web UI therefore got no deadlines at all, silently. It is idempotent, so
  # running it nightly costs nothing and closes that hole.
  calendar) src=compliance_calendar.php; dst=cc.php ;;
  # DRY RUN by design. Destroying personal data is never something a cron job
  # does by default; this reports what is due and the CA acts on the screen.
  retention) src=retention.php;          dst=rt.php ;;
  *) echo "usage: $(basename "$0") {digest|docs|filings|calendar|retention}" >&2; exit 2 ;;
esac

docker compose cp "$src" "dolibarr:/tmp/$dst" >/dev/null \
  || { echo "FATAL: cannot copy $src into the app container" >&2; exit 1; }

# Forward the settings the PHP actually reads. An unset one arrives empty, which
# every consumer already treats as "use the default".
docker compose exec -T \
  -e CA_EMAIL -e CA_FIRM_NAME -e CA_SMTP_HOST -e CA_SMTP_PORT \
  -e CA_BASE_URL -e CA_GOLIVE -e CA_DOC_HORIZON -e CA_REMIND_DAYS \
  dolibarr php "/tmp/$dst"
rc=$?

# Same hygiene deploy.sh enforces: nothing of ours is left lying in the container.
docker compose exec -T dolibarr rm -f "/tmp/$dst" >/dev/null 2>&1
exit $rc
