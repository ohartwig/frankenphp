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
  Deleting, replacing, renaming, moving or rewriting a fileadmin file purges its public URL (and its processed variants) by Souin key on every site host. The extension emits `Surrogate-Key` on every rendered frontend page through `SurrogateKeyMiddleware`, so tag purges have entries to find: the site identifier, and with `KOH_EDGE_PURGE_BY_TAG=1` the page's TYPO3 cache tags (`pageId_<n>` and the rest; see *Purging by tag*). Without the switch it is the site identifier only on TYPO3 14: the `pageId_<n>` the middleware also meant to add needs page information that TYPO3 14 sets only behind it. Files are served by the web server and carry no key. A tag purge only sends the tags Souin actually holds and logs `no Surrogate-Key emitted for tag` for the rest; "Flush site" in the backend module reports that instead of success. Pages cached before the key was emitted are not reachable by tag until they expire or are flushed completely.
  A saved page or content element purges the URLs of the affected pages in the page's own site, one per language the page exists in (hidden and just-deleted pages included, never the site base in their place), each by its exact Souin key (`GET-<host>-<path>$`), plus one purge per host for query-string variants (`GET-<host>-/?.+=`). Souin treats a purge key as an unanchored regex and as a prefix, so an unanchored key for `/` empties the whole host. The core flushes every sibling of a saved page; the edge follows only when the save changed a field a menu or teaser shows (title, slug, visibility, position, abstract, media and the like), otherwise it purges the page and its parent (always, where the page TSconfig sets `TCEMAIN.edgeCache.purgeSiblings = 0`). A save in a workspace purges nothing; its publish does.
- **Backend module** — Admin Tools → manual flushes and cache-key inspection.

## Components

### Frontend worker

| Class | Responsibility |
|---|---|
| `Classes/Runtime/FrontendWorker` | Worker loop with `frankenphp_handle_request()`, a fresh TYPO3 bootstrap per request with `REQUESTTYPE_FE` |
| `Classes/Runtime/RequestStateReset` | Clears what survives a bootstrap in a long-lived process: the `getIndpEnv()` cache (host and URL of the previous request), singletons, `BE_USER`/`LANG`/`TYPO3_REQUEST`, and the error and exception handlers each bootstrap registers |
| `Classes/Runtime/DatabaseConnectionReuse` | Keeps the database connection open for the next request, so the TCP/TLS handshake is not paid per request, and closes it when that is not safe: a transaction left open (rolled back and logged), idle longer than the idle limit, older than the age limit, past the request limit, or not answering a `SELECT 1` before the request |
| `Classes/Runtime/PersistentBootstrap` | With `KOH_PERSISTENT_BOOTSTRAP=1`: boots TYPO3 for the first two requests, records the state of the second boot, and puts it back before every later request (see *Persistent bootstrap*) |
| `Classes/Runtime/LeakGuard` | With `KOH_PERSISTENT_BOOTSTRAP=1`: checks each response against its request before it is sent; on a finding the request gets a 503 and the worker ends |
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
| `KOH_CHASH_REDIRECT` | Redirect page URLs that carry parameters without the required cHash to the same URL without them, instead of the 404 (see `CacheHashRedirectMiddleware`); `0` restores the 404 | `1` |
| `KOH_PERSISTENT_BOOTSTRAP` | Boot TYPO3 once per worker and start every later request from the recorded boot state instead of booting again (see *Persistent bootstrap*); `0` boots per request, as before | `0` |
| `KOH_PERSISTENT_BOOTSTRAP_AUDIT` | Diagnostics for the persistent bootstrap: log the kept objects and what each request changes in them (see *Persistent bootstrap*, *Audit*) | `0` |
| `KOH_PERSISTENT_CONFIG_SERVICES` | With `KOH_PERSISTENT_BOOTSTRAP=1`: build the CSP policies and the Fluid namespaces and component collections with the boot and keep them, checked and put back before every request (see *Persistent bootstrap*, *Configuration services*) | `0` |
| `KOH_WORKER_CACHE_MEMO` | Skip the HMAC check (and, for object-free payloads, `unserialize()`) of read-mostly cache entries whose backend bytes this worker already verified (see *Worker memos*) | `0` |
| `KOH_WORKER_TEMPLATE_PATH_MEMO` | Remember Fluid's file lookups inside code directories per worker (see *Worker memos*) | `0` |
| `KOH_EARLY_HINTS_MEMO` | Send the 103 Early Hints before the render, from what the worker remembers of the page's last render (see *EarlyHintMiddleware*); `0` switches the 103 off | `1` |
| `KOH_REQUEST_TIMING` | One JSON line per frontend request the worker serves: bootstrap and application time apart, and whether TYPO3 rendered the page (see *Request timing*); `0` switches it off | `1` |
| `KOH_LAZY_PAGE_LAYOUT` | Resolve a page's backend layout from page TSconfig on first use instead of on every frontend request, page cache hits included (see *Lazy page layout*) | `0` |
| `KOH_EDGE_PURGE_BY_TAG` | Responses carry their TYPO3 cache tags in `Surrogate-Key`, and an editorial change purges the edge by the tags the core flushed, so every URL a page renders under goes, not only its base URL (see *Cache invalidation*, *Purging by tag*). Read where pages are rendered and where editors save | `0` |

### EarlyHintMiddleware

The PSR-15 middleware announces the render-critical resources of a page twice:

1. **103 Early Hints** via `headers_send(103)`, **before** TYPO3 renders. What a page needs is only known after its render, so the 103 carries what the last render of the same page (host + path) needed, or, for a page this worker has not rendered yet, what all rendered pages of the host had in common (the base stylesheet and the font, typically). The memory is per FrankenPHP worker (`EarlyHintMemo`, at most `KOH_EARLY_HINTS_MEMO_MAX_ENTRIES` pages, default 1024, oldest forgotten first); a deployment starts new workers and so starts empty. Sent only for document navigations (`Accept: text/html`, `Sec-Fetch-Dest` absent, `document` or `iframe`). `KOH_EARLY_HINTS_MEMO=0` switches the 103 off. Outside a worker (CLI, php-fpm) there is no memory and no 103.
2. **`Link:` header on the final response** with the full list, which an edge cache keeps with the page.

The list is built after the render from

- the stylesheets of the `AssetCollector`, and its scripts that block the parser — a `defer`, `async` or module script is not preloaded, because a preload fetches it at high priority, ahead of the stylesheets and the LCP image it should wait behind;
- the `<link rel="stylesheet">` and `<link rel="preload" as="font|image|style|script">` elements of the rendered `<head>` (page.includeCSS and headerData never pass the AssetCollector), same-origin paths only, a stylesheet's `integrity` passed on.

Until 5.11 the 103 went out after the render, together with the final response, and gave the browser no head start; scripts were preloaded whatever their loading mode.

Middleware position: before `typo3/cms-frontend/timetracker` (see `Configuration/RequestMiddlewares.php`).

### Request timing

The frontend worker writes one JSON line per request it serves, to the container log:

```json
{"koh_timing":"frontend","host":"example.org","path":"/blog","status":200,"page":"rendered","boot_ms":31.2,"app_ms":214.9,"total_ms":246.4,"worker_request":17,"mem_peak_mb":42.0,"bootstrap":"fresh"}
```

`boot_ms` is TYPO3's bootstrap, which the worker runs for every request; `app_ms` is the application after it. `page` is `rendered` when TYPO3 generated the page content in this request (`AfterCacheableContentIsGeneratedEvent`), `not-rendered` for a page from the page cache or a response that is no page, so read it with `status`. The edge only sees the total of a request it had not cached; these lines say whether that time went into booting or into rendering. Only the path is logged, never the query string. A request turned away (backend, Install) is not timed. `KOH_REQUEST_TIMING=0` switches it off.

`bootstrap` is `fresh` when the request booted TYPO3 and `reused` when it ran on the persistent bootstrap's kept boot; `boot_ms` is then the cost of putting the boot state back.

### Persistent bootstrap

By default the worker boots TYPO3 for every request: building the container, loading `ext_localconf.php` and TCA, wiring the services. With `KOH_PERSISTENT_BOOTSTRAP=1` it boots for the first two frontend requests and keeps the second boot: every later request starts from the state that boot left behind. Off, the worker behaves as before.

Why the second: the first boot of a worker often runs on cold caches (after a deployment or a cache flush). Building TCA and the configuration from scratch then instantiates request services too: the runtime cache, the CacheManager, `ConnectionPool`, `FlashMessageService`, services of the form framework. Kept as boot state, the runtime cache carried one request's entries into the next; the form framework's translations came out empty in English, and the empty labels went into the `l10n` cache, where they outlived the worker. The first request therefore serves its page with a boot of its own, which is dropped; the second boot runs on the caches the first one warmed. The runtime cache, the CacheManager, Context and the FlashMessageService are never kept even if a boot created them, and globals the first request added are removed before the second boot.

What a boot produces is either configuration, the same for every request, or request state, which must not outlive its request. Right after the kept boot the worker records what exists at that point, and before every later request it puts exactly that back:

| What | Per request | Why |
|---|---|---|
| Services in the DI container | `Container::reset()` drops every service instantiated since the boot (Context, PageRenderer, AssetCollector, CacheManager with the runtime cache, the Extbase persistence session, every extension service) and calls `reset()` on `ResetInterface` services; the services that existed after the boot are put back | The boot's own services hold what `ext_localconf.php` registered (renderer, text extractor and meta tag registries, the TCA schema); everything else is request state |
| Objects kept from the boot | Every property of the kept services, singleton instances and objects in globals goes back to the value it had after the boot (`KeptObjectState`): declared, non-static, non-readonly properties of every class in the hierarchy, uninitialised ones back to uninitialised | Keeping an object keeps what a request writes into it. `MetaTagManagerRegistry` creates its managers on first use and keeps them in the boot's instance; without this, every page carried the description and Open Graph tags of an earlier request, because EXT:seo adds them without replacing |
| `RequestId`, `RequestClock`, `LogManager` | New instances, as `Bootstrap::init()` creates them | The request id carries the CSP nonce; the clock and the log lines belong to the request |
| `GeneralUtility` | Container set again, singleton instances back to those after the boot, `getIndpEnv()` memo and class-name cache emptied | `RequestStateReset` empties both after every request |
| Globals | Restored to their state after the boot (`TYPO3_CONF_VARS`, `TCA`, ...); globals a request added (`TYPO3_REQUEST`, `BE_USER`, `LANG`, `TSFE`, ...) removed | A request may change `TYPO3_CONF_VARS` at runtime |
| Time | `SystemEnvironmentBuilder::run()`: `EXEC_TIME`, `SIM_EXEC_TIME`, `ACCESS_TIME`, `SIM_ACCESS_TIME`, `Environment`; the date aspect follows from the new Context | Access checks and the date aspect depend on it |
| Error handling, time zone | Set up again | `displayErrors = -1` decides per client address |
| Database connections | Unchanged: `DatabaseConnectionReuse` as before | `ConnectionPool` keeps them outside the container |

A failsafe boot (no usable configuration) is never kept.

**Leak guard.** With the switch on, the worker checks every response before it is sent: the host `getIndpEnv()` memoised, every other memoised `getIndpEnv()` value against a fresh computation, the host of `$GLOBALS['TYPO3_REQUEST']`, and the host and path prefix of the site language TYPO3 resolved. On a mismatch the response is discarded, the client gets `503` with `Cache-Control: no-store`, one line is logged

```json
{"koh_worker_leak":"frontend","check":"indpenv_host","expected":"site-b.example","actual":"site-a.example","worker_request":2}
```

and the worker ends, so FrankenPHP starts a fresh one for the next request. Of the page itself it reads one tag: the `og:url` in the `<head>` of an HTML response with status 200 must name the request's host and page (`og_url_host`, `og_url_path`; a request further below the `og:url`, as a route enhancer produces, is fine, except below the language's root). The meta tags come from `MetaTagManagerRegistry`, and `og:url` is the one of them TYPO3 renders as a link to the page itself, so it is what a meta tag manager carried over from another request gives away. Wrong content from other state (for example a service that caches a page title) is the reset's job and the worker alternation test's to prove.

**Audit.** `KOH_PERSISTENT_BOOTSTRAP_AUDIT=1` (diagnostics, not for production traffic) logs, once per worker, the kept objects and any of them that point to request state (a Context, a request, a site, a site language, a LanguageService, PageRenderer, AssetCollector, a user), and after every request what it changed in kept objects, in objects up to four levels behind them, and in static properties, before the reset undoes it:

```json
{"koh_worker_kept":49,"observed":120,"request_references":[],"objects":{"service:TYPO3\\CMS\\Core\\MetaTag\\MetaTagManagerRegistry":"TYPO3\\CMS\\Core\\MetaTag\\MetaTagManagerRegistry"}}
{"koh_worker_drift":1,"objects":[{"label":"service:TYPO3\\CMS\\Core\\MetaTag\\MetaTagManagerRegistry","class":"TYPO3\\CMS\\Core\\MetaTag\\MetaTagManagerRegistry","properties":["instances","managers"]}],"statics":[]}
```

A kept object's own properties are restored anyway; what the audit is for are the objects behind them and static properties, which nothing restores.

**Configuration services.** `KOH_PERSISTENT_CONFIG_SERVICES=1` (only with `KOH_PERSISTENT_BOOTSTRAP=1`) builds `content.security.policies`, `fluid.namespaces` and `fluid.component.collections` before the boot state is recorded, so they are kept instead of being derived again from the package list by every request (one file check per package and service). Their content is what KeptObjectState cannot put back: the Fluid services are `ArrayObject`s, the policies a `Map` of further maps. So their state right after the boot is recorded separately, and before every request each is compared with it; one that a request changed in place is put back in place, the same object, and reported as `{"koh_worker_drift":…,"objects":[…],"restored":true}`. Reading the policies moves their iteration cursor; that is put back without a report. The worker alternation test runs with the switch on as well (`test:worker-alternation:config-services`), with a middleware that changes both kinds after every site B page; no later response may show it.

**Static properties are not reset**, in either mode: TYPO3 offers no inventory of what an extension keeps in static properties, and resetting them to their defaults would also drop what is meant to last, such as `ConnectionPool`'s connections. An extension that appends to a static property in a constructor (the worker alternation test's check 5) grows by one piece per request in either mode. `MAX_REQUESTS` bounds that: the worker restarts after that many requests.

Measured with the worker alternation test (SQLite, 96 requests, one worker, medians): booting per request, `boot_ms` 11.6 and `total_ms` 20.0, with the per-request memory peak growing from 16 to 26 MB over the run; with the persistent bootstrap (then still keeping the first boot), the first request 284 ms, every later one `boot_ms` 0.1 and `total_ms` 6.7, with the memory peak flat at 16 MB. With the form checks (122 requests, 26 of them form steps, keeping the second boot): booting per request, `total_ms` 34.2 and a memory peak growing from 20 to 30 MB; with the persistent bootstrap, the two booting requests about 350 ms, every later one `boot_ms` 0.1 and `total_ms` 23.3, with the memory peak between 22 and 26 MB.

### Worker memos

Two switches keep work across requests in a FrankenPHP worker. Both are off by default, take effect only in a worker (CLI, classic requests and backend pods are unchanged), and hold no request state.

**`KOH_WORKER_CACHE_MEMO=1`** gives the read-mostly caches `hash`, `rootline`, `dpnglossary_termscache`, `structured_content_schema` and `consent_catalog` (`KOH_WORKER_CACHE_MEMO_CACHES`, comma-separated, overrides the list; only caches with the plain `VariableFrontend` that exist when this extension's `ext_localconf.php` runs are switched) the frontend `WorkerMemoVariableFrontend`. In TYPO3 14 a cache read is a backend read, an HMAC check (SHA3-384 over the payload) and `unserialize()`; for a 2.2 MiB menu tree that is 1.8 + 6.1 + 2.6 ms. The frontend still reads the backend on every `get()`, and that read is the validation: if the bytes are identical to bytes this worker already verified, the HMAC check is skipped, and for payloads without objects and PHP references the decoded value (scalars and arrays, which PHP copies on write) is returned as is. Payloads with objects are unserialised again for every read, so no object is shared between requests. Different bytes, a missing entry or a failed check go the core way. Flushes, tag flushes and writes on any pod are therefore seen on the next read on every pod, with no generation key and no TTL. Budget: `KOH_WORKER_CACHE_MEMO_MAX_BYTES` (default 32 MiB per worker, raw payload plus measured decoded size), least recently used out.

**`KOH_WORKER_TEMPLATE_PATH_MEMO=1`** decorates `ViewFactoryInterface` so that Fluid views resolve their files through `MemoizingTemplatePaths`: sanitised root paths, resolved templates, layouts and partials (including failed lookups), template identifiers and the existence of an explicit template file are remembered per worker, keyed by the full lookup input (root paths, name, format). Only lookups inside the vendor directory and the active packages are kept; anything else (`fileadmin/`, `var/`, paths with `..`) is resolved on every call. Code there changes only with a deployment, which starts new workers; a template edited inside a running worker is not noticed, so keep the switch off for local template work. `open_basedir` is untouched. Bound: `KOH_WORKER_TEMPLATE_PATH_MEMO_MAX_ENTRIES` (default 8192).

The worker alternation test runs once more with both switches on (`test:worker-alternation:memo`).

### Lazy page layout

TYPO3 resolves the backend layout of every frontend page while it builds the page information (`PageInformationFactory`), also when the page cache then answers the request and nothing reads the layout. For a layout from page TSconfig (`pagets__*`) that builds the page's TSconfig, conditions included. With **`KOH_LAZY_PAGE_LAYOUT=1`** the container's `PageLayoutResolver` is `LazyPageLayoutResolver`, which returns a PHP lazy proxy for such a layout: a `PageLayout` that runs TYPO3's own resolution the first time something reads it (PAGEVIEW, `PageContentFetchingProcessor`) and is then exactly what TYPO3 returns. Every other identifier (`default`, `none`, a `backend_layout` record, other data providers) resolves at once, as before. Off, the resolver is TYPO3's unchanged. Needs PHP 8.4 and TYPO3 14; the switch is read per request, in and outside a worker.

One case differs: a `pagets__*` identifier the page TSconfig does not define. TYPO3 then sets no layout (`getPageLayout()` is null); the proxy, already handed out, resolves to a layout with that identifier, an empty title and no content areas, and logs `{"koh_page_layout_missing":"pagets__…","page":<uid>}`. `PageContentFetchingProcessor` treats a missing layout as no content areas anyway; PAGEVIEW passes an empty collection instead of null, so `{content.main}` renders nothing in both cases and only a template that tests `{content}` itself sees a difference. The log line points at the misconfiguration.

### CacheHashRedirectMiddleware

With `FE.cacheHash.enforceValidation` and `FE.pageNotFoundOnCHashError`, TYPO3 answers a page URL that carries a parameter the cHash would have to cover, but no cHash, with 404. That keeps random parameters from bypassing every cache, but a visitor who followed a link with a click id a mailer or a social network appended lands on an error page.

The middleware runs right before `typo3/cms-frontend/page-argument-validator` and acts only in that case: GET or HEAD, no (or an empty) cHash, at least one parameter the cHash would have to cover. It answers `301` to the same URL without those parameters, with `Cache-Control: private, max-age=3600`. Parameters excluded from the cHash (`utm_*`, `gclid`, …) stay in the target. A redirect costs no render and creates no page cache entry, so the protection stays and the visitor gets the page.

Behind caddy-proxy, tracking parameters are removed before the edge cache and never reach TYPO3. The proxy passes the client's query string in `X-Koh-Original-Query` (always set by the proxy, so a client value never arrives); the parameters it removed are put back into the target, so analytics still sees them. That is why the redirect is `private`: the edge cache key carries no tracking parameters, and a stored redirect would hand one visitor's values to the next. Edge protection is unchanged, because a new value of an unknown parameter is a miss either way.

Left to TYPO3: a cHash that is present but wrong (still 404), other methods, and configurations in which a missing cHash is not a 404. `KOH_CHASH_REDIRECT=0` switches it off.

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

**Trees whose pages do not list each other.** A move, delete, publish or new page keeps the core's siblings, because nothing says which fields changed. In a tree of articles that is every other article for each new one, in every language, although each article lists hand-picked related pages and only the parent's list shows the new one. The page TSconfig of such a tree can switch the siblings off at the edge:

```typoscript
# The articles below page 123; a condition on the parent leaves page 123's own siblings as they are.
[123 in tree.rootLineParentIds]
    TCEMAIN.edgeCache.purgeSiblings = 0
[END]
```

A change to a page there purges the page and its parent, whatever changed. The page cache still flushes the siblings; only the edge leaves them. Anything but `0` keeps the siblings. Do not set it where a page shows its siblings (a section menu, previous/next links, a "more in this section" list): those would keep the old title or miss the new page until their edge entry expires.

**Purging by tag.** A page renders under more URLs than the one the router builds for it: pagination and category routes (`/blog/seite/2`, `/blog/kategorie/<slug>`), another page type (`/blog/feed.xml`), plugin detail routes (`/glossar/<term>`), query variants. Each is its own Souin key, and a URL purge reaches none of them; they stayed at the edge until they expired. With **`KOH_EDGE_PURGE_BY_TAG=1`**:

- `SurrogateKeyMiddleware` adds the cache tags TYPO3 collected for the response after the site identifier: the page's own `pageId_<n>` (from the routing result), the other `pageId_<n>` (content from another page, the translation's uid), then the record and plugin tags (`menuId_<n>`, `<table>_<uid>`, `addPageCacheTags`, …), each once, at most 8000 bytes (the limit TYPO3 sets for its own `X-TYPO3-Cache-Tags`). What does not fit is left out from the end, so record tags go before page tags, and logged as `Surrogate-Key truncated`. A typical page carries 40–80 bytes (`main, pageId_12, pageId_13, menuId_1, menuId_7`). A page served from the TYPO3 page cache carries the tags stored with it.
- An editorial change purges, besides the page URLs, by tag: `pageId_<n>` of every page the URL purge covers, and the other tags the core flushed (`<table>_<uid>`, `menuId_<pid>`, `cachetag:<tag>`). The narrowing above applies: a page save that changed no menu field purges the page and its parent by tag, without the siblings and without the menu and link tags. A menu field change keeps them all, so every page that shows the menu with the old title goes, as in TYPO3's page cache.
- Tags of a backend-only table (TCA `ctrl.adminOnly`: backend users and groups, file mounts, TypoScript records, scheduler tasks, workspaces, …) are neither emitted nor purged (`EdgeCacheTags::isEdgeTag()`, shared by header and purge): no page renders those records. The scheduler saving its task records after a run, for example, sends no PURGE at all.
- The host-wide purge of query variants (`GET-<host>-/?.+=`) is not sent: a query variant carries its page's tags.
- The tags go in one `PURGE {apiEndpoint}` per 8000 bytes of header, without asking Souin first which tags it holds. Souin (v1.7.10) looks a tag up exactly and deletes the keys filed under it, so `pageId_23` does not reach `pageId_2300` or `pageId_230`: checked against the caddy-proxy 4.10.8 image with a lab TYPO3 in front, 2026-11-11.
- The tags travel in `PurgeEdgeCache`, so the koh_async consumer sends them whatever its own switches say. A message queued by an earlier release (without tags) is handled as before.

Responses cached before the switch carry no page tags; the page URL purges still reach their base URLs, everything else goes when it expires or with one full flush after switching on. The switch is read in two places: where pages are rendered (the header) and where editors save (the purge); set it on both, the frontend first or at the same time.

**Where the purge is sent.** The listener resolves the URLs and dispatches them as one `PurgeEdgeCache` message per hook call; `PurgeEdgeCacheHandler` sends the PURGE requests. By default TYPO3 handles the message in the request, so an editor's save waits for the purges as it always did. With [koh/typo3-async](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async) the message can leave the request and be sent by its consumer instead, when all three hold:

- the installed koh/typo3-async has the `koh_async_cache` transport (the first release after 1.4.8);
- its async mode is on (`MESSENGER_ASYNC=1`, set by the platform together with the consumer);
- `KOH_ASYNC_EDGE_PURGE=1` on the pods editors save from.

Switch it on per installation, and only after its consumer reads the queue (`messenger:consume … koh_async_cache`); a queue nobody reads keeps the purges and the edge stays stale. The message class is put on koh_async's `messageClassPrefixes` whenever the transport exists, so the consumer decodes it whatever its own switches say. With `KOH_CACHE_PURGE_AUDIT=1` the audit line written in the consumer still names the request and backend user that asked, and adds `handled_by` for the consumer. Full flushes (`all`/`pages`, `CacheFlushEvent`) and file purges stay in the request.

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

With `KOH_EDGE_PURGE_BY_TAG=1`, a tag added to the page cache (`$request->getAttribute('frontend.cache.collector')->addCacheTags(...)`, `stdWrap.addPageCacheTags`) is on the response as well, and a `cachetag:<tag>` flush reaches the edge. A flush through the `CacheManager` alone (no DataHandler) has no hook and does not.

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

## Worker alternation test

A worker serves one request after another in the same PHP process, so
anything a request leaves behind in static properties or globals is there
for the next one. The reset between requests (`RequestStateReset`) exists
because of exactly that: without it, `GeneralUtility::getIndpEnv()` answers a
request for one domain with the host of the domain served before it.

`Tests/Integration/WorkerAlternation/` checks this end to end. `run.sh`
builds a throwaway TYPO3 14 project on SQLite with this working tree's
extension, two sites (`site-a.test`, `site-b.test`) with German and English
and two pages each, all on the same slugs, and starts FrankenPHP with exactly
one frontend worker. `check.php` then sends 96 page requests that switch host,
language and page in a fixed, interleaved order, including back-to-back
switches and repeats, and checks every response against its own request:

- HTTP 200;
- host, site URL and request URI from `getIndpEnv()`, from the request's
  `normalizedParams`, from `$GLOBALS['TYPO3_REQUEST']` and from a
  `SingletonInterface` object that remembers the host it first saw;
- site identifier and base, language id, code and base, page uid and title;
- absolute links to the page itself and to the other page of the site, from
  PHP and from TypoScript `typolink`.

A second group of checks covers the state TYPO3 builds up during a request
and would keep if the bootstrap were shared between requests:

1. **Context aspects:** the language aspect (id, content id, overlay type,
   fallback chain; English is configured with `fallbackType: fallback`, so
   the two languages differ in all four), the visibility aspect (nothing
   hidden or deleted included), the `frontend.user` and `backend.user`
   aspects (nobody logged in), and the request time: the date aspect's
   timestamp, `EXEC_TIME` and `SIM_EXEC_TIME` must fall within the seconds
   the request was under way and never go backwards. The runner pauses for
   just over a second twice, so a time kept from an earlier request cannot
   pass for a fresh one.
2. **Extbase:** `findByUid()` on a small repository over a test table with one
   greeting per site and language. A persistence session kept from an
   earlier request would answer from its identity map, in that request's
   language.
3. **PageRenderer and AssetCollector:** each page adds a meta tag, inline
   JavaScript and a style sheet through PageRenderer, and inline JavaScript
   and a style sheet through AssetCollector, all keyed by page and language.
   The response must contain each exactly once, for its own page, and
   exactly one `<title>`, its own.
   **The head and the menu:** every page has its own description and Open
   Graph title and description (EXT:seo, through `MetaTagManagerRegistry`,
   added without replacing), an `og:url` from `page.meta`, a meta tag of an
   extension's own meta tag manager registered in `ext_localconf.php`,
   canonical and hreflang (EXT:seo), JSON-LD with `@id` and `url`, and a main
   menu with the current page marked. Each must be there exactly once and be
   this page's, in this language; `html lang` too.
4. **Language-dependent data loaded in a constructor**, the way glossary
   parsers load their terms: once in a `SingletonInterface` object created
   through `makeInstance()`, once in a shared service of the DI container.
   Both must hold the terms of the request's language.
5. **A static property a constructor appends to**, as
   `featdd/dpn_glossary`'s `ParserService` does: its length must not change
   from request to request. *Informational:* this check only warns, see
   below.
6. **The global request at the end of rendering:** `$GLOBALS['TYPO3_REQUEST']`
   and its `frontend.page.information` page id, read by the last `USER_INT`
   of the page and by a middleware after the page has been rendered, must be
   this request's.
7. **Forms of the form framework** (`typo3/cms-form`), modelled on the intake
   forms of the real sites. Each site has a single-page form (Text, Textarea
   and SingleSelect fields, `NotEmpty` and `EmailAddress` validators) on its
   second page and a two-page form on its root page; labels, validation
   messages and the confirmation come from XLF in German and English, the
   confirmation naming site and language. The finishers are `SaveToDatabase`,
   a test finisher that records the site, language and host of the request
   into the same row, and `Confirmation`. Between the page requests the
   runner fills them in on both hosts and in both languages, each value
   unique to its request:
   - an invalid submission (required field empty, a broken address) gets the
     error messages of its own language, shows its own values again and
     stores nothing;
   - a valid one gets the confirmation of its site and language and stores
     exactly one row with its values, site, language and host;
   - step 1 of the two-page form on one host, requests to the other host,
     then step 2 on the first: it finishes with step 1's name, which only
     travelled in the HMAC-signed `__state`;
   - right after a submission, the form on the other host is empty, with a
     state that holds no values;
   - a submission with a changed `__trustedProperties` or `__state` (the
     signature kept) is refused: no confirmation, no row;
   - no response contains a value an earlier submission sent.
8. **Content security policy and Fluid configuration:** each site has its
   own `csp.yaml` and the markers package a frontend policy of its own; every
   response's `Content-Security-Policy` header must carry the package's and
   its own site's source and never the other site's, must be the same for
   every response of that page apart from the nonce and the report URI's
   request time, and its nonce must be used in the body and never have been
   sent before. A ViewHelper reached through a global Fluid namespace
   (`Configuration/Fluid/Namespaces.php`, no declaration in the template)
   renders the page and language. After every site B page a middleware
   changes the policies and the global Fluid namespaces in place; no later
   response may allow its source or know its namespace.

The worker passes checks 1 to 4 and 6 to 8 in both modes, booting per request
and with `KOH_PERSISTENT_BOOTSTRAP=1`. Check 1 includes a marker that is only
set on a Context object that has already served a request, so it also proves
that every request gets a new Context. Check 5 does not pass in either mode:
nothing resets static properties, so the appended string grows by one piece
with every request (see *Persistent bootstrap*). The test reports that as a
warning and does not fail; making it a failure is a TODO for the day static
state can be reset safely.

The markers come from a `USER_INT` with `config.no_cache = 1`, so every
request runs the full render path. Each response also carries a token and a
request counter that live in a static property of the worker: the run only
counts if the token never changes and the counter rises by exactly one per
request, which proves that one worker process served all of it. Any mismatch
fails with the request number, URL, marker, wrong value and expected value.

CI runs it twice: `test:worker-alternation` boots per request,
`test:worker-alternation:persistent` sets `WA_PERSISTENT_BOOTSTRAP=1`, which
starts the worker with `KOH_PERSISTENT_BOOTSTRAP=1`. After the requests the
run summarises the worker's `koh_timing` lines (medians of `boot_ms`,
`app_ms`, `total_ms`, the memory peak) and fails unless they show the
expected mode (every request `fresh`; or the first two `fresh` and every
later one `reused`) and no `koh_worker_leak` line. Locally it needs what the CI image
provides (PHP with curl and pdo_sqlite, Composer, FrankenPHP, git, tar).
Docker cannot mount every directory, so copy the working tree into a
container rather than mounting it:

```bash
docker create --name worker-alternation --entrypoint sleep \
    registry.ole-hartwig.eu/devops/images/franken-php/ci:3.2.173-8.5 infinity
docker start worker-alternation
git ls-files -z -co --exclude-standard | tar --null -T - -cf - \
    | docker exec -i worker-alternation sh -c 'mkdir -p /repo && tar --no-same-owner -xf - -C /repo'
docker exec worker-alternation sh -c 'cd /repo && git init -q && git add -A \
    && Tests/Integration/WorkerAlternation/run.sh'
docker rm -f worker-alternation
```

`WA_WORK_DIR` sets the directory the test project is built in (default: a
new temporary directory); the FrankenPHP and TYPO3 logs are printed when the
test fails.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| I don't see the 103 in `curl -v` | The Souin cache answers hits itself, without a 103. Use `Cache-Control: no-cache` plus a cache buster in the URL. The request needs `Accept: text/html`, and the worker must have rendered a page of that host once. Also, curl < 8.10 silently does not show the 103. |
| Browser console `Refused to apply style from 'https://host/EXT:...'` | Before v4.0.3 — EXT: resolution was not implemented. Update to >= v4.0.6. |
| The cache is not invalidated | Check the Caddy host/port/protocol, enable `enableDebugLogging`, check the logs in `/var/log/typo3` |
| API calls fail with 401 | Check the API key, allow the `X-API-Key` header in the Caddy config |

## License

GPL-2.0-or-later
