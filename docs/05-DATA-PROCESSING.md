# Data Processing & Compliance Note
Not legal advice. Written so your position is defensible and the gaps are stated.

## What personal data this holds
| Data | Whose | Personal data under DPDP? |
|---|---|---|
| PAN, TAN, phone, email | individual proprietors, contacts | **Yes** — natural persons |
| GSTIN, CIN | companies and LLPs | No — DPDP covers natural persons |
| Fees, invoices, TDS | your practice | Your commercial data |
| Document status, chase history | client relationship | Yes, where the client is an individual |

## Roles
You are the **Data Fiduciary**. Processors: your VPS provider, **Meta** (WhatsApp
message content — client name, filing, days remaining, document list), and your
SMTP relay. Under §8(2) you remain responsible for them. Name Meta and the relay
in your engagement letter.

## Cross-border transfer
DPDP §16 uses a **blacklist** model — transfer is permitted to any country the
Central Government has not restricted. There is no general localisation mandate
for a CA practice. Encrypted offsite backup abroad is lawful. India-region
hosting is still recommended for latency, INR billing and client comfort.

## How the build meets §8(4) "reasonable security safeguards"
| Safeguard | Implementation |
|---|---|
| Encryption at rest for backups | `age`, private key held off the server |
| Transport | TLS via Caddy; HSTS; CSP; Meta calls refuse non-HTTPS |
| Access control | per-person logins; `Practice Staff` role without Setup |
| Attribution | Dolibarr audit events enabled (158 triggers) |
| Secrets | `secrets.env`/`ca.env` at 0600; no passwords on the command line |
| Network | no published ports except Caddy; DOCKER-USER fail-closed rule |
| Host | key-only SSH, no root password, fail2ban, unattended upgrades |

## Breach notification (§8(5)–(6))
Because logins are per person and the audit trail is on, you can determine who
accessed which client record and when — which is what makes accurate
notification possible. **Keep it that way.** Reverting to a shared `admin` login
or disabling audit events removes your ability to comply.

## Consent (§5, §6) — WhatsApp
`whatsapp_optin` is enforced in code: no opt-in, no message. **You must still**
(a) tell the client what you will send and how often, (b) record when and how
they consented, (c) honour withdrawal by unticking the box.
*A consent line for your engagement letter:*
> I consent to [Firm] sending me WhatsApp messages at [number] regarding document
> requests and statutory deadlines for my filings. I may withdraw this at any
> time in writing.

## Retention and erasure (§8(7)) — **NOT IMPLEMENTED**
There is no automated retention or deletion path. Client data and backups
accumulate indefinitely. **This is the one open compliance gap.** Until it is
built, operate a documented manual policy: on disengagement, delete the client
record and purge them from backups older than your retention period.

## ICAI
Clause (1), Part I, Second Schedule of the CA Act 1949 — disclosure of client
information without consent is misconduct. Per-person logins and the audit trail
are what let you *demonstrate* reasonable care; peer review will ask about access
control and backups.


## Retention and erasure (DPDP §8(7))

Erasure is **held, not immediate**. A CA is separately required to retain
records — Income-tax Act s.44AA/Rule 6F (6 years), CGST s.36 (72 months),
ICAI SQC 1 (7 years), Companies Act s.128(5) (8 years) — so a request to erase
is recorded and becomes actionable only once the longest of those has run. The
binding period and its statutory basis are shown and editable on
`custom/ca/privacy.php`.

When an erasure falls due and is carried out:

| Erased | Retained |
|---|---|
| name, email, phone | the filing record: what was filed, for which period |
| GSTIN, PAN, TAN, CIN | the acknowledgement number and filed date |
| WhatsApp number, opt-in | the agenda history |

The retained side is not an oversight. It is the practice's evidence of having
filed on time, and the statute requiring it is the same one that justified
holding the erasure.

Each erasure writes an audit row containing a one-way hash of the client name —
never the name — so the practice can evidence compliance without re-storing the
identifier it just erased.

These periods are defaults. Confirm them with your own counsel.
