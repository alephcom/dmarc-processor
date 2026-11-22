# Installation — VPS and DNS

> **Status:** Provisional. Based on the draft spec (`docs/SPEC.md`). The application code does not exist yet, so this covers preparing the server and DNS only.

Throughout, replace:
- `example.com` with your MSP domain
- `203.0.113.10` with your VPS's public IP

---

## 1. VPS

### Sizing (estimate for ~50 tenants / 500 domains)
- 2 vCPU, 4 GB RAM, 40–80 GB SSD
- Ubuntu 24.04 LTS or Debian 12
- Static public IPv4 (IPv6 optional)

### Provider checks
- Confirm the provider allows **inbound port 25** (some block it by default or require a support ticket)

### Base setup (as root)
```bash
apt update && apt upgrade -y
```
```bash
adduser dmarc && usermod -aG sudo dmarc
```
```bash
apt install -y ufw fail2ban unattended-upgrades
```
```bash
dpkg-reconfigure -plow unattended-upgrades
```
```bash
timedatectl set-timezone UTC
```

### SSH hardening
- Copy your SSH key to the `dmarc` user
- In `/etc/ssh/sshd_config` set `PasswordAuthentication no` and `PermitRootLogin no`
- Restart SSH:
```bash
systemctl restart ssh
```

### Firewall
```bash
ufw default deny incoming && ufw default allow outgoing
```
```bash
ufw allow OpenSSH && ufw allow 25/tcp && ufw allow 80/tcp && ufw allow 443/tcp
```
```bash
ufw enable
```
- 25: SMTP receiver (DMARC reports)
- 80: Caddy's Let's Encrypt challenge and HTTP→HTTPS redirect
- 443: web UI
- Note: Docker-published ports bypass UFW. Only publish 25, 80 and 443 in Compose.

### Docker
```bash
curl -fsSL https://get.docker.com | sh
```
```bash
usermod -aG docker dmarc
```
- Log out and back in for the group change to apply
- Check: `docker compose version`

### Free up port 25
```bash
ss -ltnp | grep ':25 '
```
- If Postfix or Exim is listening, remove it:
```bash
apt purge -y postfix exim4*
```

---

## 2. DNS records

Add these in the `example.com` zone:

| Name | Type | Value | Purpose |
|---|---|---|---|
| `dmarc.example.com` | A | `203.0.113.10` | Web UI (`APP_URL`) |
| `mx.reports.example.com` | A | `203.0.113.10` | SMTP receiver host and its TLS cert |
| `reports.example.com` | MX | `10 mx.reports.example.com.` | Routes report mail to the VPS |
| `*._report._dmarc.reports.example.com` | TXT | `"v=DMARC1"` | Lets customer domains send reports to your domain (RFC 7489 §7.1) |

Notes:
- If the VPS has IPv6, add matching `AAAA` records, but only if port 25 is open on IPv6 too
- On Cloudflare, set the A records to **DNS only** (grey cloud). Port 25 can't be proxied, and Caddy needs to answer the cert challenge directly.
- Without the wildcard `_report._dmarc` record, many reporters (Google, Microsoft, etc.) won't send reports for customer domains

### Reverse DNS (PTR) — optional, recommended
Set the IP's PTR to `mx.reports.example.com` in your provider's panel.
- Not required: the VPS only receives mail, and outbound alerts/digests go through an external relay
- Worth doing: it takes seconds, avoids generic hostnames in logs, and becomes essential if you ever send mail directly from the VPS

### Verify
```bash
dig +short A dmarc.example.com
```
```bash
dig +short MX reports.example.com
```
```bash
dig +short TXT anything.com._report._dmarc.reports.example.com
```
```bash
dig +short -x 203.0.113.10
```

### Per customer domain (later, via the app)
The setup wizard will generate a record like:
```
_dmarc.customer.com  TXT  "v=DMARC1; p=none; rua=mailto:rua-<token>@reports.example.com; fo=1"
```

---

## 3. Have ready for later
- **Outbound SMTP relay** credentials (`MAIL_*`), used for alerts and digests
- **IPinfo Lite token** (optional and free; DB-IP is used without one)
- **SSO app registrations** (optional): Microsoft Entra ID and/or Google client ID and secret

---

## 4. Application install (planned)
Once the code exists:
1. Clone the repo
2. Fill in `.env`
3. `docker compose up -d`
4. `php artisan dmarc:install`, which creates the first super-admin, prints the required DNS records and verifies them
