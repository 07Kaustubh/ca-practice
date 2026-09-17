#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
[ -f secrets.env ] || { echo "FATAL: secrets.env missing - run ./gen-secrets.sh"; exit 1; }
for k in DB_ROOT_PASSWORD DB_PASSWORD DOLI_ADMIN_PASSWORD CA_CRON_KEY WA_TOKEN; do
  grep -q "^$k=." secrets.env || { echo "FATAL: $k missing from secrets.env - re-run ./gen-secrets.sh"; exit 1; }
done
[ "$(stat -f %Lp secrets.env 2>/dev/null || stat -c %a secrets.env)" = "600" ] \
  || { echo "FATAL: secrets.env is not 0600"; exit 1; }
set -a; . ./secrets.env; set +a; . ./ca.env
./check-prod.sh || { echo "FATAL: prod safety check failed"; exit 1; }
echo "[1/7] containers"; docker compose up -d >/dev/null
echo "[2/7] waiting for install"
# Poll from INSIDE the container. The prod overlay deliberately publishes no
# 8080, so polling 127.0.0.1:8080 made deploy.sh unusable on the profile that
# actually ships - which is why the production instructions skipped it entirely.
for i in $(seq 1 60); do
  # curl, not php file_get_contents: allow_url_fopen is Off in this image, so
  # file_get_contents("http://...") silently returns false forever.
  # `|| echo 000` is REQUIRED: under `set -euo pipefail` a failing command
  # substitution aborts the whole script. In the first seconds after `up -d` the
  # container will not accept exec yet, so deploy died on cold start while
  # working perfectly when containers were already warm.
  c=$( { docker compose exec -T dolibarr curl -s -o /dev/null -w '%{http_code}' \
          http://localhost/ 2>/dev/null || echo 000; } | tr -dc '0-9')
  c=${c:-000}
  [ "$c" = "200" ] && { echo "      up (${i} polls)"; break; }; sleep 5
done
[ "$c" = "200" ] || { echo "FAILED: Dolibarr never became ready"; exit 1; }
echo "[3/7] modules + config + persona"
docker compose cp setup_all.php dolibarr:/tmp/setup_all.php >/dev/null
docker compose exec -T -e CA_FIRM_NAME -e CA_EMAIL -e CA_SMTP_HOST -e CA_SMTP_PORT -e CA_CRON_KEY \
  dolibarr php /tmp/setup_all.php
echo "[4/7] extrafields (WhatsApp number + opt-in) - wa_fanout.py is DEAD without these"
docker compose cp extrafields.php dolibarr:/tmp/extrafields.php >/dev/null
docker compose exec -T dolibarr php /tmp/extrafields.php
if [ -f clients.csv ] && [ "${CA_IMPORT_CLIENTS:-0}" = "1" ]; then
  echo "[4b] importing clients.csv"
  docker compose cp clients.csv dolibarr:/tmp/clients.csv >/dev/null
  docker compose cp import_clients.php dolibarr:/tmp/import_clients.php >/dev/null
  docker compose exec -T dolibarr php /tmp/import_clients.php
fi
# Smoke test only under the dev profile: it asserts against Mailpit, which
# production deliberately does not have. Prod verification is healthcheck.sh
# plus a real reminder to a real inbox.
echo "[4b2] per-user accounts + scoped staff role"
# Wired here, not run by hand. A shared admin login means a confidentiality duty
# cannot be attributed to a person - which ICAI expects a practice to be able to
# do, and which DPDP s8(5)-(6) breach notification requires.
docker compose cp users_setup.php dolibarr:/tmp/users_setup.php >/dev/null
docker compose exec -T -e CA_EMAIL -e CA_STAFF dolibarr php /tmp/users_setup.php

echo "[4c] practice finances (invoices, bank, expenses, TDS 194J, service catalogue)"
docker compose cp finance_setup.php dolibarr:/tmp/finance_setup.php >/dev/null
docker compose exec -T dolibarr php /tmp/finance_setup.php

# Installed INTO the app, not copied to /tmp and deleted: these are the libraries
# the CLI shares with the screens, plus the screens themselves. They must land
# before the calendar runs - compliance_calendar.php now requires
# ca_calendar_lib.php from here rather than carrying its own copy of the rules.
echo "[4c2] CA libraries + the screens the practice is run from"
docker compose exec -T dolibarr sh -c 'mkdir -p /var/www/html/custom/ca'
n=0
while IFS=: read -r src dst; do
  [ -n "${src:-}" ] || continue
  [ -f "$src" ] || { echo "FATAL: $src is missing from the working directory"; exit 1; }
  # </dev/null: the pair list arrives on this loop's stdin and `docker compose cp`
  # reads stdin - without it the loop swallows its own input after one iteration.
  docker compose cp "$src" "dolibarr:/var/www/html/custom/ca/$dst" >/dev/null </dev/null
  n=$((n+1))
done <<'PAIRS'
ca_filing_lib.php:ca_filing_lib.php
ca_calendar_lib.php:ca_calendar_lib.php
filings_screen.php:filings.php
client_screen.php:client.php
rates_screen.php:rates.php
adjustments_screen.php:adjustments.php
ca_privacy_lib.php:ca_privacy_lib.php
privacy_screen.php:privacy.php
PAIRS
docker compose exec -T dolibarr sh -c 'chown -R www-data:www-data /var/www/html/custom/ca 2>/dev/null; chmod 0644 /var/www/html/custom/ca/*.php'
inst=$(docker compose exec -T dolibarr sh -c 'ls /var/www/html/custom/ca/*.php 2>/dev/null | wc -l' | tr -d ' \r')
[ "${inst:-0}" = "$n" ] || { echo "      FATAL: installed $inst of $n CA web file(s)"; exit 1; }
echo "      $n file(s) under /custom/ca"

if [ "${CA_GENERATE_CALENDAR:-1}" = "1" ]; then
  echo "[4d] statutory compliance calendar"
  docker compose cp compliance_calendar.php dolibarr:/tmp/compliance_calendar.php >/dev/null
  docker compose exec -T -e CA_REMIND_DAYS dolibarr php /tmp/compliance_calendar.php
fi

if [ "${CA_RAISE_FEES:-0}" = "1" ]; then
  echo "[4e] raising fee invoices for the current period"
  docker compose cp raise_fees.php dolibarr:/tmp/raise_fees.php >/dev/null
  docker compose exec -T dolibarr php /tmp/raise_fees.php --commit
fi

echo "[4f] document requests (what the client owes him)"
docker compose cp doc_requests.php dolibarr:/tmp/doc_requests.php >/dev/null
docker compose exec -T dolibarr php /tmp/doc_requests.php

echo "[4g] filing ledger + late-fee exposure"
docker compose cp filings.php dolibarr:/tmp/filings.php >/dev/null
docker compose exec -T -e CA_GOLIVE dolibarr php /tmp/filings.php

case "${COMPOSE_FILE:-}" in
  *prod*) echo "[5/7] smoke test skipped (prod profile has no Mailpit - use ./healthcheck.sh)";;
  *)      echo "[5/7] end-to-end smoke test"; ./smoke.sh || { echo "FATAL: smoke test failed"; exit 1; };;
esac
# Every file copied to the container's /tmp above stays there forever otherwise.
# clients.csv alone holds 47 real PAN/GSTIN/TAN/phone records; any path-traversal
# or file-read bug in Dolibarr would read it straight off disk.
echo "[5b] wiping copied artefacts from the container"
# Wipe by GLOB, not by a hardcoded list. The list had to be edited every time a
# script was added and silently fell behind - fl.php, fr.php and dfc.php were all
# missing from it. The assertion below already demands ZERO .php/.csv in /tmp, so
# the wipe should simply match the assertion instead of enumerating filenames.
docker compose exec -T dolibarr sh -c 'rm -f /tmp/*.php /tmp/*.csv /tmp/*.eml 2>/dev/null; true'
left=$(docker compose exec -T dolibarr sh -c 'ls /tmp/*.csv /tmp/*.php 2>/dev/null | wc -l' | tr -d " \r")
[ "${left:-0}" = "0" ] && echo "      /tmp clean" || { echo "      FATAL: $left artefact(s) left in container /tmp"; exit 1; }
rm -f /tmp/digest.eml /tmp/wa_digest.eml 2>/dev/null

echo "[6/7] volumes in use"
docker compose config --volumes | sed 's/^/      /'
case "${COMPOSE_FILE:-}" in
  *prod*) echo "[7/7] READY -> https://${CA_DOMAIN:-<CA_DOMAIN>}/  (admin / see secrets.env)";;
  *)      echo "[7/7] READY -> http://127.0.0.1:8080  (admin / see secrets.env)";;
esac
