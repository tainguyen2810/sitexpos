#!/usr/bin/env bash
# End-to-end test of the Docker stack (docs/DOCKER.md): install through the web
# installer API, workers start, encrypted backup + verify, restart = no-op upgrade.
# Run from a directory holding docker-compose.yml and a compose .env whose
# SITEX_PUBLIC_* point at http://localhost:808x and MYSQL_PASSWORD is set.
# Usage: scripts/e2e/docker-stack.sh [compose-dir]      (CI: deploy/docker)
set -euo pipefail
cd "${1:-.}"

DC="docker compose"
API=http://127.0.0.1:${SITEX_PORT_API:-8080}
MYSQL_PASSWORD=$(grep -E '^MYSQL_PASSWORD=' .env | cut -d= -f2-)
fail() { echo "FAIL: $*" >&2; $DC ps; $DC logs --no-color --tail=80 app horizon; exit 1; }
step() { echo "==> $*"; }

step "Stack up"
$DC up -d
for _ in $(seq 1 60); do curl -fsS "$API/up" > /dev/null 2>&1 && break; sleep 3; done
curl -fsS "$API/up" > /dev/null || fail "API /up"
curl -fsS "$API/install" | grep -q 'data-deployment="docker"' || fail "installer page"
curl -fsS "http://127.0.0.1:${SITEX_PORT_APP:-8081}/config.js" | grep -q '"apiBaseUrl":"http' || fail "config.js"
curl -fsS "http://127.0.0.1:${SITEX_PORT_ADMIN:-8082}/deep/link" | grep -q '<div id="app">' || fail "SPA fallback"

step "Installer (via its API, as the wizard does)"
TOKEN=$($DC exec -T app artisan sitex:install:token | grep -oE '^[A-Za-z0-9_-]{24,}$' | tail -1)
[ -n "$TOKEN" ] || fail "setup token"
call() { # method path json
  curl -fsS -X "$1" "$API/install/api/$2" -H "X-Setup-Token: $TOKEN" -H 'Accept: application/json' \
    -H 'Content-Type: application/json' -H 'X-Forwarded-Proto: https' ${3:+-d "$3"}
}
call GET environment | grep -q '"can_continue":true' || fail "environment checks"
call GET environment | grep -q '"key":"https","group":"server","status":"PASS"' || fail "trusted proxy (X-Forwarded-Proto)"
call PUT database "{\"host\":\"mysql\",\"port\":3306,\"database\":\"sitexpost\",\"username\":\"sitexpost\",\"password\":\"$MYSQL_PASSWORD\"}" | grep -q '"version_ok":true' || fail "database"
call PUT application '{"app_name":"SiteX Post","app_url":"http://localhost:8080","frontend_url":"http://localhost:8081","admin_url":"http://localhost:8082","locale":"vi","timezone":"Asia/Ho_Chi_Minh","mail":{"mailer":"log"}}' > /dev/null || fail "application"
call PUT queue '{"driver":"redis","redis_host":"redis","redis_port":6379}' | grep -q '"connected":true' || fail "redis"
call PUT admin '{"name":"E2E Admin","email":"owner@example.test","password":"Str0ng!Passw0rd#2026","password_confirmation":"Str0ng!Passw0rd#2026"}' > /dev/null || fail "admin"
call POST run | grep -q '"status":"completed"' || fail "installation run"
STATUS=$(curl -s -o /dev/null -w '%{http_code}' "$API/install")
[ "$STATUS" = "403" ] || fail "installer must be locked after install (got $STATUS)"

step "Workers start by themselves"
for _ in $(seq 1 40); do $DC logs horizon 2>/dev/null | grep -q 'Starting Horizon' && break; sleep 3; done
$DC exec -T app artisan horizon:status | grep -q 'running' || fail "horizon"
$DC logs scheduler | grep -q 'Starting the scheduler' || fail "scheduler"

if [ "${SKIP_BACKUP:-0}" != "1" ]; then
step "Encrypted backup (MariaDB client against MySQL 8.4) + verify"
KEY_LINE=$($DC exec -T app artisan sitex:backup:key | grep -E '^SITEX_BACKUP_ENCRYPTION_KEY=')
$DC exec -T app sh -c "echo '$KEY_LINE' >> /var/www/config/.env"
$DC restart app horizon scheduler
for _ in $(seq 1 40); do curl -fsS "$API/up" > /dev/null 2>&1 && break; sleep 3; done
$DC exec -T app artisan sitex:backup:run --verify || fail "backup"
fi

step "Restart = upgrade pre-flight, nothing to migrate, site stays up"
$DC restart app
for _ in $(seq 1 40); do curl -fsS "$API/up" > /dev/null 2>&1 && break; sleep 3; done
$DC logs app | grep -q 'Database is up to date' || fail "pre-flight on restart"
curl -fsS "$API/up" > /dev/null || fail "site down after restart"
$DC exec -T app sh -c '[ ! -f /var/www/html/backend/storage/framework/down ]' || fail "maintenance mode left on"

echo "Docker stack E2E: OK"
