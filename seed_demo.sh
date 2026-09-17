#!/usr/bin/env bash
# DEMO STATE ONLY. NOT part of deploy, NOT run by cron.
#
# A freshly deployed practice has nothing ready to file: every filing is still
# waiting on documents, so the filing screen correctly reads "nothing is ready".
# That is honest but it shows none of the lifecycle. A practice that has been
# running a fortnight has documents coming back, returns going out, and
# acknowledgement numbers on record.
#
# This reproduces that mid-flight state from real data - it marks documents as
# received and files returns THROUGH THE REAL WRITE PATH (file_return.php), so
# every ARN here passes the same validation a CA's own entry would. Nothing is
# inserted behind the application's back.
#
# An earlier version of this existed only as an ad-hoc script in /tmp, which is
# why the "12 filed with ARNs" shown once could not be reproduced afterwards.
set -uo pipefail
cd "$(dirname "$0")"
. ./ca.env; set -a; . ./secrets.env; set +a

READY=${CA_DEMO_READY:-12}     # documents all back, waiting to be filed
FILED=${CA_DEMO_FILED:-9}      # already filed, with an acknowledgement

Q(){ docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr -sN \
       -e "$1" 2>/dev/null | grep -v -i "insecure\|warning"; }

# Clean up on EVERY exit path. The early `exit 1` below skipped the tidy-up at the
# end and left fl.php/fr.php in the container, which the next deploy refused to
# start with - correctly, since that is where clients.csv-grade artefacts live.
trap 'docker compose exec -T dolibarr rm -f /tmp/fl.php /tmp/fr.php >/dev/null 2>&1' EXIT
docker compose cp filings.php     dolibarr:/tmp/fl.php >/dev/null
docker compose cp file_return.php dolibarr:/tmp/fr.php >/dev/null

# 1. documents come back from the client -> those filings become 'ready'
n=$((READY + FILED))
# Spread across filing FAMILIES, not just the earliest due dates. Ordering by due
# alone returned nothing but advance tax (they all fall on the 15th), so the demo
# showed one kind of return and none of the breadth the calendar actually covers.
# Same ROW_NUMBER partition the morning digest uses to stop one statutory date
# monopolising the screen.
ids=$(Q "SELECT fk_actioncomm FROM (
          SELECT f.fk_actioncomm,
                 ROW_NUMBER() OVER (PARTITION BY SUBSTRING_INDEX(f.filing,' ',1) ORDER BY f.due) rn
            FROM ca_filing f
            JOIN ca_docrequest d ON d.fk_actioncomm = f.fk_actioncomm
           WHERE f.status='pending' AND f.fk_actioncomm IS NOT NULL
           GROUP BY f.fk_actioncomm, f.filing, f.due
         ) t WHERE rn <= 4 ORDER BY rn LIMIT $n;")
[ -n "$ids" ] || { echo "  nothing pending to seed - run deploy.sh first"; exit 1; }
for ac in $ids; do Q "UPDATE ca_docrequest SET status='received' WHERE fk_actioncomm=$ac;" >/dev/null; done
docker compose exec -T -e CA_GOLIVE dolibarr php /tmp/fl.php | sed 's/^/  /'

# 2. file some of them properly, through the real write path
#    Acknowledgement shapes differ per filing family. CONSTRUCT them the way the
#    portal does - zero-padding a small random number produced things like
#    00000000000316267740, which no CA would believe for a second.
filed=0
# a handful of real BSR codes, so a challan identification number looks like one
BSRS="0510308 0004329 6390340 0240293 0300043"
# the people who actually do the filing - rotated so the ledger shows a practice,
# not one shared admin account
STAFF="ca.partner article1 article2"
for rid in $(Q "SELECT rowid FROM ca_filing WHERE status='ready' ORDER BY due LIMIT $FILED;"); do
  fam=$(Q "SELECT filing FROM ca_filing WHERE rowid=$rid;")
  # spread the filing dates across the last fortnight so "filed in the last
  # 7 days" is a real subset rather than everything landing on one day
  d=$(( RANDOM % 12 ))
  on=$(date -j -v-${d}d +%Y-%m-%d 2>/dev/null || date -d "-${d} days" +%Y-%m-%d)
  DD=${on:8:2}; MM=${on:5:2}; YYYY=${on:0:4}; YY=${on:2:2}
  ser6=$(( 100000 + RANDOM % 900000 ))          # 6 digits, never leading zero
  ser5=$(( 10000  + RANDOM % 90000  ))          # 5 digits, never leading zero
  chk=$(echo Z | tr 'Z' "$(printf '\\%03o' $((65 + RANDOM % 26)))")
  case "$fam" in
    # GST ARN: AA + state + MM + YY + 6-digit serial + 1 check char = 15
    GSTR-*|CMP-08*)                  ack="AA07${MM}${YY}${ser6}${chk}" ;;
    # ITR acknowledgement / TDS provisional receipt: 15 digits, non-zero leading
    ITR*|"Tax audit"*|TDS\ return*)   ack="$(( 1 + RANDOM % 9 ))${ser6}${ser6}${DD}" ;;
    # CIN: 7-digit BSR + DDMMYYYY + 5-digit challan serial = 20
    TDS\ payment*|Advance\ tax*)      bsr=$(echo $BSRS | tr ' ' '\n' | sed -n "$((1 + RANDOM % 5))p")
                                     ack="${bsr}${DD}${MM}${YYYY}${ser5}" ;;
    # MCA SRN: a letter then 8 digits
    AOC-4*|MGT-7*|LLP\ Form*|DIR-3*) ack="T$(( 10000000 + RANDOM % 89999999 ))" ;;
    *)                               ack="ACK${ser6}${ser5}" ;;
  esac
  # Attribute filings across the real staff logins, not all to admin. The product
  # claims work is traceable to a person and the gate asserts every article can
  # record a filing - a ledger showing "admin" on every row contradicts both.
  who=$(echo $STAFF | tr ' ' '\n' | sed -n "$(( (filed % 3) + 1 ))p")
  if docker compose exec -T dolibarr php /tmp/fr.php "$rid" "$ack" "$on" --user="$who" >/dev/null 2>&1; then
    filed=$((filed+1))
  fi
done

docker compose exec -T dolibarr rm -f /tmp/fl.php /tmp/fr.php >/dev/null 2>&1
r=$(Q "SELECT COUNT(*) FROM ca_filing WHERE status='ready';")
f=$(Q "SELECT COUNT(*) FROM ca_filing WHERE status='filed';")
a=$(Q "SELECT COUNT(*) FROM ca_filing WHERE status='filed' AND arn IS NOT NULL AND arn<>'';")
echo "  demo state: ready=$r  filed=$f (recorded this run: $filed)  with acknowledgement=$a"
[ "$f" = "$a" ] || { echo "  FATAL: $((f-a)) filed row(s) carry no acknowledgement"; exit 1; }
