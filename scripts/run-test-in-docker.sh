#!/usr/bin/env bash
set -euo pipefail

# Runner: start test DB and execute the manual DB optimizer test inside the php service
# Usage: ./scripts/run-test-in-docker.sh

COMPOSE_FILE="docker-compose.test.yml"

echo "Starting test DB..."
docker-compose -f "$COMPOSE_FILE" up -d db

echo "Waiting for DB to be healthy (mysqladmin ping)..."
until docker-compose -f "$COMPOSE_FILE" exec -T db mysqladmin ping -h 127.0.0.1 -uroot -proot >/dev/null 2>&1; do
  printf '.'
  sleep 1
done

echo "DB is healthy. Running test in php container..."

# Temporarily override .env so wp-config.php picks correct DB_HOST inside container
BACKUP_ENV=".env.bak-for-test"
TEMP_ENV=".env.test-run"
cat > "$TEMP_ENV" <<EOF
DB_NAME='local'
DB_USER='root'
DB_PASSWORD='root'
DB_HOST='db'
WP_ENV='local'
EOF

if [ -f .env ]; then
  mv .env "$BACKUP_ENV"
  RESTORE=true
else
  RESTORE=false
fi
mv "$TEMP_ENV" .env

trap 'echo "Restoring .env"; if [ "$RESTORE" = true ]; then mv "$BACKUP_ENV" .env; else rm -f .env; fi' EXIT

docker-compose -f "$COMPOSE_FILE" run --rm php php scripts/test-database-optimizer.php

EXIT_CODE=$?

echo "Test run finished with exit code: $EXIT_CODE"
exit $EXIT_CODE
