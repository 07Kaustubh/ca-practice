#!/usr/bin/env bash
# Generates secrets.env. Run once per host. Never commit the output.
set -euo pipefail
cd "$(dirname "$0")"
[ -f secrets.env ] && { echo "secrets.env already exists - refusing to overwrite"; exit 1; }
r(){ LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-28}"; }
cat > secrets.env <<EOF
DB_ROOT_PASSWORD=$(r 32)
DB_PASSWORD=$(r 32)
DOLI_ADMIN_PASSWORD=$(r 20)
CA_CRON_KEY=$(r 32)
DOLI_URL_ROOT=http://localhost:8080
EOF
chmod 600 secrets.env
chmod 600 secrets.env
# Do NOT echo the password: it lands in scrollback, CI logs and shell history.
echo "secrets.env created (0600). Read the admin password with:"
echo "    grep DOLI_ADMIN_PASSWORD secrets.env"
