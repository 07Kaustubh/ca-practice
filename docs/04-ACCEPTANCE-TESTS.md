# Acceptance Tests (UAT)
Run with the client present. Each row is a binary observable — no "looks fine".
Sign at the bottom only when every row passes or is explicitly waived in writing.

## A. Deployment
| # | Test | Expected | Pass |
|---|---|---|---|
| A1 | `./gen-secrets.sh` on a clean host | `secrets.env` at 0600; password NOT echoed | ☐ |
| A2 | `./deploy.sh` | ends `SMOKE PASS` then `READY` | ☐ |
| A3 | `./healthcheck.sh` | `HEALTHY` | ☐ |
| A4 | `./regression.sh` | `REGRESSION SUITE PASS` (all gates) | ☐ |
| A5 | Browse `https://<domain>` | padlock valid, real CA-issued cert | ☐ |
| A6 | `curl -I https://<domain>` | `content-security-policy` and `strict-transport-security` present | ☐ |
| A7 | `curl http://<ip>:8080` | connection refused | ☐ |

## B. Clients
| # | Test | Expected | Pass |
|---|---|---|---|
| B1 | Import the client CSV | `FAILED=0`; count matches their list | ☐ |
| B2 | Import a row with PAN `NOTAPAN99` | rejected: `malformed PAN` | ☐ |
| B3 | Import GSTIN that does not embed its PAN | rejected with that reason | ☐ |
| B4 | **Compliance → Client profiles**, add a client with GSTIN, PAN and a GST scheme | saves, and the message states how many statutory deadlines were generated **on that save** | ☐ |
| B4a | Save a client with PAN `NOTAPAN99` | refused with `malformed PAN`; **no** client row is created | ☐ |
| B4b | Save a GSTIN that does not embed the PAN | refused, naming both values | ☐ |
| B4c | Leave **Aggregate turnover** blank | no GSTR-9 is generated, and the morning email lists it as a data-quality gap rather than guessing | ☐ |
| B4d | Set **Remind this client** to 21, re-run `./ca_job.sh calendar` | that client's reminders sit 21 days before each deadline; every other client stays on the practice default | ☐ |
| B5 | Re-run the import | `already-present=<n>`, no duplicates | ☐ |

## C. Compliance calendar
| # | Test | Expected | Pass |
|---|---|---|---|
| C1 | Pick a **GST-monthly** client | GSTR-1 on the 11th and GSTR-3B on the 20th, every month | ☐ |
| C2 | Pick a **QRMP** client | quarterly GSTR-1/3B only | ☐ |
| C3 | Pick a client **with a TAN** | TDS payment 7th monthly + 4 quarterly returns | ☐ |
| C4 | Pick an **audit** client | ITR dated 31 Oct, not 31 Jul; 3CD on 30 Sep | ☐ |
| C5 | Pick a **Pvt Ltd** | AOC-4, MGT-7, DIR-3 KYC present | ☐ |
| C6 | Re-run the generator | `deadlines 0 created` — no duplicates | ☐ |

## D. Documents and chase — the core value
| # | Test | Expected | Pass |
|---|---|---|---|
| D1 | Open document requests | rows are real filings only, never "Invoice X validated" | ☐ |
| D2 | A GSTR-3B request | asks for purchase register, bank statement, ITC reconciliation | ☐ |
| D3 | Client with opt-in OFF | chase reports `SKIP ... no WhatsApp opt-in` | ☐ |
| D4 | Tick opt-in, re-run chase | that client is now chased | ☐ |
| D5 | Run the chase twice in one day | second run does not re-message | ☐ |
| D6 | A client due in 2 days | tone is `urgent` | ☐ |
| D7 | On **Compliance → Ready to file**, press **Got them** on a row | its outstanding documents show `all in`, and the next chase run no longer asks that client for them | ☐ |

## D2. The daily screen
| # | Test | Expected | Pass |
|---|---|---|---|
| D8 | Open **Compliance → Ready to file** | everything due in the next 30 days, soonest first — **not** only the rows whose documents are already in | ☐ |
| D9 | Paste an ITR acknowledgement against a GSTR row | refused, naming the shape a GST ARN actually takes | ☐ |
| D10 | Record a filing dated tomorrow | refused: a return cannot have been filed in the future | ☐ |
| D11 | **Add task** "collect Form 16", pick a client, set a date | appears in the same list, and an email reminder is scheduled for it like any statutory date | ☐ |
| D12 | Press **Done** on that task | it leaves the list; no acknowledgement number is demanded | ☐ |
| D13 | Press **Remove** on a task, then try **N/A** on a real GSTR row | the task is deleted; the GSTR row is **kept** and marked not-applicable with your reason | ☐ |
| D14 | Try to remove a row already recorded as filed | refused — it stays on the record | ☐ |
| D15 | Click a client's name in the list | their whole open position and full filing history, with every acknowledgement number | ☐ |

## E. Reminders
| # | Test | Expected | Pass |
|---|---|---|---|
| E1 | Create a deadline in the UI, tick reminder | unit shows **Days**, not Minutes | ☐ |
| E2 | Seed one due now, run cron | email arrives in the CA's real inbox (not spam) | ☐ |
| E3 | Reminder for a user with no email | `status=-1` + readable `lasterror`; others unaffected | ☐ |
| E4 | Run `./sweeper.sh` on that failure | revived; after 3 tries an `[ACTION REQUIRED]` digest arrives | ☐ |

## F. Money
| # | Test | Expected | Pass |
|---|---|---|---|
| F1 | Raise fees | one invoice per client, retainer × cycle, 18% GST | ☐ |
| F2 | A client flagged `tds_applicable` | invoice carries a 194J figure | ☐ |
| F3 | A client NOT flagged | no 194J figure | ☐ |
| F4 | Re-run | `already billed` — no double invoicing | ☐ |

## G. Security and recovery
| # | Test | Expected | Pass |
|---|---|---|---|
| G1 | `ls -l secrets.env ca.env` | both `0600` | ☐ |
| G2 | `ssh -o PubkeyAuthentication=no <host>` | password auth refused | ☐ |
| G3 | Log in as `article1` | no Setup menu; cannot delete clients | ☐ |
| G4 | Modify a client as `article1` | audit event records that user | ☐ |
| G5 | `./backup.sh` | `backup.tar.gz.age`; no plaintext `db.sql` left | ☐ |
| G6 | Delete a client, run `./restore.sh` | client returns | ☐ |
| G7 | Confirm the backup exists offsite | present at the remote target | ☐ |

**Accepted by:** ______________________  **Date:** __________
**Waived items (with reasons):** ______________________________
