<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Message;

/**
 * The edge cache purge of one CacheInvalidationListener::purgePages() call:
 * the URLs of each page, in the order they were collected, and the URLs whose
 * hosts get their query-string variants purged.
 *
 * Handled by PurgeEdgeCacheHandler, in the request by default (TYPO3 routes
 * every message to its synchronous transport) or in the koh/typo3-async
 * consumer where EdgePurgeRouting sends it to koh_async_cache.
 *
 * Only strings and arrays of them: a queued message is serialized into
 * sys_messenger_messages, and the consumer decodes no class that is not on
 * koh_async's allowlist.
 *
 * $origin is the PurgeAudit context of the request that asked for the purge,
 * when the audit was on there, so the audit line written where the purge is
 * sent still names the request and the backend user behind it.
 */
final readonly class PurgeEdgeCache
{
    /**
     * @param list<list<string>>        $pageUrls         one list per page, as CaddyCacheService::getPageUrls() returned it
     * @param list<string>              $queryVariantUrls one URL per host whose query-string variants are purged
     * @param array<string, mixed>|null $origin           PurgeAudit::originContext() of the requesting code path
     */
    public function __construct(
        public array $pageUrls,
        public array $queryVariantUrls = [],
        public ?array $origin = null,
    ) {}
}
