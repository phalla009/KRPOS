#!/bin/sh
HOST="${DB_HOST:-127.0.0.1}"
PORT="${DB_PORT:-3306}"

if [ -z "$DB_HOST" ]; then
  echo "ERROR: DB_HOST is not set. Add it in Render Environment."
  exit 1
fi

echo "Waiting for database at $HOST:$PORT..."
TRIES=0
until nc -z "$HOST" "$PORT"; do
  TRIES=$((TRIES+1))
  if [ "$TRIES" -ge 30 ]; then
    echo "Database not reachable after 30 tries, exiting."
    exit 1
  fi
  sleep 2
done
echo "Database is up."