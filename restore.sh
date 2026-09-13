#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"; set -a; . ./secrets.env; set +a
SRC="${1:?usage: ./restore.sh backups/YYYYmmdd-HHMMSS}"
[ -f "$SRC/db.sql" ] || { echo "no db.sql in $SRC"; exit 1; }
docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mariadb -udolibarr dolibarr < "$SRC/db.sql"
[ -f "$SRC/documents.tar.gz" ] && docker compose exec -T dolibarr tar -xzf - -C /var/www < "$SRC/documents.tar.gz"
echo "restored from $SRC"
