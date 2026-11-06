#!/usr/bin/env bash
# One-command upgrade of a Docker install (docs/DOCKER.md §4).
#
#   sudo /opt/sitexpost/update.sh            # newest image of the current tag (e.g. latest)
#   sudo /opt/sitexpost/update.sh 1.1.0      # switch to version 1.1.0
#
# Steps: encrypted backup (+verify) → set SITEX_VERSION → pull → up -d → wait for the
# app's pre-flight/migrations → report. If the new version is blocked (destructive
# migration, failed requirement) the site is left in maintenance mode by the
# container; this script prints the one command that goes back to the old version.
# It never deletes volumes and never touches Laravel's .env.
set -euo pipefail
SELF="$(readlink -f "$0")"
cd "$(dirname "$SELF")"

NEW="${1:-}"
DC="docker compose"
API_PORT=$(grep -E '^SITEX_PORT_API=' .env 2>/dev/null | cut -d= -f2- || true)
API="http://127.0.0.1:${API_PORT:-8080}"

die() { echo "!! $*" >&2; exit 1; }
say() { echo "==> $*"; }

[ -f .env ] && [ -f docker-compose.yml ] || die "Run from the folder holding docker-compose.yml and .env (e.g. /opt/sitexpost)."
if [ -n "$NEW" ] && ! [[ "$NEW" =~ ^[A-Za-z0-9._-]{1,64}$ ]]; then die "Invalid version: $NEW"; fi
OLD=$(grep -E '^SITEX_VERSION=' .env | cut -d= -f2- || true)
OLD="${OLD:-latest}"
TARGET="${NEW:-$OLD}"
say "Version: $OLD → $TARGET"

# 1. Backup (only once installed; required when a backup key is configured)
if $DC ps --status running --services 2>/dev/null | grep -qx app \
   && $DC exec -T app sh -c 'grep -Eq "^SITEX_INSTALLED=(true|1)" /var/www/config/.env' 2>/dev/null; then
  if $DC exec -T app sh -c 'grep -Eq "^SITEX_BACKUP_ENCRYPTION_KEY=.+" /var/www/config/.env'; then
    say "Encrypted backup (+verify)"
    $DC exec -T app artisan sitex:backup:run --verify || die "Backup failed — nothing was changed."
  else
    echo "WARNING: no SITEX_BACKUP_ENCRYPTION_KEY yet (docs/DOCKER.md §3.3) — upgrading WITHOUT a backup."
  fi
fi

# 2. Version + pull
if [ -n "$NEW" ]; then
  if grep -qE '^SITEX_VERSION=' .env; then
    sed -i "s/^SITEX_VERSION=.*/SITEX_VERSION=$NEW/" .env
  else
    echo "SITEX_VERSION=$NEW" >> .env
  fi
fi
say "Pulling images"
if [ "${SITEX_SKIP_PULL:-0}" != "1" ]; then
  if ! $DC pull app horizon scheduler; then
    [ -n "$NEW" ] && sed -i "s/^SITEX_VERSION=.*/SITEX_VERSION=$OLD/" .env
    die "Pull failed (docker login ghcr.io? version exists?) — nothing was changed."
  fi
fi

# 3. Recreate containers (volumes are kept)
say "Restarting containers"
$DC up -d

# 4. Wait for the app's pre-flight
say "Waiting for the app (pre-flight / migrations)"
since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
for _ in $(seq 1 60); do
  logs=$($DC logs --since "$since" app 2>/dev/null || true)
  if grep -q 'maintenance mode' <<< "$logs"; then
    echo "$logs" | grep '\[sitex\]' | tail -n 8
    die "Upgrade to $TARGET was BLOCKED; the site is in maintenance mode. Go back with:  sudo $SELF $OLD"
  fi
  if grep -qE 'Database is up to date|Not installed' <<< "$logs" \
     || { grep -q 'Running additive migrations' <<< "$logs" && grep -q 'supervisord started' <<< "$logs"; }; then
    curl -fsS "$API/up" > /dev/null 2>&1 && break
  fi
  sleep 5
done
curl -fsS "$API/up" > /dev/null 2>&1 || die "App did not come up — see: docker compose logs app"

$DC ps
say "Done: SiteX Post $TARGET is running. Previous version: $OLD (go back: sudo $SELF $OLD)"
