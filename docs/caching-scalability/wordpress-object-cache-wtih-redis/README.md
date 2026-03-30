# WordPress Object Cache with Redis — Headless Caching & Scalability

This document explains a simple Redis-backed object cache and request-response caching approach for a Headless WordPress CMS with a Next.js frontend. It is written for developers new to caching.

**What these files do**

- `HeadlessCacheManager` (provided in `functions.php` in the attachment):
  - Connects to Redis and provides helpers to cache REST API responses, retrieve cached responses, and invalidate cache when content changes.
  - Key methods:
    - `cache_api_response($request, $response)` — serializes and stores GET responses in Redis with a TTL determined by route type.
    - `get_cached_response($request)` — looks up the cached serialized response and returns it if present.
    - `invalidate_content_cache($post_id)` — removes post-specific and listing caches when content changes and triggers a frontend cache purge webhook.
    - `get_cache_key($request)` — builds a cache key from route + params (strips auth params).
    - `calculate_ttl($request)` — chooses TTL per route (posts/pages/projects/default).
    - `trigger_frontend_cache_purge($post_id)` — non-blocking `wp_remote_post()` to notify the frontend to revalidate.
- `wp-config` constants (attachment):
  - Configure Redis connection and behavior (host/port, persistent connections, compression, TTL defaults).
  - These are used by Redis client libraries or object-cache drop-ins to connect and tune behavior.

**How it works — high level**

1. On a REST GET request the caching layer first checks Redis for a cached response for that exact route + query params.
2. If found, the cached response is returned immediately (avoids PHP/DB work and reduces latency).
3. If not found, WordPress handles the request normally; after the response is generated, `HeadlessCacheManager::cache_api_response()` stores the response in Redis with a TTL.
4. When content changes in the CMS (post save, update, delete), `invalidate_content_cache()` deletes relevant keys and optionally fires a webhook to the Next.js frontend so it can revalidate or purge its static cache.

**Diagram (flow)**

```mermaid
flowchart LR
  A[Browser / Next.js ISR request] -->|HTTP GET| B[Edge / CDN]
  B -->|miss or purged| C[Next.js frontend (server)]
  B -->|cache hit (fast)| D[Cached HTML/JSON]
  C -->|requests REST API JSON| E[WordPress REST API]
  E --> F{Redis cache lookup}
  F -- hit --> G[Return cached JSON]
  F -- miss --> H[WP generates response]
  H --> I[Send response; store serialized result in Redis]
  I --> G

  subgraph Admin/Content changes
    X[Editor saves post] --> J[WP triggers save_post hooks]
    J --> K[HeadlessCacheManager::invalidate_content_cache]
    K --> L[Redis: delete keys]
    K --> M[Webhook to Next.js / Purge CDN]
  end
```

**Impact of implementing this**

- Positive:
  - Major latency reductions for repeated requests: cached JSON is served without running PHP/DB.
  - Lower origin CPU and DB load, improving scalability.
  - Better frontend performance (Next.js ISR or on-demand revalidation can rely on faster API responses).
  - Fine-grained TTLs let you balance freshness vs. performance.
- Tradeoffs / costs:
  - Cache invalidation complexity — careful invalidation logic is required when related content changes.
  - Extra operational dependency: Redis must be provisioned, monitored, and secured.
  - Consistency vs. freshness: short TTLs improve freshness but reduce hit rate; long TTLs increase staleness risk.
  - Serialization format and size: large responses use memory; consider storing compact payloads or only key fragments.

**Failure modes and mitigations**

- Redis outage: gracefully fall back to dynamic responses (avoid hard failure). The `HeadlessCacheManager` should catch Redis exceptions and let WP serve responses normally.
- Stale content: make sure `invalidate_content_cache()` is called for all content-changing hooks; use webhooks to prompt frontend revalidation for important pages.
- Cache key collisions: include route + canonicalized query params + locale + preview flag in keys.

**How I would implement this in this repository**

1. Provision Redis for each environment (local/dev/test/staging/prod). For local development use Docker Compose with a Redis service.

2. PHP Redis client and WordPress object-cache plugin:
   - Install either the `phpredis` PHP extension or a pure-PHP client (`predis/predis`) depending on hosting.
   - Use a well-tested WordPress object-cache drop-in (e.g., `tillkruss/redis-cache` or `rmccue/redis-cache`) or provide a minimal `object-cache.php` that uses `WP_REDIS_*` constants (see attached `wp-config` snippet).

3. Add `HeadlessCacheManager` into a mu-plugin or an autoloaded library (so caching is available early):
   - Place the class in `wp-content/mu-plugins/headless-cache/headless-cache.php` or a PSR-4 autoloaded class in a plugin.
   - Ensure the class uses a resilient Redis connection (catch exceptions) and exposes the `get_cached_response()` and `cache_api_response()` methods.

4. Hook into REST flow to serve cached responses and populate cache on miss:
   - On request start, check for a cached response and short-circuit if present. Example hook points: an early REST server filter or `rest_pre_dispatch`/`rest_post_dispatch` depending on your implementation.
   - After the response is generated, call `cache_api_response()` for GET requests.

5. Invalidate cache on content change:
   - Hook `save_post`, `delete_post`, and taxonomy hooks to call `invalidate_content_cache($post_id)`.
   - Invalidate listing pages, post-specific keys, homepage and sitemap keys as appropriate.

6. Integrate with Next.js revalidation:
   - When invalidating, fire a webhook to the Next.js app (as in `trigger_frontend_cache_purge`) that calls Next.js revalidation endpoints or your CDN purge API.
   - Secure the webhook with a shared secret header (as shown in the `HeadlessCacheManager` attachment).

7. Monitoring, metrics and safety:
   - Track cache hit/miss rate and average TTLs in logs or a metrics system.
   - Fail open: if Redis is unreachable, return dynamic responses instead of failing the request.

**Example integration checklist (concrete steps)**

1. Add Redis to Docker Compose for local development:

```yaml
services:
  redis:
    image: redis:7
    ports:
      - "6379:6379"
```

2. Add `WP_REDIS_*` constants to your `wp-config.php` (see attachment) or use environment variables.

3. Add `headless-cache.php` mu-plugin with `HeadlessCacheManager` and the following hooks (example conceptual snippets):

```php
// On REST request — try to serve from cache
add_filter('rest_request_before_callbacks', function($server, $request) {
  $cached = HeadlessCacheManager::getInstance()->get_cached_response($request);
  if ($cached) {
    return rest_ensure_response($cached);
  }
  return null; // continue
}, 10, 2);

// After dispatch, store cache for GET responses
add_filter('rest_post_dispatch', function($result, $server, $request) {
  HeadlessCacheManager::getInstance()->cache_api_response($request, $result);
  return $result;
}, 10, 3);

// Invalidate on content changes
add_action('save_post', function($post_id) {
  HeadlessCacheManager::getInstance()->invalidate_content_cache($post_id);
});
```

4. Configure Next.js revalidation endpoints and set `headless_frontend_url` + `headless_webhook_secret` via `add_option()` or environment-driven `wp-config` values.

5. Measure and iterate: collect metrics, tune `calculate_ttl()` values, and add finer-grained invalidation if necessary.

**Notes & tips**

- Keep cache keys stable and canonical (order query params, exclude volatile tracking params).
- Consider partial caching: cache structured JSON fragments (e.g., post body) rather than entire payloads if some parts change more often.
- Use Redis namespaces/prefixes by environment to avoid cross-environment collisions.
- For very large responses consider compressing payloads before storing in Redis, or use object-cache for smaller pieces and a separate request cache for full responses.

---

If you'd like, I can:

- add a mu-plugin skeleton implementing `HeadlessCacheManager` wired to REST hooks in this repo, or
- add a Docker Compose Redis service to the developer environment and example `object-cache.php` drop-in.

Prepared by the repository assistant — tell me which follow-up you'd prefer.

---

## Current repository implementation & notes

- The repository now includes a mu-plugin at `wp-content/mu-plugins/headless-cache/headless-cache.php` which implements `HeadlessCacheManager` and the REST caching/invalidation hooks.
- The mu-plugin was updated to prefer the WordPress cache API (`wp_cache_get`/`wp_cache_set`) so cached REST responses are stored through whatever object-cache drop-in is active. If the drop-in persists to Redis (and the PHP runtime provides a Redis client), the responses will be persisted in Redis.
- The mu-plugin still supports a direct Redis client fallback (phpredis or Predis) and exposes a health route `/wp-json/headless-cache/v1/health` that reports both `wp_cache` status and low-level `redis` connectivity.
- `scripts/install-object-cache.php` was updated to prefer the maintained `rhubarbgroup/redis-cache` vendor drop-in and fall back to legacy `tillkruss/redis-cache` paths for compatibility.
- The abandoned `tillkruss/redis-cache` package was removed from `composer.json` and `composer update` was run; any `tillkruss` plugin folder managed by Composer was removed during that step.

FAQ — do I need to install the Redis object-cache plugin via the WordPress UI?

- No — you do not need to install an additional plugin through the WordPress admin UI when using the Composer-managed approach and the `object-cache.php` drop-in. The `object-cache.php` drop-in lives directly in `wp-content/object-cache.php` (installed by `scripts/install-object-cache.php` from the vendor package). When the drop-in is present, WordPress will automatically use `wp_cache_*` functions through that backend.

- If you prefer managing plugins from the admin UI, you can still install the upstream Redis cache plugin there — but it is unnecessary when the drop-in is already deployed at `wp-content/object-cache.php` and configured via `WP_REDIS_*` constants in `wp-config.php`.

Local development note

- On Local by Flywheel the PHP runtime provided by Local may lack build files or the `phpredis` extension, so the drop-in may operate in a graceful fallback mode and the mu-plugin's health endpoint will show `redis: not-connected` while `wp_cache: ok`. This is expected and safe: cached responses will still be served via the drop-in's in-process/cache behavior. To get true Redis persistence locally either run Local with a PHP runtime that has `redis` enabled, or run the site in a Docker container with Redis + phpredis available.

If you want, I can add a short README in the repo root explaining how to enable Redis locally (Docker Compose example) or try switching the Local site to a PHP runtime that has `redis` enabled and re-check the health endpoint.

## Database-level optimizer (MinimalDatabaseOptimizer)

Summary

- `MinimalDatabaseOptimizer` is a small, safe mu-plugin added to this repo to reduce the cost of serving REST responses on cache misses. It does not replace the response cache — it sits under it and reduces DB/Query work when the response cache is missed.
- Location: `wp-content/mu-plugins/database-optimizer/database-optimizer.php` (loaded via `wp-content/mu-plugins/02-database-optimizer-loader.php`).

What it does (high level)

- Caches short-lived lists of post IDs for REST collection responses (default TTL: 120s). These ID lists are stored via `wp_cache_set` so they are persisted by whichever object-cache drop-in is active (Redis when available).
- On subsequent matching collection requests, the plugin short-circuits `WP_Query` by setting `post__in` to the cached IDs and `orderby` to `post__in`, avoiding expensive meta queries and large result set processing.
- Records per-post references for each cached list under keys named `headless:refs:post:{ID}` so invalidation can target only affected caches instead of flushing everything.

Why add it

- The headless response cache (Redis) covers most traffic. When a response miss happens (first request or after invalidation), rebuilding the response may be expensive. `MinimalDatabaseOptimizer` reduces the cost of those cache-miss rebuilds by reusing recent query results.

Design choices (safety-first)

- No schema changes: the implementation does not run `ALTER TABLE` or add indexes automatically. Index creation is separately recommended as a manual/CLI migration step.
- Short TTLs: default 120 seconds for ID lists to limit staleness and keep logic simple. Per-post ref lists are kept longer (1 day) for reliable invalidation without immediate expiry.
- Fail-open: all cache or ref-tracking errors are caught; the plugin never blocks or fails requests.

Integration details

- It hooks into REST flow using `rest_post_dispatch` to record IDs and `rest_post_query` to short-circuit queries on cache hits. The hooks are wired in the mu-plugin loader so they run early.
- It uses `wp_cache_get` / `wp_cache_set` (object-cache API). With an object-cache drop-in that persists to Redis, these cached ID lists and ref keys are backed by Redis.
- Targeted invalidation: on `save_post` and `deleted_post`, the plugin looks up `headless:refs:post:{ID}`, deletes each referenced cache key from the `headless-ids` group, and deletes the ref key. This avoids wide `wp_cache_flush()` calls.

Quick install / enable

1. Ensure object-cache drop-in is present (`wp-content/object-cache.php`) and `WP_REDIS_*` values in `wp-config.php` are set for your environment.
2. The mu-plugin is already in the repo under `wp-content/mu-plugins/database-optimizer/`; it loads automatically when WP boots (mu-plugins are always loaded).
3. Optionally tune the TTL values in the plugin (`$ttl` for IDs, ref TTL when stored) if you need longer cache lifetime.

Testing

- Unit tests can validate cache key generation and the small utility functions. Integration tests run in the project's PHPUnit harness and verify:
  - a REST collection request records per-post refs,
  - saving a post triggers targeted invalidation and removes the referenced cache keys.
- The repo includes `tests/Integration/test-database-optimizer.php` and a dockerized test runner (`scripts/run-tests-docker.sh`) that starts MySQL and Redis to exercise the full object-cache flow.

When to add DB indexes

- Indexes (e.g., on `postmeta(meta_key, meta_value(100))` or `posts(post_type, post_status)`) yield the largest performance gains for heavy meta queries, but they must be applied as a controlled migration (online schema change if available) and not from plugin activation. Document and run index changes during maintenance windows.

Next steps and improvements

- Replace per-post ref arrays with Redis sets (`SADD`/`SMEMBERS`/`SREM`) for atomic operations and better scaling when the drop-in exposes a Redis client instance. This repo already prefers the WordPress object-cache API; switch to direct Redis set ops only after confirming the drop-in's API exposes Redis.
- Add taxonomy/term invalidation refs if you cache term-based collections. The pattern mirrors per-post refs: `headless:refs:term:{term_id}`.
- Add a WP-CLI migration that creates recommended DB indexes as an explicit maintenance task (do not run automatically).

Files added in this repo

- `wp-content/mu-plugins/database-optimizer/database-optimizer.php` — MinimalDatabaseOptimizer implementation. Key features: ID-list caching (120s), per-post ref tracking, targeted invalidation hooks.
- `wp-content/mu-plugins/02-database-optimizer-loader.php` — loader to require the implementation.
- `tests/Integration/test-database-optimizer.php` — integration test that asserts refs are recorded and invalidated on `save_post`.
- `scripts/test-database-optimizer.php` and `scripts/run-test-in-docker.sh` — manual test helpers and a Docker runner for quick verification.

If you want, I can add a short WP-CLI helper to list stored refs for a post (debug tool), or convert per-post refs to Redis sets for atomicity. Which would you prefer?
