#!/usr/bin/env bash
# Build a SiteX Post release archive on a BUILD machine (not on production).
#   scripts/release/build-release.sh https://api.example.vn https://app.example.vn
# Output: dist/release/sitex-post-<version>.tar.gz (+ .sha256) and the WordPress plugin zip.
# The archive never contains .env, storage data, node_modules, tests or demo UI.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
API_URL="${1:?usage: build-release.sh <api-url> <customer-app-url>}"
APP_URL="${2:?usage: build-release.sh <api-url> <customer-app-url>}"
VERSION="$(php -r '$c = require "'"$ROOT"'/backend/config/sitex.php"; echo $c["version"];' 2>/dev/null || true)"
VERSION="${VERSION:-$(grep -oE "'version' => '[0-9.]+'" "$ROOT/backend/config/sitex.php" | head -1 | grep -oE '[0-9.]+')}"
OUT="$ROOT/dist/release"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

grep -q "## \[$VERSION\]" "$ROOT/backend/CHANGELOG.md" || { echo "CHANGELOG.md has no entry for $VERSION" >&2; exit 1; }
if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then echo "Working tree is not clean; commit first." >&2; exit 1; fi

echo "==> Frontend ($API_URL)"
(cd "$ROOT/frontend" && npm ci && VITE_API_BASE_URL="$API_URL" VITE_CUSTOMER_APP_URL="$APP_URL" npm run build && npm run check:no-demo && npm run build:installer-css)

echo "==> WordPress plugin"
"$ROOT/wordpress-plugin/scripts/build-plugin.sh"

echo "==> Backend (composer --no-dev)"
git -C "$ROOT" archive HEAD backend wordpress-plugin/sitex-post deploy docs scripts/deploy | tar -x -C "$STAGE"
cp -r "$ROOT/backend/public/installer/installer.css" "$STAGE/backend/public/installer/installer.css"
mkdir -p "$STAGE/frontend" && cp -r "$ROOT/frontend/dist" "$STAGE/frontend/dist"
mkdir -p "$STAGE/wordpress-plugin/dist" && cp "$ROOT"/wordpress-plugin/dist/sitex-post-*.zip "$STAGE/wordpress-plugin/dist/"
(cd "$STAGE/backend" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
rm -rf "$STAGE/backend/tests" "$STAGE/backend/.env" "$STAGE/backend/storage/logs/"*.log

mkdir -p "$OUT"
NAME="sitex-post-$VERSION"
tar -czf "$OUT/$NAME.tar.gz" -C "$STAGE" --transform "s,^\.,$NAME," .
(cd "$OUT" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
echo "==> $OUT/$NAME.tar.gz"
cat "$OUT/$NAME.tar.gz.sha256"
