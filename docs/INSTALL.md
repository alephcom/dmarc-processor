# Installing DMARC Processor

This guide sets up the whole stack on one Linux server with Docker Compose. Budget about an hour, most of it waiting for DNS.

## 1. What you need

- A Linux VPS: 2 vCPU, 4 GB RAM and 40 GB disk handles roughly 500 domains
- Docker Engine 24+ with the Compose plugin
- Inbound TCP **25** (SMTP), **80** and **443** open. Many providers block port 25 by default; ask them to unblock it for inbound mail.
- A domain you control for the service (examples below use `example-msp.com`)
- An SMTP relay for outbound mail (alerts, invitations, summaries): your existing provider, Microsoft 365, SES, Postmark…

## 2. DNS

Create these records in your MSP's zone, pointing at the server:

| Name | Type | Value | Purpose |
|---|---|---|---|
| `dmarc.example-msp.com` | A / AAAA | server IP | Web UI (`APP_HOST`) |
| `mx.reports.example-msp.com` | A / AAAA | server IP | Receives reports (`MX_HOSTNAME`) |
| `reports.example-msp.com` | MX | `10 mx.reports.example-msp.com.` | Reporting domain (`REPORTS_DOMAIN`) |
| `*._report._dmarc.reports.example-msp.com` | TXT | `v=DMARC1` | Authorises reports for **every** customer domain ([RFC 7489 §7.1](https://www.rfc-editor.org/rfc/rfc7489#section-7.1)) |

Without the wildcard TXT record, Google and other providers silently drop reports for customer domains. The setup wizard flags it on every domain until it exists.

Optionally, set a PTR (reverse DNS) record for the server IP to `mx.reports.example-msp.com`.

## Quick install (recommended)

On a fresh Ubuntu or Debian server, after creating the DNS records in section 2:

```bash
git clone https://github.com/alephcom/dmarc-processor.git
```
```bash
cd dmarc-processor && sudo ./install.sh
```

The script:
- checks the server (ports 25/80/443 free, memory) and offers to install Docker from Docker's official repository;
- asks for your hostnames and SMTP relay, generates every secret and writes `.env` (mode 600);
- checks that your DNS resolves, then builds and starts the stack;
- downloads the IP database, creates the first MSP admin, and runs `dmarc:install --check`.

Re-running it is safe: existing settings and secrets are kept, and the stack is rebuilt and restarted, so it also works for upgrades. `--reconfigure` asks the questions again (backing up `.env` first, and keeping the secrets).

For automation, pass `--non-interactive` and provide the answers as environment variables. Run `./install.sh --help` for the list. The first admin is created from `ADMIN_EMAIL`, `ADMIN_NAME` and `ADMIN_PASSWORD`; the password is passed via standard input, never on a command line.

Sections 3–4 below describe the same steps done by hand.

## 3. Configure

```bash
git clone https://github.com/alephcom/dmarc-processor.git
```
```bash
cd dmarc-processor && cp .env.example .env
```

Edit `.env`:

- `APP_HOST`, `REPORTS_DOMAIN`, `MX_HOSTNAME`, `APP_URL` (= `https://` + `APP_HOST`), `ACME_EMAIL` (Let's Encrypt notices)
- `DB_PASSWORD`: a long random string
- `INGEST_HMAC_SECRET`: shared by the SMTP receiver and the app. Generate it with:
  ```bash
  openssl rand -hex 32
  ```
- `MAIL_*`: your SMTP relay
- Optional: `IPINFO_TOKEN` (better sender identification), `DMARC_BRAND_*` (report branding), SSO keys (section 6)

Generate the application key and paste it into `APP_KEY`:

```bash
docker compose run --rm web php artisan key:generate --show
```

## 4. Start

```bash
docker compose up -d --build
```

The `web` container runs database migrations on start. Caddy obtains TLS certificates for the web UI and the MX host within a minute or two; the SMTP receiver picks up its certificate automatically once it exists.

Run the installation check. It verifies configuration, the database and your DNS records, and offers to create the first MSP admin:

```bash
docker compose exec web php artisan dmarc:install
```

Download the IP-to-network database (the scheduler also refreshes it weekly):

```bash
docker compose exec web php artisan dmarc:update-ip-db
```

## 5. First sign-in

1. Open `https://dmarc.example-msp.com/admin` and sign in as the super-admin. You'll be asked to set up two-factor authentication; this is mandatory for MSP admins.
2. **Tenants → New**: create a customer.
3. On the customer's **Members** tab, invite their first owner (or invite yourself to try it).
4. In the customer workspace (`/app/<customer>`), add a domain. The setup wizard takes it from there.
5. Under **Settings → MSP notifications**, add an email or webhook channel to receive every customer's alerts.

To check mail is flowing end to end, add a test domain you control, publish its DMARC record from the wizard, and wait for the first reports (usually within 24–48 hours). **Admin → Ingest queue** shows every received message.

## 6. Single sign-on (optional)

Customer users can sign in with Microsoft 365 or Google Workspace. MSP admins always use a password plus two-factor code.

Each customer must be configured explicitly under **Admin → Tenants → (customer) → Single sign-on**:

- **Email domains**: e.g. `acme.com`
- **Microsoft Entra directory IDs**: required for Microsoft sign-in. Find it in the customer's Entra admin centre under Overview → Tenant ID.
- **Role for new users**: Viewer (default) or Admin

On first sign-in, matching users get an account automatically. Existing accounts with the same email are linked. Email addresses alone are never trusted:
- **Microsoft:** the user must come from an allow-listed Entra directory.
- **Google:** the account must be a Workspace account whose verified domain matches.

### Microsoft Entra ID

1. In the Entra admin centre for **your MSP's** tenant: App registrations → New registration.
2. Supported account types: **Accounts in any organizational directory (multitenant)**.
3. Redirect URI (Web): `https://dmarc.example-msp.com/auth/sso/microsoft/callback`
4. Certificates & secrets → New client secret.
5. Set `MICROSOFT_CLIENT_ID` (Application ID) and `MICROSOFT_CLIENT_SECRET` in `.env`, then run:
   ```bash
   docker compose up -d
   ```

The first time a customer's user signs in, they (or their admin) consent to the app reading their basic profile.

### Google Workspace

1. Google Cloud console → APIs & Services → Credentials → Create OAuth client ID → Web application.
2. Authorised redirect URI: `https://dmarc.example-msp.com/auth/sso/google/callback`
3. OAuth consent screen: user type **External** (so any Workspace organisation can use it); scopes `openid`, `email`, `profile`.
4. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`, then run:
   ```bash
   docker compose up -d
   ```

## 7. Next

See [OPERATIONS.md](OPERATIONS.md) for upgrades, backups, monitoring and troubleshooting.
