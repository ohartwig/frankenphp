<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\EventListener;

use Koh\FrankenPHP\Service\CaddyCacheService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\BeforeFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileMovedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;

/**
 * Takes a deleted, replaced, renamed, moved or rewritten fileadmin file back from the edge.
 *
 * TYPO3 core routes these changes through FAL events; neither the DataHandler hook nor
 * CacheFlushEvent fires for them, so before this listener the public URL kept answering from
 * Souin until its lifetime ran out (AUD-062-F01). The Before* events are used because only
 * they still carry the file's old name and folder; the purge itself is deferred to shutdown,
 * like the full flush, so it runs against the final state and outside container teardown.
 */
final class FileCacheInvalidationListener
{
    /** @var \Closure(callable): void */
    private readonly \Closure $defer;

    public function __construct(
        private readonly CaddyCacheService $caddyCacheService,
        private readonly LoggerInterface $logger,
        private readonly ProcessedFileRepository $processedFileRepository,
        ?\Closure $defer = null,
    ) {
        $this->defer = $defer ?? static function (callable $callback): void {
            register_shutdown_function($callback);
        };
    }

    #[AsEventListener(identifier: 'frankenphp-file-deleted')]
    public function onFileDeleted(BeforeFileDeletedEvent $event): void
    {
        $this->purge($event->getFile());
    }

    #[AsEventListener(identifier: 'frankenphp-file-replaced')]
    public function onFileReplaced(BeforeFileReplacedEvent $event): void
    {
        $this->purge($event->getFile());
    }

    #[AsEventListener(identifier: 'frankenphp-file-renamed')]
    public function onFileRenamed(BeforeFileRenamedEvent $event): void
    {
        $this->purge($event->getFile());
    }

    #[AsEventListener(identifier: 'frankenphp-file-moved')]
    public function onFileMoved(BeforeFileMovedEvent $event): void
    {
        $this->purge($event->getFile());
    }

    #[AsEventListener(identifier: 'frankenphp-file-contents-set')]
    public function onFileContentsSet(BeforeFileContentsSetEvent $event): void
    {
        $this->purge($event->getFile());
    }

    private function purge(FileInterface $file): void
    {
        if (!$this->caddyCacheService->isEnabled()) {
            return;
        }

        try {
            $paths = $this->publicPaths($file);
        } catch (\Throwable $e) {
            // Runs inside a file operation: failing to compute a URL must not stop the editor.
            $this->logger->warning('Caddy Cache: Could not resolve file URL', ['error' => $e->getMessage()]);
            return;
        }
        if ($paths === []) {
            return;
        }

        $service = $this->caddyCacheService;
        $logger = $this->logger;
        ($this->defer)(static function () use ($service, $logger, $paths): void {
            try {
                $service->invalidateFilePaths($paths);
            } catch (\Throwable $e) {
                $logger->error('Caddy Cache: Deferred file purge failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function publicPaths(FileInterface $file): array
    {
        $urls = [$file->getPublicUrl()];
        if ($file instanceof File) {
            foreach ($this->processedFileRepository->findAllByOriginalFile($file) as $processed) {
                $urls[] = $processed->getPublicUrl();
            }
        }

        $paths = [];
        foreach ($urls as $url) {
            if ($url === null) {
                continue;
            }
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $paths[] = '/' . ltrim($path, '/');
            }
        }

        return array_values(array_unique($paths));
    }
}
