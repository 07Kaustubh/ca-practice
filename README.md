# CA Practice Management

Client register, **auto-generated statutory compliance calendar**, email +
WhatsApp reminders, and practice-fee invoicing for a Chartered Accountant in
India. Built on [Dolibarr](https://www.dolibarr.org/) (GPL-3.0), self-hosted with
Docker and Caddy.

## Why

Indian statutory deadlines are fixed by law and **derivable from each client's
profile**. One GST-monthly client with a TAN owes ~50 filings a year; 47 clients
is over a thousand deadlines. Nobody types those, which is why generic CRMs get
abandoned. This derives them:

| Client profile | Generates |
|---|---|
| GST Monthly | GSTR-1 by 11th, GSTR-3B by 20th, monthly |
| GST QRMP | GSTR-1 by 13th, GSTR-3B by 22nd, quarterly |
| Composition | CMP-08 quarterly, GSTR-4 annual |
| Has TAN | TDS payment by 7th; 24Q/26Q by 31 Jul/Oct/Jan/May |
| Tax audit | 3CA/3CD by 30 Sep, ITR by 31 Oct |
| Otherwise | ITR by 31 Jul, advance tax ×4 |
| Company / LLP | AOC-4, MGT-7, DIR-3 KYC / Form 8, Form 11 |

Also tracks **TDS deducted u/s 194J** on his own fees with Form 16A status —
untracked, a CA overpays his own tax.

## Quick start

```bash
./gen-secrets.sh          # once per host, writes secrets.env (0600)
cp clients.sample.csv clients.csv
CA_IMPORT_CLIENTS=1 ./deploy.sh
```
→ http://127.0.0.1:8080 · mailbox at http://127.0.0.1:8025

Production, backups, WhatsApp and the operational gotchas: **[USAGE.md](USAGE.md)**.
Design decisions, security posture and every trap found the hard way:
**[HANDOVER.md](HANDOVER.md)**.

## Verification

`./regression.sh` wipes to empty volumes and runs 35 gates — deploy, CSV import
with PAN/GSTIN validation, calendar generation, invoicing with 194J assertions,
the filing lifecycle end to end (documents received → ready → filed with a valid
acknowledgement, junk and back-dated acknowledgements refused), statutory dates
asserted against the year the statute means, app/db clock agreement, per-user
attribution, audit trail, backup→destroy→**restore**, WhatsApp opt-in
enforcement, every cron job under `env -i`, and the production profile through
Caddy with TLS.

Nothing here is claimed without a gate that fails when it stops being true — and
a gate that cannot fail is treated as a defect, not as a pass.

## Scope

**Does:** client register · compliance calendar · reminders · document collection
· a filing ledger that records *that* a return went out, when, and under which
acknowledgement number · his practice's invoicing, bank, expenses and books.

**Run from the browser, no shell:** add or edit a client and the statutory
calendar is generated on save (`/custom/ca/client.php`) · see everything due,
tick off documents as they arrive, and record a filing with its acknowledgement
(`filings.php`) · add a **one-off task** that is not a statutory return at all
and have it remind you like one · edit the late-fee rates when a statute changes
(`rates.php`) · record a gazetted holiday or a government extension, which moves
that due date for every client at once (`adjustments.php`).
The calendar also regenerates nightly, so nothing waits on a deploy.

Server configuration — SMTP, WhatsApp credentials, backup keys — stays in
`ca.env` / `secrets.env` on the host, deliberately: secrets do not belong in a
web form.

**Deliberately does not:** *file* returns — that is the GST portal / Winman. It
records the filing and its ARN/SRN/acknowledgement afterwards, which is what lets
it answer "what is still outstanding?" and evidence timely filing if challenged.
It also does not keep *clients'* ledgers (Dolibarr is one company's books — that
is Tally's job).

## Not done

Real Meta WhatsApp delivery, an SMTP relay with SPF/DKIM/DMARC, and the VPS need
your own accounts. See
HANDOVER.md, which lists these plainly rather than burying them.

## Licence
MIT — see [LICENSE](LICENSE). Dolibarr itself is GPL-3.0 and is not vendored here.
