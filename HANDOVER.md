# CA Practice Management — Dolibarr
Client CRUD + dashboard + email reminders + WhatsApp fan-out. GPL-3.0, self-hosted.

## Why Dolibarr
No purpose-built open-source CA practice-management product exists. GitHub and
GitLab both swept: `madrecha/jamku` (24*) is a proprietary SaaS's issue tracker with
no source; `openaccountants` (372*) is tax-guide content, not an app;
`india-compliance` (265*) files GST for the instance owner's own company, not for
100 client firms. GitLab returns 0-star SEO repos only.

Finalists compared:
- **Dolibarr 7.6k GPL-3.0** — WINNER. Native reminder engine (`llx_actioncomm_reminder`
  with offset scheduling + delivery status), 146 opt-in modules (default install
  enables ONE), LAMP, runs in 2GB.
- **Krayin 23.8k MIT** — declined. `routes/console.php` contains the app's entire
  schedule: `Schedule::command('inbound-emails:process')`. No reminder engine at all.
- **SuiteCRM 5.7k AGPL-3.0** — declined: heavier admin, AGPL, SugarCRM lineage.
- **NocoDB** — declined: "Sustainable Use License", source-available, NOT OSI.

## Quick start (dev / laptop)
    ./gen-secrets.sh     # once per host; writes secrets.env (0600)
    ./deploy.sh          # idempotent; ends with a real smoke test
    ./healthcheck.sh     # catches every silent failure mode below
    ./sweeper.sh         # cron this: revives dead reminders, escalates exhausted
    # dolibarr-cron container handles Dolibarr's own jobs (*/5) automatically
    ./backup.sh          # db + documents; ./restore.sh backups/<ts>
    ./regression.sh      # STANDING GATE: wipes to virgin volumes and re-proves everything

## Production (VPS)
    sudo ./bootstrap-vps.sh        # docker, ufw + DOCKER-USER rule, fail2ban, swap, logrotate, crontab
    ./gen-secrets.sh               # then set CA_DOMAIN=<his domain> in secrets.env
    # Set a REAL relay in ca.env - check-prod.sh aborts if CA_SMTP_HOST is still
    # 'mailpit', because prod has no Mailpit and mail would vanish silently.
    #   export CA_SMTP_HOST="smtp.zoho.in"; export CA_SMTP_PORT="587"
    # point DNS at the box, then:
    export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
    docker compose up -d
    ./deploy.sh                    # REQUIRED. Without it there are no extrafields
                                   # (WhatsApp dies "Unknown column"), no
                                   # AGENDA_REMINDER_EMAIL, no admin email, no
                                   # SMTP config and no persona labels.

Caddy terminates TLS (auto Let's Encrypt for a real domain), Dolibarr publishes NO
port, and Mailpit is excluded. Verified locally with `tls internal`:
`https://localhost:8443 -> 200`, HSTS/X-Frame-Options/X-Content-Type-Options
present, `<title>Login @ 24.0.0` served through the proxy, `:8080` closed.

## Verified (executed, not asserted)
- Client + deadline + reminder created **through the web UI** -> email delivered
- Persistence: client AND uploaded document survive `docker compose down`
- Backup -> provable destruction -> restore of BOTH db and /var/www/documents
- Sweeper exits 2 and screams when the DB is unreachable
- Retry cycle: fail -> revive x3 -> escalate -> digest email delivered with every
  SMTP response code checked
- WhatsApp dedup: no-optin SKIPs then DELIVERS once opt-in is ticked; sent never
  duplicates; Meta-down retries to a cap instead of dropping silently
- Menu renders "Clients", not "Third parties"
- **Unattended operation**: the `dolibarr-cron` container fires `*/5` on its own.
  Seeded a due reminder, touched nothing, email arrived at t=2min.

## Gotchas that cost a failed test each
1. Const is `AGENDA_REMINDER_EMAIL`, NOT `MAIN_AGENDA_REMINDER_EMAIL`. Wrong name =
   guard returns 0 = cron prints "OK result = 1" and sends nothing. Guard-blocked
   failure is SILENT; row-level failure correctly reports KO. Monitor
   `llx_cronjob.lastoutput`, never the exit status.
2. Reminders go to the assigned USER, not the client, and a fresh install has NO
   email on admin -> every reminder dies permanently. setup_all.php fixes this.
3. `status=-1` is TERMINAL — Dolibarr never retries. sweeper.sh is not optional.
4. Reminder unit dropdown DEFAULTS TO MINUTES. "7" on a filing deadline = 7 minutes.
5. `CRON_KEY` is stored encrypted (`dolcrypt:AES-256-CTR:`); unreadable via plain
   SQL. It lives in secrets.env — and must be defined in exactly ONE file.
6. Without `MAIN_INFO_SOCIETE_*` every page redirects to a "Setup not complete" nag.
7. `llx_overwrite_trans` is INERT unless `MAIN_ENABLE_OVERWRITE_TRANSLATION=1`
   (translate.class.php:538). Use `lang=NULL` rows so `MAIN_LANG_DEFAULT=auto`
   cannot bypass them.
8. DB session is UTC, PHP is Asia/Kolkata. wa_fanout.py pins `SET time_zone='+05:30'`.
9. Dolibarr 24.0.0 throws a benign mysqli teardown fatal after cron. Cosmetic.

## NOT PROVEN — state these to the client, unhedged
- **Real WhatsApp delivery.** Tested against a mock endpoint. Template approval,
  phone registration and token scopes are untested. A number already on the
  WhatsApp consumer/Business app CANNOT be onboarded to Cloud API without removing
  it first — the CA's client-facing number almost certainly is.
- **Real email deliverability.** Mailpit is a local sink. A fresh VPS IP with no
  SPF/DKIM/DMARC will be spam-foldered; most providers block outbound port 25.
  Use a relay (Zoho/Brevo/SES).
- **Host-loss DR.** Backups sit on the same host. Copy offsite or it is not DR.
- **The VPS.** Nothing is provisioned. No TLS, no reverse proxy, no firewall.
  Tested only on Docker Desktop.
- **"Agenda" menu label** still reads Agenda, not Deadlines — its menu key is not
  in the override set. Cosmetic.

## Open question that changes the design
**Who receives the WhatsApp — the CA, or his client?** Both are flags in ca.env
(`WA_NOTIFY_CA`, `WA_NOTIFY_CLIENT`), so either answer works without a rewrite.
But if it is the client, Meta requires documented opt-in per recipient
(`whatsapp_optin` extrafield exists and is enforced) and the message content is
client-facing, which needs his review.

## Security notes
Ports bind 127.0.0.1 only — Docker's iptables rules bypass ufw, so a 0.0.0.0 bind
would publish Mailpit's unauthenticated archive and an open SMTP relay. Reach the
UI over an SSH tunnel until a TLS reverse proxy is in front.

## Scheduling: what runs by itself and what does NOT
- **Runs unattended (verified):** the `dolibarr-cron` container. `DOLI_CRON=1` makes
  the image write `/etc/cron.d/dolibarr` (`*/5`) and skip the install path
  (docker-run.sh:343), so it cannot race the web container's schema setup.
  Do NOT override its `entrypoint` - that bypasses the script that writes conf.php.
- **Does NOT run by itself:** `sweeper.sh` and `wa_fanout.py`. They shell out to
  `docker compose` from the host, so on the VPS install host crontab entries:

      */10 * * * * cd /opt/ca && ./sweeper.sh   >> /var/log/ca-sweeper.log 2>&1
      */10 * * * * cd /opt/ca && ./wa_fanout.py >> /var/log/ca-wa.log 2>&1

  Until those exist, dead-reminder revival and WhatsApp do not happen on their own.
  Untested on a real host.
- **No log rotation** on `ca-jobs.log` or the host cron logs. Add logrotate.

## Regression gate — run this before any handover
Every defect found in review traced to one omission: the suite had never been run
against EMPTY volumes. `./regression.sh` does exactly that and is the standing gate.
Latest run: **REGRESSION SUITE PASS** (virgin volumes -> deploy -> extrafields 2/2
-> wa_fanout exit 0 -> smoke PASS -> HEALTHY -> sweeper exits 2 with the DB down).

## Two bugs I shipped and had to fix — worth knowing about
1. `extrafields.php` was wired into nothing, so on a fresh deploy WhatsApp died
   with `Unknown column 'e.whatsapp_number'`. Earlier "proof" had run against
   hand-made state the deploy path never produced. Now called from `deploy.sh`
   with a self-check.
2. `ca_wa_sent` shared ONE counter between `skipped` and `failed`. Since "no
   opt-in yet" is every new client's default and the job runs every 10 minutes,
   the retry budget was consumed within ~30 minutes and a real failure was
   abandoned after ONE attempt, silently, while printing "will retry, cap 3".
   Fixed with a separate `fails` column; exhaustion now prints an ALERT to stderr
   and exits non-zero at the moment it happens.

## bootstrap-vps.sh — now actually tested on Linux
Runs to completion on Debian 12 (`docker run --privileged debian:12`). Verified
independently after the run: `ufw` pkg state `ii`, DOCKER-USER DROP rule present,
logrotate installed, 3 crontab jobs, swapfile created, unattended-upgrades on.
Two WARNs are genuine container limits and degrade loudly, not silently:
`systemd unavailable` (so the DOCKER-USER systemd unit isn't enabled) and
`swapon failed`. Both work on a real VM; re-run there to clear them.

**Bug this testing caught:** the script previously installed `iptables-persistent`,
which on Debian CONFLICTS with `ufw` — apt resolved it by REMOVING ufw, returned
exit 0, and printed success. The VPS would have had no firewall while the operator
believed it did. Now: no iptables-persistent, an explicit `dpkg -l ufw | grep ^ii`
assertion, and the DOCKER-USER rule persisted by a systemd unit instead.

## Scope delivered — three pillars
All proven from destroyed volumes via `./regression.sh`.

1. **Clients** — 12 profile fields. The compliance-relevant ones (entity type,
   GST scheme, TAN, tax audit, ROC applicable) drive the calendar and travel in
   `clients.csv`, so an import carries the calendar with it.
2. **Compliance** — `compliance_calendar.php` derives **1,111 statutory
   deadlines** for 47 clients from their profiles, with **825 reminders**.
   Idempotent; run once each April for the new FY.
3. **Finances — his OWN practice.** Fee invoices from retainer + billing cycle
   at 18% GST, bank/cash, supplier bills, a 9-item CA service catalogue, and
   **TDS u/s 194J** tracking with Form 16A status. One quarter's billing here:
   Rs 1,093,270 billed, Rs 45,525 TDS receivable across 23 clients.
   **NOT his clients' ledgers** — Dolibarr is one company's books; 47 clients
   would mean 47 "Companies" each with its own chart of accounts. That is Tally.

## Gotcha 10 — reminders are PURGED after one month
`core/ajax/check_notifications.php:81` executes:

    DELETE FROM llx_actioncomm_reminder
     WHERE dateremind < <now minus 1 month> AND fk_user = <logged-in user>

It fires from the browser notification poller — a **UI action**, so there is no
cron log of it. **`sweeper.sh` cannot recover these**: the rows are deleted, not
left at `status=-1`. This is why 270 reminders silently vanished during testing
before it was diagnosed. `compliance_calendar.php` now refuses to create a
reminder that would already be stale (and still records the deadline), so
`future_without_reminder` is 0.

## New scripts
    compliance_calendar.php   statutory deadlines from client profiles (idempotent)
    finance_setup.php         enable invoicing/bank/expenses/tax + service catalogue + TDS fields
    raise_fees.php            raise fee invoices for the period (--commit; dry run by default)

## Security posture — audited, 24 findings closed
An external audit (never run before) found 5 P0, 6 P1 and 7 P2 issues, plus an
orphan-reachability scan found 6 unwired scripts. All closed and gated.

### Fixed — P0 (were "unfit to deploy with real client data")
| Was | Now |
|---|---|
| Backups plaintext, same host, 0755, next to `secrets.env` | `age`-encrypted, `$HOME/ca-practice-backups` 0700, optional `rclone` offsite, 30-day rotation, **restore drill in the gate** |
| SSH hardening never automated; port 22 open, all root | `bootstrap-vps.sh` sets key-only, `PermitRootLogin prohibit-password`, `MaxAuthTries 3`; refuses to reload if no `authorized_keys` (no lockout) |
| Shared `admin`; **audit trail deliberately disabled** (158 triggers off for cosmetics) | 4 named users, `Practice Staff` group (clients+agenda, no Setup), **158 audit triggers back ON** |
| `WA_TOKEN` in world-readable `ca.env`; `WA_API_BASE` defaulted to `http://` | Token in `secrets.env`; both files 0600; `wa_fanout.py` **exits** if the base is not https |
| `raise_fees.php` derived TDS 194J from `rowid % 2` — fabricated statutory figures | Driven by a recorded `tds_applicable` field; gate asserts `tds_invoices == tds_clients` |

### Fixed — P1/P2
Images pinned by sha256 digest · container `/tmp` wiped of the 47-PAN CSV after
deploy (asserted) · `.gitignore` for secrets/PII/backups · DB password off argv
in 7 files (`MYSQL_PWD`) · **inline `<script>` removed from `MAIN_HTML_HEADER`** —
now an SRI-pinned static file, so a CSP can police it · CSP + Permissions-Policy
at Caddy · CSRF/session/password policy constants · ip6tables rule + hourly
firewall reassert · PAN/GSTIN/TAN regex validation with a GSTIN-embeds-PAN
cross-check · dedupe on PAN not name · no password to stdout ·
`group_concat_max_len` · stale security comment corrected.

### Refuted by the audit (I had these wrong)
- **No exploitable SQL injection.** Every interpolation is `(int)`-cast, escaped,
  or routed through Dolibarr's ORM. The CSV never reaches raw SQL.
- **Container exposure was already sound.** No service publishes a port; the
  `DOCKER-USER` rule fails closed for new ports. The hole was SSH, not Docker.

### DPDP Act 2023 / ICAI
Residency: §16 is a **blacklist** model, not localisation — encrypted offsite
backup abroad is lawful. What the old design actually failed: §8(4) reasonable
safeguards, §8(5)-(6) breach notification (impossible without attribution or an
audit log), §8(7) erasure. Attribution and the audit trail are now in place;
**retention/erasure policy is still not implemented** — see below.

## Gotcha 11 — a hardening constant broke user creation
Setting `USER_PASSWORD_GENERATED='Perso'` makes `User::create()` load
`modGeneratePassPerso`, which calls `dolibarr_set_const()`. Any CLI script that
creates users must `require_once core/lib/admin.lib.php` or every creation dies
with an undefined-function fatal. Also: generated passwords must satisfy the
project's own 12-char + uppercase policy — `bin2hex()` is lowercase-only and
fails it.

## Known remaining, stated plainly
- **DPDP §8(7) retention/erasure** — no policy or deletion path. Data and
  backups accumulate. This is the one audit finding not closed.
- Real Meta delivery, a real SMTP relay with SPF/DKIM/DMARC, and the VPS itself
  still need the CA's own accounts.
- `bootstrap-vps.sh` runs to completion on `debian:12 --privileged`; the SSH and
  IPv6 paths need one run on a real VM (`nmap -6` to confirm).
