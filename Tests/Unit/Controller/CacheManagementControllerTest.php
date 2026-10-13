<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Controller;

use Koh\FrankenPHP\Controller\CacheManagementController;
use Koh\FrankenPHP\Service\CaddyCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Service\ExtensionService;

/**
 * What the backend module reports back after a flush.
 *
 * The two flush actions are the whole of this controller's logic, and both end
 * the same way — a redirect to the overview — so the only thing that tells an
 * editor what happened is the flash message. An action that swallows a failure
 * and still says "successfully flushed" is worse than one that crashes: the
 * cache stays stale and the person who pressed the button believes it did not.
 *
 * indexAction() is deliberately not here. It assembles a ModuleTemplate, which
 * is final and takes nine services, and it makes no decision of its own — every
 * branch worth a test is below. Covering it would mean a functional test, and
 * this repository has no functional suite.
 *
 * The controller's Extbase plumbing is set through reflection rather than a
 * container: addFlashMessage() reaches for two injected services and the
 * request, and a redirect needs the URI builder. All four are supplied here so
 * the actions can be called the way Extbase calls them.
 */
final class CacheManagementControllerTest extends TestCase
{
    private FlashMessageQueue $queue;

    /** @var list<FlashMessage> */
    private array $messages = [];

    /** @var list<string> */
    private array $invalidated = [];

    private ?\Throwable $failure = null;

    private bool $siteExists = true;

    protected function setUp(): void
    {
        parent::setUp();
        // A queue that keeps what it is handed. The real one routes a
        // session message into the BE user's session, which does not exist in a
        // unit test — the message would simply disappear, and the assertions
        // below would be about nothing.
        $this->queue = new class ('extbase.flashmessages.tx_frankenphp', $this->messages) extends FlashMessageQueue {
            /** @param list<FlashMessage> $captured */
            public function __construct(string $identifier, public array &$captured)
            {
                parent::__construct($identifier);
            }

            public function enqueue($message): void
            {
                $this->captured[] = $message;
            }
        };
    }

    private function controller(string $siteArgument = 'main'): CacheManagementController
    {
        $service = $this->createStub(CaddyCacheService::class);
        $service->method('invalidateAllSites')->willReturnCallback(function (): void {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->invalidated[] = '*';
        });
        $service->method('invalidateSite')->willReturnCallback(function (Site $site): void {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->invalidated[] = $site->getIdentifier();
        });

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturnCallback(
            function (string $identifier): Site {
                if (!$this->siteExists) {
                    throw new \InvalidArgumentException('No site found for identifier ' . $identifier, 1521716622);
                }

                return new Site($identifier, 1, ['base' => 'https://www.example.org/']);
            }
        );

        $controller = new CacheManagementController(
            $service,
            $siteFinder,
            // Final, and only indexAction() touches it — which is not tested
            // here. Built without its constructor rather than doubled: the
            // object the flush actions never use is the object that cannot be
            // built, and an uninitialised one throws if anything does reach it
            // instead of quietly answering.
            (new \ReflectionClass(ModuleTemplateFactory::class))->newInstanceWithoutConstructor(),
        );

        $this->wire($controller, $siteArgument);

        return $controller;
    }

    /**
     * Supplies what Extbase would inject. The two services are private on
     * ActionController and reached through their setters; the request and URI
     * builder are protected properties.
     */
    private function wire(CacheManagementController $controller, string $siteArgument): void
    {
        $extensionService = $this->createStub(ExtensionService::class);
        $extensionService->method('getPluginNamespace')->willReturn('tx_frankenphp');
        $controller->injectInternalExtensionService($extensionService);

        $flashMessageService = $this->createStub(FlashMessageService::class);
        $flashMessageService->method('getMessageQueueByIdentifier')->willReturn($this->queue);
        $controller->injectInternalFlashMessageService($flashMessageService);

        $parameters = (new ExtbaseRequestParameters())
            ->setControllerExtensionName('Frankenphp')
            ->setPluginName('Web_FrankenphpCache')
            ->setArgument('site', $siteArgument);

        $request = new Request(
            (new \TYPO3\CMS\Core\Http\ServerRequest('/typo3/module/web/frankenphp', 'GET'))
                ->withAttribute('extbase', $parameters)
        );

        $requestProperty = new \ReflectionProperty(\TYPO3\CMS\Extbase\Mvc\Controller\ActionController::class, 'request');
        $requestProperty->setValue($controller, $request);

        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('uriFor')->willReturn('/typo3/module/web/frankenphp');
        $uriProperty = new \ReflectionProperty(\TYPO3\CMS\Extbase\Mvc\Controller\ActionController::class, 'uriBuilder');
        $uriProperty->setValue($controller, $uriBuilder);
    }

    /** @return array{title: string, body: string, severity: ContextualFeedbackSeverity} */
    private function onlyMessage(): array
    {
        $messages = $this->messages;
        self::assertCount(1, $messages);

        return [
            'title' => $messages[0]->getTitle(),
            'body' => $messages[0]->getMessage(),
            'severity' => $messages[0]->getSeverity(),
        ];
    }

    private function assertRedirectsToTheOverview(ResponseInterface $response): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/typo3/module/web/frankenphp', $response->getHeaderLine('location'));
    }

    // --- flush everything ---------------------------------------------------

    #[Test]
    public function flushingReportsSuccessAndRedirects(): void
    {
        $response = $this->controller()->flushAction();

        self::assertSame(['*'], $this->invalidated);
        self::assertSame(ContextualFeedbackSeverity::OK, $this->onlyMessage()['severity']);
        $this->assertRedirectsToTheOverview($response);
    }

    /**
     * The failure has to reach the editor. Both paths redirect, so a swallowed
     * error would leave them looking at an overview that says nothing while the
     * cache is still stale.
     */
    #[Test]
    public function aFailedFlushIsReportedAsAnErrorRatherThanSwallowed(): void
    {
        $this->failure = new \RuntimeException('connection refused');

        $response = $this->controller()->flushAction();

        $message = $this->onlyMessage();
        self::assertSame(ContextualFeedbackSeverity::ERROR, $message['severity']);
        self::assertStringContainsString('connection refused', $message['body']);
        $this->assertRedirectsToTheOverview($response);
    }

    // --- flush one site -----------------------------------------------------

    #[Test]
    public function flushingOneSiteInvalidatesThatSiteOnly(): void
    {
        $response = $this->controller('second')->flushSiteAction();

        self::assertSame(['second'], $this->invalidated);

        $message = $this->onlyMessage();
        self::assertSame(ContextualFeedbackSeverity::OK, $message['severity']);
        self::assertStringContainsString('second', $message['body']);
        $this->assertRedirectsToTheOverview($response);
    }

    #[Test]
    public function aRequestWithoutASiteIdentifierIsRefusedBeforeAnythingIsFlushed(): void
    {
        $response = $this->controller('')->flushSiteAction();

        self::assertSame([], $this->invalidated);
        self::assertSame('Invalid Request', $this->onlyMessage()['title']);
        $this->assertRedirectsToTheOverview($response);
    }

    /**
     * An identifier that names no site is an error the editor has to see —
     * silently flushing nothing would read as success.
     */
    #[Test]
    public function anUnknownSiteIsReportedRatherThanIgnored(): void
    {
        $this->siteExists = false;

        $response = $this->controller('does-not-exist')->flushSiteAction();

        self::assertSame([], $this->invalidated);

        $message = $this->onlyMessage();
        self::assertSame(ContextualFeedbackSeverity::ERROR, $message['severity']);
        self::assertStringContainsString('does-not-exist', $message['body']);
        $this->assertRedirectsToTheOverview($response);
    }

    #[Test]
    public function aFailureWhileFlushingOneSiteIsReportedToo(): void
    {
        $this->failure = new \RuntimeException('connection refused');

        $this->controller('main')->flushSiteAction();

        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->onlyMessage()['severity']);
    }
}
