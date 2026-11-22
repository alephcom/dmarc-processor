#!/usr/bin/env bash
#
# Move a DMARC Processor installation to a new server.
#
#   On the old server:  sudo scripts/migrate.sh export [--out DIR] [--restart] [-y]
#   Copy the bundle:    scp dmarc-migration-*.tar new-server:
#   On the new server:  sudo scripts/migrate.sh import FILE [--force] [-y]
#
# export  stops the stack, dumps the database and archives .env and the volumes
#         (raw emails, receiver spool, Redis queue, Caddy certificates) into one
#         tar file. The stack stays stopped so no report lands on the old server
#         after the export; --restart starts it again (for a trial run).
# import  run from a fresh clone of the repository. Installs Docker if needed,
#         restores everything, then runs install.sh. Refuses to overwrite an
#         existing .env or data unless --force.
#
# Afterwards, point the A/AAAA records of APP_HOST and MX_HOSTNAME at the new
# server. See docs/OPERATIONS.md, "Moving to a new server".

# Shell snippets run inside the mariadb container expand its variables there, hence single quotes.
# shellcheck disable=SC2016

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT=dmarc   # matches `name:` in docker-compose.yml; volumes are dmarc_<name>
# Volumes copied as files. mariadb_data is moved as a SQL dump instead (portable across versions).
VOLUMES=(app_storage smtp_spool redis_data caddy_data caddy_config)
HELPER_IMAGE=alpine:3.20

# Reuse the installer's output helpers, confirm() and install_docker_if_needed().
# Called without arguments so install.sh's option parsing sees none.
load_installer() {
  # shellcheck source=install.sh
  source "${ROOT}/install.sh"
}
load_installer
trap 'rc=$?; trap - ERR; fail "Stopped (exit ${rc}) at line ${LINENO}: ${BASH_COMMAND}"; exit 1' ERR

usage() {
  sed -n '3,18p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  exit 0
}

compose() { docker compose -f "${ROOT}/docker-compose.yml" --project-directory "${ROOT}" "$@"; }

need_docker() {
  if [[ $EUID -ne 0 ]] && ! docker info >/dev/null 2>&1; then
    die "Run as root (sudo) or as a user in the docker group."
  fi
}

volume_exists() { docker volume inspect "${PROJECT}_$1" >/dev/null 2>&1; }

wait_for_mariadb() {
  local id status i
  id="$(compose ps -q mariadb)"
  for ((i = 0; i < 60; i++)); do
    status="$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null || true)"
    [[ "$status" == healthy ]] && return 0
    sleep 2
  done
  die "MariaDB did not become healthy; see: docker compose logs mariadb"
}

# Runs a shell snippet inside mariadb with the app's credentials available as env vars.
in_mariadb() { compose exec -T mariadb sh -c "$1"; }

# ---------------------------------------------------------------- export

do_export() {
  local out_dir="$PWD" restart=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --out) out_dir="${2:?--out needs a directory}"; shift ;;
      --restart) restart=1 ;;
      -y|--yes) ASSUME_YES=1 ;;
      -h|--help) usage ;;
      *) die "Unknown option: $1 (see --help)" ;;
    esac
    shift
  done
  out_dir="$(cd "$out_dir" && pwd)"

  printf '%sDMARC Processor: export for migration%s\n' "$BOLD" "$RESET"
  cd "$ROOT"
  need_docker
  [[ -f .env ]] || die "No .env in ${ROOT}; run this from the installed copy of the repository."
  volume_exists app_storage || die "Volume ${PROJECT}_app_storage not found; is the stack installed here?"

  warn "This stops all services. Mail sent meanwhile is queued by the senders and retried."
  if [[ $restart -eq 0 ]]; then
    warn "The stack stays stopped afterwards (use --restart for a trial run)."
  fi
  confirm "Continue?" || die "Cancelled."

  local stamp work bundle
  stamp="$(date +%Y%m%d-%H%M%S)"
  work="$(mktemp -d "${out_dir}/.dmarc-migration-XXXXXX")"
  bundle="${out_dir}/dmarc-migration-${stamp}.tar"
  chmod 700 "$work"
  # shellcheck disable=SC2064
  trap "rm -rf '$work'" EXIT

  step "Stopping services"
  # Stop the entry points first so nothing new arrives, then everything except the database.
  compose stop caddy smtp-receiver
  compose stop scheduler worker web redis
  compose up -d mariadb
  wait_for_mariadb
  ok "Services stopped (MariaDB still running for the dump)"

  step "Dumping the database"
  in_mariadb 'exec mariadb-dump --single-transaction --routines --triggers -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
    | gzip > "${work}/database.sql.gz"
  gzip -t "${work}/database.sql.gz"
  ok "database.sql.gz ($(du -h "${work}/database.sql.gz" | cut -f1))"
  compose stop mariadb

  step "Archiving volumes"
  local v
  for v in "${VOLUMES[@]}"; do
    if ! volume_exists "$v"; then
      warn "${PROJECT}_${v} does not exist, skipped"
      continue
    fi
    docker run --rm -v "${PROJECT}_${v}:/data:ro" "$HELPER_IMAGE" \
      tar czf - -C /data . > "${work}/volume-${v}.tgz"
    ok "${v} ($(du -h "${work}/volume-${v}.tgz" | cut -f1))"
  done

  step "Writing the bundle"
  cp .env "${work}/env"
  {
    echo "created=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "source_host=$(hostname -f 2>/dev/null || hostname)"
    echo "git_commit=$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
  } > "${work}/manifest"
  (cd "$work" && sha256sum -- * > SHA256SUMS)
  (umask 077 && tar cf "$bundle" -C "$work" .)
  chmod 600 "$bundle"
  ok "${bundle} ($(du -h "$bundle" | cut -f1))"
  warn "The bundle contains .env (APP_KEY, passwords): copy it securely and delete it when done."

  if [[ $restart -eq 1 ]]; then
    step "Restarting services (--restart)"
    compose up -d
    warn "Reports received from now on are not in this bundle."
  fi

  printf '\nNext:\n'
  printf '  1. scp %s new-server:\n' "$bundle"
  printf '  2. On the new server, in a clone of the repository: sudo scripts/migrate.sh import %s\n' "$(basename "$bundle")"
  [[ $restart -eq 1 ]] || printf '  To abort and keep using this server: docker compose up -d\n'
}

# ---------------------------------------------------------------- import

do_import() {
  local file="" force=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --force) force=1 ;;
      -y|--yes) ASSUME_YES=1 ;;
      -h|--help) usage ;;
      -*) die "Unknown option: $1 (see --help)" ;;
      *) [[ -z "$file" ]] || die "Only one bundle file expected."; file="$1" ;;
    esac
    shift
  done
  [[ -n "$file" ]] || die "Usage: scripts/migrate.sh import FILE"
  [[ -f "$file" ]] || die "No such file: ${file}"
  file="$(cd "$(dirname "$file")" && pwd)/$(basename "$file")"

  printf '%sDMARC Processor: import from migration bundle%s\n' "$BOLD" "$RESET"
  cd "$ROOT"

  step "Checking the bundle"
  local work
  work="$(mktemp -d)"
  chmod 700 "$work"
  # shellcheck disable=SC2064
  trap "rm -rf '$work'" EXIT
  tar xf "$file" -C "$work" 2>/dev/null || die "${file} is not a migration bundle."
  [[ -f "${work}/SHA256SUMS" && -f "${work}/env" && -f "${work}/database.sql.gz" ]] \
    || die "${file} is not a migration bundle."
  (cd "$work" && sha256sum --quiet -c SHA256SUMS) || die "Checksum mismatch: the bundle is damaged."
  ok "Checksums match"
  local key value
  while IFS='=' read -r key value; do
    case "$key" in created|source_host|git_commit) ok "${key}: ${value}" ;; esac
  done < "${work}/manifest"
  local src_commit here_commit
  src_commit="$(sed -n 's/^git_commit=//p' "${work}/manifest")"
  here_commit="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
  if [[ "$src_commit" != unknown && "$here_commit" != unknown && "$src_commit" != "$here_commit" ]]; then
    if git merge-base --is-ancestor "$src_commit" "$here_commit" 2>/dev/null; then
      ok "This checkout is newer than the old server's; migrations will upgrade the data on start"
    else
      warn "This checkout (${here_commit:0:12}) is not the old server's version (${src_commit:0:12}) or newer."
      warn "To be safe: git checkout ${src_commit:0:12}, import, then upgrade."
      confirm "Continue anyway?" || die "Cancelled."
    fi
  fi

  check_requirements   # from install.sh: root/docker access, installs Docker if needed, free ports

  local existing=()
  [[ -f .env ]] && existing+=(".env")
  local v
  for v in mariadb_data "${VOLUMES[@]}"; do
    volume_exists "$v" && existing+=("${PROJECT}_${v}")
  done
  if (( ${#existing[@]} )); then
    if [[ $force -eq 0 ]]; then
      die "Already present: ${existing[*]}. Use --force to replace them with the bundle's data."
    fi
    warn "--force: replacing ${existing[*]}"
    confirm "Delete the current data on this server?" || die "Cancelled."
    compose down
  fi

  step "Restoring configuration"
  [[ -f .env ]] && cp .env ".env.before-migration-$(date +%Y%m%d-%H%M%S)"
  (umask 077 && cp "${work}/env" .env)
  ok ".env"

  step "Restoring volumes"
  for v in "${VOLUMES[@]}"; do
    if [[ ! -f "${work}/volume-${v}.tgz" ]]; then
      warn "${v} not in the bundle, skipped"
      continue
    fi
    docker volume create "${PROJECT}_${v}" >/dev/null
    docker run --rm -i -v "${PROJECT}_${v}:/data" "$HELPER_IMAGE" \
      sh -c 'find /data -mindepth 1 -delete && tar xzf - -C /data' < "${work}/volume-${v}.tgz"
    ok "$v"
  done

  step "Restoring the database"
  if [[ $force -eq 1 ]] && volume_exists mariadb_data; then
    docker volume rm "${PROJECT}_mariadb_data" >/dev/null
  fi
  compose up -d mariadb
  wait_for_mariadb
  gunzip -c "${work}/database.sql.gz" \
    | in_mariadb 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
  ok "Database restored ($(in_mariadb 'mariadb -N -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "select count(*) from users"') users)"

  step "Running the installer"
  local args=()
  [[ $ASSUME_YES -eq 1 ]] && args+=(--yes)
  trap - ERR
  "${ROOT}/install.sh" "${args[@]}"

  printf '\nNext:\n'
  printf '  1. Point the A/AAAA records of the web UI and MX hostnames at this server.\n'
  printf '  2. Once DNS has switched, check: docker compose exec web php artisan dmarc:install --check\n'
  printf '     and: scripts/smtp-test.py <MX hostname> --reports-domain <reports domain>\n'
  printf '  3. Delete the bundle (it contains secrets); keep the old server stopped for a few days.\n'
}

# ---------------------------------------------------------------- main

main() {
  local cmd="${1:-}"
  [[ $# -gt 0 ]] && shift
  case "$cmd" in
    export) do_export "$@" ;;
    import) do_import "$@" ;;
    -h|--help|"") usage ;;
    *) die "Unknown command: ${cmd} (expected export or import)" ;;
  esac
}

main "$@"
