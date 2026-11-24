#!/bin/sh
set -e

# --- SQLite: create the file if needed ---
if [ "$DB_CONNECTION" = "sqlite" ]; then
  DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
  mkdir -p "$(dirname "$DB_FILE")"
  touch "$DB_FILE"
  chown -R www-data:www-data "$(dirname "$DB_FILE")"
fi

# --- MySQL: wait for the DB ---
if [ "$DB_CONNECTION" = "mysql" ]; then
  if [ -z "$DB_HOST" ] || [ -z "$DB_PORT" ]; then
    echo "ERROR: DB_HOST or DB_PORT is not set"; exit 1
  fi
  echo "Waiting for database at $DB_HOST:$DB_PORT..."
  TRIES=0
  until nc -z "$DB_HOST" "$DB_PORT"; do
    TRIES=$((TRIES+1))
    [ "$TRIES" -ge 30 ] && echo "Database unreachable" && exit 1
    sleep 2
  done
fi

php artisan config:clear
php artisan migrate --force

php-fpm -D

# Use the port that Railway provides
sed -i "s/listen 10000;/listen ${PORT};/g" /etc/nginx/sites-available/default
exec nginx -g 'daemon off;'