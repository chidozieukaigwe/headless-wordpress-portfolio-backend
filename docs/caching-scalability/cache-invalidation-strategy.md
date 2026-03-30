# Cache Invalidation Strategy — Headless WordPress + Next.js

This document explains the cache invalidation strategy implemented in this repository and the reasoning behind it. It is written for developers new to caching and operators who need to understand what we do, how it works, and why it is safe and scalable.

**What?**

- We cache REST API responses (full JSON payloads) and short-lived ID-lists for collection queries to reduce database and PHP work on frequently requested endpoints.
- We track which cached ID-lists reference which posts so we can invalidate only the affected cached entries when a post changes (targeted invalidation).
- Per-post reference tracking is stored as Redis SETs when the active object-cache drop-in exposes a Redis client, with a safe fallback to array-based refs via `wp_cache_*` when Redis is not available.
- A webhook is sent to the Next.js frontend on content changes to trigger frontend revalidation and/or CDN purge.

**Why?**

- Correctness: Invalidation keeps cached responses fresh after content updates.
- Performance: Targeted invalidation (delete only affected keys) avoids full cache flushes and reduces the cost of rebuilding pages after an update.
- Atomicity & Concurrency: Redis SETs provide atomic `SADD` and `SMEMBERS` operations, eliminating read-modify-write races inherent to array-based ref lists under concurrent writes.
- Safety: The implementation fails open — if Redis or the drop-in is unavailable, WordPress continues serving dynamic responses and falls back to `wp_cache_*` behavior.

**How? (end-to-end)**

1. Request flow (cache-aside):
   - On REST GET, `HeadlessCacheManager` first tries `wp_cache_get($key, 'headless-cache')`. If present, the cached JSON is returned immediately.
   - On cache miss, WP generates the response; after dispatch, `HeadlessCacheManager::cache_api_response()` stores the response via `wp_cache_set($key, $data, 'headless-cache', $ttl)`.

2. ID-list optimizer (minimize cost on misses):
   - `MinimalDatabaseOptimizer` stores short-lived ID lists for collection responses using `wp_cache_set($key, $ids, 'headless-ids', $ttl)`.
   - After caching IDs, per-post refs are recorded. When a Redis client is available from the object-cache drop-in we perform `SADD headless:refs:set:post:{POST_ID} {CACHE_KEY}` for each post. Otherwise we append the cache key to an array stored under `headless:refs:post:{POST_ID}` via `wp_cache_set` (legacy fallback).

3. Invalidation on content change:
   - Hooks: `save_post`, `deleted_post` (and `before_delete_post` where appropriate) call the invalidation handler.
   - Redis path: for `post_id` read `SMEMBERS headless:refs:set:post:{POST_ID}`, delete each member with `wp_cache_delete(member, 'headless-ids')`, then `DEL headless:refs:set:post:{POST_ID}`.
   - Fallback path: if Redis is not available, read array refs from `headless:refs:post:{POST_ID}` and delete each referenced cache key, then delete the array key.

4. Webhook to frontend:
   - The invalidation handler sends a non-blocking `wp_remote_post()` to the Next.js revalidation endpoint (e.g. `/api/revalidate`) with a shared secret header `X-Webhook-Secret` and a payload containing `post_id`, `post_type`, `post_slug`, `action`, and `timestamp`.
   - Next.js validates the secret, maps the payload to site paths, and calls `res.revalidate(path)` and/or calls CDN purge APIs to refresh frontend caches.

**Where? (repo locations)**

- REST response cache: `wp-content/mu-plugins/headless-cache/headless-cache.php`
- ID-list optimizer + ref tracking: `wp-content/mu-plugins/database-optimizer/database-optimizer.php`
- Safe webhook invalidator (uses Redis SETs): `wp-content/mu-plugins/webhook-invalidation/webhook-invalidation.php`
- WP-CLI migrate refs: `wp-content/mu-plugins/cli-migrations/cli-migrations.php`
- Documentation: this file `docs/caching-scalability/cache-invalidation-strategy.md` and the broader README `docs/caching-scalability/wordpress-object-cache-wtih-redis/README.md`

**When (timing & TTL choices)**

- Response TTLs: `HeadlessCacheManager::calculate_ttl()` sets TTLs per-route (posts ~1h, pages ~24h, projects ~2h) — tune to your freshness needs and traffic patterns.
- ID-list TTLs: short (default 120s) to reduce staleness while giving good hit-rate for bursty traffic.
- Ref lifetime: sets are created and removed on invalidation. Array-based refs (fallback) are stored up to a day to ensure invalidation works across TTLs.

**Impact (tradeoffs & benefits)**

- Benefits:
  - Reduced latency and origin load: cached JSON avoids repeated PHP/DB work.
  - Precise invalidation: only affected keys are deleted, minimizing downstream rebuilds and thundering-herd risk.
  - Atomic operations with Redis sets reduce races and make invalidation robust under concurrency.

- Tradeoffs:
  - Operational dependency on Redis for best behavior (though code safely falls back).
  - Bookkeeping overhead: ref tracking consumes Redis memory and requires housekeeping.
  - Migration: existing array refs require migrating to sets for atomic behavior (a provided WP-CLI command helps with this).

**Migration & Operational steps**

1. Ensure object-cache drop-in is installed at `wp-content/object-cache.php` and configured (`WP_REDIS_*` constants or env vars). Use Docker Compose for local Redis during testing.
2. Run discovery: `wp headless migrate-refs --dry-run` to list what would be migrated.
3. Run `wp headless migrate-refs` to migrate array refs into Redis SETs (requires the drop-in to expose a Redis client).
4. Verify invalidation: edit a post, watch logs for `headless-cache` invalidation, check Redis for `headless:refs:set:post:{ID}` being removed, and confirm Next.js revalidation happened.
5. Optional final step: once confident, remove array-based fallback paths or set them to no-op to simplify code.

**How to debug & verify**

- Check `wp-content/mu-plugins/headless-cache/headless-cache.php` logs for cache hits/misses.
- Inspect Redis keys (careful; prefer `SMEMBERS` on known set keys) instead of `KEYS *`.
- Use the CLI migration dry-run to enumerate refs.
- Use integration tests (`./scripts/run-tests-docker.sh`) with Redis enabled to exercise end-to-end behavior.

**Why this design choices matter**

- Using `wp_cache_*` for primary storage preserves compatibility with any object-cache drop-in. Direct Redis operations are only used when the drop-in exposes a client — this prevents key-format mismatches and keeps the code portable.
- Redis SETs provide the right balance between atomicity and simplicity for targeted invalidation, and they avoid the heavy cost and concurrency problems of pattern-based `KEYS` deletions in production.

If you want, I can add a short troubleshooting checklist or a small WP-CLI command to list refs for a single post for debugging.
