<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\RequestLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The number of requests a worker serves before it makes way for a fresh one.
 */
final class RequestLimitTest extends TestCase
{
    /** The lowest value the worker may draw. */
    private static function lowest(): \Closure
    {
        return static fn(int $min, int $max): int => $min;
    }

    /** The highest value the worker may draw. */
    private static function highest(): \Closure
    {
        return static fn(int $min, int $max): int => $max;
    }

    #[Test]
    public function aWorkerMovesTheConfiguredLimitByUpToTenPercent(): void
    {
        self::assertSame(900, RequestLimit::fromEnvironment(['MAX_REQUESTS' => '1000'], self::lowest()));
        self::assertSame(1100, RequestLimit::fromEnvironment(['MAX_REQUESTS' => '1000'], self::highest()));
    }

    #[Test]
    public function withoutAConfiguredLimitItIsTheDefault(): void
    {
        self::assertSame(450, RequestLimit::fromEnvironment([], self::lowest()));
        self::assertSame(550, RequestLimit::fromEnvironment([], self::highest()));
    }

    /**
     * Two workers that start together must not end together: that is the
     * whole point, so the limits of a pool differ.
     */
    #[Test]
    public function theWorkersOfAPoolDrawDifferentLimits(): void
    {
        $limits = [];
        for ($worker = 0; $worker < 50; ++$worker) {
            $limit = RequestLimit::fromEnvironment(['MAX_REQUESTS' => '10000']);
            self::assertGreaterThanOrEqual(9000, $limit);
            self::assertLessThanOrEqual(11000, $limit);
            $limits[$limit] = true;
        }

        self::assertGreaterThan(1, count($limits));
    }

    /**
     * A limit of zero ends the worker before its first request, and
     * FrankenPHP starts the next one into the same end.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unusableValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'not a number' => ['many'];
        yield 'empty' => [''];
        yield 'a fraction' => ['12.5'];
        yield 'an array' => [['500']];
    }

    #[Test]
    #[DataProvider('unusableValues')]
    public function anUnusableValueIsTheDefault(mixed $value): void
    {
        self::assertSame(550, RequestLimit::fromEnvironment(['MAX_REQUESTS' => $value], self::highest()));
    }

    #[Test]
    public function aSmallLimitIsTakenAsItIs(): void
    {
        self::assertSame(1, RequestLimit::fromEnvironment(['MAX_REQUESTS' => '1'], self::lowest()));
        self::assertSame(9, RequestLimit::fromEnvironment(['MAX_REQUESTS' => ' 9 '], self::lowest()));
    }

    #[Test]
    public function aDrawOutsideTheRangeIsHeldToIt(): void
    {
        $wild = static fn(int $min, int $max): int => 100000;

        self::assertSame(110, RequestLimit::fromEnvironment(['MAX_REQUESTS' => '100'], $wild));
    }
}
