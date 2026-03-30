#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE=docker-compose.test.yml
DB_HOST=db:3306
DB_NAME=local
DB_USER=root
DB_PASSWORD=root

export WP_TEST_DB_HOST="$DB_HOST"
export WP_TEST_DB_NAME="$DB_NAME"
export WP_TEST_DB_USER="$DB_USER"
export WP_TEST_DB_PASSWORD="$DB_PASSWORD"

echo "Starting test DB, Redis and PHP runner..."
docker-compose -f "$COMPOSE_FILE" up -d --build db redis php

echo "Waiting for MySQL to become available inside container..."
RETRIES=60
until docker-compose -f "$COMPOSE_FILE" exec -T db mysql -h 127.0.0.1 -u"$DB_USER" -p"$DB_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; do
  ((RETRIES--)) || { echo "MySQL did not start in time"; docker-compose -f "$COMPOSE_FILE" logs db; docker-compose -f "$COMPOSE_FILE" down -v; exit 1; }
  sleep 1
done

echo "MySQL is up. Installing Composer dependencies inside PHP container (if needed) (skip composer scripts to avoid core installer)..."
# Avoid running Composer scripts (which run johnpbloch/wordpress core installer that creates ./wordpress)
if [ -d "vendor" ]; then
  echo "vendor/ exists on host — skipping composer install inside container"
else
  echo "vendor/ not found — installing dependencies inside PHP container (this may install WordPress core)"
  docker-compose -f "$COMPOSE_FILE" run --rm php composer install --no-interaction --prefer-dist || true
fi

echo "Running PHPUnit inside PHP container..."
# Pass DB env vars through to the container run command
docker-compose -f "$COMPOSE_FILE" run --rm -e WP_TEST_DB_HOST="$WP_TEST_DB_HOST" -e WP_TEST_DB_NAME="$WP_TEST_DB_NAME" -e WP_TEST_DB_USER="$WP_TEST_DB_USER" -e WP_TEST_DB_PASSWORD="$WP_TEST_DB_PASSWORD" php vendor/bin/phpunit "$@"

RESULT=$?

echo "Tearing down test DB and PHP containers..."
docker-compose -f "$COMPOSE_FILE" down -v

exit $RESULT
