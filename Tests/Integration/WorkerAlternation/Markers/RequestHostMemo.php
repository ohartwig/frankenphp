<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Stands in for the many singletons that memoise something from the request
 * that created them: it remembers the first host it is asked about.
 *
 * Within one request that is harmless. Kept from one request to the next, it
 * answers a request for one site with the host of another, so the
 * `singleton.request_host` marker shows whether the worker drops singletons
 * between requests.
 */
final class RequestHostMemo implements SingletonInterface
{
    private ?string $host = null;

    public function hostOf(ServerRequestInterface $request): string
    {
        return $this->host ??= $request->getUri()->getAuthority();
    }
}
