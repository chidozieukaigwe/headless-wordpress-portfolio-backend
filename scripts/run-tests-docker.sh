#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE=docker-compose.test.yml
DB_HOST=127.0.0.1:3307
DB_NAME=local
DB_USER=root
DB_PASSWORD=root

export WP_TEST_DB_HOST="$DB_HOST"
export WP_TEST_DB_NAME="$DB_NAME"
export WP_TEST_DB_USER="$DB_USER"
export WP_TEST_DB_PASSWORD="$DB_PASSWORD"

echo "Starting test DB container..."
docker-compose -f "$COMPOSE_FILE" up -d

echo "Waiting for MySQL to become available..."
# Wait until mysql responds to a simple query
RETRIES=60
until docker-compose -f "$COMPOSE_FILE" exec -T db mysql -h 127.0.0.1 -u"$DB_USER" -p"$DB_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; do
  ((RETRIES--)) || { echo "MySQL did not start in time"; docker-compose -f "$COMPOSE_FILE" logs db; exit 1; }
  sleep 1
done

echo "MySQL is up. Running PHPUnit..."

# Run phpunit with the environment variables defined above
WP_TESTS_ENV_VARS=(
  "WP_TEST_DB_HOST=$WP_TEST_DB_HOST"
  "WP_TEST_DB_NAME=$WP_TEST_DB_NAME"
  "WP_TEST_DB_USER=$WP_TEST_DB_USER"
  "WP_TEST_DB_PASSWORD=$WP_TEST_DB_PASSWORD"
)

# Run phpunit
"${WP_TESTS_ENV_VARS[@]}" ./vendor/bin/phpunit "$@"

RESULT=$?

echo "Tearing down test DB container..."
docker-compose -f "$COMPOSE_FILE" down -v

exit $RESULT
