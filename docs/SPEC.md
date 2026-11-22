# DMARC Processor — v1 Specification

Status: **Draft for review**
Audience: MSP operators (us) and developers

---

## 1. Goals

A self-hosted, multi-tenant DMARC analyzer for an MSP. It should:

- Receive DMARC aggregate (RUA) reports for customer domains
- Walk customers (or MSP staff) through publishing correct DMARC, SPF and DKIM records
- Monitor results, identify senders, alert on problems, and guide the move to `p=reject`

### Non-goals (v1)
- Forensic/failure (RUF) reports
- Public self-service sign-up and billing
- Per-tenant white-labeling (MSP branding only)
- MTA-STS / TLS-RPT / BIMI checks (possible v2)
- Server-generated PDF files (printable HTML reports instead, see §7)

### Scale target
- Up to about 50 tenants and 500 domains on a single VPS
- The design should not block growth to roughly 5k domains (rollup tables, indexes)

---

## 2. Architecture

```
                 Internet
        ┌───────────┴─────────────┐
   SMTP :25 (+STARTTLS)       HTTPS :443
        │                         │
┌───────▼────────┐        ┌───────▼───────┐
│ smtp-receiver  │        │    caddy      │  TLS (Let's Encrypt), also issues
│ (Go binary)    │        │ reverse proxy │  the cert used by smtp-receiver
└───────┬────────┘        └───────┬───────┘
        │ HMAC-signed HTTP        │
        │ (internal network)      │
┌───────▼─────────────────────────▼───────┐
│        app (Laravel + Filament)         │  PHP-FPM + nginx
└───────┬─────────────────────────┬───────┘
        │                         │
┌───────▼────────┐   ┌────────────▼──────────┐
│ worker         │   │ scheduler             │
│ (Horizon queue)│   │ (schedule:work)       │
└───────┬────────┘   └────────────┬──────────┘
        └──────────┬──────────────┘
          ┌────────▼───────┐   ┌───────┐
          │ MariaDB 11     │   │ Redis │
          └────────────────┘   └───────┘
```

Deployment: a single `docker-compose.yml` on a Linux VPS. The same app image is used for `app`, `worker` and `scheduler`.

### Stack
| Concern | Choice |
|---|---|
| Web/backend | PHP 8.3+, Laravel 13 |
| UI | Filament 5 (panels, tables, forms, wizard, charts, built-in TOTP 2FA) + Livewire |
| Queue | Redis + Laravel Horizon |
| DB | MariaDB 11 |
| Inbound mail | Custom Go binary (`emersion/go-smtp`) |
| TLS | Caddy (automatic Let's Encrypt) |
| Reports | Printable HTML pages (print stylesheet) |
| IP intel | IPinfo Lite / DB-IP ASN (offline, refreshed monthly) + rDNS + built-in known-sender list |
| Auth extras | Filament's built-in TOTP 2FA; Socialite for Microsoft Entra ID & Google SSO |

---

## 3. Inbound mail: `smtp-receiver` (Go)

A receive-only SMTP server. It does not relay mail, deliver it locally or send any mail out.

### Addressing
- Each domain gets an unguessable reporting address: `rua-<token>@reports.<msp-domain>`
  - `<token>`: 10 random lowercase base32 chars, unique, rotatable
- DNS published once by the MSP for the reports domain:
  - `MX reports.<msp-domain> → mx.reports.<msp-domain>`
  - `*._report._dmarc.reports.<msp-domain> TXT "v=DMARC1"` (wildcard external-destination authorisation, RFC 7489 §7.1)

### SMTP session rules
- `RCPT TO`: the token is validated against the app (internal endpoint, cached for 60s in memory)
  - Unknown or disabled tokens are rejected with `550 5.1.1`, so we never accept mail for nobody
- Message size limit: 10 MB (configurable)
- Maximum 5 recipients per message
- Rate limits per connecting IP (connections/min, messages/hour)
- STARTTLS offered, using the cert Caddy issues for `mx.reports.<msp-domain>` (file watched and reloaded on renewal)
- AUTH not offered

### Durability
1. After `DATA`, the message is written to a disk spool (`/spool/incoming/<ulid>.eml`) and fsynced before replying `250`
2. A background goroutine POSTs it to `app:/internal/ingest`
   - Headers carry the recipient token and the SMTP envelope
   - The request is signed with an HMAC-SHA256 shared secret
3. On 2xx the spool file is deleted. On failure it retries with exponential backoff (up to 72h), then moves the file to `/spool/dead/`

### Ops
- Structured JSON logs
- `/healthz` and Prometheus `/metrics` on an internal-only port
- Config via environment variables
- About 10 MB static binary in a `scratch`/`distroless` image

---

## 4. Report processing (Laravel)

1. `/internal/ingest` verifies the HMAC, stores the raw `.eml` (gzip-compressed) in `storage/raw/`, creates an `inbound_messages` row and dispatches `ParseInboundMessage`.
2. **Parsing**
   - Extract attachments: `.xml`, `.xml.gz`, `.zip` (also a bare gzip/zip body)
   - Safety: XXE disabled; limit on decompressed size (50 MB) and ratio (zip-bomb guard); max 1 XML file per zip
   - Validate against the RFC 7489 aggregate schema, tolerating common real-world deviations (e.g. Microsoft/Yahoo quirks)
   - Deduplicate on `(reporter_org, report_id, policy_domain)`
   - **Domain match:** the report's `policy_published/domain` must equal the token's domain, or be a subdomain of it. Otherwise the message is quarantined for admin review. This blocks a tenant's token from being used to inject data for another domain.
3. Records are inserted in bulk. Unseen source IPs are queued for enrichment (rDNS, ASN, known-sender match) (M6).

Message statuses: `processed`, `ignored` (no report attachment), `quarantined` (report for a domain the token doesn't cover), `failed` (unparseable; can be re-queued from the admin Ingest queue).
4. `daily_domain_stats` and `daily_source_stats` rollups are updated for the affected (domain, day) pairs.
5. Alert evaluation runs (see §7).

Messages that cannot be parsed: stored with status `failed` and an error message, visible to MSP admins, and re-processable.

---

## 5. Data model (main tables)

All tenant-owned tables carry `tenant_id` with a global scope; Filament's tenancy enforces it in the UI.

| Table | Key columns |
|---|---|
| `tenants` | name, slug, retention_days_raw (default 395), settings JSON |
| `users` | name, email, password, is_super_admin, totp_secret, sso_provider/id |
| `tenant_user` | tenant_id, user_id, role (`owner`, `admin`, `viewer`) |
| `domains` | tenant_id, name, rua_token, status (`pending`/`active`/`paused`), onboarding_step, current_policy, target_policy |
| `dns_checks` | domain_id, type (dmarc/spf/dkim), raw_record, parsed JSON, issues JSON, checked_at |
| `inbound_messages` | rua_token, envelope, raw_path, status, error |
| `reports` | domain_id, reporter_org, reporter_email, report_id, date_begin, date_end, policy_published JSON |
| `report_records` | report_id, source_ip, count, disposition, dkim_aligned, spf_aligned, header_from, envelope_from, dkim_results JSON, spf_results JSON, override_reasons JSON |
| `ip_intel` | ip, rdns, asn, as_org, country, known_sender_id, refreshed_at |
| `known_senders` | name (e.g. "Google Workspace", "Microsoft 365", "Mailchimp"), match rules (ASN / rDNS suffix / SPF include) |
| `domain_sources` | domain_id, source key (known_sender or ASN/rDNS group), classification (`legit`, `unknown`, `suspicious`), notes |
| `daily_domain_stats` | domain_id, day, total, dmarc_pass, spf_aligned, dkim_aligned, quarantined, rejected |
| `daily_source_stats` | domain_id, day, source key, same counters |
| `alert_rules` | tenant_id (null = MSP-wide default), type, thresholds JSON, enabled |
| `alert_events` | domain_id, rule_id, severity, payload, opened_at, resolved_at |
| `notification_channels` | tenant_id, type (`email`, `webhook`), config (encrypted), events subscribed |
| `audit_log` | actor, tenant_id, action, subject, changes |

Retention job (nightly, `dmarc:prune`):
- Deletes `reports`/`report_records` and raw `.eml` files older than the tenant's `retention_days_raw`
- Daily rollups are kept indefinitely
- No table partitioning in v1: at the target scale, chunked deletes are fast enough, and MariaDB partitioning can't be combined with the foreign keys we rely on. Revisit if `report_records` grows past ~50M rows.

Rollups:
- `daily_source_stats` is keyed by source IP rather than by sender, so sender identification can improve later without rebuilding rollups; grouping into senders happens at query time via `ip_intel`.
- Rollups are recomputed per (domain, day) from `report_records` rather than incremented, so retries and duplicates can't double-count.
- A report is attributed to the UTC day of its `date_range/begin`.

---

## 6. Guided setup wizard (per domain)

A Filament wizard that the user can leave and resume (progress stored in `domains.onboarding_step`).

1. **Add domain**
   - Enter the domain; it is validated and checked against the public suffix list
   - Rejected if already registered by another tenant (MSP admin can override)
2. **Current state scan**
   - Fetch existing `_dmarc`, SPF and common DKIM selectors (`google`, `selector1`, `selector2`, `k1`, `s1`, `default`, `mxvault`, …, plus user-supplied selectors)
   - Show a traffic-light summary with plain-English explanations
3. **SPF review**
   - Parse and flatten includes
   - Count DNS lookups (warn ≥8, error >10)
   - Flag `+all`/`?all`, duplicate SPF records, deprecated `ptr`, void lookups
4. **DKIM review**
   - List found selectors with key type and length (warn on RSA < 1024, suggest 2048)
   - Offer per-provider guidance links (M365, Google Workspace, common ESPs)
5. **DMARC record builder**
   - Generate `v=DMARC1; p=none; rua=mailto:rua-<token>@reports.<msp>; fo=1; adkim=r; aspf=r`
   - If a DMARC record already exists, *merge*: keep their policy and add our `rua` alongside existing addresses
   - Every tag gets a tooltip explanation
   - Copy-to-clipboard, plus a provider-specific "how to add a TXT record" panel (Cloudflare, GoDaddy, 123-reg, Route 53, M365 DNS, generic)
6. **Verify**
   - "Check now" button, plus automatic re-check every 15 minutes for 48 hours
   - Green ticks once the record is live and contains our `rua`
7. **Waiting for data**
   - Explains that reports arrive in 24–48h
   - Domain status becomes `active` when the first report lands, and the user is notified

### Policy progression guide (ongoing, on the domain page)
A "Next step" card driven by observed data:

| From → To | Suggested when (defaults, configurable) |
|---|---|
| `none` → `quarantine; pct=25` | ≥ 30 days of reports, ≥ 98% DMARC pass of volume from `legit` sources, no `unknown` source > 1% volume in last 14 days |
| `quarantine pct` 25 → 50 → 100 | 14 days at each step meeting the same criteria |
| `quarantine` → `reject` | 30 days at `quarantine; pct=100` meeting the criteria |

The card shows the exact new record to publish and the evidence behind the suggestion. Any regression (a new unknown sender, a drop in pass rate) blocks the next step.

---

## 7. Monitoring

### Dashboards
- **MSP overview**
  - All tenants/domains with health score
  - Current policy vs target
  - Last report received
  - Open alerts
- **Tenant overview:** domains, overall pass rate, alerts
- **Domain detail**
  - Volume and pass-rate time series (7/30/90/365 days)
  - SPF vs DKIM alignment breakdown
  - Disposition breakdown
  - Top sources table (known-sender name / ASN org, IP count, volume, pass %, classification)
  - Reporter breakdown (Google, Microsoft, Yahoo…)
- **Source drill-down:** IPs, rDNS, header_from/envelope_from pairs, DKIM selectors seen, failing reasons, and suggested fixes (e.g. "Add `include:sendgrid.net` to SPF", "Enable DKIM signing in Mailchimp")

### Sender identification
- Known-sender matching by ASN, rDNS suffix and SPF include domain; ships with a seed list of about 50 common providers
- Users classify each source as `legit` / `unknown` / `suspicious`; unclassified sources default to `unknown`

### Alerts (defaults; tenants can tune thresholds)
| Alert | Trigger |
|---|---|
| New unknown sender | A source not previously seen, sending above N msgs/day |
| Failure spike | DMARC pass rate drops by more than X points vs the 7-day baseline |
| Legit source failing | A `legit` source drops below 95% aligned |
| No reports | No reports received for an active domain in 72h |
| DNS changed | Daily DNS check finds the DMARC/SPF record changed, removed, or our `rua` missing |
| SPF lookup limit | SPF exceeds 10 lookups |
| Ingest failures | (MSP only) parse failures or receiver dead-letters |

Alerts deduplicate while open and auto-resolve when the condition clears.

### Notification channels
- **Email:** via the configured SMTP relay
- **Generic webhook:** JSON POST with HMAC signature, suitable for PSA tools
- **In-app:** Filament database notifications (bell icon)

### Digests
- Weekly (default) or monthly email per tenant: per-domain summary, top changes, open alerts, policy next steps
- MSP-wide digest to super-admins

### Printable reports
Per-domain and per-tenant reports for a chosen period:
- MSP-branded
- Summary, charts (SVG, so they print crisply) and source tables
- Recommendations and policy next steps

Rendered as a dedicated HTML page with a print stylesheet:
- Hides navigation, A4/Letter page breaks, light theme forced
- "Print" button; users can choose "Save as PDF" in their browser
- Shareable via a signed, expiring link so customers can open it without logging in (optional, per report)

No server-side PDF engine in v1; one could be added later by rendering the same page.

---

## 8. Users, auth & tenancy

- **Two Filament panels**
  - `/admin`: MSP super-admins only. All tenants, global settings, known-sender list, ingest queue, audit log
  - `/app`: tenant users, with a tenant switcher if a user belongs to several tenants
- **Roles**
  - `owner`/`admin`: manage domains, users, alerts, channels
  - `viewer`: read-only
- **Impersonation:** super-admins can impersonate tenant users. This is always audited and shown with a banner.
- **2FA:** TOTP, enforceable per tenant and mandatory for super-admins
- **SSO:** Microsoft Entra ID and Google via Socialite
  - Per tenant: allowed SSO domains with just-in-time user creation at the `viewer` role
  - Or invite-only
- **Invitations:** by email, with signed expiring links

---

## 9. Security

- Tenant isolation is enforced by global scopes and policies, with tests that try cross-tenant access on every resource
- Internal endpoints (`/internal/*`):
  - Reachable only on the Docker network (blocked at Caddy)
  - HMAC-signed with a timestamp (±5 min) to prevent replay
- XML parsing is hardened (no external entities, size/depth limits)
- Secrets are stored encrypted: webhook URLs, SSO client secrets, TOTP seeds
- Rate limiting on login and on the receiver
- Every config change is recorded in the audit log

---

## 10. Configuration (`.env` highlights)

```
APP_URL=https://dmarc.<msp-domain>
REPORTS_DOMAIN=reports.<msp-domain>
MX_HOSTNAME=mx.reports.<msp-domain>
INGEST_HMAC_SECRET=...
MAIL_*            # outbound SMTP relay for alerts/digests
IPINFO_TOKEN=...  # optional; DB-IP fallback without a token
MS_CLIENT_ID / MS_CLIENT_SECRET / GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET
```

A first-run CLI (`php artisan dmarc:install`):
- Creates the first super-admin
- Prints the DNS records the MSP must publish (MX, A, wildcard `_report._dmarc`)
- Verifies those records

---

## 11. Milestones

| # | Deliverable |
|---|---|
| M1 | Repo scaffold: docker-compose, Laravel + Filament panels, MariaDB/Redis, Caddy, CI (tests + lint) |
| M2 | Go `smtp-receiver` with spool, recipient validation, HMAC ingest, tests |
| M3 | Report parser, dedupe, domain-match guard, rollups, retention job (with real-world sample reports as fixtures) |
| M4 | Tenancy, users/roles, invitations, 2FA, impersonation, audit log |
| M5 | Setup wizard: DNS scanner (DMARC/SPF/DKIM), record builder, verification |
| M6 | Dashboards, sender identification (IP intel + known senders + classification) |
| M7 | Alerts, notification channels, digests, policy progression guide |
| M8 | Printable reports, SSO, install command, docs (install/upgrade/backup) |

---

## 12. Open decisions

1. **MSP domain names**: the actual `APP_URL` / `REPORTS_DOMAIN` (only needed at deploy time)
2. **IPinfo Lite vs DB-IP**: IPinfo Lite needs a free token but is more accurate; DB-IP needs none. Proposal: support both, with IPinfo preferred
3. **Default thresholds** for alerts and policy progression (§6, §7): review the proposed values
