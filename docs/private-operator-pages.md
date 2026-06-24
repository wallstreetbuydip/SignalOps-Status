# Private Operator Pages

SignalOps is built around a public status page, but many teams also add private operator pages for AI run journals, incident notes, deployment logs, or support-only diagnostics.

These pages should use a different performance model than the public dashboard.

## Cache Boundary

Public status page:

- May use browser revalidation.
- May emit CDN-friendly headers.
- May be cached by Cloudflare when the deployment is configured for it.

Private operator page:

- Should send `Cache-Control: no-store, private, max-age=0`.
- Should send `Pragma: no-cache` and `Expires: 0` for older clients.
- Should send `X-Robots-Tag: noindex, nofollow`.
- Should not be included in Cloudflare Cache Rules.
- Should not store user-specific content in public snapshot files.

Seeing `CF-Cache-Status: BYPASS` on a private page is expected. If the browser timing panel shows a long `Waiting for server response`, fix the origin query path rather than trying to cache the private response at the edge.

## Fast Origin Pattern

For large private ledgers, avoid loading full text or JSON blobs for every row in the list view.

Recommended pattern:

1. Query lightweight list columns first: `id`, timestamp, profile/type, status, row counts, and short labels.
2. Load the full payload only for the selected row.
3. Keep exports explicit. Full Markdown or JSON downloads can run heavier queries because the operator intentionally requested the file.
4. Add a short server-side private cache for list/detail payloads when data freshness allows it.
5. Put that cache on tmpfs where available, such as `/dev/shm/signalops-status/private`.
6. Keep cache file permissions restrictive, for example `0750` on the directory and `0640` on files.

Example private route headers:

```php
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, nofollow');
```

Example timing header:

```php
$startedAt = microtime(true);
// Query and render private data here.
header('Server-Timing: operator_page;dur=' . number_format((microtime(true) - $startedAt) * 1000, 1, '.', ''));
```

## Database Notes

For operator pages backed by MySQL:

- Prefer `ORDER BY created_at DESC LIMIT n` against an indexed timestamp column.
- Add composite indexes for common filters, for example `(profile, created_at)`.
- Do not use `%LIKE%` searches over large text or JSON columns on every default page load.
- If search is needed, only run it when the operator enters a query.
- Consider a separate summary table if the page needs expensive aggregates.

## Cloudflare Notes

Cloudflare can still protect and accelerate the connection, TLS, and static assets for private pages, but it should not cache the private HTML response.

If the page is slow:

- Check `Waiting for server response` in DevTools.
- Check `Server-Timing` for application timing.
- Measure the raw PHP route with `curl -w`.
- Profile SQL separately with lightweight and full-payload variants.

Do not fix a private-page origin bottleneck by broadening an edge cache rule. That risks exposing authenticated output to the wrong audience.
