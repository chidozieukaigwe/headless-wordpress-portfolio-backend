# Preview integration — Headless WordPress

## Overview

This document describes the preview integration implemented for the headless WordPress setup. It explains what we built, why it was needed, how it works (implementation details), the security and operational impact, and how to test it locally.

## What we are doing

We provide a secure, cross‑origin preview flow that allows the React frontend to request draft/preview content from WordPress without relying on the editor’s browser cookies. When an editor clicks Preview in WordPress, the preview link points at the React app and includes both a WP nonce and a short‑lived signed `preview_token`. The React app uses that token to call a custom REST endpoint which returns the preview payload for the requested post.

## Why

- Nonces (`_wpnonce`) are tied to a logged‑in user/session and typically require cookies. A SPA running on a different origin cannot reliably present the editor's cookies to WP.
- A signed, time‑limited token solves this: it can be sent as part of the URL or API call, is verifiable server‑side, and does not require cookies.
- This keeps previews secure, works cross‑origin, and avoids complex cookie/CORS hosting setups.

## How (implementation details)

Files and locations:

- `wp-config.php` — loads environment variables (including `PREVIEW_SECRET` if present).
- `wp-content/mu-plugins/cors-headers.php` — early mu-plugin that sets CORS headers and whitelists nonce headers.
- `wp-content/themes/headless-theme/functions.php` — main preview logic (route, token creation, verification, preview payload).

Server flow (high level):

1. `modify_preview_link()` (theme) — when WP generates a preview link in the admin it is rewritten to the React preview URL. The function appends:
   - `_wpnonce=<nonce>` (legacy WP nonce)
   - `preview_token=<base64(hmac:expires)>` — HMAC signed token, 10 minute expiry by default. The HMAC is computed as `hash_hmac('sha256', "{post_id}|{expires}", PREVIEW_SECRET)`.

2. `register_preview_endpoint()` (theme) — registers the REST endpoint under the configured namespace: `/wp-json/custom/v1/preview/{post_type}/{id}` which is handled by `get_preview_content()`.

3. `verify_preview_permission()` (theme) — when the endpoint receives a request it first checks for `preview_token`. If present it:
   - base64‑decodes token into `hmac:expires`, validates expiry, computes expected HMAC using `post_id|expires` and `PREVIEW_SECRET`, and performs `hash_equals()`.
   - If token validation passes, the request is allowed. If not present/invalid, it falls back to normal checks: logged‑in user capability `edit_post` or verifying nonces from `X-WP-Nonce`, `X-Preview-Nonce`, or `_wpnonce`.

4. `get_preview_content()` — builds the preview response (post content, ACF fields, featured image info, post meta for custom types) and returns JSON via `rest_ensure_response()`.

Frontend flow:

- React preview page receives the editor link containing `preview_token` and `_wpnonce`. It extracts `preview_token` (preferred) and calls the preview endpoint:

```js
const token = new URLSearchParams(window.location.search).get("preview_token");
fetch(
  `https://chidodesigns.local/wp-json/custom/v1/preview/page/${id}?preview_token=${token}`,
)
  .then((r) => r.json())
  .then((data) => {
    /* render preview */
  });
```

Note: sending `_wpnonce` as `X-WP-Nonce` and `credentials: 'include'` is supported if you host the SPA in a way that forwards/sends WP cookies, but this is not required when using `preview_token`.

## Configuration

- Add a strong secret to your `.env` (do not commit):
  ```
  PREVIEW_SECRET=<long-random-string>
  ```
- If `PREVIEW_SECRET` is not set, the code falls back to `AUTH_KEY` for HMAC (not recommended — set `PREVIEW_SECRET`).
- CORS mu-plugin already includes `Access-Control-Allow-Credentials: true` and whitelists `X-WP-Nonce` and `X-Preview-Nonce` headers.

## Security considerations

- The preview token is short‑lived (10 minutes) and HMAC binds it to the `post_id` and expiry, so tokens cannot be trivially reused for other posts.
- Treat `PREVIEW_SECRET` like a secret: store it in `.env` and rotate if compromised.
- Token flow is additive: same‑origin cookie/nonce checks still work — token is used primarily for cross‑origin previewing.

## Impact and tradeoffs

- Pros:
  - Reliable cross‑origin preview without cookie dependency.
  - Minimal server changes, compatible with existing REST/ACF code.
- Cons:
  - Anyone who obtains a valid token can fetch preview content until it expires — keep the secret secure and expiry short.
  - Requires the React app to include the token in API calls (small integration work).

## Testing

1. Add `PREVIEW_SECRET` to `.env` (generate `openssl rand -hex 32`).
2. In WP admin click Preview for a draft — ensure the generated React preview URL includes `preview_token` and `_wpnonce`.
3. Open the React preview URL and verify the fetch to `/wp-json/custom/v1/preview/...` returns the preview JSON.
4. Test invalid/expired token behavior with `curl` and confirm server returns 401:

```bash
curl 'https://chidodesigns.local/wp-json/custom/v1/preview/page/29?preview_token=BAD'
```

## Next steps / suggestions

- Add a small helper in the React app that extracts `preview_token` and fetches preview JSON (I can add an example file if desired).
- Add a deploy checklist item to set/rotate `PREVIEW_SECRET`.
- Consider logging or admin notices for failed preview token validations while testing (temporary) to help debugging.

---

Last updated: 2026-03-22
