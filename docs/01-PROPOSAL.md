# Proposal — Practice Management System
**For:** [Client firm name], Chartered Accountants
**Prepared by:** [Your name]  ·  **Valid:** 30 days from issue

## The problem this solves
You already know GSTR-3B is due on the 20th — that is your profession. What
costs you a deadline is a client who has not sent the purchase register on the
17th. Existing compliance software tells you dates you already know. It does
not chase your clients.

## What you get
1. **Client register** — 12 fields per client including GSTIN, PAN, TAN, CIN,
   entity type, GST scheme and audit applicability.
2. **Statutory calendar, generated not typed.** Your clients' profiles produce
   the year's filings automatically — GST, TDS, advance tax, ITR, audit, ROC.
   47 clients produce roughly 1,200 filings a year.
3. **Document collection** — the actual job. Per client, per period, what you
   are waiting on, with status and chase history.
4. **WhatsApp chase aimed at your clients**, with escalating tone (T-10 polite,
   T-5 firm, T-2 urgent), capped at one message per client per day.
5. **A morning email** — this week's blocked filings on one screen, ranked by
   urgency, plus warnings when a client profile has gone stale.
6. **Your practice's books** — fee invoices from retainer and billing cycle at
   18% GST, bank and cash, expenses, and **TDS u/s 194J tracking** with Form 16A
   status so you stop overpaying your own tax.

## What it deliberately does not do
- **File returns.** The GST portal, Winman and Genius do that. This tracks and
  chases; it does not file.
- **Keep your clients' books.** Tally does that. This is one company's ledger —
  yours.

## Commercials
| Item | One-off | Monthly |
|---|---|---|
| Implementation, data migration, training | ₹[__] | — |
| VPS (2 GB, India region) | — | ~₹1,000 |
| WhatsApp Business (Meta, per message) | — | usage-based* |
| Domain + TLS | ~₹1,000/yr | — |
| Support & updates (optional) | — | ₹[__] |

\* Meta bills per conversation. Verify current utility-template pricing before
committing — it changed during 2025 and is not quoted here from memory.

**Software licence: none.** Dolibarr is GPL-3.0; this integration is MIT. You own
the deployment and can take it to any provider or engineer.

## Timeline
| Phase | Duration | Depends on |
|---|---|---|
| VPS + TLS + deployment | 1 day | your VPS credentials, domain |
| Client data migration | 1 day | your client list as CSV/Excel |
| Email deliverability (SPF/DKIM/DMARC) | 0.5 day | DNS access |
| WhatsApp onboarding | **1–2 weeks elapsed** | Meta business verification |
| Training + UAT | 1 day | 2 hours of your time |

WhatsApp is the long pole and is entirely Meta's queue, not build time.

## Assumptions and exclusions
- A **dedicated** phone number is bought for WhatsApp. A number already on the
  WhatsApp consumer or Business app cannot be onboarded without removing it there
  first — your client-facing number almost certainly is.
- Client documents are collected; document *storage* volume beyond the VPS disk
  is not included.
- Statutory rules are current as at the FY encoded in the calendar. Budget rule
  changes are a maintenance item.
