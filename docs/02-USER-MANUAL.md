# User Manual — for the CA

## Your morning, in 30 seconds
You get one email each weekday at 08:00. It has three parts:

```
NEXT 14 DAYS — waiting on the client
!! Nair Traders Pvt Ltd    Advance tax 45% FY2026-27   2d  2 docs  (not yet chased)
!  Sharma Logistics        GSTR-3B Aug 2026            5d  3 docs  (chased 1x)

FILED in the last 7 days: 6   |   READY TO FILE now: 7

DATA QUALITY — a stale profile makes the calendar confidently wrong
   Rao Industries & Co     no WhatsApp opt-in (cannot be chased)

UNPAID FEES: 47 invoices, Rs 10,93,270
```
`!!` = 2 days or less. `!` = 5 days or less.
**DATA QUALITY** is the part that protects you — see the warning below. A client
listed as `NO CALENDAR` there has no deadlines at all, which is the one failure
that would otherwise be silent.

## Adding a client
**Compliance → Client profiles.** One form, one page. (Dolibarr's own
"New Third Party" form still exists and still works, but it scatters these fields
behind a *More...* link and does not build the calendar.)

1. Client name, email, phone
2. GSTIN, PAN, TAN, CIN — checked as you save. A GSTIN that does not embed the
   PAN is refused, because one of the two is then a typo
3. **GST scheme**, entity type, tax audit, ROC — this is what the calendar is
   derived from
4. **Aggregate turnover** — decides whether GSTR-9 applies at all. It is exempt
   up to Rs 2 crore. Leave it blank and the calendar will not guess; the morning
   email will tell you it could not decide
5. **WhatsApp opt-in** — tick only if the client has agreed. Meta requires it and
   the system refuses to message them without it
6. **Remind this client N days before** — leave blank to use the practice
   default. Set it when one client wants a fortnight and another wants two days

**The calendar is generated the moment you press Save.** You do not wait for
overnight, and you do not ask anyone to run anything.

**The GST scheme field is the important one.** It decides whether that client
generates 24 filings a year, 8, or none.

## When a client's situation changes
Turnover crosses the audit limit, or they move from QRMP to monthly — **update
the profile the same day**, then re-run the calendar. If you do not, the system
will confidently show you the wrong deadlines, and it will look authoritative.
That is the one way this tool can hurt you.

## Your day: Compliance → Ready to file
Everything due in the next 30 days, soonest first. Each row shows the documents
still outstanding for it.

**When the documents arrive — press `Got them`.** One click, no typing. That is
what stops the client being chased: the WhatsApp chase runs off outstanding
documents, so until you say they have arrived, a client who has already sent
everything keeps being asked for it.

**When you have filed on the portal — paste the acknowledgement and press
`Record as filed`.** The number is checked against the shape that filing family
actually issues, so a GST ARN cannot be entered against an ITR. A date in the
future is refused.

**You do not have to tick the documents first.** The list shows what is due, not
what the software thinks is ready; you are the one who knows whether you have it.

## One-off tasks
Not everything is a statutory return. *Collect Form 16 from the client. Reply to
the 143(1) notice. Renew the DSC.*

Type it into **One-off task** at the top of the same list, pick the client (or
leave it as "no particular client"), set a date, press **Add task**. It appears in
the same list and **reminds you the same way a statutory deadline does**. Press
**Done** when it is finished, or **Remove** to delete it.

## When a return does not apply
Statutory rows cannot be deleted. Put a reason in the box and press **N/A** —
it is marked not-applicable and kept on the record with your reason. Deleting an
inconvenient GSTR-3B is exactly what this system exists to prevent.

## "Did you file my GSTR?"
Click the client's name anywhere in that list. You get their open position and
their whole filing history, with every acknowledgement number and the date.

## The reminder trap
When you create a deadline by hand, the reminder unit dropdown ships defaulting
to **Minutes**. This deployment forces it to **Days**, but if you ever see
`Minutes` there, change it — otherwise you are reminded 7 minutes before a filing.

## What the client sees
A WhatsApp template naming their firm, the filing, days remaining, and the
documents outstanding. Escalating in tone as the date approaches, never more than
once a day.
