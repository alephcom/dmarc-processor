#!/usr/bin/env bash
#
# DMARC Processor installer for a single Linux server (Ubuntu / Debian).
#
#   sudo ./install.sh                 interactive install, or re-run to upgrade/restart
#   sudo ./install.sh --reconfigure   ask the questions again (keeps generated secrets)
#   ./install.sh --config-only        write .env and stop (no Docker needed)
#
# Non-interactive: pass --non-interactive and set the answers as environment variables:
#   APP_HOST REPORTS_DOMAIN MX_HOSTNAME ACME_EMAIL MAIL_HOST MAIL_PORT MAIL_USERNAME
#   MAIL_PASSWORD MAIL_FROM_ADDRESS [IPINFO_TOKEN] [DMARC_BRAND_NAME]
#   [ADMIN_EMAIL ADMIN_NAME ADMIN_PASSWORD]   (creates the first MSP admin)
#
# See docs/INSTALL.md for what each setting means.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"
ENV_EXAMPLE="${SCRIPT_DIR}/.env.example"

INTERACTIVE=1
RECONFIGURE=0
CONFIG_ONLY=0
ASSUME_YES=0

# ---------------------------------------------------------------- output helpers

if [[ -t 1 ]]; then
  BOLD=$'\e[1m'; DIM=$'\e[2m'; RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RESET=$'\e[0m'
else
  BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi

step() { printf '\n%s==> %s%s\n' "$BOLD" "$*" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$*"; }
fail() { printf '  %s✗%s %s\n' "$RED" "$RESET" "$*" >&2; }
die()  { fail "$*"; exit 1; }

trap 'fail "Installer stopped at line ${LINENO}. Nothing already running was removed; fix the problem and re-run."' ERR

usage() {
  sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

# ---------------------------------------------------------------- argument parsing

while [[ $# -gt 0 ]]; do
  case "$1" in
    --non-interactive) INTERACTIVE=0 ;;
    --reconfigure)     RECONFIGURE=1 ;;
    --config-only)     CONFIG_ONLY=1 ;;
    -y|--yes)          ASSUME_YES=1 ;;
    -h|--help)         usage ;;
    *) die "Unknown option: $1 (see --help)" ;;
  esac
  shift
done

# ---------------------------------------------------------------- prompts

# ask VAR "Question" [default] [secret]
# Uses an existing environment value in non-interactive mode.
ask() {
  local var="$1" question="$2" default="${3:-}" secret="${4:-}" current="${!1:-}" answer=''
  if [[ $INTERACTIVE -eq 0 ]]; then
    answer="${current:-$default}"
  else
    local shown="${current:-$default}"
    local hint=''
    if [[ -n "$shown" ]]; then
      if [[ -n "$secret" ]]; then hint=" ${DIM}[keep current]${RESET}"; else hint=" ${DIM}[${shown}]${RESET}"; fi
    fi
    while true; do
      if [[ -n "$secret" ]]; then
        read -r -s -p "  ${question}${hint}: " answer; echo
      else
        read -r -p "  ${question}${hint}: " answer
      fi
      answer="${answer:-$shown}"
      [[ -n "$answer" || "$var" == IPINFO_TOKEN || "$var" == MAIL_USERNAME || "$var" == MAIL_PASSWORD ]] && break
      warn "This is required."
    done
  fi
  printf -v "$var" '%s' "$answer"
}

confirm() {
  local question="$1"
  [[ $ASSUME_YES -eq 1 || $INTERACTIVE -eq 0 ]] && return 0
  local reply
  read -r -p "  ${question} [Y/n] " reply
  [[ -z "$reply" || "$reply" =~ ^[Yy] ]]
}

valid_hostname() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$ ]]; }
valid_email()    { [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; }

# ---------------------------------------------------------------- .env handling

# Values are written single-quoted so "$" and "#" are taken literally by both Docker Compose
# and Laravel. A single quote can't be represented that way, so it's rejected.
env_get() {
  [[ -f "$ENV_FILE" ]] || return 0
  local line
  line="$(grep -E "^$1=" "$ENV_FILE" | tail -n1 || true)"
  line="${line#*=}"
  line="${line#\'}"; line="${line%\'}"
  line="${line#\"}"; line="${line%\"}"
  printf '%s' "$line"
}

env_set() {
  local key="$1" value="$2" tmp
  [[ "$value" == *"'"* ]] && die "The value for ${key} contains a single quote ('), which can't be stored safely. Please choose another."
  [[ "$value" == *$'\n'* ]] && die "The value for ${key} contains a line break."
  tmp="$(mktemp "${ENV_FILE}.XXXXXX")"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    # Pass the value via the environment: awk -v would interpret backslashes in passwords.
    K="$key" V="'${value}'" awk 'BEGIN { k = ENVIRON["K"]; v = ENVIRON["V"] } index($0, k "=") == 1 { print k "=" v; next } { print }' "$ENV_FILE" > "$tmp"
  else
    cat "$ENV_FILE" > "$tmp"
    printf "%s='%s'\n" "$key" "$value" >> "$tmp"
  fi
  cat "$tmp" > "$ENV_FILE"
  rm -f "$tmp"
}

random_hex() { openssl rand -hex "$1"; }

# ---------------------------------------------------------------- checks

check_requirements() {
  step "Checking this server"
  command -v openssl >/dev/null || die "openssl is required (apt-get install -y openssl)."
  ok "openssl"

  [[ $CONFIG_ONLY -eq 1 ]] && return 0

  if [[ $EUID -ne 0 ]]; then
    if docker info >/dev/null 2>&1; then
      ok "Running as $(id -un) with Docker access"
    else
      die "Run as root (sudo ./install.sh) or as a user in the docker group."
    fi
  else
    ok "Running as root"
  fi

  if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    case "${ID:-}" in
      ubuntu|debian) ok "${PRETTY_NAME:-$ID}" ;;
      *) warn "${PRETTY_NAME:-Unknown OS}: only Ubuntu and Debian are tested." ;;
    esac
  fi

  local mem_mb
  mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
  if (( mem_mb > 0 && mem_mb < 1900 )); then
    warn "${mem_mb} MB RAM; 2 GB or more is recommended."
  else
    ok "${mem_mb} MB RAM"
  fi

  install_docker_if_needed

  local port in_use=()
  for port in 25 80 443; do
    if ss -ltnH "sport = :${port}" 2>/dev/null | grep -q .; then
      # Our own stack holding the port is fine (re-run).
      if ! docker compose -f "${SCRIPT_DIR}/docker-compose.yml" ps --status running 2>/dev/null | grep -qE 'caddy|smtp-receiver'; then
        in_use+=("$port")
      fi
    fi
  done
  if (( ${#in_use[@]} )); then
    fail "Port(s) ${in_use[*]} are already in use by another program:"
    ss -ltnp 2>/dev/null | grep -E ":($(IFS='|'; echo "${in_use[*]}"))\b" || true
    die "Stop that service (e.g. postfix, apache2, nginx) and re-run."
  fi
  ok "Ports 25, 80 and 443 are free"
}

install_docker_if_needed() {
  if command -v docker >/dev/null && docker compose version >/dev/null 2>&1; then
    ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '(unknown)') with Compose $(docker compose version --short 2>/dev/null)"
    return 0
  fi
  warn "Docker Engine with the Compose plugin is not installed."
  [[ $EUID -eq 0 ]] || die "Install Docker as root, or re-run this script with sudo."
  confirm "Install Docker from Docker's official apt repository now?" || die "Docker is required. See https://docs.docker.com/engine/install/"

  # Docker's documented apt-repository method (no curl | sh).
  . /etc/os-release
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg >/dev/null
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
  systemctl enable --now docker >/dev/null 2>&1 || true
  docker compose version >/dev/null || die "Docker installation failed."
  ok "Docker installed"
}

# ---------------------------------------------------------------- configuration

configure() {
  step "Configuration"
  [[ -f "$ENV_EXAMPLE" ]] || die "Missing ${ENV_EXAMPLE}. Run this script from the repository checkout."

  local fresh=0
  if [[ ! -f "$ENV_FILE" ]]; then
    cp "$ENV_EXAMPLE" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    fresh=1
  elif [[ $RECONFIGURE -eq 1 ]]; then
    cp "$ENV_FILE" "${ENV_FILE}.backup-$(date +%Y%m%d%H%M%S)"
    ok "Backed up the existing .env"
  else
    ok "Using existing .env (run with --reconfigure to change settings)"
    return 0
  fi

  # Start from current .env values (or the caller's environment in non-interactive mode).
  local k
  for k in APP_HOST REPORTS_DOMAIN MX_HOSTNAME ACME_EMAIL MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS IPINFO_TOKEN DMARC_BRAND_NAME; do
    if [[ -z "${!k:-}" ]]; then
      local v; v="$(env_get "$k")"
      # Ignore the placeholder values from .env.example.
      # shellcheck disable=SC2016  # a literal "${" marks a template reference, not a real value
      [[ "$v" == *example-msp.com* || "$v" == smtp.example.com || "$v" == *'${'* ]] && v=''
      printf -v "$k" '%s' "$v"
    fi
  done

  echo "  ${DIM}Hostnames (see docs/INSTALL.md §2 for the DNS records they need):${RESET}"
  while true; do
    ask APP_HOST "Web UI hostname, e.g. dmarc.example-msp.com"
    valid_hostname "$APP_HOST" && break
    warn "That doesn't look like a hostname."; APP_HOST=''
    [[ $INTERACTIVE -eq 0 ]] && die "APP_HOST is not a valid hostname."
  done
  local base="${APP_HOST#*.}"
  while true; do
    ask REPORTS_DOMAIN "Domain that receives reports" "reports.${base}"
    valid_hostname "$REPORTS_DOMAIN" && break
    warn "That doesn't look like a hostname."; REPORTS_DOMAIN=''
    [[ $INTERACTIVE -eq 0 ]] && die "REPORTS_DOMAIN is not a valid hostname."
  done
  while true; do
    ask MX_HOSTNAME "Mail server hostname for that domain" "mx.${REPORTS_DOMAIN}"
    valid_hostname "$MX_HOSTNAME" && break
    warn "That doesn't look like a hostname."; MX_HOSTNAME=''
    [[ $INTERACTIVE -eq 0 ]] && die "MX_HOSTNAME is not a valid hostname."
  done
  while true; do
    ask ACME_EMAIL "Email for Let's Encrypt certificate notices"
    valid_email "$ACME_EMAIL" && break
    warn "That doesn't look like an email address."; ACME_EMAIL=''
    [[ $INTERACTIVE -eq 0 ]] && die "ACME_EMAIL is not a valid email address."
  done

  echo
  echo "  ${DIM}Outbound email for alerts, invitations and summaries (your SMTP relay):${RESET}"
  ask MAIL_HOST "SMTP host"
  ask MAIL_PORT "SMTP port" "587"
  ask MAIL_USERNAME "SMTP username (blank if none)"
  ask MAIL_PASSWORD "SMTP password (blank if none)" "" secret
  while true; do
    ask MAIL_FROM_ADDRESS "From address" "dmarc@${base}"
    valid_email "$MAIL_FROM_ADDRESS" && break
    warn "That doesn't look like an email address."; MAIL_FROM_ADDRESS=''
    [[ $INTERACTIVE -eq 0 ]] && die "MAIL_FROM_ADDRESS is not a valid email address."
  done

  echo
  echo "  ${DIM}Optional:${RESET}"
  ask DMARC_BRAND_NAME "Your company name (shown on reports)" "DMARC Monitor"
  ask IPINFO_TOKEN "IPinfo token for better sender identification (blank to use DB-IP)"

  env_set APP_HOST "$APP_HOST"
  env_set APP_URL "https://${APP_HOST}"
  env_set REPORTS_DOMAIN "$REPORTS_DOMAIN"
  env_set MX_HOSTNAME "$MX_HOSTNAME"
  env_set ACME_EMAIL "$ACME_EMAIL"
  env_set APP_ENV production
  env_set APP_DEBUG false
  env_set MAIL_MAILER smtp
  env_set MAIL_HOST "$MAIL_HOST"
  env_set MAIL_PORT "$MAIL_PORT"
  env_set MAIL_USERNAME "$MAIL_USERNAME"
  env_set MAIL_PASSWORD "$MAIL_PASSWORD"
  env_set MAIL_FROM_ADDRESS "$MAIL_FROM_ADDRESS"
  env_set DMARC_BRAND_NAME "$DMARC_BRAND_NAME"
  env_set IPINFO_TOKEN "$IPINFO_TOKEN"

  # Secrets: generated once, never overwritten on re-runs.
  [[ -n "$(env_get APP_KEY)" ]] || env_set APP_KEY "base64:$(openssl rand -base64 32)"
  [[ -n "$(env_get DB_PASSWORD)" ]] || env_set DB_PASSWORD "$(random_hex 24)"
  local ingest; ingest="$(env_get INGEST_HMAC_SECRET)"
  [[ ${#ingest} -ge 32 ]] || env_set INGEST_HMAC_SECRET "$(random_hex 32)"

  chmod 600 "$ENV_FILE"
  if [[ $fresh -eq 1 ]]; then ok "Wrote .env (secrets generated; keep a backup of this file)"; else ok "Updated .env"; fi
}

# ---------------------------------------------------------------- DNS

server_ips() {
  hostname -I 2>/dev/null | tr ' ' '\n' | grep -vE '^(127\.|::1|fe80:|172\.(1[6-9]|2[0-9]|3[01])\.|$)' || true
}

resolve() { getent ahosts "$1" 2>/dev/null | awk '{print $1}' | sort -u; }

check_dns() {
  step "DNS"
  local app_host mx_host reports
  app_host="$(env_get APP_HOST)"; mx_host="$(env_get MX_HOSTNAME)"; reports="$(env_get REPORTS_DOMAIN)"
  local ips; ips="$(server_ips | paste -sd' ' -)"

  echo "  Publish these records in your DNS (this server's address${ips:+ appears to be: ${BOLD}${ips}${RESET}}):"
  printf '    %-45s %-5s %s\n' "${app_host}" "A" "<server IPv4>"
  printf '    %-45s %-5s %s\n' "${mx_host}" "A" "<server IPv4>"
  printf '    %-45s %-5s %s\n' "${reports}" "MX" "10 ${mx_host}."
  printf '    %-45s %-5s %s\n' "*._report._dmarc.${reports}" "TXT" "\"v=DMARC1\""
  echo

  local missing=0 host
  for host in "$app_host" "$mx_host"; do
    local addrs; addrs="$(resolve "$host" | paste -sd' ' -)"
    if [[ -z "$addrs" ]]; then
      warn "${host} does not resolve yet."; missing=1
    else
      ok "${host} → ${addrs}"
    fi
  done

  if [[ $missing -eq 1 ]]; then
    warn "HTTPS certificates can't be issued until these hostnames resolve to this server."
    if [[ $INTERACTIVE -eq 1 ]] && ! confirm "Continue anyway? (Caddy keeps retrying in the background)"; then
      die "Add the DNS records, wait a few minutes, then re-run ./install.sh"
    fi
  fi
}

# ---------------------------------------------------------------- start

compose() { docker compose -f "${SCRIPT_DIR}/docker-compose.yml" --project-directory "${SCRIPT_DIR}" "$@"; }

start_stack() {
  step "Building and starting the services (the first build takes a few minutes)"
  compose up -d --build
  ok "Containers started"

  printf '  Waiting for the app to become ready'
  for _ in $(seq 1 90); do
    if compose exec -T web php artisan about --only=environment >/dev/null 2>&1 \
      && compose exec -T web php artisan migrate:status >/dev/null 2>&1; then
      echo; ok "App is ready"; return 0
    fi
    printf '.'; sleep 2
  done
  echo
  die "The app didn't become ready. Check: docker compose logs web"
}

post_install() {
  step "Finishing setup"
  if compose exec -T web php artisan dmarc:update-ip-db --if-missing >/dev/null 2>&1; then
    ok "IP database installed"
  else
    warn "Couldn't download the IP database now; the scheduler retries hourly."
  fi

  local admins
  admins="$(compose exec -T web php artisan tinker --execute 'echo App\Models\User::where("is_super_admin", true)->count();' 2>/dev/null | tr -dc '0-9')"
  if [[ "${admins:-0}" -eq 0 ]]; then
    if [[ $INTERACTIVE -eq 1 ]]; then
      echo "  Create the first MSP admin (you'll set up two-factor authentication at first sign-in):"
      local email name
      while true; do read -r -p "  Admin email: " email; valid_email "$email" && break; warn "That doesn't look like an email address."; done
      read -r -p "  Admin name: " name
      compose exec web php artisan dmarc:make-super-admin "$email" --name="${name:-Admin}"
    elif [[ -n "${ADMIN_EMAIL:-}" && -n "${ADMIN_PASSWORD:-}" ]]; then
      printf '%s\n' "$ADMIN_PASSWORD" | compose exec -T web php artisan dmarc:make-super-admin "$ADMIN_EMAIL" --name="${ADMIN_NAME:-Admin}" --password-stdin >/dev/null
      ok "Created MSP admin ${ADMIN_EMAIL}"
    else
      warn "No MSP admin yet. Create one with: docker compose exec web php artisan dmarc:make-super-admin you@example.com"
    fi
  else
    ok "MSP admin already exists"
  fi

  step "Installation check"
  compose exec -T web php artisan dmarc:install --check || warn "Some checks need attention (see above). Re-run the check any time with: docker compose exec web php artisan dmarc:install --check"
}

summary() {
  local app_host; app_host="$(env_get APP_HOST)"
  step "Done"
  cat <<EOF
  MSP admin:        https://${app_host}/admin
  Customer sign-in: https://${app_host}/app

  Next steps:
    1. Sign in to /admin and set up two-factor authentication.
    2. Create a customer (Tenants → New) and invite their first owner.
    3. Add a notification channel under Settings → MSP notifications.

  Back up ${ENV_FILE}: it holds the keys that encrypt stored secrets.
  Upgrades, backups and troubleshooting: docs/OPERATIONS.md
EOF
}

# ---------------------------------------------------------------- main

main() {
  printf '%sDMARC Processor installer%s\n' "$BOLD" "$RESET"
  cd "$SCRIPT_DIR"
  check_requirements
  configure
  if [[ $CONFIG_ONLY -eq 1 ]]; then
    ok "Configuration written to ${ENV_FILE} (--config-only: not starting services)"
    exit 0
  fi
  check_dns
  start_stack
  post_install
  summary
}

main "$@"
