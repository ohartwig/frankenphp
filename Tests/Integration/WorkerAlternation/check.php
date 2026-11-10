<?php

declare(strict_types=1);

/*
 * Sends alternating requests for two sites and two languages to a FrankenPHP
 * instance with ONE frontend worker and checks that every response describes
 * its own request. Usage: php check.php <server-ip> <port> <project-dir>
 *
 * Every response carries the markers MarkerRenderer and the TypoScript of the
 * markers site set render. Each marker must equal what the request that
 * produced the response should yield; one value from a previous request is a
 * leak and fails the run. The worker markers prove that one worker served the
 * whole run: the token never changes and the count grows by exactly one per
 * request.
 *
 * Beyond the markers, each response must carry exactly its own <title> and
 * its own PageRenderer and AssetCollector assets (no other page's, no
 * duplicates), and a request time from the seconds it was under way. Its
 * head must describe it and nothing else: description and Open Graph tags
 * (EXT:seo and page.meta, through MetaTagManagerRegistry), a meta tag of an
 * extension's own meta tag manager, canonical, hreflang, html lang, JSON-LD
 * @id and url, and the main menu must mark this page as the current one.
 *
 * Between the page requests it fills in forms of the form framework on both
 * sites and in both languages (see the form scenarios below), POSTs that run
 * through the same worker and the same checks, and reads the project's
 * database to see what the finishers stored.
 *
 * Exit code 0 on success, 1 on any mismatch. Warnings (the static-append
 * probe) are printed but do not change the exit code.
 */

$ip = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8080);
$projectDir = rtrim((string) ($argv[3] ?? ''), '/');
$settings = is_file($projectDir . '/config/system/settings.php') ? require $projectDir . '/config/system/settings.php' : [];
$databasePath = (string) ($settings['DB']['Connections']['Default']['path'] ?? '');
if ($databasePath === '' || !is_file($databasePath)) {
    fwrite(STDERR, "No SQLite database found for project '$projectDir'.\n");
    exit(2);
}
$database = new PDO('sqlite:' . $databasePath);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sites = [
    'site-a' => ['host' => 'site-a.test', 'label' => 'Site A', 'pages' => [1 => 2, 2 => 1]],
    'site-b' => ['host' => 'site-b.test', 'label' => 'Site B', 'pages' => [10 => 11, 11 => 10]],
];
$languages = [
    0 => ['code' => 'de', 'hreflang' => 'de-DE', 'prefix' => '/', 'titles' => ['Startseite', 'Zweite Seite'], 'slugs' => ['', 'zweite-seite'], 'greeting' => 'Hallo', 'overlayType' => 'includeFloating', 'fallbackChain' => 'pageNotFound'],
    1 => ['code' => 'en', 'hreflang' => 'en-GB', 'prefix' => '/en/', 'titles' => ['Home', 'Second Page'], 'slugs' => ['', 'second-page'], 'greeting' => 'Hello', 'overlayType' => 'mixed', 'fallbackChain' => '0,pageNotFound'],
];

/**
 * One target per site, language and page, with everything the response for
 * it must contain.
 *
 * @var array<string, array{url: string, host: string, path: string, expected: array<string, string>}> $targets
 */
$targets = [];
foreach ($sites as $identifier => $site) {
    $authority = $site['host'] . ':' . $port;
    $siteBase = 'http://' . $authority . '/';
    $pageIds = array_keys($site['pages']);
    foreach ($languages as $languageId => $language) {
        $paths = [];
        foreach ($pageIds as $index => $pageId) {
            $paths[$pageId] = $language['prefix'] . $language['slugs'][$index];
        }
        foreach ($pageIds as $index => $pageId) {
            $path = $paths[$pageId];
            $otherPageId = $site['pages'][$pageId];
            $title = $site['label'] . ' ' . $language['titles'][$index];
            $key = sprintf('%s/%s/%d', $identifier, $language['code'], $pageId);
            $terms = 'Site A ' . $language['greeting'] . ',Site B ' . $language['greeting'];
            $hreflang = [];
            foreach ($languages as $alternative) {
                $hreflang[$alternative['hreflang']] = 'http://' . $authority . $alternative['prefix'] . $alternative['slugs'][$index];
            }
            $hreflang['x-default'] = $hreflang[$languages[0]['hreflang']];
            ksort($hreflang);
            $targets[$key] = [
                'url' => 'http://' . $authority . $path,
                'host' => $site['host'],
                'path' => $path,
                'assetKey' => $pageId . '-' . $languageId,
                'title' => $site['label'] . ': ' . $title,
                // The head: what EXT:seo, page.meta and the header data
                // render for this page and language, see seed.php and the
                // markers site set.
                'head' => [
                    'html.lang' => [$language['hreflang']],
                    'meta.description' => ['Description of ' . $title],
                    'meta.og:title' => ['OG title of ' . $title],
                    'meta.og:description' => ['OG description of ' . $title],
                    'meta.og:url' => ['http://' . $authority . $path],
                    'meta.wa-mt' => ['wa-mt-' . $pageId . '-' . $languageId],
                    'canonical' => ['http://' . $authority . $path],
                    'hreflang' => [implode(' ', array_map(static fn(string $code, string $url): string => $code . '=' . $url, array_keys($hreflang), $hreflang))],
                    'jsonld.@id' => ['http://' . $authority . $path . '#webpage'],
                    'jsonld.url' => ['http://' . $authority . $path],
                    'menu.cur' => [$path],
                    'menu.act' => $index === 0 ? [] : [$language['prefix']],
                ],
                'expected' => [
                    'indpenv.http_host' => $authority,
                    'indpenv.request_host' => 'http://' . $authority,
                    'indpenv.site_url' => $siteBase,
                    'indpenv.request_uri' => $path,
                    'normalized.http_host' => $authority,
                    'normalized.site_url' => $siteBase,
                    'globals.request_host' => $authority,
                    'singleton.request_host' => $authority,
                    'site.identifier' => $identifier,
                    'site.base' => $siteBase,
                    'language.id' => (string) $languageId,
                    'language.code' => $language['code'],
                    'language.base' => 'http://' . $authority . $language['prefix'],
                    'page.uid' => (string) $pageId,
                    'page.title' => $title,
                    'link.self' => 'http://' . $authority . $path,
                    'link.other' => 'http://' . $authority . $paths[$otherPageId],
                    'ts.site.identifier' => $identifier,
                    'ts.indpenv.http_host' => $authority,
                    'ts.language.id' => (string) $languageId,
                    'ts.page.title' => $title,
                    'ts.link.self' => 'http://' . $authority . $path,
                    // Context aspects, on a Context object of this request
                    'context.fresh' => '1',
                    'context.language.id' => (string) $languageId,
                    'context.language.content_id' => (string) $languageId,
                    'context.language.overlay_type' => $language['overlayType'],
                    'context.language.fallback_chain' => $language['fallbackChain'],
                    'context.visibility.hidden_pages' => '0',
                    'context.visibility.hidden_content' => '0',
                    'context.visibility.deleted_records' => '0',
                    'context.frontend_user.logged_in' => '0',
                    'context.frontend_user.id' => '0',
                    'context.backend_user.logged_in' => '0',
                    'context.backend_user.id' => '0',
                    // Extbase repository read in the request's language
                    'extbase.title' => $site['label'] . ' ' . $language['greeting'],
                    'extbase.language_uid' => (string) $languageId,
                    // Language-dependent data loaded in a constructor
                    'singleton.language_id' => (string) $languageId,
                    'singleton.language_terms' => $terms,
                    'service.language_id' => (string) $languageId,
                    'service.language_terms' => $terms,
                    // The global request at the end of rendering
                    'late.int.globals_host' => $authority,
                    'late.int.globals_page_id' => (string) $pageId,
                    'late.int.request_page_id' => (string) $pageId,
                    'late.mw.globals_host' => $authority,
                    'late.mw.globals_path' => $path,
                    'late.mw.globals_page_id' => (string) $pageId,
                ],
            ];
        }
    }
}

// The request sequence. Deterministic, so a failure can be replayed.
mt_srand(20261109);
$byHost = ['site-a' => [], 'site-b' => []];
foreach (array_keys($targets) as $key) {
    $byHost[explode('/', $key)[0]][] = $key;
}
$sequence = [];
// 1. Six rounds over all targets, each in a new order, the host switching on
//    every request.
for ($round = 0; $round < 6; ++$round) {
    $a = $byHost['site-a'];
    $b = $byHost['site-b'];
    shuffle($a);
    shuffle($b);
    foreach ($a as $index => $key) {
        if ($round % 2 === 0) {
            array_push($sequence, $key, $b[$index]);
        } else {
            array_push($sequence, $b[$index], $key);
        }
    }
}
// 2. Back-to-back switches between the two hosts on the same path, and
//    between the two languages on the same host.
foreach (['de/1', 'de/2', 'en/1', 'en/2'] as $pair) {
    [$code, $n] = explode('/', $pair);
    $a = sprintf('site-a/%s/%d', $code, (int) $n);
    $b = sprintf('site-b/%s/%d', $code, 9 + (int) $n);
    array_push($sequence, $a, $b, $a, $b);
}
foreach (['site-a' => [1, 2], 'site-b' => [10, 11]] as $identifier => $pageIds) {
    foreach ($pageIds as $pageId) {
        array_push($sequence, "$identifier/de/$pageId", "$identifier/en/$pageId", "$identifier/de/$pageId", "$identifier/en/$pageId");
    }
}
// 3. The same request twice, then a switch.
$keys = array_keys($targets);
shuffle($keys);
foreach ($keys as $key) {
    array_push($sequence, $key, $key);
}

/**
 * What a page says about itself in its head, and its menu: every value of
 * each kind, in document order (hreflang as one sorted line).
 *
 * @return array<string, list<string>>
 */
$headFacts = static function (string $body): array {
    $decode = static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    $facts = [];
    $headEnd = stripos($body, '</head>');
    $head = $headEnd === false ? $body : substr($body, 0, $headEnd);
    if (preg_match('#<html\b[^>]*\blang="([^"]*)"#', $body, $match)) {
        $facts['html.lang'][] = $match[1];
    }
    preg_match_all('#<meta\b[^>]*>#', $head, $tags);
    foreach ($tags[0] as $tag) {
        if (preg_match('#\b(?:name|property)="([^"]*)"#', $tag, $name) && preg_match('#\bcontent="([^"]*)"#', $tag, $content)) {
            $facts['meta.' . $name[1]][] = $decode($content[1]);
        }
    }
    $hreflang = [];
    preg_match_all('#<link\b[^>]*>#', $head, $links);
    foreach ($links[0] as $link) {
        if (!preg_match('#\brel="([^"]*)"#', $link, $rel) || !preg_match('#\bhref="([^"]*)"#', $link, $href)) {
            continue;
        }
        if ($rel[1] === 'canonical') {
            $facts['canonical'][] = $decode($href[1]);
        } elseif ($rel[1] === 'alternate' && preg_match('#\bhreflang="([^"]*)"#', $link, $code)) {
            $hreflang[$code[1]] = $code[1] . '=' . $decode($href[1]);
        }
    }
    if ($hreflang !== []) {
        ksort($hreflang);
        $facts['hreflang'][] = implode(' ', $hreflang);
    }
    preg_match_all('#<script\b[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s', $body, $scripts);
    foreach ($scripts[1] as $json) {
        $data = json_decode($json, true);
        foreach (['@id', 'url'] as $field) {
            $facts['jsonld.' . $field][] = is_array($data) && is_string($data[$field] ?? null) ? $data[$field] : '(unparsable)';
        }
    }
    preg_match_all('#<li data-menu="(no|act|cur)"><a\b[^>]*\bhref="([^"]*)"#', $body, $items, PREG_SET_ORDER);
    $facts['menu.act'] = [];
    foreach ($items as [, $state, $href]) {
        if ($state !== 'no') {
            $facts['menu.' . $state][] = (string) parse_url($decode($href), PHP_URL_PATH);
        }
    }

    return $facts;
};

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RESOLVE => [
        'site-a.test:' . $port . ':' . $ip,
        'site-b.test:' . $port . ':' . $ip,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
]);

$failures = [];
// Checks that report without failing the run, see below.
$warnings = [];
$appendLength = null;
$previousTimestamp = 0;
$token = null;
$firstCount = null;
$lastCount = null;
$tokens = [];
$pids = [];
$number = 0;
$posts = 0;
// Every value a form submission has sent so far; none may show up in a later
// response that did not send it itself.
$submitted = [];
$started = microtime(true);

/**
 * One request through the worker, with every per-request check. GET when
 * $post is null, otherwise a multipart POST of $post to $url (a form action
 * of the page $key). Returns the body of a 200 response, null otherwise.
 *
 * With $strict false (a submission that must be refused) any status is
 * accepted and only the worker markers are checked, if the page has them.
 *
 * @param array<string, string>|null $post
 * @param list<string>               $ownValues submitted values this response may contain
 * @return array{status: int, body: string}|string|null
 */
$send = function (string $key, ?string $url = null, ?array $post = null, array $ownValues = [], bool $strict = true) use (
    &$failures, &$warnings, &$appendLength, &$previousTimestamp, &$token, &$firstCount, &$lastCount,
    &$tokens, &$pids, &$number, &$posts, &$submitted, $targets, $curl, $headFacts,
): array|string|null {
    $target = $targets[$key];
    $url ??= $target['url'];
    $label = sprintf('#%d %s %s (%s)', $number + 1, $post === null ? 'GET' : 'POST', $url, $key);
    // Twice in the run, let the clock move on by more than a second, so a
    // request time kept from an earlier request cannot pass for a fresh one.
    if ($number === 1 || $number === 48) {
        usleep(1_100_000);
    }
    curl_setopt($curl, CURLOPT_URL, $url);
    if ($post === null) {
        curl_setopt($curl, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
        ++$posts;
    }
    $before = time();
    $body = curl_exec($curl);
    $after = time();
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    ++$number;

    preg_match_all('#<li data-marker="([^"]+)">(.*?)</li>#s', is_string($body) ? $body : '', $matches, PREG_SET_ORDER);
    $markers = [];
    foreach ($matches as [, $name, $value]) {
        $markers[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    }

    // One worker for the whole run: same token, and the count it rendered
    // one up from the last page it rendered.
    if (isset($markers['worker.count'])) {
        $count = (int) $markers['worker.count'];
        $token ??= $markers['worker.token'] ?? null;
        $firstCount ??= $count;
        if (($markers['worker.token'] ?? null) !== $token) {
            $failures[] = sprintf('%s: served by another worker (token %s, first request had %s)', $label, var_export($markers['worker.token'] ?? null, true), var_export($token, true));
        }
        if ($lastCount !== null && $count !== $lastCount + 1) {
            $failures[] = sprintf('%s: worker request count is %d, expected %d; the worker restarted or another one answered', $label, $count, $lastCount + 1);
        }
        $lastCount = $count;
        $tokens[$markers['worker.token'] ?? '?'] = true;
        $pids[$markers['worker.pid'] ?? '?'] = true;
    }

    if (!$strict) {
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
    if (!is_string($body) || $status !== 200) {
        $failures[] = sprintf('%s: HTTP %d%s', $label, $status, is_string($body) ? ', body: ' . substr(trim($body), 0, 300) : ', ' . curl_error($curl));

        return null;
    }
    if (!isset($markers['worker.count'])) {
        $failures[] = sprintf('%s: page without worker markers', $label);
    }

    $expectedMarkers = $target['expected'];
    // A form is posted to its action, the page URL with the plugin's query.
    $expectedMarkers['indpenv.request_uri'] = (string) parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) !== null ? '?' . parse_url($url, PHP_URL_QUERY) : '');
    foreach ($expectedMarkers as $name => $expected) {
        $actual = $markers[$name] ?? null;
        if ($actual !== $expected) {
            $failures[] = sprintf(
                '%s: marker %s is %s, expected %s',
                $label,
                $name,
                $actual === null ? '(missing)' : var_export($actual, true),
                var_export($expected, true),
            );
        }
    }

    // The request time: within the seconds the request was under way, and
    // never behind the previous request's.
    foreach (['context.date.timestamp', 'globals.exec_time', 'globals.sim_exec_time'] as $name) {
        $value = (int) ($markers[$name] ?? 0);
        if ($value < $before || $value > $after || $value < $previousTimestamp) {
            $failures[] = sprintf(
                '%s: marker %s is %s, expected a time between %d and %d (not before the previous request\'s %d)',
                $label,
                $name,
                var_export($markers[$name] ?? null, true),
                $before,
                $after,
                $previousTimestamp,
            );
        }
    }
    $previousTimestamp = (int) ($markers['context.date.timestamp'] ?? 0);

    // PageRenderer and AssetCollector: exactly one of each asset, and it is
    // this page's in this language.
    preg_match_all('#<title>(.*?)</title>#s', $body, $titles);
    if ($titles[1] !== [$target['title']]) {
        $failures[] = sprintf('%s: <title> is %s, expected exactly %s', $label, json_encode($titles[1], JSON_UNESCAPED_UNICODE), json_encode([$target['title']], JSON_UNESCAPED_UNICODE));
    }
    preg_match_all('#wa-(pr-meta|pr-js|pr-css|ac-js|ac-css)-(\d+-\d+)#', $body, $assets, PREG_SET_ORDER);
    $found = array_fill_keys(['pr-meta', 'pr-js', 'pr-css', 'ac-js', 'ac-css'], []);
    foreach ($assets as [, $kind, $assetKey]) {
        $found[$kind][] = $assetKey;
    }
    foreach ($found as $kind => $assetKeys) {
        if ($assetKeys !== [$target['assetKey']]) {
            $failures[] = sprintf('%s: asset wa-%s found for %s, expected exactly [%s]', $label, $kind, $assetKeys === [] ? 'nothing' : '[' . implode(', ', $assetKeys) . ']', $target['assetKey']);
        }
    }

    // The head, and the menu: each tag exactly once and this page's.
    // Canonical only for a GET: a form action's arguments may go into it.
    $head = $headFacts($body);
    foreach ($target['head'] as $name => $expected) {
        if ($name === 'canonical' && $post !== null) {
            continue;
        }
        $actual = $head[$name] ?? [];
        if ($actual !== $expected) {
            $failures[] = sprintf('%s: head %s is %s, expected %s', $label, $name, json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    // Nothing an earlier form submission sent, unless this request sent it.
    foreach (array_diff($submitted, $ownValues) as $value) {
        if (str_contains($body, $value)) {
            $failures[] = sprintf('%s: form value %s of an earlier submission is in the response', $label, var_export($value, true));
        }
    }

    // A static property a constructor appends to (AppendingParser). The
    // worker does not reset static properties, so on a per-request bootstrap
    // this grows with every request; it is reported, not failed.
    // TODO: make this a failure once static state can be reset safely
    // between requests.
    $length = $markers['static.append_length'] ?? null;
    $appendLength ??= $length;
    if ($length !== $appendLength) {
        $warnings[] = sprintf('%s: static.append_length is %s, the first request had %s', $label, var_export($length, true), var_export($appendLength, true));
    }

    return $body;
};

/**
 * The form in a page as a browser would submit it: its action, and every
 * input, select, textarea and the forward submit button with their values
 * (hidden fields, the honeypot, __state, __trustedProperties).
 *
 * @return array{action: string, fields: array<string, string>, values: array<string, string>, state: string}|null
 */
$parseForm = static function (string $body, string $base): ?array {
    if (!preg_match('#<form\b[^>]*\baction="([^"]*)"[^>]*>(.*?)</form>#s', $body, $form)) {
        return null;
    }
    $fields = [];
    $values = [];
    preg_match_all('#<input\b([^>]*)>#s', $form[2], $inputs);
    foreach ($inputs[1] as $attributes) {
        if (!preg_match('#\bname="([^"]*)"#', $attributes, $name)) {
            continue;
        }
        preg_match('#\bvalue="([^"]*)"#', $attributes, $value);
        $fields[html_entity_decode($name[1])] = html_entity_decode($value[1] ?? '');
        if (!str_contains($attributes, 'type="hidden"')) {
            $values[html_entity_decode($name[1])] = html_entity_decode($value[1] ?? '');
        }
    }
    preg_match_all('#<textarea\b[^>]*\bname="([^"]*)"[^>]*>(.*?)</textarea>#s', $form[2], $textareas, PREG_SET_ORDER);
    foreach ($textareas as [, $name, $value]) {
        $fields[html_entity_decode($name)] = html_entity_decode($value);
        $values[html_entity_decode($name)] = html_entity_decode($value);
    }
    preg_match_all('#<select\b[^>]*\bname="([^"]*)"[^>]*>(.*?)</select>#s', $form[2], $selects, PREG_SET_ORDER);
    foreach ($selects as [, $name, $options]) {
        $selected = preg_match('#<option\b[^>]*value="([^"]*)"[^>]*selected#', $options, $option) ? $option[1] : '';
        $fields[html_entity_decode($name)] = html_entity_decode($selected);
        $values[html_entity_decode($name)] = html_entity_decode($selected);
    }
    // The submit button that moves forward: the highest __currentPage.
    preg_match_all('#<button\b[^>]*\bname="([^"]*__currentPage[^"]*)"[^>]*\bvalue="(\d+)"#', $form[2], $buttons, PREG_SET_ORDER);
    usort($buttons, static fn(array $x, array $y): int => (int) $y[2] <=> (int) $x[2]);
    if ($buttons !== []) {
        $fields[html_entity_decode($buttons[0][1])] = $buttons[0][2];
    }
    $state = '';
    foreach ($fields as $name => $value) {
        if (str_ends_with($name, '[__state]')) {
            $state = $value;
        }
    }

    return [
        'action' => $base . html_entity_decode($form[1]),
        'fields' => $fields,
        'values' => $values,
        'state' => $state,
    ];
};

/** The serialised form state inside __state, without its HMAC. */
$decodeState = static function (string $state): string {
    return (string) base64_decode(substr($state, 0, -64), true);
};

/**
 * @param array<string, string> $fields
 * @param array<string, string> $data   element identifier => value
 * @return array<string, string>
 */
$fill = static function (array $fields, array $data): array {
    foreach ($fields as $name => $value) {
        if (preg_match('#\[([^\[\]]+)\]$#', $name, $element) && array_key_exists($element[1], $data)) {
            $fields[$name] = $data[$element[1]];
        }
    }

    return $fields;
};

/** The confirmation text in a page, for failure messages. */
$confirmationIn = static function (string $body): string {
    return preg_match('#[^<>]*CONFIRMATION[^<>]*#', $body, $text) ? var_export(trim($text[0]), true) : 'none';
};

$rowCount = static function () use ($database): int {
    return (int) $database->query('SELECT COUNT(*) FROM tx_workeralternationmarkers_submission')->fetchColumn();
};

/** @return array<string, mixed> */
$lastRow = static function () use ($database): array {
    $row = $database->query('SELECT form, name, email, topic, message, site, language, host FROM tx_workeralternationmarkers_submission ORDER BY uid DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
};

$fail = function (string $message) use (&$failures, &$number): void {
    $failures[] = sprintf('#%d form: %s', $number, $message);
};

/**
 * Form scenarios, one request per step, run between the page requests. The
 * order interleaves both hosts and both languages, and leaves requests to the
 * other host between the two steps of a two-page form.
 */
$scenario = [];
$languageOf = ['de' => 0, 'en' => 1];
$errorText = ['de' => ['WA-ERROR-DE: Dieses Feld ist ein Pflichtfeld', 'WA-ERROR-DE: keine gültige E-Mail-Adresse'], 'en' => ['WA-ERROR-EN: this field is required', 'WA-ERROR-EN: not a valid email address']];
$forms = [];
$contact = function (string $identifier, string $code) use (&$forms, &$submitted, $send, $parseForm, $fill, $rowCount, $lastRow, $fail, $decodeState, $confirmationIn, $sites, $languageOf, $errorText, $port): array {
    $pageId = $identifier === 'site-a' ? 2 : 11;
    $key = "$identifier/$code/$pageId";
    $base = 'http://' . $sites[$identifier]['host'] . ':' . $port;
    $tag = "$identifier-$code";

    return [
        // Fetch the empty form.
        function () use (&$forms, $key, $base, $tag, $send, $parseForm, $fail): void {
            $body = $send($key);
            $form = $body === null ? null : $parseForm($body, $base);
            if ($form === null) {
                $fail("$key: no form in the page");

                return;
            }
            $forms[$tag] = $form;
        },
        // Invalid: required name missing, an address that is none.
        function () use (&$forms, &$submitted, &$number, $key, $base, $tag, $code, $send, $parseForm, $fill, $rowCount, $fail, $errorText): void {
            $form = $forms[$tag] ?? null;
            if ($form === null) {
                return;
            }
            $email = sprintf('not-an-address-r%d-%s', $number + 1, $tag);
            $message = sprintf('Message r%d %s invalid', $number + 1, $tag);
            $rows = $rowCount();
            $body = $send($key, $form['action'], $fill($form['fields'], ['name' => '', 'email' => $email, 'topic' => 'hosting', 'message' => $message]), [$email, $message]);
            $submitted[] = $email;
            $submitted[] = $message;
            if ($body === null) {
                return;
            }
            foreach ($errorText[$code] as $text) {
                if (!str_contains($body, htmlspecialchars($text))) {
                    $fail("$key: invalid submission does not show '$text'");
                }
            }
            foreach ($errorText[$code === 'de' ? 'en' : 'de'] as $text) {
                if (str_contains($body, htmlspecialchars($text))) {
                    $fail("$key: invalid submission shows the other language's '$text'");
                }
            }
            foreach ([$email, $message] as $value) {
                if (!str_contains($body, htmlspecialchars($value))) {
                    $fail("$key: invalid submission does not show its own value '$value' again");
                }
            }
            if ($rowCount() !== $rows) {
                $fail("$key: invalid submission stored a row");
            }
            $again = $parseForm($body, $base);
            if ($again === null) {
                $fail("$key: invalid submission shows no form");

                return;
            }
            $forms[$tag] = $again;
        },
        // Valid, continuing from the error page as a browser would.
        function () use (&$forms, &$submitted, &$number, $identifier, $code, $key, $tag, $send, $fill, $rowCount, $lastRow, $fail, $confirmationIn, $languageOf, $sites, $port): void {
            $form = $forms[$tag] ?? null;
            if ($form === null) {
                return;
            }
            $values = [
                'name' => sprintf('Name r%d %s', $number + 1, $tag),
                'email' => sprintf('r%d.%s@example.org', $number + 1, $tag),
                'topic' => 'support',
                'message' => sprintf('Message r%d %s valid', $number + 1, $tag),
            ];
            $rows = $rowCount();
            $body = $send($key, $form['action'], $fill($form['fields'], $values));
            array_push($submitted, $values['name'], $values['email'], $values['message']);
            if ($body === null) {
                return;
            }
            $confirmation = sprintf('WA-CONFIRMATION %s %s:', $identifier, $code);
            if (!str_contains($body, $confirmation)) {
                $fail("$key: valid submission does not confirm with '$confirmation', it shows " . $confirmationIn($body));
            }
            if (preg_match_all('#WA-CONFIRMATION [a-z-]+ [a-z]+:#', $body) !== 1) {
                $fail("$key: valid submission does not show exactly one confirmation");
            }
            if ($rowCount() !== $rows + 1) {
                $fail(sprintf('%s: valid submission stored %d rows, expected 1', $key, $rowCount() - $rows));

                return;
            }
            $expected = $values + [
                'form' => 'wa-contact-' . substr($identifier, -1),
                'site' => $identifier,
                'language' => $languageOf[$code],
                'host' => $sites[$identifier]['host'] . ':' . $port,
            ];
            $row = $lastRow();
            foreach ($expected as $column => $value) {
                if ((string) ($row[$column] ?? '') !== (string) $value) {
                    $fail(sprintf('%s: stored %s is %s, expected %s', $key, $column, var_export($row[$column] ?? null, true), var_export($value, true)));
                }
            }
            unset($forms[$tag]);
        },
        // Right after it, the same form on the other host: empty.
        function () use ($identifier, $code, $send, $parseForm, $decodeState, $fail, $sites, $port): void {
            $other = $identifier === 'site-a' ? 'site-b' : 'site-a';
            $key = sprintf('%s/%s/%d', $other, $code, $other === 'site-a' ? 2 : 11);
            $body = $send($key);
            $form = $body === null ? null : $parseForm($body, 'http://' . $sites[$other]['host'] . ':' . $port);
            if ($form === null) {
                $fail("$key: no form in the page");

                return;
            }
            foreach ($form['values'] as $name => $value) {
                if ($value !== '') {
                    $fail("$key: fresh form has $name prefilled with " . var_export($value, true));
                }
            }
            if (!str_contains($decodeState($form['state']), 'formValues";a:0:{}')) {
                $fail("$key: fresh form carries a state with values");
            }
        },
    ];
};

$steps = function (string $identifier, string $code) use (&$forms, &$submitted, &$number, $send, $parseForm, $fill, $rowCount, $lastRow, $fail, $decodeState, $confirmationIn, $sites, $port, $languageOf): array {
    $pageId = $identifier === 'site-a' ? 1 : 10;
    $key = "$identifier/$code/$pageId";
    $base = 'http://' . $sites[$identifier]['host'] . ':' . $port;
    $tag = "steps-$identifier-$code";

    return [
        function () use (&$forms, $key, $base, $tag, $send, $parseForm, $fail): void {
            $body = $send($key);
            $form = $body === null ? null : $parseForm($body, $base);
            if ($form === null) {
                $fail("$key: no two-step form in the page");

                return;
            }
            $forms[$tag] = $form;
        },
        // Step 1: the name, which from now on lives only in __state.
        function () use (&$forms, &$submitted, &$number, $key, $base, $tag, $send, $parseForm, $fill, $decodeState, $fail): void {
            $form = $forms[$tag] ?? null;
            if ($form === null) {
                return;
            }
            $name = sprintf('Step name r%d %s', $number + 1, $tag);
            $body = $send($key, $form['action'], $fill($form['fields'], ['name' => $name]), [$name]);
            $submitted[] = $name;
            $next = $body === null ? null : $parseForm($body, $base);
            if ($next === null || !isset(array_flip(array_map(static fn(string $n): string => preg_replace('#^.*\[([^\[\]]+)\]$#', '$1', $n), array_keys($next['values'])))['email'])) {
                $fail("$key: step 1 does not lead to the email step");

                return;
            }
            if (!str_contains($decodeState($next['state']), $name)) {
                $fail("$key: the state after step 1 does not carry the name");
            }
            $forms[$tag] = $next + ['name' => $name];
        },
        // Step 2, after requests to the other host: finishes with step 1's name.
        function () use (&$forms, &$submitted, &$number, $identifier, $code, $key, $tag, $send, $fill, $rowCount, $lastRow, $fail, $confirmationIn, $languageOf, $sites, $port): void {
            $form = $forms[$tag] ?? null;
            if ($form === null || !isset($form['name'])) {
                return;
            }
            $email = sprintf('step.r%d.%s@example.org', $number + 1, $tag);
            $rows = $rowCount();
            $body = $send($key, $form['action'], $fill($form['fields'], ['email' => $email]), [$form['name']]);
            $submitted[] = $email;
            if ($body === null) {
                return;
            }
            $confirmation = sprintf('WA-CONFIRMATION %s %s:', $identifier, $code);
            if (!str_contains($body, $confirmation)) {
                $fail("$key: step 2 does not confirm with '$confirmation', it shows " . $confirmationIn($body));
            }
            if ($rowCount() !== $rows + 1) {
                $fail(sprintf('%s: step 2 stored %d rows, expected 1', $key, $rowCount() - $rows));

                return;
            }
            $expected = [
                'form' => 'wa-steps-' . substr($identifier, -1),
                'name' => $form['name'],
                'email' => $email,
                'site' => $identifier,
                'language' => $languageOf[$code],
                'host' => $sites[$identifier]['host'] . ':' . $port,
            ];
            $row = $lastRow();
            foreach ($expected as $column => $value) {
                if ((string) ($row[$column] ?? '') !== (string) $value) {
                    $fail(sprintf('%s: step 2 stored %s as %s, expected %s', $key, $column, var_export($row[$column] ?? null, true), var_export($value, true)));
                }
            }
            unset($forms[$tag]);
        },
    ];
};

/**
 * A submission whose __trustedProperties or __state was changed after
 * signing: it must not be accepted (no confirmation, no row).
 */
$tampered = function (string $identifier, string $code, string $field) use ($send, $parseForm, $fill, $rowCount, $fail, $sites, $port, &$submitted, &$number): array {
    $pageId = $identifier === 'site-a' ? 2 : 11;
    $key = "$identifier/$code/$pageId";
    $base = 'http://' . $sites[$identifier]['host'] . ':' . $port;
    $form = null;

    return [
        function () use (&$form, $key, $base, $send, $parseForm, $fail): void {
            $body = $send($key);
            $form = $body === null ? null : $parseForm($body, $base);
            if ($form === null) {
                $fail("$key: no form in the page");
            }
        },
        function () use (&$form, &$submitted, &$number, $key, $field, $identifier, $code, $send, $fill, $rowCount, $fail): void {
            if ($form === null) {
                return;
            }
            $fields = $fill($form['fields'], [
                'name' => sprintf('Tampered r%d', $number + 1),
                'email' => sprintf('tampered.r%d@example.org', $number + 1),
                'topic' => 'support',
                'message' => 'tampered',
            ]);
            foreach ($fields as $name => $value) {
                if (str_ends_with($name, '[' . $field . ']')) {
                    // Change the signed part, keep the signature.
                    $fields[$name] = $field === '__state'
                        ? base64_encode(str_replace('lastDisplayedPageIndex";i:0', 'lastDisplayedPageIndex";i:1', (string) base64_decode(substr($value, 0, -64)))) . substr($value, -64)
                        : str_replace('"message":1', '"message":1,"uid":1', substr($value, 0, -64)) . substr($value, -64);
                }
            }
            $rows = $rowCount();
            $label = sprintf('%s: submission with a changed %s', $key, $field);
            $response = $send($key, $form['action'], $fields, [], false);
            $body = is_array($response) ? $response['body'] : '';
            $status = is_array($response) ? $response['status'] : 0;
            if (str_contains($body, 'WA-CONFIRMATION')) {
                $fail("$label was confirmed (HTTP $status)");
            }
            if ($rowCount() !== $rows) {
                $fail("$label was stored (HTTP $status)");
            }
        },
    ];
};

$queue = array_merge(
    // site A German and site B English, single-page, interleaved step by step
    ...array_map(null, $contact('site-a', 'de'), $contact('site-b', 'en')),
);
$twoStep = [$steps('site-a', 'en'), $steps('site-b', 'de')];
$queue = array_merge(
    $queue,
    [$twoStep[0][0], $twoStep[0][1]],
    // requests to the other host between step 1 and step 2 of site A
    ...array_map(null, $contact('site-b', 'de'), [$twoStep[1][0], $twoStep[1][1], $twoStep[1][2], static fn() => null]),
);
$queue[] = $twoStep[0][2];
$queue = array_merge($queue, $contact('site-a', 'en'), $tampered('site-a', 'de', '__trustedProperties'), $tampered('site-b', 'en', '__state'));
$queue = array_values(array_filter($queue));

// The page requests, with a form step after every second one.
foreach ($sequence as $position => $key) {
    $send($key);
    if ($position % 2 === 1 && $queue !== []) {
        (array_shift($queue))();
    }
}
while ($queue !== []) {
    (array_shift($queue))();
}

$seconds = microtime(true) - $started;
$total = $number;
printf(
    "%d requests (%d page requests, %d form submissions) over %d targets (2 hosts x 2 languages x 2 pages) in %.1f s; worker token(s) %s, worker request count %s..%s, PID(s) %s\n",
    $total,
    count($sequence),
    $posts,
    count($targets),
    $seconds,
    implode(',', array_keys($tokens)),
    $firstCount ?? '-',
    $lastCount ?? '-',
    implode(',', array_keys($pids)),
);

if ($warnings !== []) {
    printf("WARNING (informational, does not fail the run): a static property a constructor appends to grew in %d of %d responses\n", count($warnings), $total);
    foreach (array_slice($warnings, 0, 5) as $warning) {
        echo '  ' . $warning . "\n";
    }
    if (count($warnings) > 5) {
        printf("  ... and %d more\n", count($warnings) - 5);
    }
}

if ($failures !== []) {
    printf("FAILED: %d mismatches\n", count($failures));
    // Which checks failed, over the whole run; the details below are cut short.
    $byCheck = [];
    foreach ($failures as $failure) {
        $check = preg_replace(['#^\#\d+ (GET|POST) \S+ \([^)]+\): #', '#^\#\d+ form: \S+: #', '#^(marker \S+|asset \S+|head \S+|<title>|HTTP \d+|served by another worker|worker request count|form value).*$#s'], ['', 'form: ', '$1'], $failure);
        $byCheck[$check] = ($byCheck[$check] ?? 0) + 1;
    }
    arsort($byCheck);
    foreach ($byCheck as $check => $times) {
        printf("  %4d x %s\n", $times, $check);
    }
    foreach (array_slice($failures, 0, 60) as $failure) {
        echo '  ' . $failure . "\n";
    }
    if (count($failures) > 60) {
        printf("  ... and %d more\n", count($failures) - 60);
    }
    exit(1);
}

echo "OK: every response matched its own request, all served by one worker.\n";
exit(0);
