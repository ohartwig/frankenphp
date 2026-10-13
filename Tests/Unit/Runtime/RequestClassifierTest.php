<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\RequestClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which requests count as backend, and therefore which ones a frontend worker
 * may serve.
 *
 * Getting this wrong is not a rendering bug. A backend request classified as
 * frontend is handed to a long-lived worker process that was never meant to
 * carry an authenticated editing session; a frontend request classified as
 * backend loses the worker's benefit entirely and silently. Neither shows up as
 * an error — the first as odd session behaviour under load, the second as a
 * site that is simply slower than it should be.
 *
 * The prefix comparison is where it actually goes wrong. "/typo3" must match
 * "/typo3" and "/typo3/module" and must NOT match "/typo3-blog" — a plain
 * str_starts_with on the bare path would take that public page for the backend
 * and never serve it from a worker.
 */
final class RequestClassifierTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        parent::tearDown();
    }

    private function request(string $uri, ?string $host = null): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
        if ($host !== null) {
            $_SERVER['HTTP_HOST'] = $host;
        }
    }

    #[Test]
    public function theDefaultBackendPathIsRecognised(): void
    {
        $classifier = new RequestClassifier();

        foreach (['/typo3', '/typo3/', '/typo3/module/web/layout', '/typo3?a=b'] as $uri) {
            $this->request($uri);
            self::assertTrue($classifier->isBackendRequest(), $uri);
            self::assertFalse($classifier->isFrontendRequest(), $uri);
        }
    }

    /**
     * The one that a bare prefix check gets wrong: a public page whose path
     * merely begins with the backend path is not the backend.
     */
    #[Test]
    public function aPageThatMerelyStartsWithTheBackendPathIsFrontend(): void
    {
        $classifier = new RequestClassifier();

        foreach (['/typo3-blog', '/typo3blog/post', '/typo3x', '/', '/about'] as $uri) {
            $this->request($uri);
            self::assertFalse($classifier->isBackendRequest(), $uri);
            self::assertTrue($classifier->isFrontendRequest(), $uri);
        }
    }

    #[Test]
    public function aCustomBackendPathIsHonoured(): void
    {
        $classifier = new RequestClassifier('/admin');

        $this->request('/admin/module');
        self::assertTrue($classifier->isBackendRequest());

        $this->request('/typo3');
        self::assertFalse($classifier->isBackendRequest());
    }

    #[Test]
    public function aBackendPathIsNormalisedWhateverTheConfigurationLooksLike(): void
    {
        foreach (['admin', '/admin', '/admin/', 'admin/'] as $configured) {
            $classifier = new RequestClassifier($configured);

            $this->request('/admin');
            self::assertTrue($classifier->isBackendRequest(), $configured);

            $this->request('/administration');
            self::assertFalse($classifier->isBackendRequest(), $configured);
        }
    }

    // --- host-based backend -------------------------------------------------

    #[Test]
    public function aBackendHostMatchesOnAnyPath(): void
    {
        $classifier = new RequestClassifier('https://backend.example.org');

        $this->request('/anything/at/all', 'backend.example.org');
        self::assertTrue($classifier->isBackendHost());
        self::assertTrue($classifier->isBackendRequest());

        $this->request('/', 'www.example.org');
        self::assertFalse($classifier->isBackendHost());
        self::assertFalse($classifier->isBackendRequest());
    }

    /**
     * A host arrives with whatever port the client used. Comparing "example.org"
     * against "example.org:8443" would classify every request on a non-default
     * port as frontend.
     */
    #[Test]
    public function thePortIsStrippedBeforeTheHostIsCompared(): void
    {
        $classifier = new RequestClassifier('https://backend.example.org');

        foreach (['backend.example.org', 'backend.example.org:8443', 'BACKEND.EXAMPLE.ORG'] as $host) {
            $this->request('/', $host);
            self::assertTrue($classifier->isBackendHost(), $host);
        }
    }

    /**
     * An IPv6 literal carries colons of its own. Cutting at the last one would
     * mangle "[::1]" into "[::" and stop matching anything.
     */
    #[Test]
    public function anIpv6HostIsNotCutAtItsOwnColons(): void
    {
        $classifier = new RequestClassifier('https://[::1]');

        $this->request('/', '[::1]');
        self::assertTrue($classifier->isBackendHost());

        $this->request('/', '[::1]:8443');
        self::assertTrue($classifier->isBackendHost());
    }

    #[Test]
    public function theHostFallsBackToServerNameWhenNoHostHeaderArrived(): void
    {
        $classifier = new RequestClassifier('https://backend.example.org');

        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_HOST']);
        $_SERVER['SERVER_NAME'] = 'backend.example.org';

        self::assertTrue($classifier->isBackendHost());
    }

    /**
     * With a path-based entry point there is no backend host, so the host check
     * must never fire — otherwise a request with no Host header at all would
     * match an empty configured host.
     */
    #[Test]
    public function aPathBasedConfigurationHasNoBackendHost(): void
    {
        $classifier = new RequestClassifier('/typo3');

        $this->request('/');
        self::assertFalse($classifier->isBackendHost());
    }

    #[Test]
    public function aHostBasedEntryPointKeepsItsPathIfItHasOne(): void
    {
        $classifier = new RequestClassifier('https://example.org/backend');

        $this->request('/backend/module', 'www.example.org');
        self::assertTrue($classifier->isBackendRequest());

        $this->request('/other', 'www.example.org');
        self::assertFalse($classifier->isBackendRequest());
    }

    // --- install tool -------------------------------------------------------

    /**
     * The Install Tool lives at a fixed path regardless of where the backend was
     * moved to, so it has to be recognised even when the backend is host-based
     * and this request's host is the public one.
     */
    #[Test]
    public function theInstallToolIsRecognisedWhereverTheBackendLives(): void
    {
        foreach (['/typo3', '/admin', 'https://backend.example.org'] as $entryPoint) {
            $classifier = new RequestClassifier($entryPoint);

            $this->request('/typo3/install.php', 'www.example.org');
            self::assertTrue($classifier->isInstallRequest(), $entryPoint);
            self::assertFalse($classifier->isFrontendRequest(), $entryPoint);
        }
    }

    #[Test]
    public function anOrdinaryPageIsNotTheInstallTool(): void
    {
        $classifier = new RequestClassifier();

        $this->request('/typo3-install.php');
        self::assertFalse($classifier->isInstallRequest());
    }

    /**
     * parse_url() answers null for a URI with no path and false for a malformed
     * one. Neither is a path, both mean the site root — and returning false
     * where a string is declared would have been a TypeError.
     */
    #[Test]
    public function aMissingOrMalformedUriIsTreatedAsTheSiteRoot(): void
    {
        $classifier = new RequestClassifier();

        unset($_SERVER['REQUEST_URI']);
        self::assertTrue($classifier->isFrontendRequest());

        $_SERVER['REQUEST_URI'] = 'http:///malformed';
        self::assertTrue($classifier->isFrontendRequest());
    }
}
