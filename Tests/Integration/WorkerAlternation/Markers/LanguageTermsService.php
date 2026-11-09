<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

/**
 * The same as LanguageTermsSingleton, but a shared service of the DI
 * container: it lives as long as the container. A bootstrap per request
 * builds a new container each time; a container kept for the whole worker
 * would serve the terms of the first request's language to every request.
 */
final class LanguageTermsService
{
    public readonly int $languageId;

    public readonly string $terms;

    public function __construct()
    {
        [$this->languageId, $this->terms] = GreetingTitles::forGlobalRequest();
    }
}
