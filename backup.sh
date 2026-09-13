#!/usr/bin/env bash
# Backs up BOTH the database AND /var/www/documents (the CA's filed returns).
# A DB-only dump loses every uploaded document permanently.
set -euo pipefail
cd "$(dirname "$0")"; set -a; . ./secrets.env; set +a; . ./ca.env
TS=$(date +%Y%m%d-%H%M%S)
# Out of the deploy directory (it sat next to secrets.env) and 0700.
BACKUP_ROOT="${CA_BACKUP_DIR:-$HOME/ca-practice-backups}"
OUT="$BACKUP_ROOT/$TS"; mkdir -p "$OUT"; chmod 700 "$BACKUP_ROOT" "$OUT"
docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb-dump -udolibarr \
  --single-transaction --routines dolibarr > "$OUT/db.sql"
docker compose exec -T dolibarr tar -czf - -C /var/www documents > "$OUT/documents.tar.gz" 2>/dev/null
dbsz=$(wc -c < "$OUT/db.sql"); docsz=$(wc -c < "$OUT/documents.tar.gz")
echo "  db.sql            $dbsz bytes"
echo "  documents.tar.gz  $docsz bytes"
# Guard against a truncated dump: a real Dolibarr schema dump is >>500KB.
[ "$dbsz" -lt 500000 ] && { echo "  FAIL: db dump implausibly small - not a valid backup"; exit 1; }
[ "$docsz" -lt 100 ]   && { echo "  FAIL: documents archive empty"; exit 1; }
grep -q "Dump completed" "$OUT/db.sql" || { echo "  FAIL: dump has no completion marker (truncated)"; exit 1; }
# ── encrypt ────────────────────────────────────────────────────────────────
# The dump holds every client's PAN, GSTIN and TAN in the clear. Ransomware or a
# lost VPS takes the data AND its only copy. DPDP s8(4) "reasonable security
# safeguards" is an outcome test, and a plaintext same-host dump fails it.
if command -v age >/dev/null && [ -n "${CA_AGE_RECIPIENT:-}" ]; then
  tar -czf - -C "$OUT" db.sql documents.tar.gz \
    | age -r "$CA_AGE_RECIPIENT" -o "$OUT/backup.tar.gz.age"
  if [ -s "$OUT/backup.tar.gz.age" ]; then
    shred -u "$OUT/db.sql" "$OUT/documents.tar.gz" 2>/dev/null || rm -f "$OUT/db.sql" "$OUT/documents.tar.gz"
    chmod 600 "$OUT/backup.tar.gz.age"
    echo "  encrypted -> $OUT/backup.tar.gz.age ($(wc -c < "$OUT/backup.tar.gz.age") bytes)"
  else
    echo "  FAIL: age produced nothing - refusing to leave a plaintext dump"; rm -rf "$OUT"; exit 1
  fi
else
  echo "  WARN: CA_AGE_RECIPIENT unset or 'age' missing - dump left UNENCRYPTED."
  echo "        Install age and set CA_AGE_RECIPIENT before this holds real client data."
  chmod 600 "$OUT"/* 2>/dev/null
fi

# ── offsite ────────────────────────────────────────────────────────────────
if [ -n "${CA_OFFSITE_TARGET:-}" ] && command -v rclone >/dev/null; then
  rclone copy "$OUT" "$CA_OFFSITE_TARGET/$TS" --quiet && echo "  offsite -> $CA_OFFSITE_TARGET/$TS"
else
  echo "  NOTE: CA_OFFSITE_TARGET unset - copy is still on this host only."
fi
find "$BACKUP_ROOT" -maxdepth 1 -type d -mtime +30 -name '20*' -exec rm -rf {} + 2>/dev/null
echo "  BACKUP OK -> $OUT"
