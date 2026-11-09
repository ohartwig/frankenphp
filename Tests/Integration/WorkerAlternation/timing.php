<?php

declare(strict_types=1);

/*
 * Summarises the koh_timing lines in a FrankenPHP log: medians of boot,
 * application and total time, how many requests booted TYPO3 and how many
 * ran on a kept bootstrap, and the memory peak over the run. Also counts
 * koh_worker_leak lines. Usage: php timing.php <log> [fresh|persistent]
 *
 * With a second argument it also checks the mode the worker really ran in:
 * `fresh`, every request booted TYPO3; `persistent`, the first booted and
 * every later one reused that boot. Exit code 1 if not, or on any
 * koh_worker_leak line.
 */

$log = $argv[1] ?? '';
$expect = $argv[2] ?? '';
$lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : false;
if ($lines === false) {
    fwrite(STDERR, "No log at '$log'.\n");
    exit(2);
}

$timings = [];
$leaks = 0;
foreach ($lines as $line) {
    // FrankenPHP wraps error_log() output in its own JSON log line.
    foreach ([$line, (string) (json_decode($line, true)['msg'] ?? '')] as $candidate) {
        $start = strpos($candidate, '{"koh_');
        if ($start === false) {
            continue;
        }
        $entry = json_decode(substr($candidate, $start), true);
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['koh_timing'])) {
            $timings[] = $entry;
        } elseif (isset($entry['koh_worker_leak'])) {
            ++$leaks;
        }
        break;
    }
}

$median = static function (array $values): string {
    $values = array_values(array_filter($values, is_numeric(...)));
    if ($values === []) {
        return '-';
    }
    sort($values);
    $middle = intdiv(count($values), 2);

    return sprintf('%.1f', count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2);
};

$byBootstrap = [];
foreach ($timings as $entry) {
    $byBootstrap[(string) ($entry['bootstrap'] ?? 'fresh')][] = $entry;
}
printf("%d timed requests, %d koh_worker_leak lines\n", count($timings), $leaks);
foreach ($byBootstrap as $kind => $entries) {
    printf(
        "  bootstrap=%s: %d requests, median boot_ms %s, app_ms %s, total_ms %s\n",
        $kind,
        count($entries),
        $median(array_column($entries, 'boot_ms')),
        $median(array_column($entries, 'app_ms')),
        $median(array_column($entries, 'total_ms')),
    );
}
$memory = array_column($timings, 'mem_peak_mb');
if ($memory !== []) {
    printf(
        "  mem_peak_mb: first %.1f, last %.1f, min %.1f, max %.1f\n",
        $memory[0],
        $memory[count($memory) - 1],
        min($memory),
        max($memory),
    );
}

if ($expect === '') {
    exit(0);
}
$fresh = count($byBootstrap['fresh'] ?? []);
$reused = count($byBootstrap['reused'] ?? []);
$ok = $leaks === 0 && match ($expect) {
    'fresh' => $reused === 0 && $fresh > 0,
    'persistent' => $fresh === 1 && $reused === count($timings) - 1 && $reused > 0,
    default => false,
};
if (!$ok) {
    printf("FAILED: expected the worker to run in %s mode without leaks; got %d fresh, %d reused, %d leak lines\n", $expect, $fresh, $reused, $leaks);
    exit(1);
}
printf("Mode confirmed: %s.\n", $expect);
