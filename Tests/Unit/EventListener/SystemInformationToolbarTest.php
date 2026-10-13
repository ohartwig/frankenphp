<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\EventListener;

use Koh\FrankenPHP\EventListener\SystemInformationToolbar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Backend\Event\SystemInformationToolbarCollectorEvent;
use TYPO3\CMS\Backend\Backend\ToolbarItems\SystemInformationToolbarItem;

/**
 * What the backend toolbar says about worker mode.
 *
 * It is one line of text, and it is the only place an operator can see whether
 * this installation is running under a FrankenPHP worker. Everything that
 * behaves differently in worker mode — the withheld opcache_reset above all —
 * keys off the same flag, so a toolbar that disagrees with it would send
 * someone debugging the wrong deployment.
 *
 * Which is why it reads the flag through the OPcache service's own helper
 * rather than checking $_SERVER itself: a bare truthiness check missed
 * getenv()/$_ENV and read the strings "false" and "off" as enabled.
 */
final class SystemInformationToolbarTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $value;
            }
        }

        parent::tearDown();
    }

    private function setFlag(?string $value): void
    {
        $name = 'FRANKENPHP_WORKER';
        if (!array_key_exists($name, $this->previousEnv)) {
            $this->previousEnv[$name] = $_SERVER[$name] ?? null;
        }
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }
        $_SERVER[$name] = $value;
    }

    /** @return array{0: SystemInformationToolbarCollectorEvent, 1: SystemInformationToolbarItem} */
    private function event(): array
    {
        $item = (new \ReflectionClass(SystemInformationToolbarItem::class))->newInstanceWithoutConstructor();

        return [new SystemInformationToolbarCollectorEvent($item), $item];
    }

    /** @return list<array<string, mixed>> */
    private function entries(SystemInformationToolbarItem $item): array
    {
        $property = new \ReflectionProperty(SystemInformationToolbarItem::class, 'systemInformation');

        /** @var list<array<string, mixed>> $value */
        $value = $property->getValue($item);

        return $value;
    }

    #[Test]
    public function workerModeIsReportedAsEnabledWhenTheFlagIsSet(): void
    {
        $this->setFlag('1');
        [$event, $item] = $this->event();

        (new SystemInformationToolbar())($event);

        $entries = $this->entries($item);
        self::assertCount(1, $entries);
        self::assertSame('Worker Mode', $entries[0]['title']);
        self::assertSame('Enabled', $entries[0]['value']);
        self::assertSame('frankenphp', $entries[0]['iconIdentifier']);
    }

    #[Test]
    public function workerModeIsReportedAsDisabledWithoutTheFlag(): void
    {
        $this->setFlag(null);
        [$event, $item] = $this->event();

        (new SystemInformationToolbar())($event);

        self::assertSame('Disabled', $this->entries($item)[0]['value']);
    }

    /**
     * The strings that a bare truthiness check reads as enabled. An operator
     * who writes FRANKENPHP_WORKER=false means off, and a toolbar
     * saying "Enabled" would be the first thing they trust and the first thing
     * that is wrong.
     */
    #[Test]
    public function theStringsThatMeanOffAreReportedAsDisabled(): void
    {
        foreach (['false', 'off', '0', 'no', ''] as $value) {
            $this->setFlag($value);
            [$event, $item] = $this->event();

            (new SystemInformationToolbar())($event);

            self::assertSame('Disabled', $this->entries($item)[0]['value'], var_export($value, true));
        }
    }
}
