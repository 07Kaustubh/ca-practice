# CA Practice Management — How To Use

Client CRUD + dashboard + email reminders + WhatsApp reminders, on Dolibarr
(GPL-3.0, self-hosted). This guide is written to be followed literally.

---

## 0. What you are getting

| | |
|---|---|
| **App** | Dolibarr 24.0.0 — open source ERP/CRM, used here as a CA client register |
| **Database** | MariaDB 11 |
| **Mail (dev)** | Mailpit — a local sink that catches mail so you can see it. **Never used in production.** |
| **Mail (prod)** | Your own SMTP relay (Zoho / Brevo / SES) |
| **TLS (prod)** | Caddy, automatic Let's Encrypt |
| **WhatsApp** | `wa_fanout.py` → Meta WhatsApp Cloud API |

The CA gets a 5-item menu: **Home · Clients · Agenda · Tools · Setup**.
Everything else in Dolibarr stays switched off.

---

## 0b. Security — read before real client data goes in

    ./gen-secrets.sh                  # 0600; never echoes the password
    age-keygen -o ~/.ca-backup.key    # keep the PRIVATE key OFF the server
    # put the public key in ca.env:
    export CA_AGE_RECIPIENT="age1..."
    export CA_OFFSITE_TARGET="b2:ca-backups"   # optional rclone remote

`backup.sh` refuses to leave a plaintext dump once `CA_AGE_RECIPIENT` is set.
`sudo ./bootstrap-vps.sh` enforces key-only SSH but will **not** reload sshd
unless an `authorized_keys` already exists — install your key first.

Logins are per person (`ca.partner`, `article1`, `article2`), not a shared
`admin`. Staff sit in a `Practice Staff` group with client + deadline rights and
no Setup access. The audit trail is ON: an earlier build disabled 158 triggers
for cosmetics, which made breach notification impossible.

## 1. Prerequisites

- Docker + Docker Compose v2 or later
- For production: a Debian/Ubuntu VPS (2 GB RAM minimum) and a domain name
- For WhatsApp: a Meta Business account and a **dedicated** phone number
  (see §7 — this cannot be his existing WhatsApp number)

---

## 2. Try it on your laptop first (5 minutes)

```bash
cd ~/ca-practice
./gen-secrets.sh      # once per machine. Writes secrets.env (chmod 600).
                      # Prints the admin password — note it down.
./deploy.sh           # brings up containers, configures everything, self-tests
```

`deploy.sh` finishes with a real smoke test — it creates a client, a reminder and
runs the cron, confirms an email actually landed in Mailpit, then deletes the test
data. If it does not print `SMOKE PASS`, do not continue; see §8.

Under the **production** profile this step is skipped, because production has no
Mailpit to assert against. Verify prod with `./healthcheck.sh` plus one real
reminder to a real inbox (§6).

Then open:

- **App**: http://127.0.0.1:8080 — log in as `admin`, password from `secrets.env`
- **Mailbox**: http://127.0.0.1:8025 — every email the system sends appears here

To load 47 sample clients:

```bash
CA_IMPORT_CLIENTS=1 ./deploy.sh
```

To stop (data is kept in named volumes):

```bash
docker compose down          # keeps data
docker compose down -v       # DESTROYS data
```

---

## 2b. The compliance calendar — the reason this exists

A CA does not type reminders. Statutory dates are fixed by law and **derivable
from each client's profile**, so the calendar is generated:

    ./deploy.sh                       # runs it automatically
    # or on demand, for a specific financial year:
    docker compose cp compliance_calendar.php dolibarr:/tmp/cc.php
    docker compose exec -T dolibarr php /tmp/cc.php 2026

| Client profile | Deadlines generated |
|---|---|
| GST **Monthly** | GSTR-1 by 11th, GSTR-3B by 20th — every month (24/yr) |
| GST **QRMP** | GSTR-1 by 13th, GSTR-3B by 22nd — quarterly (8/yr) |
| GST **Composition** | CMP-08 quarterly, GSTR-4 annual |
| Any GST registration | GSTR-9 annual by 31 Dec |
| Has a **TAN** | TDS payment by 7th monthly (March: 30 Apr); 24Q/26Q by 31 Jul / 31 Oct / 31 Jan / 31 May |
| Not an individual, or audited | Advance tax 15 Jun / 15 Sep / 15 Dec / 15 Mar |
| **Tax audit** applicable | 3CA/3CD by 30 Sep, ITR by 31 Oct |
| Otherwise | ITR by 31 Jul |
| **Private Limited** | AOC-4 by 30 Oct, MGT-7 by 29 Nov, DIR-3 KYC by 30 Sep |
| **LLP** | Form 11 by 30 May, Form 8 by 30 Oct |

47 clients produce **1,111 deadlines** with **825 reminders**. Idempotent — re-run
it as often as you like. Run it once each April for the new FY.

The profile lives on the client record (entity type, GST scheme, TAN, tax audit,
ROC applicable) and travels in `clients.csv`, so an import brings the calendar
with it.

## 2c. The practice's own books

Enabled: customer invoices, bank & cash, supplier bills (expenses), taxes,
service catalogue, basic bookkeeping.

    CA_RAISE_FEES=1 ./deploy.sh       # raise this period's fee invoices
    # or on demand:
    docker compose exec -T dolibarr php /tmp/rf.php            # dry run
    docker compose exec -T dolibarr php /tmp/rf.php --commit

Each client carries an **annual retainer** and a **billing cycle**; invoices are
raised pro-rata at 18% GST. A 9-item service catalogue (GST monthly Rs 2,500 ·
ITR Rs 7,500 · tax audit Rs 35,000 · retainer Rs 60,000 ...) makes billing two
clicks rather than free text.

**TDS u/s 194J.** Clients withhold 10% of professional fees. Two fields on every
invoice — `tds_194j` and `tds_certificate` (Form 16A received) — track what was
withheld and which certificates are outstanding. Without this he overpays his
own tax. On one quarter's billing here that is Rs 45,525 across 23 clients.

**Deliberately NOT included: his clients' books.** Dolibarr is one company's
ledger. Modelling 47 clients' accounts means 47 "Companies" each with its own
chart of accounts. He has Tally or Winman for that.

## 3. Daily use — what the CA actually does

### Add a client
**Clients → New Client.** Fill Name and Email. Tick **Customer** (the form uses
`prospect`/`customer` checkboxes — if you skip this the record saves but will not
appear in the client list). GSTIN, PAN, WhatsApp Number and WhatsApp Opt-in are
custom fields further down.

### Add a deadline with a reminder
**Agenda → New Event.**

1. **Title** — e.g. `GSTR-3B filing due — Sharma Textiles`
2. **Date** — required. The form will reject a blank date.
3. **Related company** — pick the client. This is what links the deadline to them.
4. Tick **"Create an automatic reminder notification for this event"**
5. **Reminder period before the event** — type `7`
6. **⚠ Change the unit dropdown from `Minutes` to `Days`.**
   It defaults to **Minutes**. Left alone, a filing deadline reminds him
   *7 minutes* before. This is the single easiest way to get burned.
7. **Callback type** — `Email`
8. **CREATE**

### What happens next
The `dolibarr-cron` container checks every 5 minutes. When the reminder comes
due it emails the **assigned user** (the CA), not the client. If WhatsApp is
enabled (§7), `wa_fanout.py` also sends a template message.

### Dashboard
**Home** shows upcoming deadlines, deadlines to action, recently modified
clients, and a **Scheduled jobs** panel with a *jobs in error* counter — worth a
glance each morning.

---

## 4. Production deployment

### 4a. Prepare the VPS
```bash
# as root on a fresh Debian/Ubuntu box
git clone <your-repo> /opt/ca     # or scp the folder to /opt/ca
cd /opt/ca
sudo ./bootstrap-vps.sh
```
Installs Docker, ufw, fail2ban, unattended-upgrades, 2 GB swap, logrotate and the
crontab. It also adds a `DOCKER-USER` iptables rule — **required**, because
Docker publishes ports straight past ufw.

### 4b. Configure
```bash
./gen-secrets.sh
vi secrets.env          # set CA_DOMAIN=practice.hisfirm.in
```

Set a **real mail relay** — production has no Mailpit, and `check-prod.sh` will
refuse to deploy while it is still pointed at one:
```bash
export CA_SMTP_HOST="smtp.zoho.in"
export CA_SMTP_PORT="587"
export CA_EMAIL="ca@hisfirm.in"
```

### 4c. Point DNS
An `A` record for `CA_DOMAIN` → the VPS IP. Caddy needs this before it can get a
certificate.

### 4d. Deploy
```bash
export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
docker compose up -d
./deploy.sh                      # REQUIRED — see below
```

**`deploy.sh` is not optional.** `docker compose up` alone gives you a Dolibarr
with no custom fields (WhatsApp dies with `Unknown column`), no
`AGENDA_REMINDER_EMAIL` (reminders silently never send), no email on the admin
account (every reminder dies permanently), no SMTP config and no "Clients"
labels.

Then: `https://practice.hisfirm.in` — Caddy terminates TLS. Port 8080 is not
published in production; Caddy is the only way in.

---

## 5. Operations

| Command | What it does | When |
|---|---|---|
| `./healthcheck.sh` | Checks every known silent-failure mode | Any time; after changes |
| `./sweeper.sh` | Revives dead reminders, escalates exhausted ones | Every 10 min via cron |
| `./wa_fanout.py` | Sends WhatsApp for due reminders | Every 10 min via cron |
| `./backup.sh` | Dumps DB **and** `/var/www/documents` | Nightly via cron |
| `./restore.sh backups/<timestamp>` | Restores both | On disaster |
| `./regression.sh` | **Wipes to empty volumes** and re-proves everything | Before handover. Destroys data. |
| `./check-prod.sh` | Refuses unsafe prod config | Called by `deploy.sh` |

`bootstrap-vps.sh` installs `ca.crontab`, which schedules sweeper, wa_fanout and
backup. It sets `PATH=` explicitly — cron's default PATH does not include Docker.

### Backups
`backup.sh` writes to `./backups/<timestamp>/` **on the same host**. That
protects against volume loss, not host loss. Copy them off the box:
```bash
17 3 * * * rsync -a /opt/ca/backups/ user@elsewhere:/backups/ca/
```

---

## 6. Verifying it actually works

Reminders fail silently in several ways. These are the checks that matter:

```bash
./healthcheck.sh                  # expect HEALTHY

# did the reminder job really do something?
docker compose exec -T db mariadb -udolibarr -p"$DB_PASSWORD" dolibarr \
  -e "SELECT label, lastresult, lastoutput FROM llx_cronjob WHERE rowid=1;"
```
**Read `lastoutput`, never the exit status.** A guard-blocked job prints
`OK result = 1` and sends nothing. `Nb of emails sent : 1` is the real signal.

```bash
# any permanently-dead reminders?
docker compose exec -T db mariadb -udolibarr -p"$DB_PASSWORD" dolibarr \
  -e "SELECT rowid, lasterror FROM llx_actioncomm_reminder WHERE status=-1;"
```
Dolibarr **never retries** a failed reminder. `sweeper.sh` retries 3× then emails
the CA an `[ACTION REQUIRED]` digest. If `sweeper.sh` is not running, a single
SMTP blip permanently loses that deadline alert.

---

## 7. WhatsApp setup

### The number
A number already registered on the WhatsApp consumer app **or** the WhatsApp
Business app **cannot** be onboarded to the Cloud API without first deleting it
there. His client-facing number almost certainly is registered. **Buy a
dedicated number.**

### Meta side
1. Meta Business Manager → business verification (he'll have GST cert and
   incorporation docs on hand)
2. Add WhatsApp, register the dedicated number, get a Phone Number ID
3. Create a **utility** template, e.g. `compliance_deadline_reminder`:
   `Hi, reminder: {{2}} for {{1}} is due on {{3}}.`
4. Wait for approval

### This side
In `ca.env` / `secrets.env`:
```bash
export WA_PHONE_ID="<phone number id>"
export WA_TOKEN="<permanent access token>"
export WA_API_BASE="https://graph.facebook.com/v21.0"
export WA_NOTIFY_CLIENT="1"     # message the client
export WA_NOTIFY_CA="0"         # or message the CA instead
export WA_CA_NUMBER="9198xxxxxxxx"
```

**Decide who receives it.** If it goes to clients, Meta requires documented
opt-in per recipient — tick **WhatsApp Opt-in** on each client record.
`wa_fanout.py` refuses to send without it.

### Behaviour
- No opt-in → `skipped`, re-checked every run. Tick opt-in later and it sends.
- Send fails → retried up to 3 times.
- 3 failures → `[ACTION REQUIRED]` digest to the CA, then it stops.
- Already sent → never duplicated.

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Reminders never arrive, cron says `OK result = 1` | `AGENDA_REMINDER_EMAIL` unset. Note: **not** `MAIN_AGENDA_REMINDER_EMAIL` | `./deploy.sh` |
| `Failed to send remind to user id=N. No email defined for user.` | Reminders go to the **assigned user**, and a fresh install has no email on admin | `./deploy.sh`, or set it in **Users & Groups** |
| Reminder fired minutes before, not days | Unit dropdown left on `Minutes` | Set it to `Days` (§3) |
| WhatsApp: `Unknown column 'e.whatsapp_number'` | `extrafields.php` never ran | `./deploy.sh` |
| WhatsApp: `KeyError` / `FATAL: WA_PHONE_ID not set` | Running outside the project dir | Run from `/opt/ca`; the script loads `ca.env` relative to itself |
| Cron jobs do nothing on the VPS | cron's PATH lacks Docker | Keep the `PATH=` line in `ca.crontab` |
| Every page redirects to "Setup is not complete" | `MAIN_INFO_SOCIETE_*` unset | `./deploy.sh` |
| Menu says "Third parties" not "Clients" | `MAIN_ENABLE_OVERWRITE_TRANSLATION` unset — the override table is inert without it | `./deploy.sh` |
| Reminder fires 5.5 h out in custom SQL | DB session is UTC, PHP is Asia/Kolkata | Use `SET time_zone='+05:30'` as `wa_fanout.py` does |
| `deploy.sh` says `FATAL: prod safety check failed` | `CA_SMTP_HOST` is still `mailpit` under the prod profile | Set a real relay (§4b) |
| Ports already in use | Another compose project is running | `docker compose ls`, then `down` the other one |
| `bootstrap-vps.sh`: no firewall afterwards | Older versions installed `iptables-persistent`, which **conflicts with ufw** and apt removed ufw silently | Current version asserts `dpkg -l ufw` is `ii`. Check it. |

Benign: Dolibarr 24.0.0 logs a `mysqli object is already closed` fatal after
every cron run. Cosmetic, noisy, safe to ignore.

---

## 9. Not done — needs the CA

These are unproven, not broken. Do not tell him otherwise.

1. **Real Meta delivery.** Tested against a mock endpoint. Template approval,
   phone registration and token scopes are untested.
2. **Real email deliverability.** Mailpit is a local sink. A fresh VPS IP with no
   SPF/DKIM/DMARC will be spam-foldered; most hosts block outbound port 25.
3. **The VPS.** Nothing is provisioned.
4. **Offsite backups.** Same-host only.
5. **Real ACME certificate.** TLS verified with `tls internal`.
6. **Who receives the WhatsApp** — CA or client. Flag-controlled; unanswered.
7. **`bootstrap-vps.sh`** ran on `debian:12 --privileged`, not a real VM.

Also cosmetic: the "Agenda" menu label still says Agenda.
`lbl.mjs` is a dev-only verification script and needs `npm install` first.


## Running the practice from the browser

Everything below is a screen. None of it needs a shell.

| Screen | What it is for |
|---|---|
| `/custom/ca/client.php` | Add or edit a client. PAN/GSTIN/TAN are validated and the statutory calendar is generated **on save** — a client added here is never invisible. Two fields decide real behaviour: **Aggregate turnover** decides whether GSTR-9 applies (exempt up to Rs 2 crore), and **Remind this client N days before** overrides the practice default for that one client. Each row links to their **Filings** and to the **Full record**, where Dolibarr handles deactivate and delete. |
| `/custom/ca/filings.php` | **Where the day is spent.** Everything due in the next 30 days, with the documents still outstanding beside each row. **Got them** marks those documents received in one click, which is what stops the client being chased. **Record as filed** takes the acknowledgement number. **Add task** puts a one-off job in the same list, with the same reminder. `?socid=N` narrows to one client — that is the answer to *"did you file my GSTR?"*. |
| `/custom/ca/rates.php` | Edit the late-fee rates when a statute changes. The `pattern` column is deliberately read-only — it is a regex the matching depends on. |
| `/custom/ca/adjustments.php` | Record a gazetted holiday, or a government extension. An extension moves that due date for **every** client at once. |
| `/custom/ca/privacy.php` | Retention policy, erasure requests, and the erasure log (DPDP §8(7)). |

The calendar also regenerates nightly (`./ca_job.sh calendar`), so a profile
edited in the UI is reflected without waiting for anyone to deploy. Changing a
client's GST scheme retires the deadlines the old scheme implied — except any
already filed, which stay as evidence.

**Server configuration stays on the host**, in `ca.env` and `secrets.env`: SMTP,
WhatsApp credentials and backup keys are not editable from a web form, on
purpose.
