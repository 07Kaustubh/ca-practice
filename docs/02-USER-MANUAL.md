# User Manual — for the CA

## Your morning, in 30 seconds
You get one email each weekday at 08:00. It has three parts:

```
THIS WEEK — waiting on the client
!! Nair Traders Pvt Ltd    Advance tax 45%   2d  2 doc(s)  (not yet chased)
!  Sharma Logistics        GSTR-3B Aug       5d  3 doc(s)  (chased 1x)

READY TO FILE (documents all in): 4

DATA QUALITY
   Rao Industries & Co     no WhatsApp opt-in (cannot be chased)
```
`!!` = 2 days or less. `!` = 5 days or less.
**READY TO FILE** is your work queue: those clients have sent everything.
**DATA QUALITY** is the part that protects you — see the warning below.

## Adding a client
**Clients → New Client.** The heading says "New Third Party"; that is Dolibarr's
own wording.
1. Client Name and Email
2. **Tick the `Client` checkbox** — miss it and the record will not appear in
   your client list
3. **Click "More..."** to reach GSTIN, PAN, TAN, entity type, **GST scheme**,
   tax audit, ROC applicable, fee and billing cycle
4. **WhatsApp Opt-in** — tick only if the client has agreed. Meta requires it and
   the system will refuse to message them without it.

**The GST scheme field is the important one.** It decides whether that client
generates 24 filings a year, 8, or none.

## When a client's situation changes
Turnover crosses the audit limit, or they move from QRMP to monthly — **update
the profile the same day**, then re-run the calendar. If you do not, the system
will confidently show you the wrong deadlines, and it will look authoritative.
That is the one way this tool can hurt you.

## Marking documents received
Open the client, find the outstanding request, set status to `received`. Anything
still `pending` keeps appearing in your morning email and keeps the chase running.

## The reminder trap
When you create a deadline by hand, the reminder unit dropdown ships defaulting
to **Minutes**. This deployment forces it to **Days**, but if you ever see
`Minutes` there, change it — otherwise you are reminded 7 minutes before a filing.

## What the client sees
A WhatsApp template naming their firm, the filing, days remaining, and the
documents outstanding. Escalating in tone as the date approaches, never more than
once a day.
