<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\MessageHandler;

use Koh\FrankenPHP\Message\PurgeEdgeCache;
use Koh\FrankenPHP\Service\CaddyCacheService;
use Koh\FrankenPHP\Service\PurgeAudit;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends the purges a PurgeEdgeCache carries, exactly as the listener sent
 * them inline before: each page's URLs without their query variants, then
 * the query variants once per host.
 *
 * A failed PURGE is logged by CaddyCacheService and not thrown, as it was in
 * the request; what does throw is left to the messenger (retry and failure
 * queue in the consumer).
 */
#[AsMessageHandler]
final readonly class PurgeEdgeCacheHandler
{
    public function __construct(private CaddyCacheService $caddyCacheService) {}

    public function __invoke(PurgeEdgeCache $message): void
    {
        PurgeAudit::onBehalfOf($message->origin, function () use ($message): void {
            foreach ($message->pageUrls as $urls) {
                $this->caddyCacheService->invalidateUrls($urls, false);
            }
            if ($message->queryVariantUrls !== []) {
                $this->caddyCacheService->invalidateQueryVariants($message->queryVariantUrls);
            }
        });
    }
}
