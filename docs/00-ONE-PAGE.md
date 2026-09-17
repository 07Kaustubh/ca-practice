# Compliance, without the spreadsheet

**One client slipping once costs Rs 10,500.**
GSTR-3B thirty days late is Rs 1,500 (Rs 50/day). The TDS return is Rs 6,000
(Rs 200/day, no cap). AOC-4 is Rs 100/day, also uncapped. That is one client,
one month, three forms.

A practice of **48 clients carries 1261 statutory dates a year.** The busiest
single client owes **49 filings**. Nobody types those, which is why they end up
in a spreadsheet nobody trusts by August.

## What it does

| | |
|---|---|
| **Derives the calendar** | GST scheme, TAN, entity type and audit status produce every date. Add a client, the deadlines appear the same second. |
| **Chases the client, not you** | WhatsApp, escalating polite to urgent, one message per client per day. Email reminders run alongside - **840 currently scheduled.** |
| **Records that it was filed** | The acknowledgement number, the date, and who filed it. That is the answer to a notice two years later. |
| **Tracks your own money** | Retainers, 18% GST, TDS withheld under 194J, and the practice bank. **Rs 1,093,270 outstanding** on this register today. |
| **One screen** | Deadlines due, fees unpaid and the bank on login. One tab for everything a filing needs. |

## What it does not do

It does not **file** returns. The GST portal and Winman do that. This makes sure
you are never the reason a date was missed, and it does not keep your clients'
books - that is Tally's job.

## Honest notes

- The statutory rules carry the provision they rest on and are checked by 35
  automated assertions on every build. **They should still be red-lined by you**
  before you rely on them.
- **13 of 48 clients here cannot be chased** - no WhatsApp opt-in on record.
  The system reports that every morning rather than failing quietly.
- Meta requires opt-in before a business may message a client. That consent is
  yours to collect; the software refuses to send without it.

## Seeing it

- **90 seconds:** `demo/ca-practice-90s.mp4`
- **Full walkthrough:** `demo/ca-practice-demo.mp4`
- **Live:** one evening to install on your own machine or a VPS you control.
  Your data never leaves it.
