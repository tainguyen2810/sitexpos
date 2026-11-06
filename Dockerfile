# syntax=docker/dockerfile:1
# SiteX Post Cloud — one image for the API, both SPAs, Horizon and the scheduler.
# Usage and operations: docs/DOCKER.md. The image never contains a .env or any secret;
# the installer writes .env to the /var/www/config volume.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---- 1. Frontend (customer + admin SPAs, built without any domain baked in) ----
FROM node:${NODE_VERSION}-alpine AS frontend
WORKDIR /src/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build && npm run check:no-demo

# ---- 2. PHP base: extensions + web server + process manager ----
FROM php:${PHP_VERSION}-fpm-alpine AS base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath gd intl pcntl pdo_mysql redis zip \
    && apk add --no-cache nginx supervisor su-exec mariadb-client mariadb-connector-c tzdata \
    && rm -f /usr/local/etc/php-fpm.d/zz-docker.conf \
    && mkdir -p /etc/my.cnf.d \
    && printf '[client]\n# MySQL 8.4 in the compose network uses a self-signed certificate.\nssl-verify-server-cert = off\n' > /etc/my.cnf.d/zz-sitex-client.cnf

# ---- 3. Composer dependencies (production only) ----
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip
WORKDIR /var/www/html/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && rm -f .env

# ---- 4. Runtime ----
FROM base AS runtime
WORKDIR /var/www/html/backend
COPY --from=vendor /var/www/html/backend /var/www/html/backend
COPY --from=frontend /src/frontend/dist /var/www/html/frontend/dist
# Plugin source: the API serves it as a zip pre-configured with this Cloud's URL.
COPY wordpress-plugin/sitex-post /var/www/html/wordpress-plugin/sitex-post
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx-spa.conf /etc/nginx/sitex-spa.conf
COPY docker/nginx-noindex.conf /etc/nginx/sitex-noindex.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-sitex.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-sitex.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/sitex-entrypoint
COPY docker/artisan.sh /usr/local/bin/artisan
# Code is root-owned and read-only for the web user; only storage/, bootstrap/cache
# and the config volume are writable.
RUN chmod 0755 /usr/local/bin/sitex-entrypoint /usr/local/bin/artisan \
    && mkdir -p /var/www/config /run/nginx \
    && chown -R www-data:www-data /var/www/config storage bootstrap/cache \
    && chmod 0750 /var/www/config

ARG SITEX_IMAGE_VERSION=dev
# Backups use the MariaDB client tools (Alpine has no Oracle MySQL client) against
# MySQL 8.4: mariadb-connector-c provides the caching_sha2_password client plugin and
# BackupManager falls back to a dump without --set-gtid-purged.
ENV SITEX_DEPLOYMENT=docker \
    SITEX_ENV_DIR=/var/www/config \
    SITEX_MYSQLDUMP_PATH=mariadb-dump \
    SITEX_MYSQL_PATH=mariadb \
    SITEX_IMAGE_VERSION=${SITEX_IMAGE_VERSION}
LABEL org.opencontainers.image.title="SiteX Post Cloud" \
      org.opencontainers.image.source="https://github.com/tainguyen2810/sitexpos" \
      org.opencontainers.image.version="${SITEX_IMAGE_VERSION}"

# 8080 = API (backend/public), 8081 = customer SPA, 8082 = admin SPA
EXPOSE 8080 8081 8082
ENTRYPOINT ["sitex-entrypoint"]
CMD ["web"]
