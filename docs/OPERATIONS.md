# Operating DMARC Processor

## Services

| Service | What it does | Logs |
|---|---|---|
| `caddy` | HTTPS for the web UI; obtains the MX host's certificate | `docker compose logs caddy` |
| `web` | Laravel app (PHP-FPM + nginx); runs migrations on start | `docker compose logs web` |
| `worker` | Horizon queue worker: report parsing, DNS checks, alerts, webhooks | `docker compose logs worker` |
| `scheduler` | Runs the schedule below | `docker compose logs scheduler` |
| `smtp-receiver` | Receives report emails on port 25 | `docker compose logs smtp-receiver` |
| `mariadb`, `redis` | Data and queues | |

### Scheduled jobs

| When (UTC) | Command | Purpose |
|---|---|---|
| Every 15 min | `dmarc:check-dns --pending` | Re-check new domains until their DMARC record is live (48 h) |
| Hourly | `dmarc:evaluate-alerts` | New senders, missing reports, ingest failures, alert expiry |
| Hourly | `dmarc:update-ip-db --if-missing` | Fetch the IP database if it isn't installed |
| Daily 03:17 | `dmarc:prune` | Delete raw report data past each customer's retention period |
| Daily 04:43 | `dmarc:check-dns` | Full DNS scan of every domain (feeds DNS-change alerts) |
| Daily 07:13 | `dmarc:send-digests` | Weekly (Mondays) / monthly (1st) summary emails |
| Weekly Sun 05:21 | `dmarc:update-ip-db` | Refresh the IP database |

## Upgrading

The simplest way:

```bash
git pull && sudo ./install.sh
```

It keeps your settings, rebuilds, restarts and re-runs the installation check. By hand:

```bash
git pull
```
```bash
docker compose build
```
```bash
docker compose up -d
```

Migrations run automatically when `web` starts. Afterwards, check the installation:

```bash
docker compose exec web php artisan dmarc:install --check
```

Mail that arrives while `web` is restarting waits in the receiver's spool and is delivered once the app is back. Nothing is lost.

Upgrade notes:
- **Encrypted sessions** (added with the password reset release): sessions are now encrypted, so everyone is signed out once after this upgrade.

## Backups

What to back up:

- the database
- the `app_storage` volume (raw report emails)
- the `smtp_spool` volume (only matters if mail is waiting to be processed)
- your `.env` file, which holds `APP_KEY`: without it, notification channel secrets and 2FA seeds can't be decrypted

Nightly database dump (add to cron):

```bash
docker compose exec -T mariadb sh -c 'mariadb-dump --single-transaction -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' | gzip > backup-$(date +%F).sql.gz
```

Raw report emails:

```bash
docker run --rm -v dmarc_app_storage:/data -v "$PWD":/backup alpine tar czf /backup/app-storage-$(date +%F).tgz -C /data .
```

Restore the database:

```bash
gunzip -c backup-YYYY-MM-DD.sql.gz | docker compose exec -T mariadb sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

Keep copies off the server.

## Monitoring

- **MSP dashboard** (`/admin`): domains not reporting for 72 h+, ingest problems, open alerts
- **Admin → Ingest queue:** every received message with its status. Failed messages can be re-processed. *Quarantined* means a report arrived at one domain's address but was about a different domain; that's either misconfiguration or abuse.
- **Horizon** (`/horizon`, super-admins): queue throughput and failed jobs
- **Receiver metrics:** Prometheus format, on the internal network only:
  ```bash
  docker compose exec web curl -s http://smtp-receiver:9090/metrics
  ```
  Watch `smtp_receiver_spool_pending` (should stay near 0) and `smtp_receiver_dead_letters` (should be 0).
- **Platform alerts:** add an MSP notification channel, then "Report processing failures" alerts reach you by email or webhook.

## Rotating secrets

- **`INGEST_HMAC_SECRET`:** change it in `.env`, then run `docker compose up -d`. Both the receiver and the app restart with the new value. Messages already spooled are retried and delivered.
- **`APP_KEY`:** don't rotate it casually. It encrypts notification channel config (webhook URLs and secrets) and two-factor seeds. If you must rotate it, set the old key in `APP_PREVIOUS_KEYS` so existing data can still be read.
- **Shared report links:** a customer's owners and admins can revoke all of them at once (Report → Revoke all share links).

## Troubleshooting

**No reports arriving for a domain**
1. Domain page → Setup wizard → Verify: is the record live, and does it include our address?
2. Is the wildcard `*._report._dmarc.<reports domain>` TXT record published? Run:
   ```bash
   docker compose exec web php artisan dmarc:install --check
   ```
3. Can the internet reach port 25? Test from another network:
   ```bash
   nc -vz mx.reports.example-msp.com 25
   ```
4. Look for `accepted`, `delivered` or `rejected` lines in the receiver's log:
   ```bash
   docker compose logs smtp-receiver
   ```
   Recipients that don't match a domain are rejected with `550`, which is expected for junk mail.

**Messages stuck in the receiver**
`smtp_receiver_spool_pending` keeps growing when the receiver can't reach the app. Check that `web` is healthy and that `INGEST_HMAC_SECRET` matches. If it doesn't, the receiver logs `401` responses. After 72 hours, undeliverable messages move to the spool's `dead/` directory inside the `smtp_spool` volume.

**Senders show as networks or bare IPs rather than names**
Check the IP database is installed (`dmarc:install --check`). Recognition improves as reports arrive. To add or adjust providers, go to Admin → Known senders; saving re-identifies everything.

**A customer's user lost their authenticator**
Admin → Users → (user) → Reset two-factor.

**Sign in as a customer user to see what they see**
Admin → Users → Sign in as. A banner shows while it's active; everything is recorded in the audit log under both names.
