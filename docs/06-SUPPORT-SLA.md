# Support & Service Levels
**Provider:** [Your name] · **Client:** [Firm] · **Term:** 12 months, renewable

## Severity and response
| Sev | Meaning | Response | Target fix |
|---|---|---|---|
| S1 | Reminders/chase not sending; site down; data loss | 4 business hours | same business day |
| S2 | A feature wrong (calendar dates, TDS figures) | 1 business day | 3 business days |
| S3 | Cosmetic, questions, small requests | 3 business days | next release |

Business hours: Mon–Sat 10:00–19:00 IST, excluding public holidays.
**Filing-season surge (Sep 15–30, Jan 1–31, Jul 15–31):** S1 response 2 hours.

## Included
Break/fix on delivered functionality · security patching and image-digest bumps ·
**annual statutory rule update** (new FY dates, rate changes) · restore
assistance · quarterly `regression.sh` run with the report sent to you.

## Not included
New features · other software (Tally, Winman, the GST portal) · Meta or VPS
account issues · data entry · training beyond handover · recovery of data lost
to a client-side action after backups have rotated.

## Your responsibilities
Keep client profiles current — **a stale GST scheme makes the calendar wrong and
that is not a defect** · keep the `age` private key safe (without it backups are
unrecoverable, by design) · keep the Meta account and VPS in good standing ·
apply OS updates or authorise auto-updates.

## Escalation
1. Email [address] with the output of `./healthcheck.sh`
2. No response inside SLA → phone [number]
3. Unresolved after 5 business days → written escalation, remedy or fee credit

## Exit
On termination you keep everything: the repository is MIT, Dolibarr is GPL-3.0,
the VPS and data are yours. Provider will supply a final encrypted backup and a
30-day handover window to another engineer. **No lock-in and no licence to lapse.**
