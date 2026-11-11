<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationOrigin;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationOriginType;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Map;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * After every site B page, changes the configuration services in place, as
 * a careless extension could: a frontend policy that allows
 * https://leak.example, and a global Fluid namespace `waleak`.
 *
 * With a bootstrap per request, and with a persistent one that builds these
 * services per request, the change dies with the request. With
 * KOH_PERSISTENT_CONFIG_SERVICES the services are kept, and the runner checks
 * that no later response carries the change: no CSP header allows
 * leak.example, no Fluid resolver knows `waleak`.
 */
final readonly class ConfigServiceMutator implements MiddlewareInterface
{
    public const LEAKED_SOURCE = 'https://leak.example';

    public const LEAKED_NAMESPACE = 'waleak';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $site = $request->getAttribute('site');
        if (!$site instanceof Site || $site->getIdentifier() !== 'site-b') {
            return $response;
        }

        $container = GeneralUtility::getContainer();
        $namespaces = $container->get('fluid.namespaces');
        if ($namespaces instanceof \ArrayObject) {
            $namespaces[self::LEAKED_NAMESPACE] = ['Koh\\FrankenPHP\\Tests\\Integration\\WorkerAlternation\\Markers\\ViewHelpers'];
        }
        $policies = $container->get('content.security.policies');
        $frontend = $policies instanceof Map ? ($policies[Scope::frontend()] ?? null) : null;
        if ($frontend instanceof Map) {
            $frontend[new MutationOrigin(MutationOriginType::package, 'worker-alternation-leak')] = new MutationCollection(
                new Mutation(MutationMode::Extend, Directive::ImgSrc, new UriValue(self::LEAKED_SOURCE)),
            );
        }

        return $response;
    }
}
