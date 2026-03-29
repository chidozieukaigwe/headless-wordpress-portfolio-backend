#!/usr/bin/env bash
set -euo pipefail

# E2E cache check:
# 1) start local redis compose
# 2) wait for redis
# 3) hit WP REST endpoint and health endpoint
# 4) inspect redis keys

WP_URL=${WP_URL:-}
if [ -z "${WP_URL}" ]; then
  # prefer WP_HOME, then WP_SITEURL from .env if present
  if [ -n "${WP_HOME-}" ]; then
    WP_URL="${WP_HOME}"
  elif [ -n "${WP_SITEURL-}" ]; then
    WP_URL="${WP_SITEURL}"
  else
    WP_URL='http://localhost'
  fi
fi
REDIS_COMPOSE_FILE="docker-compose.redis.yml"

# Load .env if present so local HEADLESS_WEBHOOK_SECRET is available to the script
if [ -f .env ]; then
  set -o allexport
  # shellcheck disable=SC1091
  source .env
  set +o allexport
fi

echo "Starting Redis via ${REDIS_COMPOSE_FILE}..."
docker compose -f "${REDIS_COMPOSE_FILE}" up -d

echo "Waiting for Redis to respond..."
for i in {1..10}; do
  if docker compose -f "${REDIS_COMPOSE_FILE}" exec -T redis redis-cli PING >/dev/null 2>&1; then
    echo "Redis is up"
    break
  fi
  echo "Retrying... ($i)"
  sleep 1
  if [ "$i" -eq 10 ]; then
    echo "Redis did not respond in time" >&2
    exit 2
  fi
done

echo "Hitting REST endpoint to populate cache..."
curl -s -w "\nTook: %{time_total}s\n" "${WP_URL}/wp-json/wp/v2/posts?per_page=1" -o /tmp/e2e_response.json

echo "Calling health endpoint..."
# Send secret header when available; permission callback allows localhost or secret
if [ -n "${HEADLESS_WEBHOOK_SECRET-}" ]; then
  curl -s -H "X-Webhook-Secret: ${HEADLESS_WEBHOOK_SECRET}" "${WP_URL}/wp-json/headless-cache/v1/health" | jq || true
else
  curl -s "${WP_URL}/wp-json/headless-cache/v1/health" | jq || true
fi

echo "Listing Redis keys (headless prefix)..."
docker compose -f "${REDIS_COMPOSE_FILE}" exec -T redis redis-cli --raw KEYS "headless*" || true

echo "If keys exist, show TTL and a sample value for the first key"
first_key=$(docker compose -f "${REDIS_COMPOSE_FILE}" exec -T redis redis-cli --raw KEYS "headless*" | head -n1 || true)
if [ -n "$first_key" ]; then
  echo "Key: $first_key"
  docker compose -f "${REDIS_COMPOSE_FILE}" exec -T redis redis-cli TTL "$first_key"
  echo "Sample value (truncated):"
  docker compose -f "${REDIS_COMPOSE_FILE}" exec -T redis redis-cli GET "$first_key" | head -c 500
  echo
else
  echo "No headless keys found — cache may not have been populated yet. Check WP logs and that REST request returned 200."
fi

echo "E2E cache check complete."
