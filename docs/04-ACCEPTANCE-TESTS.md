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
| B4 | Create a client in the UI, tick `Client`, fill GSTIN under "More..." | appears in the client list with GSTIN on the card | ☐ |
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
| D7 | Mark all documents `received` | client disappears from the morning email; counts under READY TO FILE | ☐ |

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
