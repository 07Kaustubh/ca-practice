#!/usr/bin/env bash
# Refuses a prod bring-up that still points SMTP at the dev mail sink, and treats
# an unparseable compose config as FAILURE rather than "service absent".
cd "$(dirname "$0")"; . ./ca.env; set -a; . ./secrets.env; set +a
fail=0
case "${COMPOSE_FILE:-}" in
  *prod*)
    svcs=$(docker compose config --services 2>&1); rc=$?
    if [ $rc -ne 0 ]; then
      echo "FAIL: compose config did not parse (rc=$rc) - cannot verify prod safety"
      echo "$svcs" | head -3 | sed 's/^/       /'
      fail=1
    elif echo "$svcs" | grep -qx mailpit; then
      echo "FAIL: mailpit present in prod config"; fail=1
    fi
    if [ "${CA_SMTP_HOST:-}" = "mailpit" ]; then
      echo "FAIL: CA_SMTP_HOST=mailpit but prod overlay active - mail will silently go nowhere"; fail=1
    fi
    ;;
  *) echo "  (dev profile; prod checks skipped)";;
esac
[ $fail -eq 0 ] && echo "  prod config OK"
exit $fail
