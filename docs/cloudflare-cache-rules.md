# Cloudflare Cache Rules

SignalOps Status is a PHP-based public status page. It can run behind Cloudflare and cache the rendered HTML at the edge while the origin keeps refreshing private probes in the background.

This guide assumes the status page is public and does not render user-specific content.

## 1. Enable CDN Headers

In your private config, enable CDN-friendly response headers:

```php
'cache' => [
    'cdn' => [
        'enabled' => true,
        'edge_max_age' => 60,
        'stale_while_revalidate' => 300,
        'stale_if_error' => 604800,
    ],
],
```

When enabled, SignalOps sends:

```http
Cache-Control: public, max-age=15, s-maxage=60, stale-while-revalidate=300, stale-if-error=604800
CDN-Cache-Control: public, max-age=60, stale-while-revalidate=300, stale-if-error=604800
Cloudflare-CDN-Cache-Control: public, max-age=60, stale-while-revalidate=300, stale-if-error=604800
ETag: "..."
Last-Modified: Wed, 24 Jun 2026 07:42:36 GMT
```

Cloudflare may consume `Cloudflare-CDN-Cache-Control`, so it might not appear in browser-facing responses after Cloudflare processes it.

## 2. Create the Cache Rule

Cloudflare does not cache HTML by default. Add a Cache Rule for only the public status hostname.

Recommended rule:

```text
Name:
  Status HTML Edge Cache

When incoming requests match:
  URI Full wildcard https://status.example.com/*

Then:
  Cache eligibility: Eligible for cache
  Edge TTL: Use cache-control header if present, bypass cache if not
  Browser TTL: Respect origin TTL
  Cache key: Ignore query string
```

Use your real status hostname in place of `status.example.com`.

## 3. Why Ignore Query String?

Status pages are often refreshed with cache-busting URLs such as:

```text
https://status.example.com/?nocache=20260620
```

If Cloudflare includes query strings in the cache key, every different query value creates a separate cache entry. For a public status page where query parameters do not change rendered content, ignore query strings to keep the edge cache hot.

Do not ignore query strings if your deployment uses query parameters to render different dashboards.

## 4. Verify

Run the same URL twice:

```bash
curl -sS -o /dev/null -D - https://status.example.com/
curl -sS -o /dev/null -D - https://status.example.com/
```

Expected progression:

```text
CF-Cache-Status: MISS
CF-Cache-Status: HIT
```

Verify browser or edge revalidation:

```bash
curl -sS -o /dev/null -D - -H 'If-Modified-Since: Wed, 24 Jun 2026 07:42:36 GMT' https://status.example.com/
```

Expected result when the snapshot has not changed:

```text
HTTP/2 304
```

Verify query strings share the same cache entry:

```bash
curl -sS -o /dev/null -D - 'https://status.example.com/?cfquery=alpha'
curl -sS -o /dev/null -D - 'https://status.example.com/?cfquery=beta'
```

After the first warm-up request, later query variants should show:

```text
CF-Cache-Status: HIT
```

## Safety Notes

- Apply the rule only to the public status hostname.
- Do not use this rule for authenticated dashboards.
- Do not cache pages that set user-specific cookies.
- Keep private health endpoints, IPs, database credentials, SSH keys, and tokens out of the rendered HTML.
- Keep real config outside the repository.
