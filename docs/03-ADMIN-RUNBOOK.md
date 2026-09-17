# Admin Runbook

## Daily / automatic
| When | Job | Log |
|---|---|---|
| every 5 min | Dolibarr reminder cron (container) | container stdout |
| 06:15 | refresh document requests | /var/log/ca-docs.log |
| 08:00 Mon–Sat | morning digest to the CA | /var/log/ca-digest.log |
| 09:30 Mon–Fri | WhatsApp client chase | /var/log/ca-chase.log |
| every 10 min | sweeper (revive dead reminders) | /var/log/ca-sweeper.log |
| every 10 min | wa_fanout (deadline WhatsApp) | /var/log/ca-wa.log |
| 02:17 | encrypted backup | /var/log/ca-backup.log |

## Health
```bash
cd /opt/ca && ./healthcheck.sh          # expect HEALTHY
```
**Read `llx_cronjob.lastoutput`, never the exit status.** A guard-blocked
Dolibarr job prints `OK result = 1` and sends nothing.

## Backups
Encrypted with `age`, written to `$CA_BACKUP_DIR` (0700), optional `rclone`
offsite, 30-day rotation. **Keep the age private key off this server.**
```bash
./backup.sh
./restore.sh ~/ca-practice-backups/<timestamp>
```
Run a restore drill quarterly. `./regression.sh` does one automatically.

## Monthly
- `SELECT * FROM ca_docrequest WHERE status='pending' AND due < CURDATE();`
- `SELECT rowid,lasterror FROM llx_actioncomm_reminder WHERE status=-1;`
  (terminal — `sweeper.sh` retries 3× then emails an `[ACTION REQUIRED]` digest)
- Review the DATA QUALITY block in the morning email until it is empty.

## Each April
```bash
docker compose exec -T dolibarr php /tmp/cc.php <FY_START_YEAR>
```
Idempotent. Review client profiles first — the calendar is only as right as they are.

## Upgrades
Images are pinned by sha256 digest. To move Dolibarr versions: bump the digest,
`./regression.sh`, and only then deploy. Never `:latest` in production.

## Known upstream behaviour
- Reminders older than **one month are permanently deleted** by Dolibarr's
  notification poller (`core/ajax/check_notifications.php`). `sweeper.sh` cannot
  recover them — the rows are gone, not failed.
- Dolibarr 24.0.0 logs a benign `mysqli object is already closed` after cron.
