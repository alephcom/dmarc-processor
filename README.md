# DMARC Processor

Self-hosted, multi-tenant DMARC analyzer for MSPs. Customers' DMARC aggregate reports are received by a small Go SMTP server, parsed by a Laravel app, and presented in Filament dashboards with a guided setup wizard, alerts and printable reports.

See [`docs/SPEC.md`](docs/SPEC.md) for the full design.

> **Built with Claude.** This project was designed and written with [Claude Code](https://claude.com/claude-code), Anthropic's AI coding agent, working from requirements set out by the project owner. The code, tests, documentation and installer were produced in that collaboration and reviewed through automated tests and CI. As with any code, review it before running it in production.

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
| M4 Tenancy, users/roles, invitations, 2FA, impersonation, audit | ✅ |
| M5 Setup wizard (DNS scanner, record builder, verify) | ✅ |
| M6 Dashboards, sender identification | ✅ |
| M7 Alerts, channels, digests, policy guide | ✅ |
| M8 Printable reports, SSO, install command, docs | ✅ |

## Deploying

See **[docs/INSTALL.md](docs/INSTALL.md)** for the step-by-step guide (DNS, configuration, first run, single sign-on) and **[docs/OPERATIONS.md](docs/OPERATIONS.md)** for upgrades, backups, monitoring and troubleshooting.

In short, after creating the DNS records:

```bash
sudo ./install.sh
```

## Development

To explore the dashboards locally, run `php artisan dmarc:demo` (only works with `APP_ENV=local`) and sign in at `/app` as `demo@example.com` / `demo-password-123`.

To try the setup wizard without real DNS, set `APP_ENV=local` and point `DMARC_FAKE_DNS_ZONE` at a JSON zone file (format in `web/app/Dns/ArrayResolver.php`). This is ignored outside the local environment.

```bash
cd smtp-receiver && go test -race ./...
```
```bash
cd web && composer install && php artisan test
```
