#!/bin/sh
set -e

# Wait for MySQL
if [ -z "$DB_HOST" ] || [ -z "$DB_PORT" ]; then
  echo "ERROR: DB_HOST or DB_PORT is not set"
  exit 1
fi

echo "Waiting for database at $DB_HOST:$DB_PORT..."
TRIES=0
until nc -z "$DB_HOST" "$DB_PORT"; do
  TRIES=$((TRIES+1))
  if [ "$TRIES" -ge 30 ]; then
    echo "Database unreachable after 30 tries"
    exit 1
  fi
  sleep 2
done
echo "Database is up."

php artisan config:clear
php artisan migrate --force

php-fpm -D

# Railway provides PORT; default 8080
LISTEN_PORT="${PORT:-8080}"
sed -i "s/listen 10000;/listen ${LISTEN_PORT};/g" /etc/nginx/sites-available/default
echo "Starting nginx on port ${LISTEN_PORT}"
exec nginx -g 'daemon off;'