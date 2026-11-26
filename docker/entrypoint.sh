#!/bin/sh
set -e
cd /var/www/html

# Seul le conteneur web prépare la base ; le worker et le planificateur attendent qu'elle soit prête.
if [ "$1" = "apache2-foreground" ]; then
  echo "Attente de PostgreSQL et application des migrations…"
  for i in $(seq 1 30); do
    php artisan migrate --force && break
    echo "Base indisponible (tentative $i/30)"; sleep 2
  done
  if [ "${SEED_DEMO:-false}" = "true" ]; then php artisan ged:install-demo; fi
  php artisan config:cache && php artisan route:cache && php artisan view:cache
  chown -R www-data:www-data storage
else
  sleep 10
fi

exec "$@"
