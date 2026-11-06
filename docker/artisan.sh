#!/bin/sh
# `docker compose exec app artisan <command>` — always runs as www-data so files
# written under storage/ (logs, cache) stay writable for PHP-FPM.
cd /var/www/html/backend || exit 1
if [ "$(id -u)" = "0" ]; then
  exec su-exec www-data php artisan "$@"
fi
exec php artisan "$@"
