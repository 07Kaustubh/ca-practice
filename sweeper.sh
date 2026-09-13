#!/usr/bin/env bash
# Dolibarr NEVER retries a failed reminder (status=-1 is terminal). For compliance
# deadlines that is a silent permanent miss. This revives and then escalates.
set -uo pipefail
cd "$(dirname "$0")"; . ./ca.env; set -a; . ./secrets.env; set +a
MAX_RETRIES=${MAX_RETRIES:-3}

# Q writes to a temp file and returns the real exit code. `exit` inside a
# command substitution only kills the subshell, so the CALLER must check.
_qerr=$(mktemp)
# No PIPESTATUS: it is bash-only and silently yields empty under zsh, which made
# a total DB outage return 0. Capture first, check $?, filter after.
Q(){
  local out rc
  out=$(docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN -e "$1" 2>"$_qerr")
  rc=$?
  if [ "$rc" -ne 0 ]; then return "$rc"; fi
  printf '%s\n' "$out" | grep -v -i "insecure\|warning"
  return 0
}
die(){ echo "FATAL: $1" >&2; sed 's/^/       /' "$_qerr" >&2; rm -f "$_qerr"; exit 2; }

Q "CREATE TABLE IF NOT EXISTS ca_reminder_retry (
     fk_reminder INT PRIMARY KEY, attempts INT NOT NULL DEFAULT 0,
     last_error VARCHAR(255), escalated TINYINT NOT NULL DEFAULT 0,
     updated DATETIME NOT NULL);" || die "database unreachable - this is NOT a clean sweep"

dead=$(Q "SELECT COUNT(*) FROM llx_actioncomm_reminder WHERE status=-1;") \
  || die "database unreachable - this is NOT a clean sweep"
[ -n "$dead" ] || die "empty result counting dead reminders - refusing to report success"
echo "  dead reminders (status=-1): $dead"
if [ "$dead" = "0" ]; then echo "  nothing to revive"; rm -f "$_qerr"; exit 0; fi

Q "INSERT INTO ca_reminder_retry (fk_reminder,attempts,last_error,updated)
   SELECT rowid,0,LEFT(COALESCE(lasterror,''),255),NOW() FROM llx_actioncomm_reminder WHERE status=-1
   ON DUPLICATE KEY UPDATE last_error=VALUES(last_error);" || die "retry-ledger write failed"

revive=$(Q "SELECT COUNT(*) FROM llx_actioncomm_reminder r JOIN ca_reminder_retry t ON t.fk_reminder=r.rowid
            WHERE r.status=-1 AND t.attempts < $MAX_RETRIES;") || die "revive count failed"
Q "UPDATE llx_actioncomm_reminder r JOIN ca_reminder_retry t ON t.fk_reminder=r.rowid
   SET r.status=0, r.lasterror=NULL, t.attempts=t.attempts+1, t.updated=NOW()
   WHERE r.status=-1 AND t.attempts < $MAX_RETRIES;" || die "revive update failed"
echo "  revived for retry: ${revive:-0}"

esc=$(Q "SELECT COUNT(*) FROM llx_actioncomm_reminder r JOIN ca_reminder_retry t ON t.fk_reminder=r.rowid
         WHERE r.status=-1 AND t.attempts >= $MAX_RETRIES AND t.escalated=0;") || die "escalation count failed"
if [ -n "$esc" ] && [ "$esc" != "0" ]; then
  echo "  ESCALATING $esc exhausted reminder(s) -> digest to $CA_EMAIL"
  body=$(Q "SELECT CONCAT('reminder#',r.rowid,'  ',a.label,'  attempts=',t.attempts,'  err=',COALESCE(t.last_error,'?'))
            FROM llx_actioncomm_reminder r JOIN ca_reminder_retry t ON t.fk_reminder=r.rowid
            JOIN llx_actioncomm a ON a.id=r.fk_actioncomm
            WHERE r.status=-1 AND t.attempts >= $MAX_RETRIES AND t.escalated=0;") || die "digest body query failed"
  printf 'Subject: [ACTION REQUIRED] %s reminder(s) permanently failed\r\nFrom: %s\r\nTo: %s\r\n\r\nThese deadline reminders exhausted %s retries and were NEVER delivered:\r\n\r\n%s\r\n' \
    "$esc" "$CA_EMAIL" "$CA_EMAIL" "$MAX_RETRIES" "$body" > /tmp/digest.eml
  docker compose cp /tmp/digest.eml dolibarr:/tmp/digest.eml >/dev/null 2>&1
  docker compose cp send_digest.php dolibarr:/tmp/send_digest.php >/dev/null 2>&1
  if ! docker compose exec -T -e CA_EMAIL -e CA_SMTP_HOST -e CA_SMTP_PORT \
        dolibarr php /tmp/send_digest.php; then
    echo "  ESCALATION EMAIL FAILED - NOT marking as escalated so it retries" >&2
    rm -f "$_qerr"; exit 3
  fi
  Q "UPDATE ca_reminder_retry t JOIN llx_actioncomm_reminder r ON r.rowid=t.fk_reminder
     SET t.escalated=1 WHERE r.status=-1 AND t.attempts >= $MAX_RETRIES AND t.escalated=0;" || die "escalation flag failed"
fi
rm -f "$_qerr"
