# DMARC Processor

Self-hosted, multi-tenant DMARC analyzer for MSPs. Customers' DMARC aggregate reports are received by a small Go SMTP server, parsed by a Laravel app, and presented in Filament dashboards with a guided setup wizard, alerts and printable reports.

See [`docs/SPEC.md`](docs/SPEC.md) for the full design.

## Repository layout

| Path | What |
|---|---|
| `smtp-receiver/` | Go, receive-only SMTP server. Accepts mail only for registered `rua-<token>@<reports domain>` addresses, spools to disk, forwards to the app with HMAC-signed HTTP. |
| `web/` | Laravel 13 + Filament 5 app: ingest API, report parser, rollups, `/admin` (MSP) and `/app` (tenant) panels. |
| `docker/` | Caddy config. |
| `docker-compose.yml` | Production stack: caddy, web, worker (Horizon), scheduler, smtp-receiver, MariaDB, Redis. |

## Status

| Milestone | State |
|---|---|
| M1 Scaffold (compose, panels, CI) | ✅ |
| M2 Go smtp-receiver | ✅ |
| M3 Parser, ingest, dedupe, rollups, retention | ✅ |
| M4 Tenancy, users/roles, invitations, 2FA, impersonation, audit | 🟡 tenancy, roles, 2FA done; invitations/impersonation/audit to do |
| M5 Setup wizard (DNS scanner, record builder, verify) | ✅ |
| M6 Dashboards, sender identification | ⏳ |
| M7 Alerts, channels, digests, policy guide | ⏳ |
| M8 Printable reports, SSO, install command, docs | ⏳ |

## Deploying (single VPS)

1. **DNS** (replace with your domains):
   - `A dmarc.example-msp.com` → server IP (web UI)
   - `A mx.reports.example-msp.com` → server IP
   - `MX reports.example-msp.com` → `mx.reports.example-msp.com` (priority 10)
   - `TXT *._report._dmarc.reports.example-msp.com` → `v=DMARC1` (authorises reports for any customer domain, RFC 7489 §7.1)
2. **Firewall**: open TCP 25, 80, 443. Many VPS providers block port 25 by default; ask them to unblock inbound.
3. **Configure**:
   ```bash
   cp .env.example .env   # then fill in hostnames, DB_PASSWORD, INGEST_HMAC_SECRET (openssl rand -hex 32)
   ```
   ```bash
   docker compose run --rm web php artisan key:generate --show   # paste into APP_KEY
   ```
4. **Start**:
   ```bash
   docker compose up -d --build
   ```
   ```bash
   docker compose exec web php artisan dmarc:make-super-admin you@example-msp.com
   ```
5. Sign in at `https://dmarc.example-msp.com/admin`, set up 2FA, create a tenant, then add domains in `/app`.

## Development

To try the setup wizard without real DNS, set `APP_ENV=local` and point `DMARC_FAKE_DNS_ZONE` at a JSON zone file (format in `web/app/Dns/ArrayResolver.php`). This is ignored outside the local environment.

```bash
cd smtp-receiver && go test -race ./...
```
```bash
cd web && composer install && php artisan test
```
