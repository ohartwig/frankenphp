# koh/frankenphp

TYPO3-Extension für FrankenPHP-Worker-Mode-Integration und Caddy/Souin-HTTP-Cache-Invalidierung.

- **Extension-Key:** `frankenphp`
- **Namespace:** `Koh\FrankenPHP`
- **Voraussetzungen:** TYPO3 13.4 / 14.x, PHP 8.3+, FrankenPHP 1.x, Caddy 2.11+ (mit `cache-handler`/Souin)

## Features

- **FrankenPHP Frontend Worker** — Request-Klassifizierung trennt Frontend-Requests (Worker-Mode) von Backend/Install-Tool. Worker bootstrappt TYPO3 pro Request, kein State-Sharing.
- **103 Early Hints** — Echte RFC-8297-Antworten via `headers_send(103)` aus dem AssetCollector. CSS/JS-Preloads starten parallel zum Server-Rendering.
- **Caddy/Souin Cache-Invalidierung** — REST-API-basiert via PURGE/DELETE. Drei Strategien: URL-basiert, Tag-basiert (Surrogate-Key), Wildcard.
- **PSR-14 Event-Architektur + DataHandler-Hook** — Auto-Invalidierung bei Page-/Content-/File-Änderungen.
- **Backend-Modul** — Admin Tools → manuelle Flushes und Cache-Key-Inspection.

## Komponenten

### Frontend-Worker

| Klasse | Aufgabe |
|---|---|
| `Classes/Runtime/FrontendWorker` | Worker-Loop mit `frankenphp_handle_request()`, pro Request frisches TYPO3-Bootstrap mit `REQUESTTYPE_FE` |
| `Classes/Runtime/RequestClassifier` | Pure-PHP (keine TYPO3-Deps), klassifiziert Requests aus `$_SERVER` in Frontend/Backend/Install. Path- ODER host-basierte Backend-Erkennung |
| `Resources/Private/Worker/frontend.php` | Worker-Entry-Point (als `worker` im Caddyfile eintragen) |

`Classes/Runtime/` ist von der DI-Auto-Registrierung ausgenommen, weil es vor dem TYPO3-Container läuft.

**Environment-Variablen:**

| Variable | Beschreibung | Default |
|---|---|---|
| `TYPO3_BACKEND_ENTRYPOINT` | Backend-Entry-Point (Pfad oder URL) | `/typo3` |
| `MAX_REQUESTS` | Requests pro Worker-Lifecycle | `1` |
| `TYPO3_AUTOLOAD_PATH` | Pfad zu `vendor/autoload.php` | Auto-Detect |

### EarlyHintMiddleware

Sammelt im PSR-15-Middleware-Hook nach `handler->handle()` die per `AssetCollector` registrierten CSS-/JS-Quellen, resolved EXT:-Pfade mit `PathUtility::getSystemResourceUri()` (TYPO3 v14+-konform) und sendet zwei parallele Hints:

1. **103 Early Hints** via `headers_send(103)` — sobald FrankenPHP die Funktion bereitstellt (`function_exists()`-Guard für CLI/Test-Kontexte)
2. **`Link:`-Header in der 200-Response** — Fallback und für HTTP/2-Push-fähige Reverse-Proxies

Beide Mechanismen liefern Browser-deduplizierbare Preload-Hints. Bei CSS/JS-lastigen Seiten messbar bessere LCP-Werte.

Middleware-Position: vor `typo3/cms-frontend/timetracker` (siehe `Configuration/RequestMiddlewares.php`).

### Cache-Invalidierung

`CaddyCacheService` spricht die Souin-REST-API:

- **DELETE** `{proto}://{host}:{port}{apiEndpoint}` mit JSON-Body `{"url": "https://example.com/path"}` — URL-basiert
- **PURGE** `{proto}://{host}:{port}{apiEndpoint}` mit `Surrogate-Key`-Header — Tag-basiert
- Wildcard-URLs — Site-weit

Auto-Invalidierung über:

- **PSR-14 `CacheFlushEvent`** — allgemeiner Cache-Flush
- **DataHandler-Hook `processDatamap_afterDatabaseOperations`** — `pages`, `tt_content`, `sys_file`, `sys_file_reference`, `fe_users`, `fe_groups`
- **PSR-14 `SystemInformationToolbarCollectorEvent`** — Backend-Toolbar-Integration

Optional API-Key via `X-API-Key`-Header.

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

### Extension-Konfiguration

Im TYPO3-Backend unter „Admin Tools → Extension Configuration → frankenphp":

| Key | Beschreibung | Default |
|---|---|---|
| `enabled` | Invalidierungen überhaupt an den Proxy senden. `0` = kein PURGE, kein Admin-API-Aufruf (lokale Entwicklung ohne caddy-proxy) | `1` |
| `caddyHost` | Caddy-Server-Host für Cache-API | `localhost` |
| `caddyPort` | Caddy-Server-Port | `80` |
| `caddyProtocol` | `http` oder `https` | `http` |
| `caddyApiEndpoint` | Souin-API-Pfad | `/souin/api/souin` |
| `caddyApiKey` | Optional, sendet `X-API-Key`-Header | — |
| `enableDebugLogging` | Detail-Logs für Cache-Calls | `0` |
| `httpTimeout` | HTTP-Timeout (Sekunden) | `5` |
| `invalidateAllSites` | Cache-Flush invalidiert alle Sites | `1` |
| `cacheTagHeader` | Header-Name für Tag-Invalidierung | `Surrogate-Key` |

## Worker-Reload (ohne Container-Restart)

OPcache cached den Middleware-Code pro Worker-Thread. Nach Code-Änderungen reicht `typo3 cache:flush --group all` nicht. Worker recyclen:

```bash
# SIGUSR1 an FrankenPHP-PID 1
docker exec <httpd-container> kill -USR1 1
```

`frankenphp reload --config /etc/caddy/Caddyfile` lädt nur die Caddy-Config, nicht zwingend die PHP-Worker-Threads.

## Logging

PSR-3-Logger via TYPO3:

- **Info** — normale Cache-Invalidierungs-Vorgänge
- **Warning** — fehlgeschlagene API-Calls
- **Error** — Exceptions während der Invalidierung
- **Debug** — Request/Response-Details (nur mit `enableDebugLogging`)

## Backend-Modul

„Admin Tools → Caddy Cache Management":

- **Flush All Cache** — invalidiert alle konfigurierten Sites
- **Flush Site Cache** — pro Site einzeln
- **Cache Status** — Übersicht über die Auto-Invalidierung

## Erweiterung

### Eigene Cache-Tags

```php
$this->caddyCacheService->invalidateByTags(['custom:tag', 'product:123']);
```

### Eigene Event-Listener

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

| Symptom | Wahrscheinlich |
|---|---|
| 103 sehe ich nicht im `curl -v` | Souin-Cache schluckt 103 bei Cache-Hits. `Cache-Control: no-cache` + Cache-Buster im URL. Außerdem zeigt curl <8.10 die 103 stillschweigend nicht. |
| Browser-Console `Refused to apply style from 'https://host/EXT:...'` | Pre-v4.0.3 — EXT:-Resolution war nicht implementiert. Auf >= v4.0.6 updaten. |
| Cache wird nicht invalidiert | Caddy-Host/Port/Protocol prüfen, `enableDebugLogging` aktivieren, Logs `/var/log/typo3` checken |
| API-Calls schlagen mit 401 fehl | API-Key prüfen, `X-API-Key`-Header in der Caddy-Config zulassen |

## Lizenz

GPL-2.0-or-later
