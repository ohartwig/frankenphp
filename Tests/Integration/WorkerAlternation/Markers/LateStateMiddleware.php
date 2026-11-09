<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Looks at $GLOBALS['TYPO3_REQUEST'] after the page has been rendered and
 * writes what it found into the placeholder the TypoScript leaves in the
 * page. At that point the global request must still be this request, with
 * this request's page.
 */
final readonly class LateStateMiddleware implements MiddlewareInterface
{
    public const PLACEHOLDER = '<!--worker-alternation-late-->';

    public function __construct(private StreamFactory $streamFactory) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $body = (string) $response->getBody();
        if (!str_contains($body, self::PLACEHOLDER)) {
            return $response;
        }

        $global = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $pageInformation = $global instanceof ServerRequestInterface ? $global->getAttribute('frontend.page.information') : null;
        $markers = [
            'late.mw.globals_host' => $global instanceof ServerRequestInterface ? $global->getUri()->getAuthority() : '',
            'late.mw.globals_path' => $global instanceof ServerRequestInterface ? $global->getUri()->getPath() : '',
            'late.mw.globals_page_id' => $pageInformation instanceof PageInformation ? (string) $pageInformation->getId() : '',
        ];
        $items = '';
        foreach ($markers as $name => $value) {
            $items .= '<li data-marker="' . $name . '">' . htmlspecialchars($value) . "</li>\n";
        }

        return $response->withBody($this->streamFactory->createStream(
            str_replace(self::PLACEHOLDER, "<ul id=\"worker-alternation-late\">\n" . $items . '</ul>', $body),
        ));
    }
}
