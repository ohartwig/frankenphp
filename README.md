# koh/frankenphp

TYPO3 extension for FrankenPHP worker-mode integration and Caddy/Souin HTTP cache invalidation.

- **Extension key:** `frankenphp`
- **Namespace:** `Koh\FrankenPHP`
- **Requirements:** TYPO3 13.4 / 14.x, PHP 8.3+, FrankenPHP 1.x, Caddy 2.11+ (with `cache-handler`/Souin)

## Features

- **FrankenPHP frontend worker** — request classification separates frontend requests (worker mode) from the backend and the Install Tool. The worker bootstraps TYPO3 per request; no state is shared.
- **103 Early Hints** — real RFC 8297 responses via `headers_send(103)` from the AssetCollector. CSS/JS preloads start in parallel with server-side rendering.
- **Caddy/Souin cache invalidation** — based on the REST API via PURGE/DELETE. Three strategies: by URL, by tag (Surrogate-Key), wildcard.
- **PSR-14 event architecture + DataHandler hook** — automatic invalidation on page, content and file changes.
- **Backend module** — Admin Tools → manual flushes and cache-key inspection.

## Components

### Frontend worker

| Class | Responsibility |
|---|---|
| `Classes/Runtime/FrontendWorker` | Worker loop with `frankenphp_handle_request()`, a fresh TYPO3 bootstrap per request with `REQUESTTYPE_FE` |
| `Classes/Runtime/RequestClassifier` | Pure PHP (no TYPO3 dependencies), classifies requests from `$_SERVER` as frontend/backend/install. Backend detection by path OR by host |
| `Resources/Private/Worker/frontend.php` | Worker entry point (register it as `worker` in the Caddyfile) |

`Classes/Runtime/` is excluded from DI auto-registration because it runs before the TYPO3 container exists.

**Environment variables:**

| Variable | Description | Default |
|---|---|---|
| `TYPO3_BACKEND_ENTRYPOINT` | Backend entry point (path or URL) | `/typo3` |
| `MAX_REQUESTS` | Requests per worker lifecycle | `1` |
| `TYPO3_AUTOLOAD_PATH` | Path to `vendor/autoload.php` | auto-detected |

### EarlyHintMiddleware

After `handler->handle()`, the PSR-15 middleware collects the CSS and JS sources registered with the `AssetCollector`, resolves EXT: paths with `PathUtility::getSystemResourceUri()` (TYPO3 v14+ compliant) and sends two hints in parallel:

1. **103 Early Hints** via `headers_send(103)` — as soon as FrankenPHP provides the function (`function_exists()` guard for CLI and test contexts)
2. **`Link:` header in the 200 response** — as a fallback and for reverse proxies capable of HTTP/2 push

Both mechanisms deliver preload hints the browser can deduplicate. Pages heavy on CSS/JS get measurably better LCP.

Middleware position: before `typo3/cms-frontend/timetracker` (see `Configuration/RequestMiddlewares.php`).

### Cache invalidation

`CaddyCacheService` talks to the Souin REST API:

- **DELETE** `{proto}://{host}:{port}{apiEndpoint}` with the JSON body `{"url": "https://example.com/path"}` — by URL
- **PURGE** `{proto}://{host}:{port}{apiEndpoint}` with a `Surrogate-Key` header — by tag
- Wildcard URLs — site-wide

Automatic invalidation via:

- **PSR-14 `CacheFlushEvent`** — general cache flush
- **DataHandler hook `processDatamap_afterDatabaseOperations`** — `pages`, `tt_content`, `sys_file`, `sys_file_reference`, `fe_users`, `fe_groups`
- **PSR-14 `SystemInformationToolbarCollectorEvent`** — backend toolbar integration

An optional API key is sent in the `X-API-Key` header.

## Installation

```bash
composer require koh/frankenphp
```

### Caddyfile

```caddyfile
your-domain.com {
    root * /app/public
    
    php_server {
        worker /app/vendor/koh/frankenphp/Resources/Private/Worker/frontend.php
        env TYPO3_BACKEND_ENTRYPOINT /typo3
        env MAX_REQUESTS 1000
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

### Extension configuration

In the TYPO3 backend under "Admin Tools → Extension Configuration → frankenphp":

| Key | Description | Default |
|---|---|---|
| `enabled` | Send invalidations to the proxy at all. `0` = no PURGE, no admin API call (local development without caddy-proxy) | `1` |
| `caddyHost` | Caddy server host for the cache API | `localhost` |
| `caddyPort` | Caddy server port | `80` |
| `caddyProtocol` | `http` or `https` | `http` |
| `caddyApiEndpoint` | Souin API path | `/souin/api/souin` |
| `caddyApiKey` | Optional, sends the `X-API-Key` header | — |
| `enableDebugLogging` | Detailed logs for cache calls | `0` |
| `httpTimeout` | HTTP timeout (seconds) | `5` |
| `invalidateAllSites` | A cache flush invalidates all sites | `1` |
| `cacheTagHeader` | Header name for tag invalidation | `Surrogate-Key` |

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
