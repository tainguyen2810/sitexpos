#!/usr/bin/env bash
# Runs every quality gate. Usage: scripts/check.sh   (from the repo root or anywhere)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Backend: Pint"
(cd "$ROOT/backend" && vendor/bin/pint --test)
echo "==> Backend: Larastan"
(cd "$ROOT/backend" && vendor/bin/phpstan analyse --memory-limit=1G --no-progress)
echo "==> Backend: Pest"
(cd "$ROOT/backend" && vendor/bin/pest)

echo "==> Frontend: lint"
(cd "$ROOT/frontend" && npm run --silent lint)
echo "==> Frontend: typecheck"
(cd "$ROOT/frontend" && npm run --silent typecheck)
echo "==> Frontend: unit tests"
(cd "$ROOT/frontend" && npm run --silent test)
echo "==> Frontend: build"
(cd "$ROOT/frontend" && npm run --silent build)
echo "==> Frontend: no demo code in production build"
(cd "$ROOT/frontend" && npm run --silent check:no-demo)

echo "All checks passed."
