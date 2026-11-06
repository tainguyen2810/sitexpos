#!/usr/bin/env bash
# Upgrade an installed SiteX Post (git checkout layout from docs/DEPLOYMENT.md) to a tag.
# Run over SSH as the web user, never as root:   sudo -u www scripts/deploy/upgrade.sh v1.1.0
# What it does: backup (+verify) → pre-flight (refuses destructive migrations) → maintenance
# mode → checkout tag → composer → pre-flight again → migrate (additive only) → caches →
# restart workers → health check → up. It never drops data and never touches APP_KEY/.env.
set -euo pipefail

TAG="${1:?usage: upgrade.sh <git-tag>}"
APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ART="php $APP_DIR/backend/artisan"
PREV="$(git -C "$APP_DIR" describe --tags --always)"

[ "$(id -u)" -eq 0 ] && { echo "Do not run as root. Use: sudo -u www $0 $TAG" >&2; exit 1; }
[ -n "$(git -C "$APP_DIR" status --porcelain -- backend frontend)" ] && { echo "Local changes in the checkout; aborting." >&2; exit 1; }

echo "==> Current: $PREV → target: $TAG"
git -C "$APP_DIR" fetch --tags --quiet
git -C "$APP_DIR" rev-parse -q --verify "refs/tags/$TAG" > /dev/null || { echo "Tag $TAG not found" >&2; exit 1; }

echo "==> Backup (encrypted, verified)"
$ART sitex:backup:run --verify

DOWN=0
fail() {
  echo "!! Upgrade failed at: $1" >&2
  echo "   Code rollback: git -C $APP_DIR checkout $PREV && (cd $APP_DIR/backend && composer install --no-dev -o) && $ART optimize" >&2
  echo "   Database: migrations are additive, the old code keeps working. Restore a backup only after reading docs/ROLLBACK-PLAN.md." >&2
  [ "$DOWN" -eq 1 ] && echo "   The site is still in maintenance mode. After fixing: $ART up" >&2
  exit 1
}

echo "==> Maintenance mode"
$ART down --retry=60 || fail "artisan down"
DOWN=1

echo "==> Checkout $TAG"
git -C "$APP_DIR" checkout --quiet "$TAG" || fail "git checkout"
(cd "$APP_DIR/backend" && composer install --no-dev --optimize-autoloader --no-interaction) || fail "composer install"

echo "==> Pre-flight (new code)"
set +e; $ART sitex:upgrade:check; RC=$?; set -e
case "$RC" in
  0) $ART migrate --force || fail "migrate" ;;
  1) echo "No migrations to run." ;;
  *) fail "pre-flight blocked (destructive migration or requirements) — follow docs/UPGRADE-GUIDE.md" ;;
esac

$ART sitex:upgrade:check --record || fail "record version"
$ART optimize:clear > /dev/null && $ART optimize || fail "optimize"
$ART horizon:terminate || true
$ART up
DOWN=0
echo "==> Health"
$ART sitex:system:monitor || true
echo "Done: $PREV → $TAG."
