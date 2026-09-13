# CA Practice Management

Client register, **auto-generated statutory compliance calendar**, email +
WhatsApp reminders, and practice-fee invoicing for a Chartered Accountant in
India. Built on [Dolibarr](https://www.dolibarr.org/) (GPL-3.0), self-hosted with
Docker and Caddy.

## Why

Indian statutory deadlines are fixed by law and **derivable from each client's
profile**. One GST-monthly client with a TAN owes ~44 filings a year; 47 clients
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

`./regression.sh` wipes to empty volumes and runs 17 gates — deploy, CSV import
with PAN/GSTIN validation, calendar generation, invoicing with 194J assertions,
per-user attribution, audit trail, backup→destroy→**restore**, WhatsApp opt-in
enforcement, every cron job under `env -i`, and the production profile through
Caddy with TLS.

Nothing here is claimed without a gate that fails when it stops being true.

## Scope

**Does:** client register · compliance calendar · reminders · his practice's
invoicing, bank, expenses and books.

**Deliberately does not:** file returns (that is the GST portal / Winman), or
keep *clients'* ledgers (Dolibarr is one company's books — that is Tally's job).

## Not done

Real Meta WhatsApp delivery, an SMTP relay with SPF/DKIM/DMARC, and the VPS need
your own accounts. DPDP §8(7) retention/erasure is not implemented. See
HANDOVER.md, which lists these plainly rather than burying them.

## Licence
MIT — see [LICENSE](LICENSE). Dolibarr itself is GPL-3.0 and is not vendored here.
