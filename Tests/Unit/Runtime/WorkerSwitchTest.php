<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\WorkerSwitch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/frankenphp_handle_request.php';

final class WorkerSwitchTest extends TestCase
{
    private const NAME = 'KOH_WORKER_SWITCH_TEST';

    protected function tearDown(): void
    {
        putenv(self::NAME);
        unset($_SERVER[self::NAME], $_ENV[self::NAME], $_SERVER['FRANKENPHP_WORKER']);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function switchValues(): iterable
    {
        yield 'one' => ['1', true];
        yield 'true' => ['true', true];
        yield 'on with spaces' => [' on ', true];
        yield 'yes' => ['yes', true];
        yield 'zero' => ['0', false];
        yield 'off' => ['off', false];
        yield 'garbage' => ['maybe', false];
        yield 'blank' => ['   ', false];
    }

    #[Test]
    #[DataProvider('switchValues')]
    public function aSwitchIsOnOnlyForATrueValue(string $value, bool $on): void
    {
        putenv(self::NAME . '=' . $value);

        self::assertSame($on, WorkerSwitch::isOn(self::NAME));
    }

    #[Test]
    public function unsetIsOff(): void
    {
        self::assertFalse(WorkerSwitch::isOn(self::NAME));
        self::assertNull(WorkerSwitch::value(self::NAME));
    }

    #[Test]
    public function theCaddyfileEnvComesBeforeTheContainerEnv(): void
    {
        putenv(self::NAME . '=0');
        $_SERVER[self::NAME] = '1';
        self::assertTrue(WorkerSwitch::isOn(self::NAME));

        $_ENV[self::NAME] = 'off';
        self::assertFalse(WorkerSwitch::isOn(self::NAME), '$_ENV first, as in the worker script');
    }

    #[Test]
    public function aNonScalarValueIsIgnored(): void
    {
        $_SERVER[self::NAME] = ['1'];
        putenv(self::NAME . '=1');

        self::assertNull(WorkerSwitch::value(self::NAME));
    }

    #[Test]
    public function valuesAreTrimmed(): void
    {
        putenv(self::NAME . '=  hash, rootline  ');

        self::assertSame('hash, rootline', WorkerSwitch::value(self::NAME));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: int}>
     */
    public static function numbers(): iterable
    {
        yield 'unset' => [null, 7];
        yield 'number' => ['42', 42];
        yield 'one' => ['1', 1];
        yield 'zero' => ['0', 7];
        yield 'negative' => ['-5', 7];
        yield 'plus sign' => ['+5', 7];
        yield 'trailing garbage' => ['5x', 7];
        yield 'leading garbage' => ['x5', 7];
        yield 'decimal' => ['1.5', 7];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function positiveIntFallsBackForAnythingButDigits(?string $value, int $expected): void
    {
        if ($value !== null) {
            putenv(self::NAME . '=' . $value);
        }

        self::assertSame($expected, WorkerSwitch::positiveInt(self::NAME, 7));
    }

    #[Test]
    public function aWorkerNeedsTheFunctionAndTheServerFlag(): void
    {
        self::assertTrue(\function_exists('frankenphp_handle_request'), 'fixture loaded');
        self::assertFalse(WorkerSwitch::isFrankenPhpWorker());

        $_SERVER['FRANKENPHP_WORKER'] = '1';
        self::assertTrue(WorkerSwitch::isFrankenPhpWorker());

        $_SERVER['FRANKENPHP_WORKER'] = '';
        self::assertFalse(WorkerSwitch::isFrankenPhpWorker());
    }
}
