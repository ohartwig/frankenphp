<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;

/**
 * Turns the "cHash missing" 404 into a redirect to the same page without the
 * parameters the cHash would have had to cover.
 *
 * With cacheHash.enforceValidation and pageNotFoundOnCHashError, TYPO3 answers a
 * page URL that carries a parameter it does not know (a click id a mailer or a
 * social network appended, a typo, a scanner) with 404. That is safe, because
 * rendering such a request would bypass every cache, but the visitor who followed
 * the link sees an error page.
 *
 * This middleware runs right before TYPO3's own validator and only acts in exactly
 * the case where the validator would answer 404 for a missing cHash: a GET or HEAD
 * request, no cHash, and at least one parameter the cHash would have to cover. It
 * answers 301 to the same URL with those parameters removed. Parameters excluded
 * from the cHash (utm_*, gclid, …) stay in the URL, so client-side analytics still
 * sees them. The redirect costs no render and no cache entry for the page; the
 * target is a URL the caches already know.
 *
 * Left alone, and therefore still answered by TYPO3: a cHash that is present but
 * wrong (that stays a 404, it is a tampering signal), other methods, and any
 * configuration in which a missing cHash does not lead to a 404.
 *
 * KOH_CHASH_REDIRECT=0 switches it off.
 */
final class CacheHashRedirectMiddleware implements MiddlewareInterface
{
    public const ENV = 'KOH_CHASH_REDIRECT';

    /** Bounded, so a parameter that later becomes legitimate is not redirected away by browsers for long. */
    private const MAX_AGE = 3600;

    public function __construct(private readonly CacheHashCalculator $cacheHashCalculator) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $target = $this->redirectTarget($request);
        if ($target === null) {
            return $handler->handle($request);
        }

        return new RedirectResponse($target, 301, [
            'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
        ]);
    }

    private function redirectTarget(ServerRequestInterface $request): ?string
    {
        if (!self::isEnabled() || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $conf = $GLOBALS['TYPO3_CONF_VARS']['FE'] ?? [];
        $enforced = (bool)($conf['cacheHash']['enforceValidation'] ?? false);
        $notFoundOnError = (bool)($conf['pageNotFoundOnCHashError'] ?? true);
        if (!$enforced || !$notFoundOnError) {
            return null;
        }

        $pageArguments = $request->getAttribute('routing');
        if (!$pageArguments instanceof PageArguments) {
            return null;
        }
        $cHash = $pageArguments->getArguments()['cHash'] ?? '';
        if ($cHash !== '') {
            return null;
        }
        $dynamic = $pageArguments->getDynamicArguments();
        unset($dynamic['cHash']);
        if ($dynamic === []) {
            return null;
        }

        try {
            $relevant = $this->cacheHashCalculator->getRelevantParameters(
                HttpUtility::buildQueryString($dynamic + ['id' => $pageArguments->getPageId()]),
            );
        } catch (\Throwable) {
            return null;
        }
        unset($relevant['id']);
        if ($relevant === []) {
            return null;
        }

        $drop = ['cHash' => true];
        foreach (array_keys($relevant) as $name) {
            $drop[strtok($name, '[')] = true;
        }
        $query = $request->getQueryParams();
        $kept = array_diff_key($query, $drop);
        if (count($kept) === count($query)) {
            // Nothing of the query string would change: redirecting would loop.
            return null;
        }

        return (string)$request->getUri()->withQuery(HttpUtility::buildQueryString($kept))->withFragment('');
    }

    public static function isEnabled(): bool
    {
        $value = getenv(self::ENV);

        return !is_string($value) || !in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
    }
}
