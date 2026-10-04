# syntax=docker/dockerfile:1.7
# Carnet DevOps — image unique : web (FrankenPHP), worker et scheduler.
# Construite par /app/vps-platform/bin/deploy.sh, une image par commit.

# ── 1. Dépendances PHP (sans les outils de dev) ─────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --ignore-platform-req=php : l'image composer a sa propre version de PHP ;
# le lockfile est résolu pour celle de l'image finale (8.4).
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist \
        --optimize-autoloader --ignore-platform-req=php

# ── 2. Application : FrankenPHP (serveur web + PHP dans un seul processus) ─
FROM dunglas/frankenphp:1-php8.4-alpine AS app

RUN install-php-extensions pdo_pgsql redis opcache intl pcntl

# Pas de HTTPS ici : nginx-proxy s'en charge. FrankenPHP écoute en HTTP sur 8000.
ENV SERVER_NAME=:8000

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions \
        storage/framework/views bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

EXPOSE 8000
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "php-server", "--listen", ":8000", "--root", "/app/public"]
