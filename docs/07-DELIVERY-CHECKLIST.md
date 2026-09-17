# Delivery Checklist

## Before you meet the client
- [ ] `./regression.sh` → `REGRESSION SUITE PASS`, screenshot it
- [ ] Fill `[__]` placeholders in `01-PROPOSAL.md` and `06-SUPPORT-SLA.md`
- [ ] Verify current Meta per-message pricing — **do not quote from memory**
- [ ] Test email deliverability to a Gmail address from the real relay

## Needs the client
- [ ] VPS credentials, or agreement for you to buy one
- [ ] Domain + DNS access
- [ ] Client list as CSV/Excel (name, GSTIN, PAN, TAN, entity type, GST scheme, email, mobile)
- [ ] **A dedicated phone number for WhatsApp** — not one already on WhatsApp
- [ ] Meta Business verification documents (GST cert, incorporation)
- [ ] SMTP relay account (Zoho/Brevo/SES) on their domain
- [ ] **Decision: does the WhatsApp chase go to clients, or only to the CA?**
- [ ] Signed consent language in their engagement letter

## Deploy
- [ ] `sudo ./bootstrap-vps.sh`; confirm SSH key-only **before** logging out
- [ ] `./gen-secrets.sh`; store the admin password in their password manager
- [ ] Set `CA_AGE_RECIPIENT`, `CA_OFFSITE_TARGET`, real `CA_SMTP_HOST`
- [ ] `export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml`
- [ ] `docker compose up -d && ./deploy.sh`
- [ ] `install -m 0644 ca.logrotate /etc/logrotate.d/ca-practice`; install `ca.crontab`
- [ ] SPF, DKIM, DMARC records; send a test to Gmail and confirm **Primary**, not spam
- [ ] Run `docs/04-ACCEPTANCE-TESTS.md` **with the client watching**

## Handover
- [ ] Walk the morning email and the document-chase flow
- [ ] Show how to update a client profile and why staleness matters
- [ ] Show `healthcheck.sh` and where the logs are
- [ ] Hand over the `age` private key on physical media; confirm they can restore
- [ ] Countersign the acceptance sheet
- [ ] Diarise: quarterly restore drill, April calendar regeneration

## State plainly, in writing
- [ ] Confirm the retention periods on `custom/ca/privacy.php` against your own
      counsel (defaults: income-tax 6y, GST 6y, ICAI 7y, Companies Act 8y)
- [ ] Meta template approval is their queue, typically 1–2 weeks
- [ ] The calendar's accuracy depends on profiles they maintain
- [ ] `bootstrap-vps.sh` SSH and IPv6 paths want one run on their actual VM

- [ ] Send `docs/00-ONE-PAGE.md` + `demo/ca-practice-90s.mp4` first (85s, 2.9 MB - forwards on WhatsApp)
- [ ] Keep `demo/ca-practice-demo.mp4` (274s) for the second conversation, not the first
