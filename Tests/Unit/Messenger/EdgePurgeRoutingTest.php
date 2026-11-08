<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Messenger;

use Koh\FrankenPHP\Message\PurgeEdgeCache;
use Koh\FrankenPHP\Messenger\EdgePurgeRouting;
use Koh\FrankenPHP\Tests\Fixtures\KohAsyncMode;
use Koh\FrankenPHP\Tests\Fixtures\KohAsyncTransports;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/KohAsyncDoubles.php';

/**
 * When the edge purge leaves the request. Every "no" here must leave
 * TYPO3_CONF_VARS without a route, because a route is the only thing that
 * moves the purge out of the request; and a route to a transport the
 * container does not know fails every save.
 */
final class EdgePurgeRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(EdgePurgeRouting::ENV);
        KohAsyncMode::$enabled = null;
        parent::tearDown();
    }

    // --- is the transport there ----------------------------------------------

    #[Test]
    public function withoutKohAsyncThereIsNoTransport(): void
    {
        self::assertNull(EdgePurgeRouting::availableTransport(false, KohAsyncTransports::class . '::CACHE'));
    }

    #[Test]
    public function aKohAsyncReleaseWithoutTheConstantHasNoTransport(): void
    {
        self::assertNull(EdgePurgeRouting::availableTransport(true, KohAsyncTransports::class . '::NOT_THERE'));
        self::assertNull(EdgePurgeRouting::availableTransport(true, 'Koh\\FrankenPHP\\Tests\\NoSuchClass::CACHE'));
    }

    #[Test]
    public function aReleaseWithTheConstantNamesTheTransport(): void
    {
        self::assertSame('koh_async_cache', EdgePurgeRouting::availableTransport(true, KohAsyncTransports::class . '::CACHE'));
    }

    #[Test]
    public function anEmptyNameIsNoTransport(): void
    {
        self::assertNull(EdgePurgeRouting::availableTransport(true, KohAsyncTransports::class . '::EMPTY'));
    }

    #[Test]
    public function theRealConstantIsTheOneKohAsyncDeclares(): void
    {
        self::assertSame('Koh\\Typo3Async\\Infrastructure\\Messenger\\Transports::CACHE', EdgePurgeRouting::TRANSPORT_CONSTANT);
        self::assertSame(['Koh\\Typo3Async\\Domain\\AsyncMode', 'fromEnvironment'], EdgePurgeRouting::ASYNC_MODE_FACTORY);
    }

    // --- async mode ----------------------------------------------------------

    #[Test]
    public function asyncModeIsOffWithoutKohAsync(): void
    {
        self::assertFalse(EdgePurgeRouting::asyncModeEnabled(['Koh\\FrankenPHP\\Tests\\NoSuchClass', 'fromEnvironment']));
    }

    #[Test]
    public function asyncModeFollowsKohAsync(): void
    {
        $factory = [KohAsyncMode::class, 'fromEnvironment'];
        self::assertFalse(EdgePurgeRouting::asyncModeEnabled($factory));
        KohAsyncMode::$enabled = true;
        self::assertTrue(EdgePurgeRouting::asyncModeEnabled($factory));
    }

    #[Test]
    public function aFactoryThatReturnsNoModeIsOff(): void
    {
        self::assertFalse(EdgePurgeRouting::asyncModeEnabled([KohAsyncMode::class, 'notAMode']));
    }

    // --- the switch ----------------------------------------------------------

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function switchValues(): array
    {
        return [
            'unset' => [null, false],
            'empty' => ['', false],
            '0' => ['0', false],
            'false' => ['false', false],
            '1' => ['1', true],
            'true' => ['true', true],
            'On with blanks' => [' On ', true],
            'yes' => ['yes', true],
        ];
    }

    #[Test]
    #[DataProvider('switchValues')]
    public function theSwitchIsReadFromTheEnvironment(?string $value, bool $on): void
    {
        putenv($value === null ? EdgePurgeRouting::ENV : EdgePurgeRouting::ENV . '=' . $value);

        self::assertSame($on, EdgePurgeRouting::switchedOn());
    }

    // --- the configuration ---------------------------------------------------

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function notAllThree(): array
    {
        return [
            'async mode off' => [false, true],
            'switch off' => [true, false],
            'both off' => [false, false],
        ];
    }

    #[Test]
    #[DataProvider('notAllThree')]
    public function withoutAllThreeThereIsNoRoute(bool $asyncMode, bool $switchedOn): void
    {
        $conf = ['SYS' => ['messenger' => ['routing' => ['*' => 'default']]]];

        EdgePurgeRouting::configure($conf, 'koh_async_cache', $asyncMode, $switchedOn);

        self::assertSame(['*' => 'default'], $conf['SYS']['messenger']['routing']);
    }

    #[Test]
    public function withoutTheTransportNothingIsTouched(): void
    {
        $conf = ['SYS' => ['messenger' => ['routing' => ['*' => 'default']]], 'EXTENSIONS' => ['frankenphp' => ['x' => 1]]];
        $before = $conf;

        EdgePurgeRouting::configure($conf, null, true, true);

        self::assertSame($before, $conf);
    }

    #[Test]
    public function withAllThreeTheMessageIsRoutedToTheTransport(): void
    {
        $conf = ['SYS' => ['messenger' => ['routing' => ['*' => 'default']]]];

        EdgePurgeRouting::configure($conf, 'koh_async_cache', true, true);

        self::assertSame(['*' => 'default', PurgeEdgeCache::class => 'koh_async_cache'], $conf['SYS']['messenger']['routing']);
    }

    #[Test]
    public function aRouteIsAddedToAnEmptyConfiguration(): void
    {
        $conf = [];

        EdgePurgeRouting::configure($conf, 'koh_async_cache', true, true);

        self::assertSame('koh_async_cache', $conf['SYS']['messenger']['routing'][PurgeEdgeCache::class]);
        self::assertSame([PurgeEdgeCache::class], $conf['EXTENSIONS']['koh_async']['messageClassPrefixes']);
    }

    /**
     * The consumer decodes the message wherever the transport exists, even
     * where this pod's switch is off: it may run with other variables than
     * the pod that queued.
     */
    #[Test]
    public function theMessageClassIsAllowedWheneverTheTransportExists(): void
    {
        $conf = ['EXTENSIONS' => ['koh_async' => ['failedRetentionDays' => 3]]];

        EdgePurgeRouting::configure($conf, 'koh_async_cache', false, false);

        self::assertSame(
            ['failedRetentionDays' => 3, 'messageClassPrefixes' => [PurgeEdgeCache::class]],
            $conf['EXTENSIONS']['koh_async'],
        );
        self::assertArrayNotHasKey('SYS', $conf);
    }

    #[Test]
    public function configuredPrefixesAreKeptInEitherForm(): void
    {
        $list = ['EXTENSIONS' => ['koh_async' => ['messageClassPrefixes' => ['Vendor\\Site\\', '', '  ', 7]]]];
        EdgePurgeRouting::configure($list, 'koh_async_cache', false, false);
        self::assertSame(['Vendor\\Site\\', PurgeEdgeCache::class], $list['EXTENSIONS']['koh_async']['messageClassPrefixes']);

        $string = ['EXTENSIONS' => ['koh_async' => ['messageClassPrefixes' => ' Vendor\\Site\\ , Other\\Thing']]];
        EdgePurgeRouting::configure($string, 'koh_async_cache', false, false);
        self::assertSame(['Vendor\\Site\\', 'Other\\Thing', PurgeEdgeCache::class], $string['EXTENSIONS']['koh_async']['messageClassPrefixes']);
    }

    #[Test]
    public function theMessageClassIsListedOnce(): void
    {
        $conf = ['EXTENSIONS' => ['koh_async' => ['messageClassPrefixes' => [PurgeEdgeCache::class]]]];

        EdgePurgeRouting::configure($conf, 'koh_async_cache', true, true);
        EdgePurgeRouting::configure($conf, 'koh_async_cache', true, true);

        self::assertSame([PurgeEdgeCache::class], $conf['EXTENSIONS']['koh_async']['messageClassPrefixes']);
    }

    #[Test]
    public function malformedSectionsAreReplacedNotFatal(): void
    {
        $conf = ['EXTENSIONS' => 'x', 'SYS' => ['messenger' => 'y']];

        EdgePurgeRouting::configure($conf, 'koh_async_cache', true, true);

        self::assertSame([PurgeEdgeCache::class], $conf['EXTENSIONS']['koh_async']['messageClassPrefixes']);
        self::assertSame([PurgeEdgeCache::class => 'koh_async_cache'], $conf['SYS']['messenger']['routing']);
    }

    #[Test]
    public function otherSysSettingsSurvive(): void
    {
        $conf = ['SYS' => ['encryptionKey' => 'k', 'messenger' => ['other' => true, 'routing' => 'nonsense']], 'EXTENSIONS' => ['koh_async' => 'nonsense']];

        EdgePurgeRouting::configure($conf, 'koh_async_cache', true, true);

        self::assertSame('k', $conf['SYS']['encryptionKey']);
        self::assertTrue($conf['SYS']['messenger']['other']);
        self::assertSame([PurgeEdgeCache::class => 'koh_async_cache'], $conf['SYS']['messenger']['routing']);
        self::assertSame(['messageClassPrefixes' => [PurgeEdgeCache::class]], $conf['EXTENSIONS']['koh_async']);
    }
}
