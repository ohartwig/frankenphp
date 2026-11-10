<?php

declare(strict_types=1);

/*
 * Creates the page trees of the worker alternation test in the test
 * project's database. Usage: php seed.php <project-dir>
 *
 * Both sites use the same slugs, so the host is the only thing that tells
 * a request for site A from one for site B. Every page has its own
 * description and Open Graph title and description.
 */

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$projectDir = $argv[1] ?? '';
if ($projectDir === '' || !is_file($projectDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php seed.php <project-dir with vendor/>\n");
    exit(2);
}

$classLoader = require $projectDir . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
Bootstrap::init($classLoader);

// uid, pid, sys_language_uid, l10n_parent, is_siteroot, title, slug
$pages = [
    [1, 0, 0, 0, 1, 'Site A Startseite', '/'],
    [2, 1, 0, 0, 0, 'Site A Zweite Seite', '/zweite-seite'],
    [3, 0, 1, 1, 1, 'Site A Home', '/'],
    [4, 1, 1, 2, 0, 'Site A Second Page', '/second-page'],
    [10, 0, 0, 0, 1, 'Site B Startseite', '/'],
    [11, 10, 0, 0, 0, 'Site B Zweite Seite', '/zweite-seite'],
    [12, 0, 1, 10, 1, 'Site B Home', '/'],
    [13, 10, 1, 11, 0, 'Site B Second Page', '/second-page'],
];

$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
$connection->truncate('pages');
$now = time();
foreach ($pages as $sorting => [$uid, $pid, $language, $parent, $siteRoot, $title, $slug]) {
    $connection->insert('pages', [
        'uid' => $uid,
        'pid' => $pid,
        'sys_language_uid' => $language,
        'l10n_parent' => $parent,
        'l10n_source' => $parent,
        'is_siteroot' => $siteRoot,
        'title' => $title,
        // What EXT:seo renders as description and Open Graph tags; each
        // page has its own, see check.php.
        'description' => 'Description of ' . $title,
        'og_title' => 'OG title of ' . $title,
        'og_description' => 'OG description of ' . $title,
        'slug' => $slug,
        'doktype' => 1,
        'hidden' => 0,
        'deleted' => 0,
        'sorting' => ($sorting + 1) * 256,
        'tstamp' => $now,
        'crdate' => $now,
        'perms_userid' => 1,
        'perms_user' => 31,
        'perms_group' => 27,
        'perms_everybody' => 0,
    ]);
}

// uid, pid, sys_language_uid, l10n_parent, title: one greeting per site,
// translated into English.
$greetings = [
    [1, 1, 0, 0, 'Site A Hallo'],
    [2, 10, 0, 0, 'Site B Hallo'],
    [3, 1, 1, 1, 'Site A Hello'],
    [4, 10, 1, 2, 'Site B Hello'],
];
$connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_workeralternationmarkers_greeting');
$connection->truncate('tx_workeralternationmarkers_greeting');
foreach ($greetings as [$uid, $pid, $language, $parent, $title]) {
    $connection->insert('tx_workeralternationmarkers_greeting', [
        'uid' => $uid,
        'pid' => $pid,
        'sys_language_uid' => $language,
        'l10n_parent' => $parent,
        'l10n_source' => $parent,
        'title' => $title,
    ]);
}

echo 'Seeded ' . count($pages) . ' pages and ' . count($greetings) . " greetings.\n";
