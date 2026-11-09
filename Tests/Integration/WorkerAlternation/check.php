<?php

declare(strict_types=1);

/*
 * Sends alternating requests for two sites and two languages to a FrankenPHP
 * instance with ONE frontend worker and checks that every response describes
 * its own request. Usage: php check.php [server-ip] [port]
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
 * duplicates), and a request time from the seconds it was under way.
 *
 * Exit code 0 on success, 1 on any mismatch. Warnings (the static-append
 * probe) are printed but do not change the exit code.
 */

$ip = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8080);

$sites = [
    'site-a' => ['host' => 'site-a.test', 'label' => 'Site A', 'pages' => [1 => 2, 2 => 1]],
    'site-b' => ['host' => 'site-b.test', 'label' => 'Site B', 'pages' => [10 => 11, 11 => 10]],
];
$languages = [
    0 => ['code' => 'de', 'prefix' => '/', 'titles' => ['Startseite', 'Zweite Seite'], 'slugs' => ['', 'zweite-seite'], 'greeting' => 'Hallo', 'overlayType' => 'includeFloating', 'fallbackChain' => 'pageNotFound'],
    1 => ['code' => 'en', 'prefix' => '/en/', 'titles' => ['Home', 'Second Page'], 'slugs' => ['', 'second-page'], 'greeting' => 'Hello', 'overlayType' => 'mixed', 'fallbackChain' => '0,pageNotFound'],
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
            $targets[$key] = [
                'url' => 'http://' . $authority . $path,
                'host' => $site['host'],
                'path' => $path,
                'assetKey' => $pageId . '-' . $languageId,
                'title' => $site['label'] . ': ' . $title,
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
                    // Context aspects
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
$started = microtime(true);

foreach ($sequence as $number => $key) {
    $target = $targets[$key];
    $label = sprintf('#%d %s (%s)', $number + 1, $target['url'], $key);
    // Twice in the run, let the clock move on by more than a second, so a
    // request time kept from an earlier request cannot pass for a fresh one.
    if ($number === 1 || $number === 48) {
        usleep(1_100_000);
    }
    curl_setopt($curl, CURLOPT_URL, $target['url']);
    $before = time();
    $body = curl_exec($curl);
    $after = time();
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    if (!is_string($body) || $status !== 200) {
        $failures[] = sprintf('%s: HTTP %d%s', $label, $status, is_string($body) ? ', body: ' . substr(trim($body), 0, 300) : ', ' . curl_error($curl));
        continue;
    }

    preg_match_all('#<li data-marker="([^"]+)">(.*?)</li>#s', $body, $matches, PREG_SET_ORDER);
    $markers = [];
    foreach ($matches as [, $name, $value]) {
        $markers[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    }

    foreach ($target['expected'] as $name => $expected) {
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

    // A static property a constructor appends to (AppendingParser). The
    // worker does not reset static properties, so on a per-request bootstrap
    // this grows with every request; it is reported, not failed.
    // TODO: make this a failure once the persistent-bootstrap change keeps
    // such singletons, or resets their static state, between requests.
    $length = $markers['static.append_length'] ?? null;
    $appendLength ??= $length;
    if ($length !== $appendLength) {
        $warnings[] = sprintf('%s: static.append_length is %s, the first request had %s', $label, var_export($length, true), var_export($appendLength, true));
    }

    // One worker for the whole run: same token, count up by one per request.
    $count = (int) ($markers['worker.count'] ?? 0);
    $token ??= $markers['worker.token'] ?? null;
    $firstCount ??= $count;
    if (($markers['worker.token'] ?? null) !== $token) {
        $failures[] = sprintf('%s: served by another worker (token %s, first request had %s)', $label, var_export($markers['worker.token'] ?? null, true), var_export($token, true));
    }
    if ($count !== $firstCount + $number) {
        $failures[] = sprintf('%s: worker request count is %d, expected %d; the worker restarted or another one answered', $label, $count, $firstCount + $number);
    }
    $lastCount = $count;
    $tokens[$markers['worker.token'] ?? '?'] = true;
    $pids[$markers['worker.pid'] ?? '?'] = true;
}

$seconds = microtime(true) - $started;
$total = count($sequence);
printf(
    "%d requests over %d targets (2 hosts x 2 languages x 2 pages) in %.1f s; worker token(s) %s, worker request count %s..%s, PID(s) %s\n",
    $total,
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
        $check = preg_replace(['#^\#\d+ \S+ \([^)]+\): #', '#^(marker \S+|asset \S+|<title>|HTTP \d+|served by another worker|worker request count).*$#s'], ['', '$1'], $failure);
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
