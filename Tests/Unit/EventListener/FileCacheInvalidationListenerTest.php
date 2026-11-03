<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\EventListener;

use Koh\FrankenPHP\EventListener\FileCacheInvalidationListener;
use Koh\FrankenPHP\Service\CaddyCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\Event\BeforeFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileMovedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;

/**
 * AUD-062-F01: a deleted, replaced, renamed, moved or rewritten file must be
 * purged from the edge. Core sends these changes through FAL events only, so
 * without this listener the public URL keeps answering until the entry expires.
 */
final class FileCacheInvalidationListenerTest extends TestCase
{
    /** @var list<list<string>> */
    private array $purged = [];

    private bool $enabled = true;

    private function listener(?string $publicUrl = 'fileadmin/docs/report.pdf', string ...$processedUrls): FileCacheInvalidationListener
    {
        $service = $this->createStub(CaddyCacheService::class);
        $service->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $service->method('invalidateFilePaths')->willReturnCallback(
            function (array $paths): void {
                $this->purged[] = $paths;
            }
        );

        $processed = [];
        foreach ($processedUrls as $url) {
            $file = $this->createStub(ProcessedFile::class);
            $file->method('getPublicUrl')->willReturn($url);
            $processed[] = $file;
        }
        $repository = $this->createStub(ProcessedFileRepository::class);
        $repository->method('findAllByOriginalFile')->willReturn($processed);

        $this->file = $this->createStub(File::class);
        $this->file->method('getPublicUrl')->willReturn($publicUrl);

        // Runs the deferred purge immediately: the deferral itself is
        // register_shutdown_function and is not testable in-process.
        return new FileCacheInvalidationListener(
            $service,
            new NullLogger(),
            $repository,
            static function (callable $callback): void {
                $callback();
            },
        );
    }

    private File $file;

    #[Test]
    public function aDeletedFileIsPurgedByItsPublicPath(): void
    {
        $listener = $this->listener();

        $listener->onFileDeleted(new BeforeFileDeletedEvent($this->file));

        self::assertSame([['/fileadmin/docs/report.pdf']], $this->purged);
    }

    #[Test]
    public function everyFileEventPurgesTheFile(): void
    {
        $listener = $this->listener();
        $folder = $this->createStub(Folder::class);

        $listener->onFileReplaced(new BeforeFileReplacedEvent($this->file, '/tmp/new'));
        $listener->onFileRenamed(new BeforeFileRenamedEvent($this->file, 'other.pdf'));
        $listener->onFileMoved(new BeforeFileMovedEvent($this->file, $folder, 'report.pdf'));
        $listener->onFileContentsSet(new BeforeFileContentsSetEvent($this->file, 'x'));

        self::assertCount(4, $this->purged);
        self::assertSame(['/fileadmin/docs/report.pdf'], $this->purged[0]);
    }

    #[Test]
    public function processedVariantsArePurgedWithTheOriginal(): void
    {
        $listener = $this->listener('/fileadmin/a.jpg', '/fileadmin/_processed_/1/2/csm_a_abc.jpg');

        $listener->onFileDeleted(new BeforeFileDeletedEvent($this->file));

        self::assertSame([['/fileadmin/a.jpg', '/fileadmin/_processed_/1/2/csm_a_abc.jpg']], $this->purged);
    }

    #[Test]
    public function aFileWithoutAPublicUrlPurgesNothing(): void
    {
        $listener = $this->listener(null);

        $listener->onFileDeleted(new BeforeFileDeletedEvent($this->file));

        self::assertSame([], $this->purged);
    }

    #[Test]
    public function nothingIsPurgedWhenTheExtensionIsDisabled(): void
    {
        $listener = $this->listener();
        $this->enabled = false;

        $listener->onFileDeleted(new BeforeFileDeletedEvent($this->file));

        self::assertSame([], $this->purged);
    }
}
