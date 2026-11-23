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
- Instant self-service sign-up (accounts are requested and reviewed, see §8) and billing
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
| `alerts` | tenant_id (null = MSP-level), domain_id, type, severity, dedupe_key, title, body, data, opened_at, last_seen_at, resolved_at, resolution (auto/manual/expired) |
| `notification_channels` | tenant_id (null = MSP channel, receives every alert), type (`email`, `webhook`), config (encrypted: addresses or url + secret), alert_types, min_severity |
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
   - **Add several** (Domains page, owners/admins): paste up to 100 names (lines, commas or spaces). Each valid new name is created and its DNS scanned in the background; names already monitored anywhere are reported as such without saying by whom. The wizard is then finished per domain.
2. **Current state scan** (runs after the page renders so DNS never blocks the request; rate-limited per domain)
   - Fetch existing `_dmarc`, SPF and common DKIM selectors (`google`, `selector1`, `selector2`, `k1`, `s1`, `default`, `mxvault`, …, plus user-supplied selectors)
   - Show a traffic-light summary with plain-English explanations
3. **SPF review**
   - Parse and flatten includes
   - Count DNS lookups (warn ≥8, error >10)
   - Flag `+all`/`?all`, duplicate SPF records, deprecated `ptr`, void lookups
4. **DKIM review**
   - List found selectors with key type and length (error below 1024-bit RSA, warn below 2048)
   - Selectors probed: about 30 common provider selectors, plus user-supplied ones, plus any seen in the domain's reports in the last 30 days
   - Offer per-provider guidance links (M365, Google Workspace, common ESPs)
5. **DMARC record builder**
   - Generate `v=DMARC1; p=none; rua=mailto:rua-<token>@reports.<msp>` (no `fo=1`: it only affects forensic reports, which we don't use; `adkim`/`aspf` default to relaxed)
   - If a DMARC record already exists, *merge*: keep their policy and add our `rua` alongside existing addresses
   - Every tag gets a tooltip explanation
   - Copy-to-clipboard, plus a provider-specific "how to add a TXT record" panel. The DNS host is detected from NS records (Cloudflare, GoDaddy, Route 53, Namecheap, Microsoft 365, Azure, Squarespace, 123 Reg, IONOS; generic instructions otherwise)
6. **Verify**
   - "Check now" button, a 30-second re-check while the page is open, and a background re-check every 15 minutes for 48 hours (`dmarc:check-dns --pending`)
   - Green ticks once the record is live and contains our `rua`
   - Also checks the MSP's `*._report._dmarc` authorisation record and flags it as an MSP-side problem if missing
   - All domains get a full DNS scan daily (`dmarc:check-dns`); results are stored in `dns_checks` for change alerts (M7)
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

Implementation notes (`PolicyAdvisor`):
- States: `setup` (record not live), `blocked` (sender health fails), `wait` (healthy, not enough time), `ready` (record shown), `done` (at target).
- "Time at step" uses `domains.policy_changed_at`, set by the DNS scanner whenever the published p/pct changes.
- "Unknown" means *not classified*: marking a spoofer `suspicious` removes it from the 1% share, which is the intended workflow.
- If no source is marked `legit` yet, the guide is blocked with a prompt to classify senders.
- A target of `quarantine` stops the ladder at `quarantine` 100%.

---

## 7. Monitoring

### Dashboards
Charts show messages passing vs failing DMARC as stacked bars (daily; weekly for the 12-month view). Colours are a validated colourblind-safe blue/orange pair, not green/red. Periods: 7, 30, 90 days and 12 months (domain page, kept in the URL).
- **MSP overview**
  - All tenants/domains with health: DMARC record status, 30-day pass rate (bar), last report (red after 72 h of silence)
  - Current policy, with the target underneath ("Target p=reject" or "At target")
  - Last report received
  - Open alerts (count per domain)
- **Tenant overview:** domains, overall pass rate, alerts
  - Until a domain has received its first report, a getting-started panel replaces the empty widgets: "Add your first domain" (button for owners/admins), or "Waiting for the first reports" with a link to finish setup for a domain whose DMARC record isn't live yet
- **Stat cards** (tenant and MSP): message volume and DMARC pass rate show a 30-day daily sparkline and the change against the prior 30 days (volume in %, pass rate in percentage points)
- **Domain detail**
  - Volume and pass-rate time series (7/30/90/365 days)
  - SPF vs DKIM alignment breakdown
  - Disposition breakdown
  - Top sources table (known-sender name / ASN org, IP count, volume, pass %, classification)
  - Reporter breakdown (Google, Microsoft, Yahoo…)
  - **Raw reports** (Domain → Raw reports): every report received, newest first, filterable by reporter; per report the individual records (source IP, count, disposition, DKIM/SPF results and alignment, header from; largest 500 shown) and a download of the original XML, re-extracted from the stored raw message (only the XML, never the email). Same access as the domain page.
- **CSV export** on the domain tables (dashboard and MSP overview), senders and raw reports: the rows the table shows (search, filters, sort) with its visible columns, up to 20,000 rows. Cells starting with `= + - @` are prefixed with `'` so report-supplied text can't run as a spreadsheet formula.
- **Source drill-down:** IPs, rDNS, header_from/envelope_from pairs, DKIM selectors seen, failing reasons, and suggested fixes (e.g. "Add `include:sendgrid.net` to SPF", "Enable DKIM signing in Mailchimp")

### Sender identification
- Each source IP is enriched once (refreshed every 30 days) with reverse DNS, ASN/organisation (offline DB-IP or IPinfo Lite database, `dmarc:update-ip-db`) and a known-sender match. `ip_intel` is shared by all tenants, so only signals we verify ourselves count. Evidence, strongest first:
  1. Forward-confirmed reverse DNS ending in a sender's suffix. Unconfirmed rDNS is ignored: anyone can set their own PTR to `mail.google.com`.
  2. **Published SPF ranges:** the IP is inside the ranges the sender's *own* SPF include domain authorises (e.g. `sendgrid.net`, `_spf.google.com`), fetched from live DNS (`SpfRangeIndex`: follows include/redirect, `a`/`mx`, ignores non-pass qualifiers, macros and absurdly broad ranges; cached 24 h, failures cached 5 min and never treated as a match). When several senders cover an IP, the most specific range wins, then the most direct (a company whose SPF includes Google's record loses to Google); an exact tie means no match.
  3. A network (ASN) dedicated to the sender. Shared clouds are never listed.
  Report contents (`auth_results` SPF/DKIM results) are never used: reports are unauthenticated, and anyone who knows a published `rua` address could otherwise relabel an IP for every tenant (security review finding). Catalogue `spf_includes` must be the provider's own include domain, not a company's main domain.
- Sources are grouped as identified service → network (ASN) → single IP. Grouping keys live on `daily_source_stats.source_key` and are updated when identification improves; classifications carry over.
- The catalogue ships with about 30 providers and is editable by MSP admins (Admin → Known senders); saving re-identifies all IPs (`dmarc:reidentify-sources`).
- Users classify each source as `legit` / `unknown` / `suspicious`; unclassified sources default to `unknown`. "Senders to review" = failing DMARC and not marked legitimate.
- Each source has a details panel: its servers, DKIM signatures and SPF results seen, and plain-English advice (e.g. "SendGrid signs with its own domain; set up custom DKIM").
- Attribution: DB-IP's free database is CC BY 4.0; the senders table credits it when it is in use.

### Alerts (defaults; tenants can tune thresholds)
| Alert | Trigger | Severity | Clears when |
|---|---|---|---|
| New unknown sender | An unclassified source first seen in the last 2 days, peaking at ≥ 10 msgs/day. Not raised in a domain's first 7 days of reports (everything is new then). Checked hourly, after sender identification has run. | warning (info if it passes DMARC) | the source is classified, or after 7 days |
| Failure spike | Latest day's pass rate ≥ 10 points below the previous 7 days (both ≥ 50 msgs) | warning (critical at 2× threshold) | the next day is back within range |
| Legit source failing | A `legit` source below 95% pass over 2 days (≥ 20 msgs) | critical | it recovers |
| No reports | No report for an active domain in 72h | warning | a report arrives |
| DMARC record changed | Record removed / our `rua` dropped (only after it was verified), or policy weakened | critical / warning | record verified again / after 7 days |
| SPF record broken | SPF has error-level issues (lookups > 10, missing include, loop, `+all`...), once setup is done | warning | SPF is fixed |
| Ingest failures | (MSP only) ≥ 1 failed/quarantined inbound message in the last hour | warning | an hour with none |

- One open alert per dedupe key: repeated detections refresh it instead of notifying again; a failed DNS lookup is never treated as a change.
- Managers can mark an alert resolved; it reopens if detected again.
- Settings: MSP defaults (Admin → Default alert settings) and per-tenant overrides (Settings → Alerts & summaries).

### Notification channels
- **In-app:** Filament database notification to the tenant's owners/admins (super-admins for MSP-level alerts)
- **Email:** via the configured SMTP relay, on open
- **Generic webhook:** JSON POST on open and resolve, suitable for PSA tools
  - Headers: `X-Dmarc-Event`, `X-Dmarc-Timestamp`, `X-Dmarc-Signature: sha256=HMAC-SHA256(secret, timestamp + "." + body)`
  - SSRF protection: HTTPS only, no credentials in the URL, every resolved address must be public (private, loopback, link-local, CGNAT, metadata, mapped forms all rejected), the connection is pinned to the checked IP, redirects are not followed. Checked when saved and again at send time.
  - Retries with backoff (30s → 30 min, 5 attempts); the last error is shown on the channel
- Channels filter by minimum severity and alert type, and have a "Send test" action. MSP channels (`tenant_id` NULL) receive alerts from every tenant.

### Digests
- Weekly (Mondays, default) or monthly (1st) email per tenant to owners/admins; tenants choose in Settings → Alerts & summaries
- Per domain: volume, pass rate and change vs the previous period, *your senders that are failing* (legit sources, fixable), unrecognised/suspicious senders, new senders, policy guide status; then open alerts
- MSP-wide weekly digest to super-admins: per-customer table, silent domains, platform alerts
- `dmarc:send-digests` runs daily and guards against double sends; `--force --tenant=<slug>` sends one now
- Per-user opt-out is not in v1

### Printable reports
Per-domain (`/reports/domains/{id}`) and per-tenant (`/reports/tenants/{id}`) reports for a chosen period:
- MSP-branded
- Summary, charts (SVG, so they print crisply) and source tables
- Recommendations and policy next steps

Rendered as a dedicated HTML page with a print stylesheet:
- Hides navigation, A4/Letter page breaks, light theme forced
- "Print" button; users can choose "Save as PDF" in their browser
- Shareable via a signed link (14 days) so people without an account can open it. Links carry the tenant's share version; "Revoke all share links" invalidates every one. Owners/admins only.
- Chart rendered server-side as inline SVG (same colours as the dashboards)
- Branding from `DMARC_BRAND_NAME`, `DMARC_BRAND_LOGO_URL`, `DMARC_BRAND_CONTACT`
- Favicon: `web/public/favicon.svg` (source), with `favicon.ico` (16/32/48) and `apple-touch-icon.png` (180) generated from it; linked from both panels, reports and invitation pages via `partials/favicon.blade.php` and also used as the panel brand logo next to the name (`filament/brand-logo.blade.php`)

No server-side PDF engine in v1; one could be added later by rendering the same page.

---

## 8. Users, auth & tenancy

- **Two Filament panels**
  - `/admin`: MSP super-admins only. All tenants, global settings, known-sender list, ingest queue, audit log
  - `/app`: tenant users, with a tenant switcher if a user belongs to several tenants
- **Roles**
  - `owner`: everything, including managing other owners
  - `admin`: domains, senders, alerts, channels, settings, and the team (admins and viewers only)
  - `viewer`: read-only; no Team, Activity, Notifications or settings pages
  - Rules (enforced in `TeamService`): nobody changes their own role or removes themselves; only owners grant or touch the owner role; a tenant always keeps at least one owner
- **Team page** (Settings → Team): members with role and 2FA status, change role, remove, invite; pending invitations with revoke. MSP admins get the same table on each tenant (Members tab).
- **Invitations**
  - Emailed link with a random 48-character token; only its SHA-256 hash is stored. Single use, expires after 7 days; re-inviting the same address replaces the old link.
  - The signed-in account's email must match the invited address.
  - New users choose a name and password (min 12 characters); their email counts as verified. Existing users sign in first and return to the invitation; a guest cannot set a password for an existing account.
- **Signing in as a customer (impersonation)**
  - Super-admins only, from Admin → Users; never as another super-admin or as yourself.
  - A banner on every page shows who is signed in as whom, with "Return to MSP admin".
  - Start and end are audited; everything done meanwhile is recorded with both the customer user (actor) and the super-admin (impersonator).
  - The session ID is regenerated on each switch, and the session password hash is updated so the session-integrity middleware doesn't log the admin out.
- **Users (Admin → Users):** edit name/email, toggle super-admin (not on yourself), reset a lost authenticator, delete (not yourself). People join via invitations; super-admins via `dmarc:make-super-admin`.
- **Passwords:** at least 12 characters everywhere (invitations, profile, reset). "Forgot password?" on both sign-in pages sends a reset link only to users who can use that panel (customers get no `/admin` link); resets are audited.
- **2FA:** TOTP (Filament's built-in), enforceable per tenant and mandatory for super-admins on *every* surface that honours super-admin rights: `/admin`, the tenant panel (super-admins without TOTP are sent to set it up), printable reports and Horizon. Report pages also apply the tenant's "require 2FA" setting (SSO sessions exempt).
- **SSO:** Microsoft Entra ID and Google via Socialite, tenant panel only (super-admins are refused and must use password + TOTP)
  - Configured per tenant by MSP admins: email domains, allowed Entra directory (tenant) IDs, role for new users (viewer/admin)
  - Accepted only when the IdP proves the organisation: Google requires `email_verified` and a Workspace `hd` equal to the email domain; Microsoft requires the `tid` from the signature-validated ID token to be allow-listed (the unverified Entra `mail` attribute is never used; the UPN is)
  - Identity is matched by provider subject ID first, then linked to an existing account by email; otherwise a user is created just-in-time. An account linked to one identity is never re-linked to another.
  - SSO sessions satisfy a tenant's "require 2FA" setting (the IdP does the second factor)
  - Buttons appear only for providers with credentials configured

### Public site and account requests
- Off unless `PUBLIC_SITE_ENABLED=true`; otherwise `/` redirects to `/app` and the request pages return 404.
- `/`: public page (no sign-in) explaining that email security basics should be easy and free, what the service does, how to request an account, the free hosting limit (`PUBLIC_SITE_FREE_MONTHLY_EMAILS`, default 10,000 emails a month) and a link to the GitHub repository (`PUBLIC_SITE_REPO_URL`) for anyone sending more who should self-host. It states that the free service doesn't include tech support.
- `/request-account`: name, email, organisation, domains, optional message, and a required confirmation that the domains send no more than the free limit. Promises review within `PUBLIC_SITE_REVIEW_HOURS` (default 48).
  - Abuse controls: Turnstile (below), 5 submissions per 10 minutes per IP, a hidden honeypot field (silently ignored), and one pending request per email address (resubmitting doesn't add another). Nothing is emailed to the requester at submission, so the form can't be used to send mail to arbitrary addresses.
  - Cloudflare Turnstile when `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY` are set: the token is verified server-side (`siteverify`, 5 s timeout) and must carry the form's action (`account-request`). Fails closed: a missing/invalid token or Cloudflare being unreachable rejects the submission with a "try again" message. `dmarc:install` warns when the public site is on without Turnstile keys.
  - Super-admins get an in-app notification and a queued email for each new request.
- **Admin → Account requests** (`account_requests` table): pending/approved/rejected, filtered to pending by default, with a navigation badge.
  - **Approve**: creates the tenant (name = organisation, unique slug) and issues an owner invitation to the requester (the normal invitation flow, §8 Invitations). The requester adds domains themselves via the setup wizard. Invitation emails for organisations created this way (including later team invitations) say the free service doesn't include tech support.
  - **Decline**: optionally emails the requester, with an optional note and a link to the self-hosting instructions.
  - Both are audited (`account_request.approved` / `account_request.rejected`) and refuse a request that was already reviewed (row lock against double approval).

### Audit log
- Recorded automatically for user-initiated changes to: domains, sender classifications, notification channels, alert settings, organisation settings, known senders, team memberships, invitations, users (incl. 2FA enabled/disabled), and manual alert resolution. Also: sign-ins, failed sign-ins, password resets, invitation acceptance, impersonation start/end, account request approval/decline.
- Changes made by the system (DNS scanner, report import, scheduled jobs) are not recorded; only allow-listed fields are diffed. Secrets (webhook config, passwords, TOTP seeds) are recorded as "changed" without values.
- Each entry: when, actor, impersonator, tenant, action, description, before/after changes, IP, user agent.
- Tenants see their own entries (Settings → Activity, owners/admins); super-admins see everything (Admin → Audit log). Entries are never editable.

---

## 9. Security

- Tenant isolation is enforced by global scopes and policies, with tests that try cross-tenant access on every resource
- Internal endpoints (`/internal/*`):
  - Reachable only on the Docker network (blocked at Caddy)
  - HMAC-signed with a timestamp (±5 min) to prevent replay
- XML parsing is hardened (no external entities, size/depth limits)
- Secrets are stored encrypted: notification channel config (webhook URLs and secrets, email recipients), SSO client secrets, TOTP seeds
- Tenant-supplied webhook URLs can't reach internal services (see Notification channels)
- Behind Caddy: the app trusts `X-Forwarded-Proto/For/Port` from the proxy hop (the web container isn't published; Caddy discards client-supplied forwarding headers) but never `X-Forwarded-Host`. Generated URLs are forced to `https` when `APP_URL` is https, and session cookies are `Secure`.
- Rate limiting on login and on the receiver
- Response headers (`SecurityHeaders` middleware): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'; base-uri 'self'; object-src 'none'` (no script CSP: Filament needs inline scripts), `Referrer-Policy: strict-origin-when-cross-origin` so signed share links never leak in a Referer (other sites see the origin only, which Turnstile needs), `Permissions-Policy`, and HSTS (1 year) on HTTPS. Caddy drops its `Server` header. `robots.txt` allows only the public pages.
- Sessions are encrypted at rest (`SESSION_ENCRYPT=true`)
- CI runs `composer audit` and `govulncheck` on every push and weekly; Dependabot raises update PRs for Composer, npm, Go modules, Docker images and GitHub Actions
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
PUBLIC_SITE_ENABLED=false   # public home page + free account requests (§8)
```

A first-run CLI (`php artisan dmarc:install`):
- Checks configuration (app key, URL, ingest secret, reports domain, queue, mail), database migrations, IP database
- Verifies the MSP's DNS: MX for the reports domain, MX host address, the wildcard `_report._dmarc` record (probed with a random label), web UI address
- Prints the DNS records to publish and offers to create the first super-admin
- `--check` runs non-interactively and exits non-zero if a required check fails (for scripts/monitoring)

Installer script (`install.sh`, repository root): checks the server, offers to install Docker (official apt repository), asks for hostnames, the SMTP relay and whether to enable the public site (with optional Turnstile keys; off by default), generates all secrets into `.env`, checks DNS, starts the stack, downloads the IP database, creates the first admin and runs `dmarc:install --check`. Idempotent (also used for upgrades); `--non-interactive` for automation, `--config-only` to only write `.env`. `scripts/migrate.sh export`/`import` moves an installation to a new server: one tar bundle with `.env`, a database dump and the `app_storage`, `smtp_spool`, `redis_data` and Caddy volumes, checksummed; import refuses to overwrite existing data without `--force` and finishes by running `install.sh`. Values are written single-quoted so `$`/`#` in passwords stay literal for both Compose and Laravel.

Operator docs: `docs/INSTALL.md`, `docs/OPERATIONS.md`.

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
