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

**The full host crontab** is shipped as `ca.crontab` — install it rather than
retyping it. **Ten scheduled lines.** The six that run PHP inside the container go
through `ca_job.sh`, which sources `ca.env`/`secrets.env` first: `docker compose`
cannot even parse the compose file without `DB_PASSWORD`, so calling it inline
from cron failed silently every night (Gotcha 12).

    50 4 * * *   ./ca_job.sh calendar    statutory dates; idempotent
    5  6 * * *   ./ca_job.sh filings     filing ledger + late-fee exposure
    15 6 * * *   ./ca_job.sh docs        document requests coming into horizon
    0  8 * * 1-6 ./ca_job.sh digest      the morning email
    20 5 * * 1   ./ca_job.sh retention   DPDP sweep - DRY RUN, reports only
    40 6 1 * *   ./ca_job.sh fees        the month's retainer invoices

`fees` is idempotent per period — a second run in the same month raises nothing,
so it cannot double-bill. Review them under Billing as usual. It used to sit
behind a deploy-time env flag, which meant routine monthly billing required an
SSH session.

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

## Gotcha 12 — three cron jobs failed every night, silently
`ca.crontab` called `docker compose` inline for the digest, the document refresh
and the filing ledger. cron supplies no environment, and **`docker compose`
cannot even parse `docker-compose.yml` without `DB_PASSWORD`** — so all three
died instantly with `required variable DB_PASSWORD is missing`, into logs nobody
reads. The digest also never forwarded `CA_EMAIL`/`CA_SMTP_HOST` into the
container, so even a working copy would have posted to `mailpit:1025` with no
recipient. They now go through `ca_job.sh`, which sources the env first.

The reason this survived: the cron-env gate discovered jobs with
`grep -oE '\./[a-z_]+\.(sh|py)'`, which matches `./sweeper.sh` but matches
**nothing** in an inline `docker compose` line. Three of seven jobs were never
tested. The gate now asserts discovered-jobs >= scheduled-lines, so a job it
cannot see is a failure rather than a silence.

## Gotcha 13 — a gate that could not fail, and how to spot one
The first step asserted "0 app volumes survived" by counting volumes matching
`bakeoff_(db_data|...)`. The compose project had been renamed to `ca-practice`
long before. The pattern matched nothing, so the count was always 0 and the gate
always passed — while three volumes sat there untouched.

The fix is the general lesson: **never trust a negative result from a detector
you have not seen produce a positive one.** That step now plants a volume, proves
the pattern sees it, and only then believes the zero it reports afterwards. The
same disease appeared twice more in one session — a junk-ARN check that "passed"
because the binary under test did not exist, and a mock-capture count that would
have been satisfied by its own health-check probe.

## Gotcha 14 — the regression suite was calling Meta for real
`wa_fanout.py` resolves `WA_API_BASE` from `ca.env`, which is
`https://graph.facebook.com/v21.0`. Two gates started `mockmeta.py` on port 9099
and then **never pointed anything at it**, so every suite run made live outbound
calls to Meta with a bogus token. Meta answered `401`, which still leaves retry
attempts, so the gate passed — and a later step burned the remaining attempts and
failed on an unrelated assertion with an SSL error.

`WA_API_BASE`/`WA_ALLOW_INSECURE` are now exported once at the top of the suite
and the mock's captured-message count is asserted, so a run that escapes to the
internet is visible. A test harness must never depend on a third party answering.

## Gotcha 15 — permission rows are (perms, subperms), not one string
`users_setup.php` asked for `perms='myactions_read'`. In `llx_rights_def` that is
two columns: `perms='myactions'`, `subperms='read'`. The lookup matched zero rows
and the `while` loop simply did not iterate — **three of five permissions were
dropped in silence**, leaving `article1`/`article2` with 2 rights each. They could
log in and do nothing, which sends every real task back to the admin account —
precisely the shared-admin posture the per-user gate claims to prevent.

That gate passed because it counted *accounts*, not *capability*. It now asserts
the least-privileged non-admin holds a workable permission set and that every
article can record a filing without the admin login. Any script that grants
permissions now fails loudly when a permission resolves to no row.

## Gotcha 16 — `hasRight()` returns 0 for any module Dolibarr has not activated
```php
// htdocs/user/class/user.class.php
if (!isModEnabled($module)) { return 0; }
```
`$conf->modules` is populated only from **activated module descriptors**. A
custom page that invents its own permission name (`hasRight('ca','write')`) will
therefore refuse everyone, for ever, with no error to explain it. `custom/ca/`
is a plain directory, not a registered module.

The filings screen gates on the **agenda** permissions instead, which are real,
enabled, and semantically honest — these filings *are* agenda events, and the
screen closes the agenda event when it records one.

## Gotcha 17 — an invalid CSRF token does not stop the page
Dolibarr 24 defaults `MAIN_SECURITY_CSRF_WITH_TOKEN` to 3 (set in
`conf.class.php`, not in the install SQL), so POST token checking is automatic
once `main.inc.php` is included. But the two failure modes differ:

- **token missing** → `http_response_code(403)` and `die`
- **token wrong** → `$_POST` is unset, a warning is queued, and **the page keeps
  executing** (`$_POST['id']` is even restored deliberately)

So an action handler must be driven by `$action`, never by the bare presence of a
POST field — otherwise it runs on a request that failed CSRF. Verified by POSTing
a forged token at the live screen: HTTP 200, filing unchanged.

## Gotcha 18 — a client added in the UI was invisible to the whole product
`compliance_calendar.php` ran **only** from `deploy.sh`, over SSH. A client
created through Dolibarr's own "New Third Party" form — with a complete
GST-Monthly profile — got **zero** deadlines, zero document requests, zero
reminders and no chase. Silently: no error, nothing in a log. The practice would
not find out until a due date was missed.

Verified by creating one and running every cron job that existed: all four counts
came back 0. The calendar is now `./ca_job.sh calendar` nightly, it runs first in
the chain (calendar → filings → documents → digest), and the morning email carries
a `NO CALENDAR - not one deadline generated` line so the failure can never be
silent again.

## Gotcha 19 — the ActionComm property is `datef`, the COLUMN is `datep2`
Re-dating an existing deadline with
`UPDATE llx_actioncomm SET datep=..., datef=...` fails with
`Unknown column 'datef'` — and because the whole statement fails, `datep` does not
move either. `datef` is the property name on the ActionComm *object*; the table
column is `datep2`.

Worse than the bug: the code counted the re-dating as successful without checking
the return value, so the job cheerfully reported "163 existing deadlines re-dated"
on every run while nothing changed. **Count what the database accepted, not what
you asked it to do.** The fix tests `$ok` and writes the DB error to stderr.

## Holidays and government extensions
Two small tables, both editable at `custom/ca/adjustments.php`:

- `ca_holiday` — a due date landing on a Sunday or a listed holiday moves to the
  next working day (section 10 of the General Clauses Act 1897). Saturdays are
  treated as working days; add specific ones if a practice disagrees.
- `ca_extension` — `filing_like` matches the START of a filing label, so one row
  moves that deadline for every client at once. Longest matching prefix wins.

Both are applied by the nightly calendar job to deadlines that **already exist**,
not just new ones — otherwise an extension announced this morning would never
reach the returns it was announced for.

## Statutory rates are data
`ca_rate` holds the late-fee table, editable at `custom/ca/rates.php`. The array
in `ca_filing_lib.php` is both the seed and the fallback when no database handle
is passed, so behaviour is identical if the table is missing. `pattern` is a
regex the matching depends on and is deliberately read-only in the UI: a broken
one silently zeroes every exposure figure.

## Gotcha 20 — I built a feature that CAUSED the harm the product prevents
An earlier version of `ca_calendar_lib.php` shifted any due date landing on a
Sunday or a listed holiday to the next working day, citing section 10 of the
General Clauses Act 1897. **That was wrong, and it was dangerous.**

Section 10 only operates where an act must be done *"in any Court or Office"* and
that office is **closed**. The GST and income-tax portals are open 24x7, so the
triggering condition is never met and the due date does not move. GST has its own
machinery for relief — s.37(4), s.39(6), s.168A — and CBIC issues a **notification**
when it intends to grant it.

The direction of the error is what makes this serious:

> Telling a CA a date **earlier** than the statute is harmless.
> Telling a **later** one means he files late and pays the fee.

Shifting Sunday to Monday did exactly that, on **163 deadlines**. The gate made it
worse: it asserted *"0 deadlines fall on a Sunday"*, so it enforced the bug.

Now: holidays are an **advisory flag**, never a date change. `ca_extension` is the
only thing that moves a date, which matches how relief is actually granted. The
gate was inverted — Sundays must now be PRESENT (177 of them) and unmoved.

## Gotcha 21 — the Act itself was replaced, and every citation went stale
**The Income-tax Act 1961 was superseded by the Income-tax Act 2025 with effect
from 1 April 2026.** FY 2026-27 — the year this calendar generates — is governed by
the **new Act**:

| Concept | 1961 Act | ITA 2025 |
|---|---|---|
| Return of income | s.139 | **s.263** |
| Tax audit | s.44AB | **s.63** |
| Advance tax | s.207/208/211 | **s.403/404/408** |
| Interest | s.234B/234C | **s.424/425** |
| Late fee | s.234F | **s.428** |
| Presumptive | s.44AD/44ADA | **s.58(2) Table Sl.1/Sl.3** |

The *dates* largely survived the transition; the *citations* did not. Anything in
this repo naming a 1961 section for FY2026-27 is pointing at a repealed provision.
`statute_test.php` carries the provision beside every assertion for exactly this
reason — so the next person can see what a rule claims to rest on.

Separately, **Finance Act 2026 substituted Explanation 2** and moved non-audit
**business and profession** assessees from 31 July to **31 August**. Salaried
assessees stay on 31 July.

## Gotcha 22 — a mock that cannot fail hides the code it exists to test
`mockmeta.py` was eleven lines and always returned HTTP 200. Because of that,
`wa_fanout.py`'s error handling had **never once executed**. It caught
`except Exception` and stored `str(ex)` — which for an `HTTPError` is only
`"HTTP Error 400: Bad Request"`. Meta's structured `error.code` lives in the
response **body**, which was never read.

So every failure looked identical and was retried three times. An expired token
(190) would have failed all ~880 reminders, three times each, before anyone was
told. A number not on WhatsApp (131026) was retried forever instead of telling the
CA to pick up the phone.

Meta's own instruction: *"Build your app's error handling around error codes
instead of subcodes or HTTP response status codes."* HTTP status is not even
documented for the Cloud API messages endpoint.

The sender now classifies into three buckets — **retryable** (the closed set Meta
actually says to retry), **permanent** (straight to the cap so the CA hears today),
and **account-fatal** (halt the run; continuing just multiplies the damage).
The mock now returns Meta's real error envelope and can inject any of them.

**A mock that cannot fail is the same defect as a gate that cannot fail.**

## Gotcha 23 — the product was a task GENERATOR, not a to-do list
Every row in the filings list came from the statutory rules. A practice has
plenty of work no statute implies — collect a Form 16, answer a 143(1) notice,
renew a DSC — and none of it matched the label regex, so none of it could appear
on the one screen the CA lives in. He kept a second list somewhere else, which is
the failure this product exists to end.

Measured as CRUD, the core noun scored **2 of 4**: no Create, no Delete. Both now
exist on the same screen. A one-off task becomes a real agenda event, so it
inherits the same email reminder as a statutory date — a task that does not remind
you is just a note.

Deletion is deliberately asymmetric. A one-off task is his, so **Remove** deletes
it. A statutory return is not: **N/A** marks it not-applicable *with a reason* and
keeps it. Same button, honest difference.

## Gotcha 24 — the screen offered an action only for rows that could never reach it
`filings.php` listed `WHERE f.status = 'ready'`. A filing became `ready` only when
every document was `received`. And the ONLY writer of `received` was
`ca_record_filing()` — a side-effect of recording the filing.

So: it could not be recorded until the documents were in, and the documents could
not be marked in until it was recorded. The demo worked solely because
`seed_demo.sh` wrote `received` with direct SQL. On a real install, once the
seeded rows were filed the screen would have been **permanently empty**.

Worse than a dead screen: `chase_clients.py` chases on outstanding documents, so a
client who had already sent everything kept receiving WhatsApp messages asking for
it. That is not a missing feature, it damages the relationship the product is
supposed to protect.

The list now shows everything DUE. Documents are a column, never a lock, and one
click says they arrived.

## Gotcha 25 — a test double that depended on the network it replaces
`mockmeta.py` bound its port and then never answered. No error, process alive.

`http.server.HTTPServer.server_bind()` calls `socket.getfqdn()` to fill in a
`server_name` this mock never reads — and that call does a reverse DNS lookup. On
a machine whose reverse DNS is slow or unreachable it blocks indefinitely, and it
happens **after bind() but before listen()**, so the port looks taken while
nothing responds. The suite's readiness probe reported "mock did not start" and
five WhatsApp gates went red, for a hostname nobody wanted.

Overridden to skip it. **A test double must not depend on the network it exists to
replace.**

## Gotcha 26 — two gates that failed for reasons that were not the product
Both of these read as product defects and were not. Worth knowing, because this
repo is public and someone else will run the suite on a different machine.

- **The prod gate hardcoded port 8443.** Its claim is "the prod profile deploys
  and serves HTTPS through Caddy", not that one number. On a machine where
  something else already held 8443 it failed with `address already in use`. It now
  picks a free port at run time.
- **The chase gate depended on the wall clock.** `chase_clients.py` only chases
  documents due within 10 days — correctly, nobody wants nagging about a return
  due in a fortnight. Run the suite on a date when the nearest deadline is 16 days
  out and the chase legitimately does nothing, so the gate's `grep` found no SKIP
  line and reported red. It now seeds a document into the window before asserting.

## Gotcha 27 — an optional new column invalidated every existing CSV
`import_clients.php` checks the header against a `$required` list. Adding
`remind_days` to it meant every CSV a practice was already using failed with
`CSV missing columns`. Both `remind_days` and `turnover_annual` are now
`$optional`: `array_combine()` simply does not create the key, and both are
`isset()`-guarded. **Adding a field must never invalidate a file someone already
has.**

Related: `turnover_annual` was in the importer, the extrafields and the statutory
rules, but was never collected by the client form — so a client added through the
UI always had turnover 0 and was silently never given a GSTR-9.

## Known remaining, stated plainly
- **DPDP §8(7) retention and erasure — now implemented.** `ca_retention_policy`
  holds the statutory floors (income-tax 6y, GST 6y, ICAI 7y, Companies Act 8y);
  the binding period is the longest of them. An erasure request is recorded and
  **held** until that period expires — a CA cannot erase inside the statutory
  window by clicking a button, because the same law that lets the client ask is
  outranked by the law requiring the practice to keep records. When it falls due,
  personal identifiers are cleared and the **filing record is deliberately kept**:
  what was filed, for which period, under which acknowledgement. That is the
  practice's own evidence of having filed, and destroying it would breach the
  retention duty that justified the hold.
  Every erasure writes a `ca_erasure_log` row storing a **sha256 of the name, not
  the name** — the log evidences that an erasure happened without re-storing the
  identifier it erased. `./ca_job.sh retention` runs weekly and is a **dry run by
  default**; erasure itself is a deliberate act on `custom/ca/privacy.php`.
  The periods are defaults, not legal advice. Confirm them with your own counsel.
- Real Meta delivery, a real SMTP relay with SPF/DKIM/DMARC, and the VPS itself
  still need the CA's own accounts.
- `bootstrap-vps.sh` runs to completion on `debian:12 --privileged`; the SSH and
  IPv6 paths need one run on a real VM (`nmap -6` to confirm).
