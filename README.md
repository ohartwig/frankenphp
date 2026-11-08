# koh/frankenphp

TYPO3 extension for FrankenPHP worker-mode integration and Caddy/Souin HTTP cache invalidation.

- **Extension key:** `frankenphp`
- **Namespace:** `Koh\FrankenPHP`
- **Requirements:** TYPO3 13.4 / 14.x, PHP 8.3+, FrankenPHP 1.x, Caddy 2.11+ (with `cache-handler`/Souin)

## Features

- **FrankenPHP frontend worker** — request classification separates frontend requests (worker mode) from the backend and the Install Tool. The worker bootstraps TYPO3 per request and resets the state a request leaves in static properties and globals before the next one (`RequestStateReset`).
- **103 Early Hints** — real RFC 8297 responses via `headers_send(103)` from the AssetCollector. CSS/JS preloads start in parallel with server-side rendering.
- **Caddy/Souin cache invalidation** — PURGE through Souin's admin API: by exact URL key, by tag (Surrogate-Key), or everything.
- **PSR-14 event architecture + DataHandler hook** — automatic invalidation on page, content and file changes.
  Deleting, replacing, renaming, moving or rewriting a fileadmin file purges its public URL (and its processed variants) by Souin key on every site host. The extension emits `Surrogate-Key` (site identifier and `pageId_<n>`) on every rendered frontend page through `SurrogateKeyMiddleware`, so tag purges have entries to find; files are served by the web server and carry no key. A tag purge only sends the tags Souin actually holds and logs `no Surrogate-Key emitted for tag` for the rest; "Flush site" in the backend module reports that instead of success. Pages cached before the key was emitted are not reachable by tag until they expire or are flushed completely.
  A saved page or content element purges the URLs of the affected pages in the page's own site, one per language the page exists in (hidden and just-deleted pages included, never the site base in their place), each by its exact Souin key (`GET-<host>-<path>$`), plus one purge per host for query-string variants (`GET-<host>-/?.+=`). Souin treats a purge key as an unanchored regex and as a prefix, so an unanchored key for `/` empties the whole host. The core flushes every sibling of a saved page; the edge follows only when the save changed a field a menu or teaser shows (title, slug, visibility, position, abstract, media and the like), otherwise it purges the page and its parent. A save in a workspace purges nothing; its publish does.
- **Backend module** — Admin Tools → manual flushes and cache-key inspection.

## Components

### Frontend worker

| Class | Responsibility |
|---|---|
| `Classes/Runtime/FrontendWorker` | Worker loop with `frankenphp_handle_request()`, a fresh TYPO3 bootstrap per request with `REQUESTTYPE_FE` |
| `Classes/Runtime/RequestStateReset` | Clears what survives a bootstrap in a long-lived process: the `getIndpEnv()` cache (host and URL of the previous request), singletons, `BE_USER`/`LANG`/`TYPO3_REQUEST`, and the error and exception handlers each bootstrap registers |
| `Classes/Runtime/DatabaseConnectionReuse` | Keeps the database connection open for the next request, so the TCP/TLS handshake is not paid per request, and closes it when that is not safe: a transaction left open (rolled back and logged), idle longer than the idle limit, older than the age limit, past the request limit, or not answering a `SELECT 1` before the request |
| `Classes/Runtime/RequestClassifier` | Pure PHP (no TYPO3 dependencies), classifies requests from `$_SERVER` as frontend/backend/install. Backend detection by path OR by host |
| `Resources/Private/Worker/frontend.php` | Worker entry point (register it as `worker` with `match` in the frontend `php` block, see below) |

`Classes/Runtime/` is excluded from DI auto-registration because it runs before the TYPO3 container exists.

**Environment variables:**

| Variable | Description | Default |
|---|---|---|
| `TYPO3_BACKEND_ENTRYPOINT` | Backend entry point (path or URL) | `/typo3` |
| `MAX_REQUESTS` | Requests per worker lifecycle | `500` |
| `TYPO3_AUTOLOAD_PATH` | Path to `vendor/autoload.php` | auto-detected |
| `DB_REUSE` | Keep database connections between requests; `0` closes them after every request, as before | `1` |
| `DB_REUSE_IDLE_TIMEOUT` | Seconds a kept connection may sit idle; keep it well below the server's `wait_timeout` (`0` = no limit) | `60` |
| `DB_REUSE_MAX_AGE` | Seconds a connection is kept at most (`0` = no limit) | `600` |
| `DB_REUSE_MAX_REQUESTS` | Requests a connection serves at most (`0` = no limit) | `1000` |
| `DB_REUSE_LIVENESS_CHECK` | `SELECT 1` on a kept connection before the request, so a connection the server dropped is replaced rather than failing the request | `1` |

### EarlyHintMiddleware

After `handler->handle()`, the PSR-15 middleware collects the CSS and JS sources registered with the `AssetCollector`, resolves EXT: paths with `PathUtility::getSystemResourceUri()` (TYPO3 v14+ compliant) and sends two hints in parallel:

1. **103 Early Hints** via `headers_send(103)` — as soon as FrankenPHP provides the function (`function_exists()` guard for CLI and test contexts)
2. **`Link:` header in the 200 response** — as a fallback and for reverse proxies capable of HTTP/2 push

Both mechanisms deliver preload hints the browser can deduplicate. Pages heavy on CSS/JS get measurably better LCP.

Middleware position: before `typo3/cms-frontend/timetracker` (see `Configuration/RequestMiddlewares.php`).

### Cache invalidation

`CaddyCacheService` sends PURGE to Souin's **admin API** (`{protocol}://{caddyHost}:{caddyPort}{apiEndpoint}`). A PURGE to the public page URL is proxied upstream and has no effect, and the edge blocks PURGE from outside; the `tls*` options cover mTLS to the proxy.

- **Per URL** — key `GET-<host>-<path>$`, plus once per host and call `GET-<host>-/?.+=` for query-string variants
- **Per tag** — `Surrogate-Key` header (tags separated by comma and space, as Souin splits them). Only tags Souin actually holds are sent; the rest is logged as `no Surrogate-Key emitted for tag`
- **Everything** — `/.+`, the whole Souin store

Souin answers 204 to every purge, matching or not; anything else is logged as a failure.

**Why the key is anchored.** Souin compiles the key into a regex, matches it unanchored, and also deletes every key it is a prefix of: `GET-host-/` empties the host, `GET-host-/blog` everything below `/blog` and `/blogger`. Groups and escapes cannot narrow it: TYPO3's `Uri` percent-encodes `\`, `(`, `[`, `|`, `^`, and Souin does not decode them, so such a key matches nothing. Characters that are regex syntax or would be encoded become `.` in the key.

Automatic invalidation via:

- **DataHandler hook `clearCachePostProc`** — every editorial change: save, workspace publish, move, delete, `clearCacheCmd`, "flush page"
- **DataHandler hook `processDatamap_afterDatabaseOperations`** — direct saves of `pages` and `tt_content`
- **PSR-14 `CacheFlushEvent`** — general cache flush, deferred until after the request
- **`FileCacheInvalidationListener`** — deleting, replacing, renaming, moving or rewriting a fileadmin file purges its public path and processed variants on every site host

What a save purges: the URLs of the page in its own site, one per language the page exists in (hidden and just-deleted pages included, never the site base in their place); the core's siblings only when a menu or teaser field changed (title, slug, visibility, position, abstract, media and the like), otherwise the page and its parent; nothing for a save in a workspace (its publish purges); each page once per DataHandler run.

An optional API key is sent in the `X-API-Key` header.

## Installation

```bash
composer require koh/frankenphp
```

### Caddyfile

A worker only receives a request when FrankenPHP routes it there. A global
`worker` in the `frankenphp` block is matched by script path: it serves a
request only if the resolved script *is* the worker file. TYPO3 rewrites every
frontend request to `/index.php`, so a global worker next to that rewrite sits
idle and every request runs in a regular thread. Declare the worker inside the
frontend `php` block and bind it with `match` instead; the worker file may live
outside the public directory.

```caddyfile
your-domain.com {
    root * /app/public

    # Backend and Install Tool: regular threads, no worker.
    @backend path /typo3 /typo3/*
    handle @backend {
        php
    }

    handle {
        rewrite * /index.php
        php {
            worker {
                file /app/vendor/koh/frankenphp/Resources/Private/Worker/frontend.php
                match *
                num 4
                env TYPO3_BACKEND_ENTRYPOINT /typo3
                env MAX_REQUESTS 500
            }
        }
    }

    route {
        cache {
            api {
                souin
            }
            ttl 1h
            default_cache_control public
        }
    }
}
```

Leave room for regular threads: with `num_threads` unset, FrankenPHP starts
twice as many threads as there are CPUs, and when the workers take all of them
it adds exactly one — on a 2-CPU pod with 4 workers, a single regular thread
then serves every backend request. Set `num_threads` in the global `frankenphp` block to the worker count
plus what the backend needs.

`frankenphp_worker_request_count` shows whether the worker is reached at all;
the series does not exist until it has served its first request.

### Extension configuration

In the TYPO3 backend under "Admin Tools → Extension Configuration → frankenphp":

| Key | Description | Default |
|---|---|---|
| `enabled` | Send invalidations to the proxy at all. `0` = no PURGE, no admin API call (local development without caddy-proxy) | `1` |
| `caddyHost` | Host of the Souin admin API | `localhost` |
| `caddyPort` | Port of the Souin admin API | `2019` |
| `protocol` | `http` or `https` | `http` |
| `apiEndpoint` | Souin API path | `/souin-api/souin` |
| `apiKey` | Optional, sends the `X-API-Key` header | — |
| `enableDebugLogging` | Detailed logs for cache calls | `0` |
| `httpTimeout` | HTTP timeout (seconds) | `5` |
| `invalidateAllSites` | A cache flush invalidates all sites | `1` |
| `cacheTagsHeader` | Header name for tag invalidation | `Surrogate-Key` |
| `tlsClientCert`, `tlsClientKey` | Client certificate and key (PEM) for mTLS to the proxy; empty = none | — |
| `tlsCaBundle`, `tlsVerify` | CA bundle to verify the proxy certificate; without one, `tlsVerify` decides | —, `1` |

## Worker reload (without a container restart)

OPcache caches the middleware code per worker thread. After code changes, `typo3 cache:flush --group all` is not enough. Recycle the workers:

```bash
# SIGUSR1 to FrankenPHP's PID 1
docker exec <httpd-container> kill -USR1 1
```

`frankenphp reload --config /etc/caddy/Caddyfile` reloads only the Caddy config, not necessarily the PHP worker threads.

## Logging

PSR-3 logger via TYPO3:

- **Info** — normal cache invalidations
- **Warning** — failed API calls
- **Error** — exceptions during invalidation
- **Debug** — request/response details (only with `enableDebugLogging`)

### Purge audit

`KOH_CACHE_PURGE_AUDIT=1` in the environment writes one JSON line per edge
cache purge to stderr (`"koh_purge_audit":"purge"`), plus one when a full
flush is scheduled (`"full-flush-scheduled"`, with the reason: the
`CacheFlushEvent` groups or the `clear_cacheCmd`). Each line carries the
Souin key, the request (method, host, path, user agent) or CLI command, the
backend user and workspace, and the calling code path. It is meant for a
measurement window, to find out what keeps emptying the edge cache; without
the variable nothing is written.

## Backend module

"Admin Tools → Caddy Cache Management":

- **Flush All Cache** — invalidates all configured sites
- **Flush Site Cache** — one site at a time
- **Cache Status** — overview of the automatic invalidation

## Extending

### Custom cache tags

```php
$this->caddyCacheService->invalidateByTags(['custom:tag', 'product:123']);
```

### Custom event listeners

```yaml
# Configuration/Services.yaml
services:
  Your\Extension\EventListener\CustomCacheListener:
    tags:
      - name: event.listener
        identifier: 'custom-cache-invalidation'
        event: Your\Extension\Event\CustomEvent
```

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| I don't see the 103 in `curl -v` | The Souin cache swallows the 103 on cache hits. Use `Cache-Control: no-cache` plus a cache buster in the URL. Also, curl < 8.10 silently does not show the 103. |
| Browser console `Refused to apply style from 'https://host/EXT:...'` | Before v4.0.3 — EXT: resolution was not implemented. Update to >= v4.0.6. |
| The cache is not invalidated | Check the Caddy host/port/protocol, enable `enableDebugLogging`, check the logs in `/var/log/typo3` |
| API calls fail with 401 | Check the API key, allow the `X-API-Key` header in the Caddy config |

## License

GPL-2.0-or-later
