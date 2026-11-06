#!/bin/sh
# SiteX Post container entrypoint (docs/DOCKER.md).
#
# Roles (the container command):
#   web        Nginx + PHP-FPM. Before installation only the /install wizard works.
#              After installation, on every start: pre-flight (sitex:upgrade:check),
#              additive migrations only, caches. A blocked upgrade (destructive
#              migration, failed requirement, failed backup) puts the site in
#              maintenance mode instead of migrating.
#   horizon    Queue workers. Waits until installed and migrated.
#   scheduler  `schedule:work`. Waits until installed and migrated.
#   anything else is executed as www-data (e.g. `php artisan about`).
#
# It never writes .env (the installer does), never runs a destructive migration and
# never restores a backup.
set -eu

APP=/var/www/html/backend
ENV_DIR="${SITEX_ENV_DIR:-/var/www/config}"
ENV_FILE="$ENV_DIR/.env"
DOWN_MARK="$APP/storage/framework/sitex-docker-down"
ROLE="${1:-web}"

log() { echo "[sitex] $*"; }
as_www() { su-exec www-data "$@"; }
artisan() { (cd "$APP" && as_www php artisan "$@"); }

installed() {
  [ -f "$ENV_FILE" ] && grep -Eq '^SITEX_INSTALLED=(true|1)[[:space:]]*$' "$ENV_FILE"
}

prepare_dirs() {
  mkdir -p "$ENV_DIR" \
    "$APP/storage/app/public" \
    "$APP/storage/framework/cache/data" \
    "$APP/storage/framework/sessions" \
    "$APP/storage/framework/views" \
    "$APP/storage/logs" \
    "$APP/bootstrap/cache"
  chown www-data:www-data "$ENV_DIR"
  chmod 0750 "$ENV_DIR"
  # Fix only what is wrong (cheap on large volumes); `docker compose exec` as root may
  # have left root-owned files behind.
  find "$APP/storage" "$APP/bootstrap/cache" "$ENV_DIR" ! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
}

write_frontend_config() {
  if [ -z "${SITEX_PUBLIC_API_URL:-}" ]; then
    log "WARNING: SITEX_PUBLIC_API_URL is empty — the SPAs will call the API on their own domain."
  fi
  # json_encode keeps the values safe inside the script (no quote/tag injection).
  php -r '
    $c = ["apiBaseUrl" => (string) getenv("SITEX_PUBLIC_API_URL"), "customerAppUrl" => (string) getenv("SITEX_PUBLIC_APP_URL")];
    echo "window.__SITEX_CONFIG__ = ", json_encode($c, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ";\n";
  ' > /tmp/sitex-config.js
  for app in customer admin; do
    cp /tmp/sitex-config.js "/var/www/html/frontend/dist/$app/config.js"
    chmod 0644 "/var/www/html/frontend/dist/$app/config.js"
  done
}

backup_key_set() {
  [ -f "$ENV_FILE" ] && grep -Eq '^SITEX_BACKUP_ENCRYPTION_KEY=.+' "$ENV_FILE"
}

block_upgrade() {
  log "!! $1"
  log "!! The site is in maintenance mode. Go back to the previous image (SITEX_VERSION in .env,"
  log "!! then: docker compose up -d) or follow docs/UPGRADE-GUIDE.md."
  artisan down --retry=60 || true
  touch "$DOWN_MARK"
  chown www-data:www-data "$DOWN_MARK"
}

upgrade_if_needed() {
  artisan optimize:clear > /dev/null 2>&1 || true
  set +e
  artisan sitex:upgrade:check
  rc=$?
  set -e
  case "$rc" in
    0)
      if backup_key_set; then
        log "Pending migrations: creating an encrypted backup first"
        if ! artisan sitex:backup:run --verify; then
          block_upgrade "Backup failed; migrations were NOT run."
          return
        fi
      else
        log "WARNING: SITEX_BACKUP_ENCRYPTION_KEY is not set — migrating without a backup."
      fi
      log "Running additive migrations"
      if ! artisan migrate --force; then
        block_upgrade "Migration failed."
        return
      fi
      artisan sitex:upgrade:check --record || true
      ;;
    1)
      log "Database is up to date."
      ;;
    *)
      block_upgrade "Upgrade blocked by pre-flight (destructive migration or requirements)."
      return
      ;;
  esac
  if [ -f "$DOWN_MARK" ]; then
    artisan up || true
    rm -f "$DOWN_MARK"
  fi
  artisan optimize > /dev/null || log "WARNING: artisan optimize failed; running without caches."
}

wait_until_ready() {
  while :; do
    if installed; then
      set +e
      artisan sitex:upgrade:check > /dev/null 2>&1
      rc=$?
      set -e
      [ "$rc" -eq 1 ] && break
      log "Waiting for the web container to finish the upgrade (pre-flight code $rc)..."
    else
      log "Not installed yet — open https://<api-domain>/install. Checking again in 15s."
    fi
    sleep 15
  done
  artisan optimize > /dev/null 2>&1 || true
}

prepare_dirs

case "$ROLE" in
  web)
    write_frontend_config
    if installed; then
      upgrade_if_needed
    else
      log "Not installed. Open https://<api-domain>/install"
      log "Setup token: docker compose exec app artisan sitex:install:token"
    fi
    exec supervisord -c /etc/supervisord.conf
    ;;
  horizon)
    wait_until_ready
    log "Starting Horizon"
    cd "$APP"
    exec su-exec www-data php artisan horizon
    ;;
  scheduler)
    wait_until_ready
    log "Starting the scheduler"
    cd "$APP"
    exec su-exec www-data php artisan schedule:work
    ;;
  *)
    cd "$APP"
    exec su-exec www-data "$@"
    ;;
esac
