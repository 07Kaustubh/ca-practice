#!/usr/bin/env bash
# Run ONCE on a fresh Debian/Ubuntu VPS as root. Idempotent.
set -euo pipefail
# ufw/iptables live in /usr/sbin, which is missing from PATH under some
# non-login root shells - the script then "succeeded" past a firewall it never
# configured. Pin it.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"
[ "$(id -u)" -eq 0 ] || { echo "run as root"; exit 1; }
cd "$(dirname "$0")"   # logrotate/crontab installs are relative to the repo
echo "[1/6] packages"
apt-get update -qq
# NOTE: do NOT install iptables-persistent. On Debian it CONFLICTS with ufw, and
# apt resolves the conflict by REMOVING ufw - exit 0, no warning, box left with
# no firewall. The DOCKER-USER rule is persisted by a systemd unit instead (step 3).
apt-get install -y -qq --no-install-recommends \
  ca-certificates curl iproute2 iptables ufw fail2ban unattended-upgrades logrotate cron
dpkg -l ufw 2>/dev/null | grep -q '^ii' || { echo "  FATAL: ufw not installed (state: $(dpkg -l ufw 2>/dev/null | tail -1))"; exit 1; }
echo "  ufw installed and held"

echo "[2/6] docker"
if ! command -v docker >/dev/null; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/debian $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin
fi

echo "[3/6] firewall"
for b in ufw iptables ip; do
  command -v "$b" >/dev/null || { echo "  FATAL: $b not on PATH after install"; exit 1; }
done
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp  >/dev/null
ufw allow 80/tcp  >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null
# CRITICAL: docker publishes straight past ufw via its own iptables chain.
# Anything bound to 0.0.0.0 is internet-reachable regardless of ufw. This closes
# that hole for everything except the ports Caddy needs.
# Derive the real NIC. Hardcoding eth0 meant the rule was ADDED SUCCESSFULLY but
# matched nothing on ens3/enp1s0 hosts - failing open with no warning.
NIC=$(ip route show default 2>/dev/null | awk '/default/{print $5; exit}')
[ -n "$NIC" ] || { echo "  FATAL: cannot determine default interface"; exit 1; }
echo "  default interface: $NIC"
# Docker creates DOCKER-USER when the daemon starts. During bootstrap the daemon
# may not have run yet, so create the chain rather than fail.
iptables -N DOCKER-USER 2>/dev/null || true
iptables -C FORWARD -j DOCKER-USER 2>/dev/null || iptables -I FORWARD -j DOCKER-USER 2>/dev/null || true
iptables -C DOCKER-USER -i "$NIC" ! -s 127.0.0.1 -p tcp -m multiport ! --dports 80,443 -j DROP 2>/dev/null \
  || iptables -I DOCKER-USER -i "$NIC" ! -s 127.0.0.1 -p tcp -m multiport ! --dports 80,443 -j DROP \
  || { echo "  FATAL: could not add DOCKER-USER rule (docker bypasses ufw without it)"; exit 1; }
iptables -C DOCKER-USER -i "$NIC" ! -s 127.0.0.1 -p tcp -m multiport ! --dports 80,443 -j DROP \
  || { echo "  FATAL: DOCKER-USER rule not present after insert"; exit 1; }
echo "  DOCKER-USER rule verified on $NIC"
# IPv6: most VPS providers hand out a public v6 address. An IPv4-only rule is a
# blind spot the moment anything is published on v6.
if command -v ip6tables >/dev/null; then
  ip6tables -N DOCKER-USER 2>/dev/null || true
  ip6tables -C FORWARD -j DOCKER-USER 2>/dev/null || ip6tables -I FORWARD -j DOCKER-USER 2>/dev/null || true
  ip6tables -C DOCKER-USER -i "$NIC" -p tcp -m multiport ! --dports 80,443 -j DROP 2>/dev/null \
    || ip6tables -I DOCKER-USER -i "$NIC" -p tcp -m multiport ! --dports 80,443 -j DROP 2>/dev/null \
    || echo "  WARN: could not add IPv6 DOCKER-USER rule"
  ip6tables -C DOCKER-USER -i "$NIC" -p tcp -m multiport ! --dports 80,443 -j DROP 2>/dev/null \
    && echo "  DOCKER-USER IPv6 rule verified on $NIC" || true
else
  echo "  WARN: ip6tables absent - IPv6 exposure UNVERIFIED. Run: nmap -6 <host>"
fi
# Persist via systemd rather than iptables-persistent (which would uninstall ufw).
cat > /etc/systemd/system/ca-docker-user.service <<UNIT
[Unit]
Description=Re-apply DOCKER-USER ingress restriction (docker bypasses ufw)
After=docker.service
Requires=docker.service
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'NIC=\$(ip route show default | awk "/default/{print \\\$5; exit}"); \
  iptables -C DOCKER-USER -i \$NIC ! -s 127.0.0.1 -p tcp -m multiport ! --dports 80,443 -j DROP 2>/dev/null || \
  iptables -I DOCKER-USER -i \$NIC ! -s 127.0.0.1 -p tcp -m multiport ! --dports 80,443 -j DROP'
[Install]
WantedBy=multi-user.target
UNIT
# The systemd unit is oneshot+RemainAfterExit and only fires at boot. A dockerd
# restart (e.g. from unattended-upgrades) would not re-run it, so reassert hourly.
( crontab -l 2>/dev/null | grep -v 'ca-docker-user'; \
  echo "17 * * * * systemctl start ca-docker-user.service >/dev/null 2>&1 # ca-docker-user" ) | crontab -
systemctl daemon-reload >/dev/null 2>&1 && systemctl enable ca-docker-user.service >/dev/null 2>&1 \
  && echo "  DOCKER-USER rule persisted via systemd" \
  || echo "  WARN: systemd unavailable (container?) - rule will not survive reboot"

echo "[4/6] swap (Dolibarr+MariaDB on 2GB needs headroom)"
if [ ! -f /swapfile ]; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null
  # swapon cannot work inside a container; do not abort the whole bootstrap for it.
  if swapon /swapfile 2>/dev/null; then
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo "  swap active"
  else
    echo "  WARN: swapon failed (container?) - /swapfile created but not enabled"
  fi
fi

echo "[4b] SSH hardening"
# Port 22 is open to the world and everything here runs as root. fail2ban slows
# brute force; it does nothing against credential stuffing or a weak root
# password. This is the single most probable initial-access vector, so it is
# codified rather than left to whatever the VPS image happened to ship.
SSHD=/etc/ssh/sshd_config
if [ -f "$SSHD" ]; then
  cp -n "$SSHD" "$SSHD.bak.ca" 2>/dev/null
  set_sshd(){ grep -qE "^[#[:space:]]*$1" "$SSHD" \
      && sed -i -E "s|^[#[:space:]]*$1.*|$1 $2|" "$SSHD" \
      || echo "$1 $2" >> "$SSHD"; }
  set_sshd PermitRootLogin prohibit-password
  set_sshd PasswordAuthentication no
  set_sshd KbdInteractiveAuthentication no
  set_sshd ChallengeResponseAuthentication no
  set_sshd PubkeyAuthentication yes
  set_sshd X11Forwarding no
  set_sshd MaxAuthTries 3
  # Refuse to lock the operator out: only reload if a key is already installed.
  if grep -qs . /root/.ssh/authorized_keys || grep -qsr . /home/*/.ssh/authorized_keys; then
    sshd -t && systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null
    echo "  sshd hardened: key-only, no root password, MaxAuthTries 3"
  else
    echo "  WARN: sshd_config hardened but NOT reloaded - no authorized_keys found."
    echo "        Install your public key, then: sshd -t && systemctl reload ssh"
  fi
else
  echo "  WARN: no sshd_config (container?) - SSH hardening skipped"
fi

echo "[5/6] unattended upgrades + fail2ban"
echo 'APT::Periodic::Unattended-Upgrade "1";' > /etc/apt/apt.conf.d/20auto-upgrades
systemctl enable --now fail2ban >/dev/null 2>&1 \
  && echo "  fail2ban enabled" \
  || echo "  WARN: could not enable fail2ban (no systemd?) - brute-force protection NOT active"

echo "[6/6] logrotate + crontab"
install -m 0644 ca.logrotate /etc/logrotate.d/ca-practice
# APPEND - do not clobber whatever else root already has scheduled.
if ! crontab -l 2>/dev/null | grep -q 'ca-practice'; then
  { crontab -l 2>/dev/null; cat ca.crontab; } | crontab -
fi
crontab -l | grep -q 'ca-practice' || { echo "  FATAL: crontab not installed"; exit 1; }
echo "  logrotate + crontab installed"
# This text is the last thing an operator sees. It MUST match HANDOVER.md's
# Production block exactly - a stale sequence here silently produces a system
# with no extrafields, no AGENDA_REMINDER_EMAIL and no admin email.
# Note: export COMPOSE_FILE (not -f flags). check-prod.sh keys off COMPOSE_FILE
# and disarms itself if it is unset, so -f would skip the prod safety checks.
cat <<'NEXT'
DONE. Next:
  ./gen-secrets.sh                  # then set CA_DOMAIN=<domain> in secrets.env
  # Set a REAL relay - check-prod.sh aborts while CA_SMTP_HOST is 'mailpit',
  # because production has no Mailpit and mail would vanish silently:
  #   export CA_SMTP_HOST="smtp.zoho.in"; export CA_SMTP_PORT="587"
  # point DNS at this box, then:
  export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
  docker compose up -d
  ./deploy.sh                       # REQUIRED - creates extrafields, sets
                                    # AGENDA_REMINDER_EMAIL, admin email, SMTP,
                                    # persona labels. Skipping it = dead reminders.
NEXT
