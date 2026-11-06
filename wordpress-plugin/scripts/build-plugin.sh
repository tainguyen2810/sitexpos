#!/usr/bin/env bash
# Builds the installable plugin zip: dist/sitex-post-<version>.zip
#   wordpress-plugin/scripts/build-plugin.sh
# Checks: php -l on every file, PHP 7.4 compatibility (needs backend/vendor),
# signer unit test, translations complete; then compiles .mo and zips.
set -euo pipefail
cd "$(dirname "$0")/.."
PLUGIN=sitex-post
VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN/sitex-post.php" | tr -d '[:space:]')

echo "== php -l"
find "$PLUGIN" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
echo "== PHP 7.4 compatibility"
if [ -f ../backend/vendor/autoload.php ]; then php scripts/check-php74.php "$PLUGIN" ../backend/vendor/autoload.php; else echo "skipped (run composer install in backend/)"; fi
echo "== signer unit test"
php tests/unit/SignerTest.php > /dev/null && echo "ok"
echo "== translations"
php scripts/i18n.php pot "$PLUGIN" "$PLUGIN/languages/sitex-post.pot"
php scripts/i18n.php check "$PLUGIN/languages/sitex-post.pot" scripts/translations/vi.php
php scripts/i18n.php po "$PLUGIN/languages/sitex-post.pot" scripts/translations/vi.php "$PLUGIN/languages/sitex-post-vi.po"
php scripts/i18n.php mo "$PLUGIN/languages/sitex-post-vi.po" "$PLUGIN/languages/sitex-post-vi.mo"

mkdir -p dist
OUT="dist/$PLUGIN-$VERSION.zip"
rm -f "$OUT"
if command -v zip > /dev/null; then
  zip -qr "$OUT" "$PLUGIN" -x '*.DS_Store'
else
  python3 -c "import shutil,sys; shutil.make_archive(sys.argv[1][:-4], 'zip', '.', sys.argv[2])" "$OUT" "$PLUGIN"
fi
echo "== built $OUT ($(du -h "$OUT" | cut -f1))"
