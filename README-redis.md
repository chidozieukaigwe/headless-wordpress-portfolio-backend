# Local Redis for Headless Cache

This project includes a minimal Docker Compose file to run Redis locally for development.

Start Redis:

```bash
docker compose -f docker-compose.redis.yml up -d
```

Stop Redis:

```bash
docker compose -f docker-compose.redis.yml down
```

## Configuration

Add the following to your `.env` (or ensure these constants are set in `wp-config.php`):

```
WP_REDIS_HOST=127.0.0.1
WP_REDIS_PORT=6379
WP_REDIS_PREFIX=headless_
WP_REDIS_TIMEOUT=1
WP_REDIS_READ_TIMEOUT=1
```

## Notes

- The mu-plugin uses the WordPress Cache API (`wp_cache_get` / `wp_cache_set`) by default; when an object-cache drop-in is present and backed by Redis, cached REST responses will persist to Redis.
- On macOS/Local: mapping the container port `6379:6379` allows the host environment (WordPress served by Local) to connect to `127.0.0.1:6379`.
- If `phpredis` is not enabled in your PHP runtime, the object-cache drop-in may operate in graceful fallback mode (health endpoint may show `redis: not-connected`). You can either enable `phpredis` in your PHP runtime or run PHP in a container/runtime that has `redis` available.

Quick verification:

1. Start Redis with the compose file above.
2. Hit the health endpoint:

```bash
curl --resolve chidodesigns.local:80:127.0.0.1 http://chidodesigns.local/wp-json/headless-cache/v1/health | jq
```

3. If `wp_cache` is `ok` and `redis` is `ok`, the mu-plugin is persisting responses to Redis.
