<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Service;

use Koh\FrankenPHP\Service\CaddyCacheService;
use Koh\FrankenPHP\Service\PurgeAudit;
use Koh\Dev\Testing\Typo3\DatabaseDoubles;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\Request;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
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
#[AllowMockObjectsWithoutExpectations]
final class CaddyCacheServiceTest extends TestCase
{
    use DatabaseDoubles;

    /** @var list<RequestInterface> */
    private array $sent = [];

    private ?\Throwable $transportError = null;

    private ResponseInterface $response;

    /** Answer to GETs (the admin API's key lists); falls back to $response. */
    private ?ResponseInterface $listResponse = null;

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

                if ($request->getMethod() === 'GET' && $this->listResponse !== null) {
                    return $this->listResponse;
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

        $request = $this->sent[0];
        self::assertSame('PURGE', $request->getMethod());
        self::assertStringStartsWith('http://localhost:2019/souin-api/souin', (string)$request->getUri());
        self::assertStringNotContainsString('www.example.org/a-page', (string)$request->getUri()->getHost());
    }

    /**
     * AUD-062-F01: a file has no page URL and no tag, so it is purged by its
     * Souin key per site host. Souin reads the key as a regex, but the key can
     * carry no escapes: TYPO3's Uri percent-encodes the backslash and the
     * brackets, Souin does not decode them, and the preg_quote()d key this
     * test used to pin matched nothing at all (caddy-proxy image, 2026-11-08).
     * Every character that is regex syntax or would be encoded becomes ".",
     * and the key is anchored, so a.pdf does not take a.pdf.zip with it.
     */
    #[Test]
    public function aFileIsPurgedByItsAnchoredSouinKeyOnEverySiteHost(): void
    {
        $this->service(
            [],
            $this->site('main', 'https://www.example.org/'),
            $this->site('other', 'https://other.example.org/'),
        )->invalidateFilePaths(['/fileadmin/docs/report (1)+final.pdf']);

        self::assertCount(2, $this->sent);
        self::assertSame('PURGE', $this->sent[0]->getMethod());
        self::assertStringStartsWith('http://localhost:2019/souin-api/souin/', (string)$this->sent[0]->getUri());
        self::assertSame(
            'http://localhost:2019/souin-api/souin/GET-www.example.org-/fileadmin/docs/report..1..final.pdf$',
            (string)$this->sent[0]->getUri(),
        );
        self::assertSame(
            'http://localhost:2019/souin-api/souin/GET-other.example.org-/fileadmin/docs/report..1..final.pdf$',
            (string)$this->sent[1]->getUri(),
        );
    }

    #[Test]
    public function aFilePurgeSendsNothingWhenDisabled(): void
    {
        $this->service(['enabled' => '0'], $this->site('main', 'https://www.example.org/'))
            ->invalidateFilePaths(['/fileadmin/a.pdf']);

        self::assertSame([], $this->sent);
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

        self::assertStringEndsWith('/GET-www.example.org-/a/page$', (string)$this->sent[0]->getUri());
    }

    #[Test]
    public function aUrlWithoutAPathPurgesTheRoot(): void
    {
        $this->service()->invalidateUrls(['https://www.example.org']);

        self::assertStringEndsWith('/GET-www.example.org-/$', (string)$this->sent[0]->getUri());
    }

    /**
     * Souin deletes every key the purge key matches anywhere, and every key it
     * is a prefix of. Without the "$", the start page's key "GET-host-/"
     * deleted every entry of the host, and "/blog" everything below it.
     */
    #[Test]
    public function thePerUrlKeyIsAnchoredSoItDoesNotTakeOtherPagesWithIt(): void
    {
        $this->service()->invalidateUrls(['https://www.example.org/', 'https://www.example.org/blog']);

        self::assertSame('/souin-api/souin/GET-www.example.org-/$', $this->sent[0]->getUri()->getPath());
        self::assertSame('/souin-api/souin/GET-www.example.org-/blog$', $this->sent[1]->getUri()->getPath());
    }

    /**
     * Each URL gets its own exact key, and every host involved one more purge
     * for its query-string variants: "/?" makes the slash optional, ".+="
     * needs a query. Two hosts, two of those; a URL given twice, one purge.
     */
    #[Test]
    public function everyUrlGetsItsOwnPurgeAndEveryHostOneForItsQueryVariants(): void
    {
        $this->service()->invalidateUrls([
            'https://www.example.org/a',
            'https://www.example.org/b',
            'https://www.example.org/a',
            'https://en.example.org/a',
        ]);

        self::assertSame([
            'http://localhost:2019/souin-api/souin/GET-www.example.org-/a$',
            'http://localhost:2019/souin-api/souin/GET-www.example.org-/b$',
            'http://localhost:2019/souin-api/souin/GET-en.example.org-/a$',
            'http://localhost:2019/souin-api/souin/GET-www.example.org-/?.+=',
            'http://localhost:2019/souin-api/souin/GET-en.example.org-/?.+=',
        ], array_map(static fn (RequestInterface $r): string => (string)$r->getUri(), $this->sent));
    }

    /**
     * The URL arrives percent-encoded, the stored key is decoded. Characters
     * that are regex syntax or that TYPO3's Uri would encode become "." -- one
     * per character, umlauts included, so the key still has the stored key's
     * length. A port stays part of the host, as in the stored key.
     */
    #[Test]
    public function aPathIsDecodedAndReducedToCharactersThatSurviveTheTrip(): void
    {
        $this->service()->invalidateUrls(['http://localhost:8080/%C3%BCber-uns/a+b(c)$d']);

        self::assertSame(
            'http://localhost:2019/souin-api/souin/GET-localhost:8080-/.ber-uns/a.b.c..d$',
            (string)$this->sent[0]->getUri(),
        );
    }

    #[Test]
    public function noUrlsSendNothing(): void
    {
        $this->service()->invalidateUrls([]);

        self::assertSame([], $this->sent);
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
            'apiEndpoint' => '/custom-api/souin/',
        ])->invalidateUrls(['https://www.example.org/a']);

        self::assertSame('https://caddy-proxy:8443/custom-api/souin/GET-www.example.org-/a$', (string)$this->sent[0]->getUri());
        self::assertSame('https://caddy-proxy:8443/custom-api/souin/GET-www.example.org-/?.+=', (string)$this->sent[1]->getUri());
    }

    // --- tags ---------------------------------------------------------------

    /**
     * A tag purge carries the tags in a header and targets the endpoint itself
     * rather than a key — the two modes are not interchangeable, and sending a
     * key with tags would purge one page instead of a whole tag set.
     */
    #[Test]
    public function aTagPurgeSendsTheKnownTagsInTheSurrogateKeyHeaderSeparatedByCommaSpace(): void
    {
        // Souin splits the header on ", ". A space alone made "a b" one unknown tag.
        $this->listResponse = $this->jsonResponse('{"SURROGATE_pageId_42":",k1","SURROGATE_tt_content_7":",k2"}');
        $this->response = (new Response())->withStatus(204);

        $result = $this->service([], $this->site('main', 'https://www.example.org/'))
            ->purgeTags(['pageId_42', 'tt_content_7']);

        self::assertCount(2, $this->sent);
        self::assertSame('GET', $this->sent[0]->getMethod());
        $request = $this->sent[1];
        self::assertSame('PURGE', $request->getMethod());
        self::assertSame('pageId_42, tt_content_7', $request->getHeaderLine('Surrogate-Key'));
        self::assertStringNotContainsString('GET-', (string)$request->getUri());
        self::assertTrue($result->isComplete());
    }

    /**
     * The edge purge after a save (KOH_EDGE_PURGE_BY_TAG) sends its tags
     * without reading Souin's tag list first: one PURGE on the API base path,
     * the tags in the Surrogate-Key header.
     */
    #[Test]
    public function surrogateKeysArePurgedWithoutAskingSouinFirst(): void
    {
        $this->response = (new Response())->withStatus(204);

        $this->service([], $this->site('main', 'https://www.example.org/'))
            ->invalidateSurrogateKeys(['pageId_2300', 'tt_content_5']);

        self::assertCount(1, $this->sent);
        self::assertSame('PURGE', $this->sent[0]->getMethod());
        self::assertSame('http://localhost:2019/souin-api/souin', (string)$this->sent[0]->getUri());
        self::assertSame('pageId_2300, tt_content_5', $this->sent[0]->getHeaderLine('Surrogate-Key'));
    }

    /**
     * caddy-proxy takes 16 KB of request headers; a publish of a large tree
     * flushes more tags than one header holds, so they are split.
     */
    #[Test]
    public function manySurrogateKeysAreSplitIntoSeveralPurges(): void
    {
        $this->response = (new Response())->withStatus(204);
        $tags = array_map(static fn (int $i): string => 'pageId_' . $i, range(1, 1500));

        $this->service([], $this->site('main', 'https://www.example.org/'))->invalidateSurrogateKeys($tags);

        self::assertGreaterThan(1, count($this->sent));
        $sent = [];
        foreach ($this->sent as $request) {
            self::assertLessThanOrEqual(8000, strlen($request->getHeaderLine('Surrogate-Key')));
            $sent = [...$sent, ...explode(', ', $request->getHeaderLine('Surrogate-Key'))];
        }
        self::assertSame($tags, $sent);
    }

    #[Test]
    public function noSurrogateKeysNoPurge(): void
    {
        $this->service([], $this->site('main', 'https://www.example.org/'))->invalidateSurrogateKeys([]);

        self::assertSame([], $this->sent);
    }

    #[Test]
    public function surrogateKeysAreNotSentWhenDisabled(): void
    {
        $this->service(['enabled' => '0'], $this->site('main', 'https://www.example.org/'))->invalidateSurrogateKeys(['pageId_1']);

        self::assertSame([], $this->sent);
    }

    #[Test]
    public function theSurrogateKeyPurgeUsesTheConfiguredHeader(): void
    {
        $this->response = (new Response())->withStatus(204);

        $this->service(['cacheTagsHeader' => 'Cache-Tags'], $this->site('main', 'https://www.example.org/'))
            ->invalidateSurrogateKeys(['pageId_1']);

        self::assertSame('pageId_1', $this->sent[0]->getHeaderLine('Cache-Tags'));
    }

    /**
     * AUD-062 F01 step 2: Souin answers 204 to a tag nothing was stored under. That
     * used to read as a purge; now the tag is reported as never emitted.
     */
    #[Test]
    public function aTagNothingEmittedIsReportedNotPurged(): void
    {
        $this->listResponse = $this->jsonResponse('{}');
        $this->response = (new Response())->withStatus(204);

        $result = $this->service([], $this->site('main', 'https://www.example.org/'))
            ->purgeTags(['file:5']);

        self::assertCount(1, $this->sent);
        self::assertSame('GET', $this->sent[0]->getMethod());
        self::assertSame(['file:5'], $result->unmatched);
        self::assertFalse($result->isComplete());
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertStringContainsString('no Surrogate-Key emitted', $this->logger->records[0]['message']);
        self::assertSame('file:5', $this->logger->records[0]['context']['tag']);
    }

    #[Test]
    public function onlyTheKnownTagsOfAMixedPurgeAreSent(): void
    {
        $this->listResponse = $this->jsonResponse('{"SURROGATE_pageId_1":",k"}');
        $this->response = (new Response())->withStatus(204);

        $result = $this->service([], $this->site('main', 'https://www.example.org/'))
            ->purgeTags(['pageId_1', 'navigation']);

        self::assertSame('pageId_1', $this->sent[1]->getHeaderLine('Surrogate-Key'));
        self::assertSame(['pageId_1'], $result->purged);
        self::assertSame(['navigation'], $result->unmatched);
    }

    #[Test]
    public function anUnreadableKeyListFailsTheTagPurgeInsteadOfClaimingSuccess(): void
    {
        $this->listResponse = (new Response())->withStatus(401);

        $result = $this->service([], $this->site('main', 'https://www.example.org/'))
            ->purgeTags(['main']);

        self::assertTrue($result->failed);
        self::assertCount(1, $this->sent);
    }

    #[Test]
    public function aDisabledServiceReportsDisabledForATagPurge(): void
    {
        $result = $this->service(['enabled' => '0'], $this->site('main', 'https://www.example.org/'))
            ->purgeTags(['main']);

        self::assertTrue($result->disabled);
        self::assertSame([], $this->sent);
    }

    #[Test]
    public function theTagHeaderNameIsConfigurable(): void
    {
        $this->listResponse = $this->jsonResponse('{"SURROGATE_a":",k"}');
        $this->response = (new Response())->withStatus(204);

        $this->service(['cacheTagsHeader' => 'Cache-Tag'], $this->site('main', 'https://www.example.org/'))
            ->invalidateByTags(['a']);

        self::assertSame('a', $this->sent[1]->getHeaderLine('Cache-Tag'));
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
        $this->listResponse = $this->jsonResponse('{"SURROGATE_main":",k"}');
        $this->response = (new Response())->withStatus(204);
        $site = $this->site('main', 'https://www.example.org/');

        $result = $this->service([], $site)->flushSite($site);

        self::assertSame('main', $this->sent[1]->getHeaderLine('Surrogate-Key'));
        self::assertTrue($result->isComplete());
    }

    /**
     * The "flush site" reproduction: nothing emits Surrogate-Key, Souin holds no
     * entry under the site tag and answers the PURGE with 204 anyway. The result must not be success.
     */
    #[Test]
    public function flushingASiteWhoseTagWasNeverEmittedIsNotSuccess(): void
    {
        $this->listResponse = $this->jsonResponse('[]');
        $this->response = (new Response())->withStatus(204);
        $site = $this->site('main', 'https://www.example.org/');

        $result = $this->service([], $site)->flushSite($site);

        self::assertFalse($result->isComplete());
        self::assertSame(['main'], $result->unmatched);
        self::assertSame([], array_filter($this->sent, static fn ($r) => $r->getMethod() === 'PURGE'));
    }

    #[Test]
    public function flushingASiteAnsweredWithAnErrorIsAFailure(): void
    {
        $this->listResponse = $this->jsonResponse('{"SURROGATE_main":",k"}');
        $this->response = (new Response())->withStatus(403);
        $site = $this->site('main', 'https://www.example.org/');

        $result = $this->service([], $site)->flushSite($site);

        self::assertTrue($result->failed);
        self::assertFalse($result->isComplete());
    }

    /**
     * Souin's PURGE answers 204. A 200 is something else replying -- a proxy
     * page -- and is no proof that anything was purged.
     */
    #[Test]
    public function aPurgeAnsweredWith200IsNotAcceptedAsASuccess(): void
    {
        $this->listResponse = $this->jsonResponse('{"SURROGATE_main":",k"}');
        $this->response = $this->jsonResponse('<html>ok</html>', 200);
        $site = $this->site('main', 'https://www.example.org/');

        $result = $this->service([], $site)->flushSite($site);

        self::assertTrue($result->failed);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame(200, $this->logger->records[0]['context']['statusCode']);
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

    /**
     * With the audit switched on, every purge leaves a line naming its Souin
     * key; switched off (the default) there is none.
     */
    #[Test]
    public function aPurgeIsRecordedWhenTheAuditIsOn(): void
    {
        $lines = [];
        PurgeAudit::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true);
        });
        try {
            $this->service()->invalidateUrls(['https://www.example.org/a']);
            self::assertSame([], $lines, 'off by default');

            putenv(PurgeAudit::ENV . '=1');
            $this->service()->invalidateUrls(['https://www.example.org/a']);
            $this->service()->flushAll();
        } finally {
            putenv(PurgeAudit::ENV);
            PurgeAudit::writeTo(null);
        }

        self::assertCount(3, $lines);
        self::assertIsArray($lines[0]);
        self::assertSame('purge', $lines[0]['koh_purge_audit']);
        self::assertSame('GET-www.example.org-/a$', $lines[0]['key']);
        self::assertIsArray($lines[1]);
        self::assertSame('GET-www.example.org-/?.+=', $lines[1]['key']);
        self::assertSame('https://www.example.org/a (query variants)', $lines[1]['ref']);
        self::assertIsArray($lines[2]);
        self::assertSame('.+', $lines[2]['key']);
        self::assertSame('*', $lines[2]['ref']);
    }

    // --- page URLs from the page records ----------------------------------

    /** @var list<array{route: mixed, language: int}> */
    private array $routes = [];

    /**
     * A service whose SiteFinder places every page in $site and whose pages
     * query answers with $rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function pageService(?Site $site, array $rows, bool $queryFails = false, ?object $queryBuilder = null): CaddyCacheService
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        if ($site === null) {
            $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('none', 1));
        } else {
            $siteFinder->method('getSiteByPageId')->willReturn($site);
        }
        $siteFinder->method('getAllSites')->willReturn([]);

        $pool = $this->createStub(ConnectionPool::class);
        if ($queryFails) {
            $pool->method('getQueryBuilderForTable')->willThrowException(new \RuntimeException('db down'));
        } else {
            $pool->method('getQueryBuilderForTable')->willReturnCallback(fn (): object => $queryBuilder ?? $this->dbQueryBuilder($rows));
        }

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['enableDebugLogging' => '1']);

        return new CaddyCacheService(
            $this->logger,
            $this->createStub(ClientInterface::class),
            $siteFinder,
            $this->requestFactory(),
            $extensionConfiguration,
            $pool,
        );
    }

    /**
     * A site with these languages (id => fallbackType) whose router answers
     * with the language base plus the slug of the record it is given, and
     * notes what it was given.
     *
     * @param array<int, string> $languages
     */
    private function routedSite(array $languages, ?\Throwable $routerError = null): Site
    {
        $siteLanguages = [];
        foreach ($languages as $id => $fallbackType) {
            $siteLanguages[$id] = new SiteLanguage(
                $id,
                $id === 0 ? 'de_DE' : 'en_GB',
                new Uri($id === 0 ? 'https://www.example.org/' : 'https://www.example.org/en/'),
                ['fallbackType' => $fallbackType],
            );
        }
        $router = $this->createStub(RouterInterface::class);
        $router->method('generateUri')->willReturnCallback(
            function (mixed $route, array $parameters) use ($routerError): Uri {
                if ($routerError !== null) {
                    throw $routerError;
                }
                $language = $parameters['_language'];
                self::assertInstanceOf(SiteLanguage::class, $language);
                $this->routes[] = ['route' => $route, 'language' => $language->getLanguageId()];
                $slug = is_array($route) ? (string)$route['slug'] : '/?id=' . (string)$route;

                return new Uri(rtrim((string)$language->getBase(), '/') . $slug);
            }
        );
        $site = $this->createStub(Site::class);
        $site->method('getLanguages')->willReturn($siteLanguages);
        $site->method('getRouter')->willReturn($router);

        return $site;
    }

    /**
     * One URL per language in which the page exists, built from the records
     * themselves. A translation goes to the router as the default page's uid
     * with its own as _LOCALIZED_UID, the shape PageRouter takes as a ready
     * record instead of looking the page up again.
     */
    #[Test]
    public function aPageHasOneUrlPerLanguageItExistsIn(): void
    {
        // Values as the database hands them over: strings.
        $urls = $this->pageService($this->routedSite([0 => 'strict', 1 => 'strict', 2 => 'strict', 3 => 'strict']), [
            ['uid' => '42', 'sys_language_uid' => '0', 'l10n_parent' => '0', 'slug' => '/ueber-uns'],
            ['uid' => '43', 'sys_language_uid' => '1', 'l10n_parent' => '42', 'slug' => '/about'],
            ['uid' => '45', 'sys_language_uid' => '3', 'l10n_parent' => '42', 'slug' => '/a-propos'],
        ])->getPageUrls(42);

        self::assertSame([
            'https://www.example.org/ueber-uns',
            'https://www.example.org/en/about',
            'https://www.example.org/en/a-propos',
        ], $urls, 'language 2 has no translation and is skipped; language 3 still gets its URL');
        self::assertSame(42, $this->routes[1]['route']['uid']);
        self::assertSame(43, $this->routes[1]['route']['_LOCALIZED_UID']);
        self::assertSame(1, $this->routes[1]['language']);
    }

    /**
     * The router never sees the bare page id. Asked that way for a hidden page
     * it answered with the site base, and every save of a hidden page purged
     * the start page. The record is used whether hidden or not: a page that
     * was just hidden is the one whose cached copy has to go.
     */
    #[Test]
    public function aHiddenPageGetsItsOwnUrlAndNotTheSiteBase(): void
    {
        $urls = $this->pageService($this->routedSite([0 => 'strict']), [
            ['uid' => 42, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'slug' => '/entwurf', 'hidden' => 1],
        ])->getPageUrls(42);

        self::assertSame(['https://www.example.org/entwurf'], $urls);
        self::assertIsArray($this->routes[0]['route']);
    }

    /**
     * A language without a translation renders the page only when it falls
     * back to the default language. Then it gets a URL under its own base with
     * the default slug; in strict or free mode it gets none.
     */
    #[Test]
    public function anUntranslatedLanguageOnlyGetsAUrlWhenItFallsBack(): void
    {
        $default = ['uid' => 42, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'slug' => '/seite'];

        self::assertSame(
            ['https://www.example.org/seite'],
            $this->pageService($this->routedSite([0 => 'strict', 1 => 'strict']), [$default])->getPageUrls(42),
        );
        self::assertSame(
            ['https://www.example.org/seite'],
            $this->pageService($this->routedSite([0 => 'strict', 1 => 'free']), [$default])->getPageUrls(42),
        );

        $this->routes = [];
        self::assertSame(
            ['https://www.example.org/seite', 'https://www.example.org/en/seite'],
            $this->pageService($this->routedSite([0 => 'strict', 1 => 'fallback']), [$default])->getPageUrls(42),
        );
        self::assertSame(1, $this->routes[1]['route']['sys_language_uid']);
        self::assertSame(0, $this->routes[1]['route']['l10n_parent']);
    }

    /**
     * Without its default-language record (a uid that is only a translation,
     * or a page that is gone) there is nothing to build a URL from.
     */
    #[Test]
    public function aPageWithoutItsDefaultRecordHasNoUrls(): void
    {
        self::assertSame([], $this->pageService($this->routedSite([0 => 'strict', 1 => 'strict']), [
            ['uid' => 43, 'sys_language_uid' => 1, 'l10n_parent' => 42, 'slug' => '/about'],
        ])->getPageUrls(42));
        self::assertSame([], $this->pageService($this->routedSite([0 => 'strict']), [
            ['uid' => 99, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'slug' => '/other'],
        ])->getPageUrls(42));
        self::assertSame([], $this->routes);
    }

    /**
     * Only the page's own site. Before, every site of the installation built
     * a URL for every page, and a site the page does not belong to answered
     * with its base.
     */
    #[Test]
    public function aPageOutsideEverySiteHasNoUrls(): void
    {
        self::assertSame([], $this->pageService(null, [
            ['uid' => 42, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'slug' => '/x'],
        ])->getPageUrls(42));
        self::assertSame([], $this->pageService($this->routedSite([0 => 'strict']), [])->getPageUrls(0));
    }

    /**
     * The caller is a DataHandler hook: a failing query or router must cost
     * the URLs, not the editor's save.
     */
    #[Test]
    public function aFailingQueryOrRouterYieldsNoUrlsRatherThanThrowing(): void
    {
        self::assertSame([], $this->pageService($this->routedSite([0 => 'strict']), [], true)->getPageUrls(42));
        self::assertSame('Caddy Cache: Could not generate URL', $this->logger->records[0]['message']);
        self::assertSame('db down', $this->logger->records[0]['context']['error']);

        self::assertSame([], $this->pageService(
            $this->routedSite([0 => 'strict'], new \ArgumentCountError('router')),
            [['uid' => 42, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'slug' => '/x']],
        )->getPageUrls(42));
        self::assertSame(42, $this->logger->records[1]['context']['pageId']);
    }

    /**
     * Hidden and deleted records count (see aHiddenPageGetsItsOwnUrl…), so
     * the query runs without the frontend restrictions, and only on live
     * records: a workspace version is not what the edge holds.
     */
    #[Test]
    public function theRecordsAreReadWithoutRestrictionsAndFromLiveOnly(): void
    {
        $queryBuilder = $this->dbQueryBuilder([['uid' => 42, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'slug' => '/x']]);
        $restrictions = $queryBuilder->getRestrictions();
        self::assertInstanceOf(\PHPUnit\Framework\MockObject\MockObject::class, $restrictions);
        $restrictions->expects(self::once())->method('removeAll');
        $parameters = [];
        $queryBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\QueryBuilder::class);
        foreach (['select', 'from', 'where'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($this->createStub(\TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static function (mixed $value) use (&$parameters): string {
                $parameters[] = $value;

                return ':p' . count($parameters);
            }
        );
        $queryBuilder->method('executeQuery')->willReturn($this->dbResult([['uid' => 42, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'slug' => '/x']]));

        $urls = $this->pageService($this->routedSite([0 => 'strict']), [], false, $queryBuilder)->getPageUrls(42);

        self::assertSame(['https://www.example.org/x'], $urls);
        self::assertSame([42, 42, 0], $parameters, 'uid, l10n_parent, t3ver_wsid = 0');
    }

    /**
     * A caller that collects the query-variant purge itself turns it off per
     * call; the exact keys still go out.
     */
    #[Test]
    public function queryVariantsCanBeLeftToTheCaller(): void
    {
        $this->service()->invalidateUrls(['https://www.example.org/a', 'https://www.example.org/b'], false);

        self::assertSame([
            'http://localhost:2019/souin-api/souin/GET-www.example.org-/a$',
            'http://localhost:2019/souin-api/souin/GET-www.example.org-/b$',
        ], array_map(static fn (RequestInterface $r): string => (string)$r->getUri(), $this->sent));
    }

    /**
     * One purge per host, named after the first URL seen on it; URLs without
     * a host contribute nothing.
     */
    #[Test]
    public function queryVariantsArePurgedOncePerHost(): void
    {
        $lines = [];
        PurgeAudit::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true);
        });
        putenv(PurgeAudit::ENV . '=1');
        try {
            $this->service()->invalidateQueryVariants([
                'https://www.example.org/a',
                'https://www.example.org/b',
                'http://localhost:8080/c',
                '/relative',
            ]);
        } finally {
            putenv(PurgeAudit::ENV);
            PurgeAudit::writeTo(null);
        }

        self::assertSame([
            'http://localhost:2019/souin-api/souin/GET-www.example.org-/?.+=',
            'http://localhost:2019/souin-api/souin/GET-localhost:8080-/?.+=',
        ], array_map(static fn (RequestInterface $r): string => (string)$r->getUri(), $this->sent));
        self::assertIsArray($lines[0]);
        self::assertSame('https://www.example.org/a (query variants)', $lines[0]['ref']);
    }

    #[Test]
    public function theHostOfAUrlKeepsItsPort(): void
    {
        self::assertSame('www.example.org', CaddyCacheService::hostOf('https://www.example.org/a?b=c'));
        self::assertSame('localhost:8080', CaddyCacheService::hostOf('http://localhost:8080/'));
        self::assertSame('', CaddyCacheService::hostOf('/relative'));
    }
}
