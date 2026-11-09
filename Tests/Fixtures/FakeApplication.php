<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stands in for TYPO3's Application in the persistent bootstrap tests:
 * records what each request finds, leaves state behind for the next one to
 * (not) find, and can hand TYPO3 a global request for another host.
 */
final class FakeApplication
{
    /** @var list<array{container: bool, leftover: bool, requestService: bool, confVar: mixed}> */
    public static array $seen = [];

    /** The container the last run() found. */
    public static ?object $container = null;

    /** Host of the global request the next run() sets; null sets none. */
    public static ?string $globalRequestHost = null;

    public static function reset(): void
    {
        self::$seen = [];
        self::$container = null;
        self::$globalRequestHost = null;
    }

    public function run(): void
    {
        $container = GeneralUtility::getContainer();
        self::$container = $container;
        self::$seen[] = [
            'container' => $container->has('boot.state'),
            'leftover' => isset($GLOBALS['FAKE_APPLICATION_LEFTOVER']),
            'requestService' => $container->has('fake.request.service'),
            'confVar' => $GLOBALS['TYPO3_CONF_VARS']['FAKE'] ?? null,
        ];
        $GLOBALS['FAKE_APPLICATION_LEFTOVER'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['FAKE'] = 'changed by request ' . count(self::$seen);
        if ($container instanceof \Symfony\Component\DependencyInjection\Container) {
            $container->set('fake.request.service', new \stdClass());
        }
        if (self::$globalRequestHost !== null) {
            $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('http://' . self::$globalRequestHost . '/');
        }
        echo 'page ' . count(self::$seen);
    }
}
