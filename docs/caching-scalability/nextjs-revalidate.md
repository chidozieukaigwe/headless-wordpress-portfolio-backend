# Next.js Revalidate API — Example and Deployment Notes

This file shows a minimal Next.js API route that accepts webhook requests from WordPress and triggers On-Demand ISR revalidation (or CDN purge). It also documents required environment variables and mapping rules.

## Environment variables

- `HEADLESS_WEBHOOK_SECRET` — shared secret used to authenticate incoming webhooks from WordPress. Must be identical on both WordPress and Next.js deployments.
- `HEADLESS_WEBHOOK_ALLOWED_IPS` (optional) — comma-separated list of IPs that are allowed to call the revalidate API (optional extra safeguard).
- `CDN_API_KEY`, `CDN_ZONE_ID` (optional) — if you use a CDN purge API, store credentials as env vars and call the CDN purge endpoints from this route or an async worker.

## Minimal Next.js API handler (pages/api/revalidate.js)

```js
// pages/api/revalidate.js
export default async function handler(req, res) {
  if (req.method !== "POST") return res.status(405).end();

  const secret = process.env.HEADLESS_WEBHOOK_SECRET;
  if (!secret) return res.status(500).json({ error: "no-secret-configured" });

  const incoming =
    req.headers["x-webhook-secret"] || req.headers["x-health-secret"];
  if (incoming !== secret)
    return res.status(401).json({ error: "invalid-secret" });

  const payload = req.body || {};
  // Example payload: { post_id, post_type, post_slug, action, timestamp, paths }

  // Map content -> frontend paths
  const paths = [];
  if (payload.post_type === "post" && payload.post_slug) {
    paths.push(`/posts/${payload.post_slug}`);
  }

  // Always revalidate first-page listings that may include this post
  paths.push("/blog");
  // Optionally revalidate homepage if the post is featured
  // if (payload.featured) paths.push('/');

  try {
    await Promise.all(paths.map((p) => res.revalidate(p)));

    // Optionally call CDN purge API here (async) using CDN_API_KEY
    return res.json({ revalidated: paths });
  } catch (err) {
    console.error("revalidate error", err);
    return res.status(500).json({ error: "revalidation-failed" });
  }
}
```

Notes:

- `res.revalidate(path)` is provided by Next.js and is idempotent.
- Keep the handler simple and fast; heavy work (bulk purges, large lists) should be delegated to background workers.

## Mapping rules (recommendation)

- Single-post pages: `/posts/{post_slug}` (map `post_slug` from payload).
- The webhook payload contains a `paths` array by default (e.g. `/posts/{post_slug}`, `/blog`).
- Collections: revalidate first-page list(s) (e.g. `/blog`). For paginated lists consider revalidating page 1 only, or compute affected pages if you track pagination position.
- Taxonomies: when terms change revalidate `/category/{slug}` or `/tag/{slug}` paths.
- Homepage: revalidate when a site-wide featured post or hero content changes.

## Security & reliability

- Use a long random `HEADLESS_WEBHOOK_SECRET` and validate the header exactly.
- Use TLS (HTTPS) for the webhook URL.
- Consider IP allowlisting or HMAC signatures for stronger guarantees.
- WordPress should call the webhook with `blocking: false` to avoid blocking the editor UI. If you need guaranteed delivery, push the event into a durable queue and have a worker call the revalidation endpoint with retries.

Filter hook: WordPress exposes an `headless_revalidate_paths` filter so themes/plugins
can customize mapping rules server-side before the webhook is sent. Example:

```php
$paths = apply_filters('headless_revalidate_paths', $paths, $post_id, $post, $payload);
```

## Local testing

- Add `HEADLESS_WEBHOOK_SECRET` to `.env.local` in your Next.js app.
- In WordPress (local), set `headless_webhook_url` to `http://localhost:3000/api/revalidate` and `headless_webhook_secret` to the same secret (or set constants/env accordingly).

## CDN purging

- If you rely on a CDN (Fastly, Cloudflare, Vercel CDN), add a short async call to the CDN purge API for the affected URLs. Keep this separate or delegated to a worker to avoid slow webhook responses.
