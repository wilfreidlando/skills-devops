#!/bin/sh
# Démarrage commun aux conteneurs app, worker et scheduler.
# Les commandes ponctuelles (sh -c "php artisan migrate", lancées par deploy.sh)
# passent directement.
set -e

mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions \
    storage/framework/views bootstrap/cache

case "$1" in
    frankenphp|php)
        # Configuration, routes, vues et événements en cache : lus une fois au
        # démarrage, pas à chaque requête ni à chaque job.
        php artisan config:cache >/dev/null
        php artisan route:cache >/dev/null
        php artisan view:cache >/dev/null
        php artisan event:cache >/dev/null
        ;;
esac

exec "$@"
