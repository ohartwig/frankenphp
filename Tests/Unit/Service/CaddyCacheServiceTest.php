<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Service;

use Koh\FrankenPHP\Service\CaddyCacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Request;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The requests this service sends to Souin, and the ones it does not.
 *
 * Cache invalidation fails quietly by nature: a PURGE that never arrives, or
 * arrives at the wrong key, leaves stale pages served to visitors while every
 * log line says the flush succeeded. So what is asserted here is the wire —
 * method, URL, headers — rather than the return value, because the return value
 * is `void` and always was.
 *
 * Three properties carry the weight.
 *
 * The target is the Souin ADMIN endpoint, never the public content URL. Souin
 * v1.7.8 intercepts invalidation only there; a PURGE to the content URL is
 * proxied upstream as a 405 and the public edge blocks external PURGE by
 * design. A refactoring that "simplified" this to purge the page URL would look
 * obviously right and silently stop invalidating anything.
 *
 * The per-URL key is "GET-<host>-<path>": scheme dropped, because the Caddyfile
 * sets `key { disable_scheme }`, and a dash between host and path, because that
 * is how Souin writes the key (see its Cache-Status header). Include the scheme,
 * or drop the dash, and the key never matches a stored entry -- Souin still
 * answers 204.
 *
 * And a transport failure is swallowed. This runs inside DataHandler hooks — an
 * exception escaping here would turn "the edge cache is unreachable" into "the
 * editor cannot save the page".
 */
final class CaddyCacheServiceTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    private ?\Throwable $transportError = null;

    private ResponseInterface $response;

    private AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = new Response();
        $this->logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
            }
        };
    }

    /** @param array<string, mixed> $settings */
    private function service(array $settings = [], Site ...$sites): CaddyCacheService
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request): ResponseInterface {
                $this->sent[] = $request;
                if ($this->transportError !== null) {
                    throw $this->transportError;
                }

                return $this->response;
            }
        );

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($settings);

        return new CaddyCacheService(
            $this->logger,
            $client,
            $siteFinder,
            $this->requestFactory(),
            $extensionConfiguration,
        );
    }

    /**
     * A PSR-17 factory rather than TYPO3's RequestFactory, which takes a
     * constructor argument this test has no use for. The service only ever
     * calls createRequest() and then withHeader(), so a real Request is both
     * enough and more honest than a double.
     */
    private function requestFactory(): RequestFactoryInterface
    {
        return new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new Request((string)$uri, $method);
            }
        };
    }

    private function only(): RequestInterface
    {
        self::assertCount(1, $this->sent);

        return $this->sent[0];
    }

    private function site(string $identifier, string $base): Site
    {
        return new Site($identifier, 1, ['base' => $base]);
    }

    private function jsonResponse(string $body, int $status = 200): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return (new Response())->withStatus($status)->withBody($stream);
    }

    // --- where the PURGE goes -----------------------------------------------

    /**
     * The admin endpoint, built from the configured host, port and path — not
     * the page URL that was invalidated. That URL only supplies the key.
     */
    #[Test]
    public function aUrlIsPurgedOnTheAdminEndpointAndNotOnItself(): void
    {
        $this->service()->invalidateUrls(['https://www.example.org/a-page']);

        $request = $this->only();
        self::assertSame('PURGE', $request->getMethod());
        self::assertStringStartsWith('http://localhost:2019/souin-api/souin', (string)$request->getUri());
        self::assertStringNotContainsString('www.example.org/a-page', (string)$request->getUri()->getHost());
    }

    /**
     * `key { disable_scheme }` in the Caddyfile means the stored key carries no
     * scheme. A key built with one matches nothing, and the purge is a no-op
     * that reports success.
     */
    #[Test]
    public function thePerUrlKeyIsTheSchemelessSouinKey(): void
    {
        $this->service()->invalidateUrls(['https://www.example.org/a/page']);

        self::assertStringEndsWith('/GET-www.example.org-/a/page', (string)$this->only()->getUri());
    }

    #[Test]
    public function aUrlWithoutAPathPurgesTheRoot(): void
    {
        $this->service()->invalidateUrls(['https://www.example.org']);

        self::assertStringEndsWith('/GET-www.example.org-/', (string)$this->only()->getUri());
    }

    #[Test]
    public function everyUrlGetsItsOwnPurge(): void
    {
        $this->service()->invalidateUrls([
            'https://www.example.org/a',
            'https://www.example.org/b',
        ]);

        self::assertCount(2, $this->sent);
    }

    /**
     * A non-default port on purpose: PSR-7 drops :443 from an https URI as the
     * scheme default, so asserting on the k3s configuration would have tested
     * Uri's normalisation rather than this service's assembly.
     */
    #[Test]
    public function theEndpointIsBuiltFromTheConfiguredParts(): void
    {
        $this->service([
            'protocol' => 'https',
            'caddyHost' => 'caddy-proxy',
            'caddyPort' => '8443',
            'apiEndpoint' => '/custom-api/souin',
        ])->invalidateUrls(['https://www.example.org/a']);

        self::assertStringStartsWith('https://caddy-proxy:8443/custom-api/souin', (string)$this->only()->getUri());
    }

    // --- tags ---------------------------------------------------------------

    /**
     * A tag purge carries the tags in a header and targets the endpoint itself
     * rather than a key — the two modes are not interchangeable, and sending a
     * key with tags would purge one page instead of a whole tag set.
     */
    #[Test]
    public function aTagPurgeSendsTheTagsInTheSurrogateKeyHeader(): void
    {
        $this->service([], $this->site('main', 'https://www.example.org/'))
            ->invalidateByTags(['pageId_42', 'tt_content_7']);

        $request = $this->only();
        self::assertSame('pageId_42 tt_content_7', $request->getHeaderLine('Surrogate-Key'));
        self::assertStringNotContainsString('GET-', (string)$request->getUri());
    }

    #[Test]
    public function theTagHeaderNameIsConfigurable(): void
    {
        $this->service(['cacheTagsHeader' => 'Cache-Tag'], $this->site('main', 'https://www.example.org/'))
            ->invalidateByTags(['a']);

        self::assertSame('a', $this->only()->getHeaderLine('Cache-Tag'));
    }

    /**
     * No site means no base URL to purge against. Sending the request anyway
     * would target a half-built URL.
     */
    #[Test]
    public function aTagPurgeWithoutAnySiteSendsNothing(): void
    {
        $this->service()->invalidateByTags(['a']);

        self::assertSame([], $this->sent);
    }

    #[Test]
    public function invalidatingASitePurgesItsIdentifierAsATag(): void
    {
        $site = $this->site('main', 'https://www.example.org/');

        $this->service([], $site)->invalidateSite($site);

        self::assertSame('main', $this->only()->getHeaderLine('Surrogate-Key'));
    }

    // --- flush all ----------------------------------------------------------

    /**
     * One Souin store serves the whole multisite system, so the flush is a
     * single regex purge of every key rather than an enumeration of site bases.
     */
    #[Test]
    public function flushingEverythingPurgesTheCatchAllKey(): void
    {
        $this->service()->flushAll();

        self::assertStringEndsWith('/.+', (string)$this->only()->getUri());
    }

    /**
     * The switch exists so an installation can opt out of the global flush.
     * Off must mean nothing is sent, not "sent but narrower".
     */
    #[Test]
    public function theGlobalFlushCanBeSwitchedOff(): void
    {
        $this->service(['invalidateAllSites' => '0'])->invalidateAllSites();

        self::assertSame([], $this->sent);
    }

    #[Test]
    public function theGlobalFlushIsOnByDefault(): void
    {
        $this->service()->invalidateAllSites();

        self::assertCount(1, $this->sent);
    }

    // --- authentication and failure ----------------------------------------

    #[Test]
    public function theApiKeyIsSentWhenOneIsConfigured(): void
    {
        $this->service(['apiKey' => 's3cr3t'])->flushAll();

        self::assertSame('s3cr3t', $this->only()->getHeaderLine('X-API-Key'));
    }

    #[Test]
    public function noApiKeyHeaderIsSentWhenNoneIsConfigured(): void
    {
        $this->service()->flushAll();

        self::assertFalse($this->only()->hasHeader('X-API-Key'));
    }

    /**
     * This runs inside DataHandler hooks. An exception escaping here would turn
     * an unreachable edge cache into an editor who cannot save a page.
     */
    #[Test]
    public function aTransportFailureIsSwallowedRatherThanThrown(): void
    {
        $this->transportError = new \RuntimeException('connection refused');

        $this->service()->flushAll();

        self::assertCount(1, $this->sent);
    }

    /**
     * A response is not an acknowledgement. A wrong API key or a proxy in the
     * way answers with a status, not an exception, and has to be logged as the
     * failure it is -- at error level, which is what reaches stderr and Loki.
     */
    #[Test]
    public function aPurgeAnsweredWithAnErrorStatusIsLoggedAsAFailure(): void
    {
        $this->response = (new Response())->withStatus(401);

        $this->service()->flushAll();

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame('Caddy Cache: PURGE failed', $this->logger->records[0]['message']);
        self::assertSame(401, $this->logger->records[0]['context']['statusCode']);
    }

    #[Test]
    public function aSuccessfulPurgeLogsNoError(): void
    {
        $this->response = (new Response())->withStatus(204);

        $this->service()->flushAll();

        self::assertSame([], $this->logger->records);
    }

    // --- reading the admin API ---------------------------------------------

    #[Test]
    public function theKeyListIsReadFromTheAdminApi(): void
    {
        $this->response = $this->jsonResponse('["GET-www.example.org/a","GET-www.example.org/b"]');

        $keys = $this->service()->getKeys();

        self::assertSame(['GET-www.example.org/a', 'GET-www.example.org/b'], $keys);
        self::assertSame('GET', $this->only()->getMethod());
    }

    #[Test]
    public function theSurrogateKeysHaveTheirOwnEndpoint(): void
    {
        $this->response = $this->jsonResponse('[]');

        $this->service()->getSurrogateKeys();

        self::assertStringEndsWith('/surrogate_keys', (string)$this->only()->getUri());
    }

    /**
     * Souin answers in more than one shape depending on the endpoint: a list, a
     * map keyed by cache key, and values that are themselves comma-joined
     * lists. All of them have to come back as a flat list of decoded keys.
     */
    #[Test]
    public function everyShapeOfSouinPayloadIsFlattened(): void
    {
        foreach ([
            '["a","b"]' => ['a', 'b'],
            '{"a":"b"}' => ['a', 'b'],
            '{"a":["b","c"]}' => ['a', 'b', 'c'],
            '["a,b"]' => ['a', 'b'],
            '["a, b , "]' => ['a', 'b'],
            '["a","a"]' => ['a'],
            '["GET-www.example.org%2Fa"]' => ['GET-www.example.org/a'],
        ] as $payload => $expected) {
            $this->sent = [];
            $this->response = $this->jsonResponse($payload);

            self::assertSame($expected, $this->service()->getKeys(), $payload);
        }
    }

    #[Test]
    public function anUnusableAdminResponseYieldsNoKeysRatherThanAnError(): void
    {
        foreach (['not json', '"a string"', '', '42'] as $payload) {
            $this->response = $this->jsonResponse($payload);

            self::assertSame([], $this->service()->getKeys(), $payload);
        }
    }

    #[Test]
    public function anErrorStatusFromTheAdminApiYieldsNoKeys(): void
    {
        foreach ([401, 404, 500] as $status) {
            $this->response = $this->jsonResponse('["a"]', $status);

            self::assertSame([], $this->service()->getKeys(), (string)$status);
        }
    }

    #[Test]
    public function anUnreachableAdminApiYieldsNoKeys(): void
    {
        $this->transportError = new \RuntimeException('connection refused');

        self::assertSame([], $this->service()->getKeys());
    }

    // --- settings -----------------------------------------------------------

    /**
     * Extension configuration arrives as strings, and a missing or non-scalar
     * value must keep the documented default rather than reading as empty or
     * as off.
     */
    #[Test]
    public function malformedSettingsFallBackToTheDefaults(): void
    {
        $this->service([
            'caddyHost' => ['not', 'scalar'],
            'caddyPort' => null,
            'protocol' => new \stdClass(),
        ])->flushAll();

        self::assertStringStartsWith('http://localhost:2019/souin-api/souin', (string)$this->only()->getUri());
    }

    /**
     * A client certificate means mTLS to caddy-proxy:443, which needs a client
     * of its own — so the shared TYPO3 client is deliberately not used and this
     * request never reaches the double.
     */
    #[Test]
    public function aConfiguredClientCertificateReplacesTheSharedHttpClient(): void
    {
        $this->service(['tlsClientCert' => '/etc/ssl/app.crt'])->flushAll();

        self::assertSame([], $this->sent, 'the shared client must not have been used');
    }

    // --- page URLs ----------------------------------------------------------

    /**
     * An installation with no sites has no URLs to purge. Returning an empty
     * list rather than throwing matters because the caller is a DataHandler
     * hook: the editor's save must not depend on the site configuration being
     * complete.
     */
    #[Test]
    public function anInstallationWithoutSitesYieldsNoPageUrls(): void
    {
        self::assertSame([], $this->service()->getPageUrls(42));
    }

    /**
     * URL generation goes through the site router, which needs more of TYPO3
     * than a unit test has. What is pinned here is the contract around it: a
     * failure to generate is caught per site and yields no URL for that site
     * instead of propagating.
     *
     * That catch is not decoration. It runs inside
     * processDatamap_afterDatabaseOperations, so an exception escaping it turns
     * a page that cannot currently be routed — a new page without a slug, a
     * site whose configuration is mid-edit — into an editor who cannot save.
     */
    #[Test]
    public function aSiteWhoseUrlsCannotBeGeneratedIsSkippedRatherThanThrowing(): void
    {
        $service = $this->service(
            [],
            $this->site('main', 'https://www.example.org/'),
            $this->site('second', 'https://second.example.org/'),
        );

        self::assertSame([], $service->getPageUrls(42));
    }

    #[Test]
    public function theSameHoldsWithDebugLoggingTurnedOn(): void
    {
        $service = $this->service(
            ['enableDebugLogging' => '1'],
            $this->site('main', 'https://www.example.org/'),
        );

        self::assertSame([], $service->getPageUrls(42));
    }

    // --- the off switch -----------------------------------------------------

    /**
     * Local development has no caddy-proxy. Off means nothing leaves the
     * service: no PURGE, no admin API call, and therefore no logged
     * connection error that looks like a real failure.
     */
    #[Test]
    public function disabledSendsNothing(): void
    {
        $site = $this->site('main', 'https://example.test/');
        $service = $this->service(['enabled' => '0'], $site);

        $service->flushAll();
        $service->invalidateUrls(['https://example.test/']);
        $service->invalidateByTags(['navigation']);
        $service->invalidateSite($site);

        self::assertFalse($service->isEnabled());
        self::assertSame([], $service->getKeys());
        self::assertSame([], $this->sent);
    }

    #[Test]
    public function enabledByDefault(): void
    {
        $service = $this->service();
        self::assertTrue($service->isEnabled());

        $service->flushAll();
        self::assertCount(1, $this->sent);
    }

    /**
     * Debug logging is off by default and gates the per-PURGE log line. It is a
     * setting an operator turns on to see what is being invalidated, so "on"
     * must not change what is sent — only what is written.
     */
    #[Test]
    public function debugLoggingChangesWhatIsLoggedAndNotWhatIsSent(): void
    {
        $this->service(['enableDebugLogging' => '1'])->flushAll();
        $withLogging = (string)$this->only()->getUri();

        $this->sent = [];
        $this->service()->flushAll();

        self::assertSame($withLogging, (string)$this->only()->getUri());
    }
}
